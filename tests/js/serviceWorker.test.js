import { readFileSync } from 'node:fs';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const SOURCE = readFileSync(new URL('../../public/sw.js', import.meta.url), 'utf8');
const ORIGIN = 'https://crm.test';

/** MessageChannel whose port2 replies asynchronously to port1 (like the real one). */
class FakeChannel {
    constructor() {
        this.port1 = { onmessage: null };
        this.port2 = { postMessage: (data) => setTimeout(() => this.port1.onmessage?.({ data }), 0) };
    }
}

/** A window client; `answer(msg)` returns the reply (or undefined for none). */
function client({ focused = false, visible = true, url = `${ORIGIN}/dashboard`, answer = () => undefined } = {}) {
    const c = {
        url,
        focused,
        visibilityState: visible ? 'visible' : 'hidden',
        received: [],
        focus: vi.fn(async () => c),
        navigate: vi.fn(async () => c),
        postMessage(msg, ports) {
            c.received.push(msg);
            const reply = answer(msg);
            if (reply !== undefined && ports?.[0]) ports[0].postMessage(reply);
        },
    };
    return c;
}

function boot(clients = [], { fetch = vi.fn(async () => ({ ok: true })), cookie = 'tok%3D' } = {}) {
    const listeners = {};
    const self = {
        location: { origin: ORIGIN },
        addEventListener: (type, fn) => (listeners[type] = fn),
        skipWaiting: vi.fn(),
        cookieStore: { get: vi.fn(async () => (cookie ? { value: cookie } : null)) },
        clients: { matchAll: vi.fn(async () => clients), openWindow: vi.fn(async () => null), claim: vi.fn() },
        registration: {
            showNotification: vi.fn(async () => {}),
            pushManager: { subscribe: vi.fn(async () => ({ toJSON: () => ({ endpoint: 'https://fcm.googleapis.com/new', keys: { p256dh: 'p', auth: 'a' } }) })) },
        },
    };
    new Function('self', 'MessageChannel', 'fetch', SOURCE)(self, FakeChannel, fetch);

    const fire = async (type, init) => {
        let pending = Promise.resolve();
        listeners[type]({ ...init, waitUntil: (p) => (pending = p) });
        await vi.runAllTimersAsync();
        await pending;
    };
    return { self, fetch, fire };
}

const payload = (over = {}) => ({ id: 'n1', event: 'NEW_LEAD_ASSIGNED', title: 'New Lead Assigned', body: 'A new lead has been assigned to you.', url: '/notifications/n1/open', ts: 1, ...over });
const push = (data) => ({ data: { json: () => data } });

describe('service worker push', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('shows an OS notification with the system sound when no CRM tab is open', async () => {
        const { self, fire } = boot([]);
        await fire('push', push(payload()));
        expect(self.registration.showNotification).toHaveBeenCalledExactlyOnceWith('New Lead Assigned', expect.objectContaining({
            body: 'A new lead has been assigned to you.',
            tag: 'n1',
            renotify: false,
            silent: false,
            data: { id: 'n1', event: 'NEW_LEAD_ASSIGNED', url: '/notifications/n1/open' },
        }));
    });

    it('lets a focused tab handle it (toast + chime) without an OS popup', async () => {
        const focused = client({ focused: true, answer: (m) => (m.mode === 'foreground' ? { handled: true, played: true } : undefined) });
        const other = client({ visible: false });
        const { self, fire } = boot([focused, other]);
        await fire('push', push(payload()));
        expect(self.registration.showNotification).not.toHaveBeenCalled();
        expect(other.received).toEqual([expect.objectContaining({ type: 'crm:push', mode: 'passive' })]);
    });

    it('falls back to an OS notification when the focused tab does not confirm', async () => {
        const focused = client({ focused: true });
        const { self, fire } = boot([focused]);
        await fire('push', push(payload()));
        expect(self.registration.showNotification).toHaveBeenCalledOnce();
        expect(self.registration.showNotification.mock.calls[0][1].silent).toBe(false);
    });

    it('asks exactly one background tab to play and makes the popup silent only if it did', async () => {
        const player = client({ visible: true, answer: (m) => (m.mode === 'background' ? { played: true } : undefined) });
        const hidden = client({ visible: false });
        const { self, fire } = boot([hidden, player]);
        await fire('push', push(payload({ event: 'FOLLOWUP_REMINDER' })));
        expect(player.received.map((m) => m.mode)).toEqual(['background']);
        expect(hidden.received.map((m) => m.mode)).toEqual(['passive']);
        expect(self.registration.showNotification.mock.calls[0][1].silent).toBe(true);
    });

    it('keeps the system sound when the background tab could not play', async () => {
        const player = client({ visible: false, answer: () => ({ played: false }) });
        const { self, fire } = boot([player]);
        await fire('push', push(payload()));
        expect(self.registration.showNotification.mock.calls[0][1].silent).toBe(false);
    });

    it('ignores invalid payloads and never links off-site', async () => {
        const { self, fire } = boot([]);
        await fire('push', { data: { json: () => { throw new Error('bad'); } } });
        await fire('push', push({ title: 'no id' }));
        expect(self.registration.showNotification).not.toHaveBeenCalled();

        await fire('push', push(payload({ url: 'https://evil.test/x' })));
        expect(self.registration.showNotification.mock.calls[0][1].data.url).toBe('/notifications');
    });

    it('keeps urgent priority messages on screen; chat messages behave like other notifications', async () => {
        const { self, fire } = boot([]);
        await fire('push', push(payload({ id: 'p1', event: 'PRIORITY_BROADCAST', title: 'Urgent: Office closed', url: '/notifications/p1/open' })));
        await fire('push', push(payload({ id: 'c1', event: 'CHAT_MESSAGE', title: 'Rahul sent you a message', url: '/notifications/c1/open' })));

        const [priority, chat] = self.registration.showNotification.mock.calls;
        expect(priority[1]).toMatchObject({ requireInteraction: true, data: { event: 'PRIORITY_BROADCAST', url: '/notifications/p1/open' } });
        expect(chat[1]).toMatchObject({ requireInteraction: false, data: { event: 'CHAT_MESSAGE' } });
    });
});

describe('service worker click', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    const click = (url = '/notifications/n1/open') => ({ notification: { close: vi.fn(), data: { url } } });

    it('focuses the existing tab and navigates it in-app', async () => {
        const tab = client({ visible: false, answer: (m) => (m.type === 'crm:open' ? { ok: true } : undefined) });
        const { self, fire } = boot([tab]);
        await fire('notificationclick', click());
        expect(tab.focus).toHaveBeenCalledOnce();
        expect(tab.received).toEqual([{ type: 'crm:open', url: '/notifications/n1/open' }]);
        expect(tab.navigate).not.toHaveBeenCalled();
        expect(self.clients.openWindow).not.toHaveBeenCalled();
    });

    it('navigates the tab itself when the page does not answer', async () => {
        const tab = client();
        const { self, fire } = boot([tab]);
        await fire('notificationclick', click());
        expect(tab.navigate).toHaveBeenCalledExactlyOnceWith(`${ORIGIN}/notifications/n1/open`);
        expect(self.clients.openWindow).not.toHaveBeenCalled();
    });

    it('opens a new window when no CRM tab exists', async () => {
        const { self, fire } = boot([client({ url: 'https://other.test/' })]);
        await fire('notificationclick', click());
        expect(self.clients.openWindow).toHaveBeenCalledExactlyOnceWith(`${ORIGIN}/notifications/n1/open`);
    });
});

describe('service worker subscription change', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('re-subscribes and registers the new endpoint, replacing the old one', async () => {
        const { self, fetch, fire } = boot([]);
        await fire('pushsubscriptionchange', { oldSubscription: { endpoint: 'https://fcm.googleapis.com/old', options: { applicationServerKey: new Uint8Array([4]) } } });
        expect(self.registration.pushManager.subscribe).toHaveBeenCalledOnce();
        const [url, init] = fetch.mock.calls[0];
        expect(url).toBe('/push-subscriptions');
        expect(init.headers['X-XSRF-TOKEN']).toBe('tok=');
        expect(JSON.parse(init.body)).toMatchObject({ endpoint: 'https://fcm.googleapis.com/new', replaces: 'https://fcm.googleapis.com/old' });
    });

    it('asks open tabs to re-register when it cannot', async () => {
        const tab = client();
        const { fire } = boot([tab], { cookie: null });
        await fire('pushsubscriptionchange', { oldSubscription: null, newSubscription: { toJSON: () => ({}) } });
        expect(tab.received).toEqual([{ type: 'crm:resubscribe' }]);
    });
});
