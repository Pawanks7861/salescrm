/*
 * Presence heartbeat for every open CRM tab. Each beat records the user as
 * active (the server throttles the write) and returns the user's own chat
 * unread total and active priority messages, so the sidebar badge and the
 * priority banner stay current without WebSockets. A chat / priority push
 * relayed by the notifier triggers an immediate beat.
 */
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { ref } from 'vue';

export const HEARTBEAT_MS = 30_000;

const IMMEDIATE_EVENTS = ['CHAT_MESSAGE', 'chat_message', 'PRIORITY_BROADCAST', 'priority_broadcast'];

/** null = use the page props. */
export const liveChatUnread = ref(null);
export const livePriority = ref(null);

let started = false;
let inflight = false;

export async function beat() {
    if (inflight) return;
    inflight = true;
    try {
        const { data } = await axios.post(route('presence.heartbeat'));
        liveChatUnread.value = Number(data.chat_unread) || 0;
        livePriority.value = Array.isArray(data.priority) ? data.priority : [];
    } catch {
        /* offline or session expired: the next navigation refreshes everything */
    } finally {
        inflight = false;
    }
}

export function setChatUnread(count) {
    liveChatUnread.value = Number(count) || 0;
}

export function dismissPriority(id) {
    const list = livePriority.value ?? null;
    if (list) livePriority.value = list.filter((b) => b.id !== id);
}

export function startLive() {
    if (started || typeof window === 'undefined') return;
    started = true;

    beat();
    setInterval(beat, HEARTBEAT_MS);
    document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && beat());
    window.addEventListener('crm:notification', (e) => IMMEDIATE_EVENTS.includes(e.detail?.event) && beat());
    router.on('navigate', () => {
        liveChatUnread.value = null;
        livePriority.value = null;
    });
}
