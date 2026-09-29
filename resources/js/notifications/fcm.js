/*
 * Registers this browser with Firebase Cloud Messaging on its own service
 * worker scope, so the existing Web Push subscription on /sw.js is left
 * alone. The permission prompt only happens from registerFcm (a click).
 * The token is posted to the server and is not written to storage.
 */
import axios from 'axios';

const SCOPE = '/firebase-cloud-messaging-push-scope';
const SYNC_KEY = 'crm.fcm.sync';

async function fingerprint(value) {
    const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(value));
    return [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

async function mint(config) {
    const { initializeApp, getApps } = await import('firebase/app');
    const { getMessaging, getToken } = await import('firebase/messaging');
    const app = getApps()[0] ?? initializeApp({
        apiKey: config.apiKey,
        authDomain: config.authDomain,
        projectId: config.projectId,
        messagingSenderId: config.messagingSenderId,
        appId: config.appId,
    });
    const registration = await navigator.serviceWorker.register('/firebase-messaging-sw.js', { scope: SCOPE });
    return getToken(getMessaging(app), { vapidKey: config.vapidKey, serviceWorkerRegistration: registration });
}

/** Asks for permission when needed, then stores this browser's FCM token. */
export async function registerFcm(config) {
    if (!config?.apiKey || !config?.vapidKey || !('serviceWorker' in navigator) || !('Notification' in window)) return 'unsupported';
    const permission = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
    if (permission !== 'granted') return permission;

    const token = await mint(config);
    if (!token) throw new Error('FCM token missing');
    await axios.post(route('fcm-tokens.store'), { token });
    sessionStorage.setItem(SYNC_KEY, await fingerprint(token));
    return 'granted';
}

/** Drops this browser's token. Never prompts. */
export async function unregisterFcm(config) {
    try {
        if (config?.apiKey && typeof Notification !== 'undefined' && Notification.permission === 'granted') {
            const token = await mint(config);
            if (token) await axios.delete(route('fcm-tokens.destroy'), { data: { token } });
        }
    } finally {
        sessionStorage.removeItem(SYNC_KEY);
    }
}

/** Re-registers after login or a token rotation. Never prompts. */
export async function syncFcm(config) {
    if (!config?.apiKey || !config?.vapidKey || typeof Notification === 'undefined' || Notification.permission !== 'granted') return;
    const token = await mint(config);
    if (!token) return;
    const mark = await fingerprint(token);
    if (sessionStorage.getItem(SYNC_KEY) === mark) return;
    await axios.post(route('fcm-tokens.store'), { token });
    sessionStorage.setItem(SYNC_KEY, mark);
}
