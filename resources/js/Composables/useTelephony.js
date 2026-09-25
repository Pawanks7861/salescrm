import axios from 'axios';
import { computed, reactive, readonly } from 'vue';

/**
 * Softphone state + provider SDK adapter. Module-level singleton so the call
 * survives Inertia page changes (AppLayout remounts per page).
 *
 * Rules:
 *  - Provider callbacks (via the server) are the source of truth for call
 *    status; the SDK only drives local audio / UI transitions.
 *  - The browser never chooses the destination number: it sends a lead id +
 *    contact field (or a manual number when the user holds call.manual_dial).
 *  - Browser-calling credentials live only in this closure (never in reactive
 *    state, localStorage, sessionStorage or console output).
 *  - All provider-SDK specific code lives in the drivers below.
 */

export const STATES = {
    offline: 'Offline',
    registering: 'Registering',
    ready: 'Ready',
    incoming: 'Incoming',
    dialing: 'Dialing',
    ringing: 'Ringing',
    connected: 'Connected',
    on_hold: 'On Hold',
    ending: 'Ending',
    ended: 'Ended',
    error: 'Error',
};

const POLL_MS = 2000;
const FINALIZE_TIMEOUT_MS = 180000;
const UNAVAILABLE = 'Calling service is temporarily unavailable.';
const MIC_REQUIRED = 'Microphone access is required for browser calling.';
const OTHER_TAB = 'Calling is active in another CRM tab.';

const state = reactive({
    booted: false,
    config: null,
    status: 'offline',
    error: null,
    call: null, // live call from the server
    mode: null,
    muted: false,
    held: false,
    connectedAt: null,
    now: Date.now(),
    finalizing: false,
    incoming: null, // { number, providerCallId, identity, loading }
    outcomeCallId: null,
    otherTab: false,
    ownsSoftphone: true,
    webrtcReady: false,
});

// Non-reactive internals (credentials never enter reactive state).
let driver = null;
let pollTimer = null;
let clockTimer = null;
let finalizeDeadline = 0;
let channel = null;
const tabId = Math.random().toString(36).slice(2);
const otherTabCalls = new Map();

const messageFrom = (error, fallback = UNAVAILABLE) => error?.response?.data?.message || error?.response?.data?.errors?.number?.[0] || error?.response?.data?.errors?.contact_field?.[0] || fallback;

/* ------------------------------------------------------------------ */
/* Drivers                                                             */
/* ------------------------------------------------------------------ */

/**
 * Local development driver: no audio. It drives the fake provider through
 * the server's normal callback pipeline so the full lifecycle can be tested.
 */
function fakeDriver() {
    const timers = [];
    const simulate = (status, extra = {}) => state.call && axios.post(route('telephony.fake.simulate', state.call.id), { status, ...extra }).catch(() => {});
    const clear = () => timers.splice(0).forEach(clearTimeout);

    return {
        name: 'fake',
        async register() {
            await new Promise((r) => setTimeout(r, 300));
        },
        async dial() {
            clear();
            timers.push(setTimeout(() => simulate('ringing'), 1200));
            timers.push(setTimeout(() => simulate('answered'), 3500));
        },
        async answer() {
            await simulate('answered');
        },
        async reject() {
            await simulate('missed');
        },
        async hangup() {
            clear();
            const answered = ['answered', 'connected'].includes(state.call?.status) || state.connectedAt;
            await simulate(answered ? 'completed' : 'cancelled');
        },
        async outcome(status) {
            clear();
            await simulate(status);
        },
        mute() {},
        hold() {},
        destroy: clear,
    };
}

/**
 * Exotel CRM Web SDK adapter. The SDK script is loaded lazily from the
 * configured URL only when browser calling is enabled for this user.
 * Verify event names against the SDK version enabled on your Exotel account.
 */
function exotelDriver(session) {
    let phone = null;
    let sdk = null;
    const credentials = { ...session.credentials }; // closure only

    const loadScript = (url) =>
        new Promise((resolve, reject) => {
            if (window.ExotelCRMWebSDK) return resolve();
            if (!url) return reject(new Error('sdk_url_missing'));
            const s = document.createElement('script');
            s.src = url;
            s.async = true;
            s.onload = () => resolve();
            s.onerror = () => reject(new Error('sdk_load_failed'));
            document.head.appendChild(s);
        });

    const onCallEvent = (event, data = {}) => {
        const name = String(event || '').toLowerCase();
        if (name.includes('incoming')) {
            onIncoming({ number: data.callFromNumber || data.from || data.phone || null, providerCallId: data.callSid || data.callId || null });
        } else if (name.includes('connect') || name.includes('answer')) {
            markConnected();
        } else if (name.includes('ring')) {
            if (state.status === 'dialing') state.status = 'ringing';
        } else if (name.includes('end') || name.includes('terminat') || name.includes('hangup') || name.includes('reject')) {
            onRemoteEnded();
        }
    };

    return {
        name: 'exotel',
        async register() {
            await loadScript(session.sdk_url);
            const Sdk = window.ExotelCRMWebSDK?.default ?? window.ExotelCRMWebSDK;
            sdk = new Sdk(credentials.access_token, credentials.user_id, true);
            phone = await sdk.Initialize(onCallEvent, () => {});
            credentials.access_token = null;
            if (!phone) throw new Error('sdk_register_failed');
        },
        async dial(number, reference) {
            await new Promise((resolve, reject) =>
                phone.MakeCall(number, (status) => (String(status).toLowerCase().includes('fail') ? reject(new Error('dial_failed')) : resolve()), reference),
            );
        },
        async answer() {
            phone?.AcceptCall();
        },
        async reject() {
            phone?.HangupCall();
        },
        async hangup() {
            phone?.HangupCall();
        },
        mute() {
            phone?.ToggleMute();
        },
        hold() {
            phone?.ToggleHold();
        },
        destroy() {
            try {
                phone?.UnRegister?.();
            } catch {
                /* ignore */
            }
            phone = null;
            sdk = null;
        },
    };
}

/* ------------------------------------------------------------------ */
/* Multi-tab coordination                                              */
/* ------------------------------------------------------------------ */

function setupTabs() {
    if ('BroadcastChannel' in window) {
        channel = new BroadcastChannel('crm-softphone');
        channel.onmessage = ({ data }) => {
            if (!data || data.tab === tabId) return;
            if (data.type === 'call') otherTabCalls.set(data.tab, Date.now());
            if (data.type === 'idle') otherTabCalls.delete(data.tab);
            if (data.type === 'ping' && state.call?.is_open) channel.postMessage({ type: 'call', tab: tabId });
            state.otherTab = otherTabCalls.size > 0;
        };
        channel.postMessage({ type: 'ping', tab: tabId });
        window.addEventListener('beforeunload', () => channel?.postMessage({ type: 'idle', tab: tabId }));
    }

    // Only one tab registers the browser softphone (incoming calls ring once).
    if (navigator.locks?.request) {
        return new Promise((resolve) => {
            navigator.locks.request('crm-softphone', { ifAvailable: true }, (lock) => {
                state.ownsSoftphone = !!lock;
                resolve();
                return lock ? new Promise(() => {}) : undefined; // hold for the tab's lifetime
            });
        });
    }
    return Promise.resolve();
}

const announce = () => channel?.postMessage({ type: state.call?.is_open ? 'call' : 'idle', tab: tabId });

/* ------------------------------------------------------------------ */
/* Lifecycle                                                           */
/* ------------------------------------------------------------------ */

function startClock() {
    if (clockTimer) return;
    clockTimer = setInterval(() => (state.now = Date.now()), 1000);
}

function stopClock() {
    clearInterval(clockTimer);
    clockTimer = null;
}

function markConnected() {
    if (!state.connectedAt) state.connectedAt = Date.now();
    if (!['ending', 'ended'].includes(state.status)) state.status = state.held ? 'on_hold' : 'connected';
    startClock();
}

function applyServerCall(call) {
    state.call = call;
    if (!call) return;

    if (call.is_open) {
        if (call.status === 'ringing' && ['dialing', 'ready', 'offline'].includes(state.status)) state.status = 'ringing';
        if (['answered', 'connected'].includes(call.status)) {
            if (!state.connectedAt) state.connectedAt = call.answered_at ? new Date(call.answered_at).getTime() : Date.now();
            markConnected();
        }
        if (['initiated', 'queued'].includes(call.status) && ['ready', 'offline'].includes(state.status)) state.status = 'dialing';
        return;
    }

    // Terminal — provider callback received.
    stopPolling();
    stopClock();
    state.status = 'ended';
    state.finalizing = false;
    state.held = false;
    state.muted = false;
    announce();
    if (call.requires_disposition && state.config?.can_dispose !== false) state.outcomeCallId = call.id;
}

async function poll() {
    if (!state.call) return;
    try {
        const { data } = await axios.get(route('calls.status', state.call.id));
        applyServerCall(data.call);
    } catch (e) {
        if (e?.response?.status === 404 || e?.response?.status === 403) {
            stopPolling();
            reset();
        }
    }
    if (state.finalizing && Date.now() > finalizeDeadline) {
        stopPolling();
        state.finalizing = false;
        state.status = 'ended';
        state.error = 'The call is still being finalised. You can add the outcome from the Calls page shortly.';
    }
}

function startPolling() {
    stopPolling();
    pollTimer = setInterval(poll, POLL_MS);
}

function stopPolling() {
    clearInterval(pollTimer);
    pollTimer = null;
}

function reset() {
    stopPolling();
    stopClock();
    Object.assign(state, { call: null, mode: null, muted: false, held: false, connectedAt: null, finalizing: false, incoming: null, error: null });
    state.status = state.webrtcReady || state.config?.enabled ? 'ready' : 'offline';
    announce();
}

function onRemoteEnded() {
    if (!state.call) return;
    state.status = 'ending';
    state.finalizing = true;
    finalizeDeadline = Date.now() + FINALIZE_TIMEOUT_MS;
    stopClock();
    poll();
}

async function onIncoming({ number, providerCallId }) {
    if (state.call?.is_open) return; // busy: provider handles it
    state.status = 'incoming';
    state.incoming = { number, providerCallId, identity: null, loading: true };
    try {
        const { data } = await axios.post(route('telephony.identify'), { number, provider_call_id: providerCallId });
        if (state.incoming) state.incoming = { ...state.incoming, identity: data, loading: false };
    } catch {
        if (state.incoming) state.incoming = { ...state.incoming, identity: { state: 'restricted', message: 'Lead information unavailable.' }, loading: false };
    }
}

async function ensureMicrophone() {
    if (!navigator.mediaDevices?.getUserMedia) throw new Error(MIC_REQUIRED);
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        stream.getTracks().forEach((t) => t.stop());
    } catch {
        throw new Error(MIC_REQUIRED);
    }
}

async function registerWebRtc() {
    if (!state.config?.modes?.includes('webrtc') || !state.ownsSoftphone || state.webrtcReady) return;
    state.status = 'registering';
    try {
        const { data } = await axios.post(route('telephony.session'));
        driver = data.driver === 'exotel' ? exotelDriver(data) : fakeDriver();
        await driver.register();
        state.webrtcReady = true;
        state.status = 'ready';
    } catch (e) {
        driver = null;
        state.webrtcReady = false;
        state.status = state.config?.modes?.includes('pstn') ? 'ready' : 'error';
        state.error = messageFrom(e, 'Browser calling could not start. Phone calling is still available.');
    }
}

/* ------------------------------------------------------------------ */
/* Public API                                                          */
/* ------------------------------------------------------------------ */

async function boot() {
    if (state.booted) return;
    state.booted = true;
    await setupTabs();

    try {
        const { data } = await axios.get(route('telephony.config'));
        state.config = data;
    } catch {
        state.config = { enabled: false, modes: [] };
    }
    state.status = state.config.enabled ? 'ready' : 'offline';

    // Restore an in-flight call / pending outcome after reload or navigation.
    try {
        const { data } = await axios.get(route('calls.active'));
        if (data.call) {
            state.call = data.call;
            if (data.call.is_open) {
                state.finalizing = true;
                finalizeDeadline = Date.now() + FINALIZE_TIMEOUT_MS;
                applyServerCall(data.call);
                startPolling();
            } else if (data.call.requires_disposition) {
                state.status = 'ended';
                state.outcomeCallId = data.call.id;
            }
        }
    } catch {
        /* ignore */
    }

    // Browser-calling agents register up-front so incoming calls can ring here.
    if (state.config.default_mode === 'webrtc' && state.config.can_receive && !state.call?.is_open) {
        registerWebRtc();
    }
}

/**
 * @param {{ leadId?: number, contactField?: string, number?: string, mode?: string }} target
 */
async function startCall(target) {
    state.error = null;
    if (state.otherTab) {
        state.error = OTHER_TAB;
        return false;
    }
    if (state.call?.is_open) {
        state.error = 'Finish the current call first.';
        return false;
    }
    if (state.outcomeCallId) {
        state.error = 'Save the outcome of your last call first.';
        return false;
    }

    const mode = target.mode || state.config?.default_mode;
    if (mode === 'webrtc') {
        if (!state.ownsSoftphone) {
            state.error = OTHER_TAB;
            return false;
        }
        try {
            await ensureMicrophone();
        } catch (e) {
            state.error = e.message;
            return false;
        }
        await registerWebRtc();
        if (!state.webrtcReady) return false;
    }

    state.status = 'dialing';
    state.mode = mode;
    try {
        const { data } = await axios.post(route('calls.store'), {
            lead_id: target.leadId ?? null,
            contact_field: target.number ? null : target.contactField || 'phone',
            number: target.number || null,
            mode,
        });
        state.call = data.call;
        state.connectedAt = null;
        announce();

        if (data.dial && driver) {
            await driver.dial(data.dial.number, data.dial.reference);
        } else if (state.config?.driver === 'fake') {
            if (!driver) driver = fakeDriver();
            await driver.dial();
        }
        startPolling();
        return true;
    } catch (e) {
        state.error = messageFrom(e);
        if (state.call) {
            // Provider/SDK failed after the CRM record was created: let the callbacks/reconciler close it.
            onRemoteEnded();
        } else {
            state.status = 'error';
            setTimeout(() => state.status === 'error' && reset(), 4000);
        }
        return false;
    }
}

async function loadIncomingCall() {
    const callId = state.incoming?.identity?.call_id;
    if (!callId || state.call?.id === callId) return;
    try {
        const { data } = await axios.get(route('calls.status', callId));
        state.call = data.call;
    } catch {
        /* the call may belong to a lead this user cannot see */
    }
}

async function answer() {
    if (!state.incoming || !driver) return;
    await loadIncomingCall();
    await driver.answer();
    state.incoming = { ...state.incoming, answered: true };
    markConnected();
    announce();
    if (state.call) startPolling();
}

async function reject() {
    await loadIncomingCall();
    if (driver) await driver.reject();
    stopPolling();
    Object.assign(state, { call: null, incoming: null });
    state.status = state.webrtcReady || state.config?.enabled ? 'ready' : 'offline';
    announce();
}

async function hangup() {
    if (!state.call && !state.incoming) return;
    state.status = 'ending';
    try {
        await driver?.hangup();
    } catch {
        /* provider callback still decides the final state */
    }
    state.incoming = null;
    if (state.call) {
        state.finalizing = true;
        finalizeDeadline = Date.now() + FINALIZE_TIMEOUT_MS;
        stopClock();
        if (!pollTimer) startPolling();
        poll();
    } else {
        reset();
    }
}

function toggleMute() {
    if (!['connected', 'on_hold'].includes(state.status)) return;
    driver?.mute();
    state.muted = !state.muted;
}

function toggleHold() {
    if (!['connected', 'on_hold'].includes(state.status)) return;
    driver?.hold();
    state.held = !state.held;
    state.status = state.held ? 'on_hold' : 'connected';
}

/** Local development only: fake driver helpers. */
async function simulate(status) {
    if (state.config?.driver !== 'fake' || !state.call) return;
    if (!driver) driver = fakeDriver();
    await driver.outcome(status);
    poll();
}

async function simulateIncoming(number) {
    if (state.config?.driver !== 'fake') return;
    if (!driver) driver = fakeDriver();
    try {
        const { data } = await axios.post(route('telephony.fake.incoming'), { number });
        await onIncoming({ number: data.number, providerCallId: data.provider_call_id });
    } catch (e) {
        state.error = messageFrom(e, 'Could not simulate an incoming call.');
    }
}

function outcomeSaved() {
    state.outcomeCallId = null;
    reset();
}

function dismissOutcome() {
    state.outcomeCallId = null;
    if (!state.call?.is_open) reset();
}

const elapsed = computed(() => (state.connectedAt ? Math.max(0, Math.floor((state.now - state.connectedAt) / 1000)) : 0));
const timer = computed(() => {
    const s = elapsed.value;
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = String(s % 60).padStart(2, '0');
    return h ? `${h}:${String(m).padStart(2, '0')}:${sec}` : `${m}:${sec}`;
});
const statusLabel = computed(() => (state.finalizing ? 'Finalizing call…' : STATES[state.status] ?? state.status));
const busy = computed(() => !!state.call?.is_open || state.status === 'incoming' || state.finalizing);

export function useTelephony() {
    return {
        state: readonly(state),
        timer,
        statusLabel,
        busy,
        boot,
        startCall,
        answer,
        reject,
        hangup,
        toggleMute,
        toggleHold,
        simulate,
        simulateIncoming,
        outcomeSaved,
        dismissOutcome,
        clearError: () => (state.error = null),
        messages: { UNAVAILABLE, MIC_REQUIRED, OTHER_TAB },
    };
}
