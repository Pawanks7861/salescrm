/*
 * Pure notification rules (unit-tested with Vitest): which events matter
 * (new lead assigned, follow-up reminder), which sound each plays, and a
 * short-lived in-memory de-duplicator keyed by notification id, so the same
 * event arriving by poll and by push chimes and toasts only once. Nothing is
 * persisted; tabs share claimed ids through a BroadcastChannel (notifier.js).
 */

export const EVENTS = Object.freeze({
    NEW_LEAD_ASSIGNED: 'NEW_LEAD_ASSIGNED',
    FOLLOWUP_REMINDER: 'FOLLOWUP_REMINDER',
});

/** In-app (database) event names mapped to the push event they represent. */
const ALIASES = Object.freeze({
    lead_assigned: EVENTS.NEW_LEAD_ASSIGNED,
    facebook_lead_assigned: EVENTS.NEW_LEAD_ASSIGNED,
    followup_reminder: EVENTS.FOLLOWUP_REMINDER,
});

const SOUNDS = Object.freeze({
    [EVENTS.NEW_LEAD_ASSIGNED]: 'lead',
    [EVENTS.FOLLOWUP_REMINDER]: 'reminder',
});

/** NEW_LEAD_ASSIGNED | FOLLOWUP_REMINDER | null (everything else is silent). */
export function normalizeEvent(event) {
    if (typeof event !== 'string') return null;
    if (Object.prototype.hasOwnProperty.call(SOUNDS, event)) return event;
    return Object.prototype.hasOwnProperty.call(ALIASES, event) ? ALIASES[event] : null;
}

export function soundKindFor(event) {
    const normalized = normalizeEvent(event);
    return normalized ? SOUNDS[normalized] : null;
}

/** Sound requires the admin switch and the user's own preference. */
export function shouldPlaySound(event, prefs) {
    return Boolean(soundKindFor(event) && prefs?.sound_allowed && prefs?.sound);
}

/** A notification is "new" for the in-app notifier if unread and recent. */
export function isFresh(item, now = Date.now(), maxAgeMs = 10 * 60 * 1000) {
    if (!item || item.read || item.stale) return false;
    const created = Date.parse(item.created_at);
    return Number.isFinite(created) && now - created <= maxAgeMs;
}

/**
 * Handles an incoming notification once per id: important events toast
 * (when asked) and chime when sound is allowed; others do nothing.
 * Resolves { handled, played }.
 *
 * @param {{deduper: ReturnType<typeof createDeduper>, prefs: () => object, play: (kind: string) => boolean|Promise<boolean>, toast: (message: string) => void}} deps
 */
export function createAnnouncer({ deduper, prefs, play, toast }) {
    return async function announce({ id, event, message, toastIt = true, sound = true }) {
        if (!deduper.claim(id)) return { handled: false, played: false, duplicate: true };
        const kind = soundKindFor(event);
        if (!kind) return { handled: false, played: false };
        if (toastIt) toast(message);
        let played = false;
        if (sound && shouldPlaySound(event, prefs())) {
            try {
                played = Boolean(await play(kind));
            } catch {
                played = false;
            }
        }
        return { handled: true, played };
    };
}

/** In-memory set of recently handled ids with expiry (no storage). */
export function createDeduper({ ttlMs = 10 * 60 * 1000, max = 200, now = () => Date.now() } = {}) {
    const seen = new Map();
    const prune = () => {
        const t = now();
        for (const [id, expires] of seen) if (expires <= t) seen.delete(id);
        while (seen.size > max) seen.delete(seen.keys().next().value);
    };
    const mark = (id) => seen.set(id, now() + ttlMs);

    return {
        has(id) {
            prune();
            return seen.has(id);
        },
        /** Marks ids as seen without side effects (baseline, other tabs). */
        seed(ids) {
            ids.filter(Boolean).forEach(mark);
            prune();
        },
        /** True exactly once per id within the TTL. */
        claim(id) {
            if (!id) return false;
            prune();
            if (seen.has(id)) return false;
            mark(id);
            prune();
            return true;
        },
        size: () => seen.size,
    };
}
