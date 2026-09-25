/*
 * Sales CRM service worker — Web Push only (no offline caching). Works with
 * no CRM tab open: every decision below falls back to a normal OS
 * notification when no tab answers.
 *
 * push:  a focused CRM tab gets the event and must confirm it showed its
 *        toast (and played the chime); otherwise an OS notification is shown.
 *        When no tab is focused, exactly one tab is asked to play the chime;
 *        the OS notification is silent only if that tab confirms it played,
 *        so a notification is never silent without a sound. Other tabs are
 *        told passively (unread count / de-duplication only).
 * click: focuses an existing CRM tab and navigates it (in-app, or by
 *        WindowClient.navigate), or opens one. Only same-origin URLs are
 *        opened; the target route re-authorizes.
 * pushsubscriptionchange: re-subscribes and re-registers the new endpoint.
 */
const ACK_FOREGROUND_MS = 1500;
const ACK_SOUND_MS = 800;
const ACK_OPEN_MS = 1000;

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

const sameOrigin = (url) => {
    try {
        const target = new URL(url || '/notifications', self.location.origin);
        return target.origin === self.location.origin ? target : null;
    } catch {
        return null;
    }
};

const crmWindows = async () =>
    (await self.clients.matchAll({ type: 'window', includeUncontrolled: true })).filter((c) => sameOrigin(c.url));

/** Posts a message to one tab and resolves with its reply, or null on timeout. */
const ask = (client, message, timeoutMs) =>
    new Promise((resolve) => {
        const channel = new MessageChannel();
        const timer = setTimeout(() => resolve(null), timeoutMs);
        channel.port1.onmessage = (e) => {
            clearTimeout(timer);
            resolve(e.data || {});
        };
        try {
            client.postMessage(message, [channel.port2]);
        } catch {
            clearTimeout(timer);
            resolve(null);
        }
    });

const tell = (clients, message) =>
    clients.forEach((c) => {
        try {
            c.postMessage(message);
        } catch {
            /* tab went away */
        }
    });

self.addEventListener('push', (event) => {
    let data = null;
    try {
        data = event.data ? event.data.json() : null;
    } catch {
        data = null;
    }
    if (!data || !data.id || !data.title) return;

    event.waitUntil(
        (async () => {
            const clients = await crmWindows();
            const focused = clients.find((c) => c.focused && c.visibilityState === 'visible');

            if (focused) {
                const reply = await ask(focused, { type: 'crm:push', mode: 'foreground', payload: data }, ACK_FOREGROUND_MS);
                if (reply && reply.handled) {
                    tell(clients.filter((c) => c !== focused), { type: 'crm:push', mode: 'passive', payload: data });
                    return;
                }
            }

            const candidates = clients.filter((c) => c !== focused);
            const player = candidates.find((c) => c.visibilityState === 'visible') || candidates[0] || null;
            let played = false;
            if (player) {
                const reply = await ask(player, { type: 'crm:push', mode: 'background', payload: data }, ACK_SOUND_MS);
                played = Boolean(reply && reply.played);
            }
            tell(candidates.filter((c) => c !== player), { type: 'crm:push', mode: 'passive', payload: data });

            const target = sameOrigin(data.url);
            await self.registration.showNotification(data.title, {
                body: data.body || '',
                icon: data.icon || undefined,
                tag: data.id,
                renotify: false,
                timestamp: data.ts || Date.now(),
                silent: played,
                data: { id: data.id, event: data.event || null, url: target ? target.pathname + target.search : '/notifications' },
            });
        })(),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = sameOrigin(event.notification.data && event.notification.data.url);
    if (!target) return;
    const url = target.pathname + target.search;

    event.waitUntil(
        (async () => {
            const clients = await crmWindows();
            const client = clients.find((c) => c.focused) || clients.find((c) => c.visibilityState === 'visible') || clients[0];
            if (!client) {
                await self.clients.openWindow(target.href);
                return;
            }

            // focus() and openWindow() share one user-activation token, so an
            // existing tab is always reused rather than opening a duplicate.
            try {
                await client.focus();
            } catch {
                /* already focused or not focusable */
            }
            const reply = await ask(client, { type: 'crm:open', url }, ACK_OPEN_MS);
            if (reply && reply.ok) return;
            if (typeof client.navigate === 'function') {
                try {
                    await client.navigate(target.href);
                } catch {
                    /* uncontrolled tab: it stays focused on its current page */
                }
            }
        })(),
    );
});

async function registerSubscription(subscription, replaces) {
    if (!self.cookieStore) return false;
    try {
        const xsrf = await self.cookieStore.get('XSRF-TOKEN');
        if (!xsrf) return false;
        const json = subscription.toJSON();
        const response = await fetch('/push-subscriptions', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent(xsrf.value),
            },
            body: JSON.stringify({ endpoint: json.endpoint, keys: json.keys, content_encoding: 'aes128gcm', replaces: replaces || null }),
        });
        return response.ok;
    } catch {
        return false;
    }
}

self.addEventListener('pushsubscriptionchange', (event) => {
    event.waitUntil(
        (async () => {
            const old = event.oldSubscription || null;
            let subscription = event.newSubscription || null;
            try {
                if (!subscription) {
                    const key = old && old.options ? old.options.applicationServerKey : null;
                    if (key) subscription = await self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
                }
            } catch {
                subscription = null;
            }
            const registered = subscription ? await registerSubscription(subscription, old && old.endpoint) : false;
            // Open tabs re-register on their side if the worker could not.
            if (!registered) tell(await crmWindows(), { type: 'crm:resubscribe' });
        })(),
    );
});
