/*
 * In-app delivery for open CRM tabs, on top of the notification centre:
 *  - Web Push messages relayed by the service worker (public/sw.js), which
 *    waits for this tab's reply: "handled" (toast + chime shown, no OS popup
 *    needed) or "played" (chime played, so the OS popup can be silent);
 *  - a light poll of notifications.recent while the tab is visible, for users
 *    without browser push. Only the focused tab announces from the poll.
 * Each notification id toasts / chimes once; ids are kept in memory for a
 * few minutes and shared with other tabs over a BroadcastChannel.
 */
import { useToast } from '@/Composables/useToast';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { ref } from 'vue';
import { setPushOwner, syncBrowserPush } from './browserPush';
import { syncFcm } from './fcm';
import { createAnnouncer, createDeduper, isFresh } from './rules';
import { play, unlockOnFirstGesture } from './sound';

const POLL_MS = 45_000;

/** Latest unread count seen by the notifier (null = use the page prop). */
export const liveUnread = ref(null);

let started = false;
let getProps = () => ({});
let deduper = createDeduper();
let announce = async () => ({ handled: false, played: false });
let channel = null;
let baselined = false;
let timer = null;

const pushActive = () => {
    const p = getProps().push ?? {};
    return Boolean(p.available && p.browser && typeof Notification !== 'undefined' && Notification.permission === 'granted');
};

const bumpUnread = () => {
    liveUnread.value = (liveUnread.value ?? getProps().notifications?.unread ?? 0) + 1;
};

async function claimAndAnnounce(item) {
    const result = await announce(item);
    if (!result.duplicate) channel?.postMessage({ type: 'claimed', id: item.id });
    return result;
}

async function poll() {
    if (document.visibilityState !== 'visible') return;
    try {
        const { data } = await axios.get(route('notifications.recent'));
        liveUnread.value = data.unread;
        const items = data.data ?? [];
        if (!baselined) {
            deduper.seed(items.map((n) => n.id));
            baselined = true;
            return;
        }
        const fresh = items.filter((n) => !deduper.has(n.id) && isFresh(n));
        // Pushes reach the focused tab directly; the poll is the fallback and
        // only announces in the focused tab (or any visible tab without push).
        if (!document.hasFocus() && pushActive()) {
            deduper.seed(fresh.map((n) => n.id));
            return;
        }
        for (const n of fresh) await claimAndAnnounce({ id: n.id, event: n.event, message: n.message, toastIt: true });
    } catch {
        /* offline or session expired: the next navigation handles it */
    }
}

async function onWorkerMessage(e) {
    const msg = e.data || {};
    const reply = (data) => e.ports?.[0]?.postMessage(data);

    if (msg.type === 'crm:open') {
        const ok = typeof msg.url === 'string' && msg.url.startsWith('/') && !msg.url.startsWith('//');
        if (ok) router.visit(msg.url);
        reply({ ok });
    } else if (msg.type === 'crm:resubscribe') {
        const p = getProps().push ?? {};
        if (p.available && p.browser && p.public_key) syncBrowserPush(p.public_key, { force: true }).catch(() => {});
        if (p.fcm && p.browser) syncFcm(p.fcm).catch(() => {});
    } else if (msg.type === 'crm:push' && msg.payload?.id) {
        const p = msg.payload;
        const known = deduper.has(p.id);
        if (!known) bumpUnread();

        if (msg.mode === 'passive') {
            deduper.seed([p.id]);
            reply({ handled: false, played: false });
            return;
        }
        const foreground = msg.mode === 'foreground';
        const result = await claimAndAnnounce({ id: p.id, event: p.event, message: `${p.title}: ${p.body}`, toastIt: foreground });
        // Already announced here (e.g. by the poll): nothing more to show or play.
        reply(result.duplicate ? { handled: true, played: true } : { handled: foreground && result.handled, played: result.played });
    }
}

export function startNotifier(propsGetter) {
    getProps = propsGetter;
    if (started || typeof window === 'undefined') return;
    started = true;

    announce = createAnnouncer({
        deduper,
        prefs: () => getProps().push ?? {},
        play: (kind) => play(kind),
        toast: (message) => useToast().info(message),
    });
    unlockOnFirstGesture();

    if (typeof BroadcastChannel !== 'undefined') {
        channel = new BroadcastChannel('crm-notifications');
        channel.onmessage = (e) => e.data?.type === 'claimed' && e.data.id && deduper.seed([e.data.id]);
    }

    navigator.serviceWorker?.addEventListener('message', onWorkerMessage);
    navigator.serviceWorker?.startMessages?.();
    document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && poll());
    router.on('navigate', () => (liveUnread.value = null));

    poll();
    timer = setInterval(poll, POLL_MS);

    setPushOwner(getProps().auth?.user?.id);
    const p = getProps().push ?? {};
    if (p.available && p.browser && p.public_key) syncBrowserPush(p.public_key).catch(() => {});
    if (p.fcm && p.browser) syncFcm(p.fcm).catch(() => {});
}

export function stopNotifier() {
    clearInterval(timer);
}
