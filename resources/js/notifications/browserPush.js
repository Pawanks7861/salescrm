/*
 * Browser side of Web Push: capability / permission state, service worker
 * registration and this browser's subscription. The permission prompt is
 * only ever triggered by an explicit user action (enableBrowserPush).
 */
import axios from 'axios';

export const SW_URL = '/sw.js';

export function isSupported() {
    return typeof window !== 'undefined' && window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

/** 'unsupported' | 'default' | 'granted' | 'denied' */
export function permissionState() {
    return isSupported() ? Notification.permission : 'unsupported';
}

function keyToBytes(base64Url) {
    const padding = '='.repeat((4 - (base64Url.length % 4)) % 4);
    const raw = atob((base64Url + padding).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(raw, (c) => c.charCodeAt(0));
}

async function registration() {
    return (await navigator.serviceWorker.getRegistration('/')) ?? navigator.serviceWorker.register(SW_URL, { scope: '/' });
}

export async function currentSubscription() {
    if (!isSupported()) return null;
    const reg = await navigator.serviceWorker.getRegistration('/');
    return reg ? reg.pushManager.getSubscription() : null;
}

const SYNC_KEY = 'crm.push.synced';

/** Short non-reversible fingerprint so the endpoint itself is never stored. */
export function fingerprint(value) {
    let hash = 0x811c9dc5;
    for (let i = 0; i < value.length; i++) {
        hash ^= value.charCodeAt(i);
        hash = Math.imul(hash, 0x01000193);
    }
    return (hash >>> 0).toString(36);
}

async function send(subscription, replaces = null) {
    const json = subscription.toJSON();
    const encodings = window.PushManager.supportedContentEncodings ?? ['aes128gcm'];
    await axios.post(route('push-subscriptions.store'), {
        endpoint: json.endpoint,
        keys: { p256dh: json.keys?.p256dh, auth: json.keys?.auth },
        content_encoding: encodings.includes('aes128gcm') ? 'aes128gcm' : 'aesgcm',
        replaces,
    });
    sessionStorage.setItem(SYNC_KEY, fingerprint(`${owner}|${json.endpoint}`));
}

let owner = '';

/** The signed-in user, so a new login on a shared browser re-registers it. */
export function setPushOwner(id) {
    owner = String(id ?? '');
}

function sameKey(subscription, publicKey) {
    const current = subscription.options?.applicationServerKey;
    if (!current) return true;
    const a = new Uint8Array(current);
    const b = keyToBytes(publicKey);
    return a.length === b.length && a.every((v, i) => v === b[i]);
}

/** This browser's subscription for `publicKey`, replacing one made with an old key. */
async function ensureSubscription(reg, publicKey) {
    let subscription = await reg.pushManager.getSubscription();
    let replaces = null;
    if (subscription && !sameKey(subscription, publicKey)) {
        replaces = subscription.endpoint;
        await subscription.unsubscribe().catch(() => {});
        subscription = null;
    }
    subscription ??= await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyToBytes(publicKey) });
    return { subscription, replaces };
}

/** Asks for permission (user gesture required), subscribes and registers this browser. */
export async function enableBrowserPush(publicKey) {
    if (!isSupported()) return 'unsupported';
    const permission = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
    if (permission !== 'granted') return permission;

    const reg = await registration();
    await navigator.serviceWorker.ready;
    const { subscription, replaces } = await ensureSubscription(reg, publicKey);
    await send(subscription, replaces);
    return 'granted';
}

/** Removes this browser's subscription (server first, then the browser). */
export async function disableBrowserPush() {
    const subscription = await currentSubscription();
    if (!subscription) return;
    try {
        await axios.delete(route('push-subscriptions.destroy'), { data: { endpoint: subscription.endpoint } });
    } finally {
        await subscription.unsubscribe().catch(() => {});
        sessionStorage.removeItem(SYNC_KEY);
    }
}

/**
 * Keeps the server in step with this browser's subscription: registers it
 * when it is new or its endpoint changed (browser rotation, new login on a
 * shared browser, VAPID key change). Never prompts.
 */
export async function syncBrowserPush(publicKey, { force = false } = {}) {
    if (!isSupported() || Notification.permission !== 'granted' || !publicKey) return;
    const reg = await registration();
    const { subscription, replaces } = await ensureSubscription(reg, publicKey);
    if (!force && !replaces && sessionStorage.getItem(SYNC_KEY) === fingerprint(`${owner}|${subscription.endpoint}`)) return;
    await send(subscription, replaces);
}
