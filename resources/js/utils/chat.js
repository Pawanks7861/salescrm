/*
 * Pure chat helpers (unit-tested with Vitest). Times are formatted in the CRM
 * timezone passed in by the caller; nothing here touches the DOM or network.
 */

export const ONLINE_WINDOW_MS = 2 * 60 * 1000;
export const DELETED_TEXT = 'This message was deleted.';

/** Online is derived from last_seen_at; the server's flag wins when present. */
export function isOnline(user, now = Date.now()) {
    if (!user || user.active === false) return false;
    if (typeof user.online === 'boolean') return user.online;
    const seen = Date.parse(user.last_seen_at);
    return Number.isFinite(seen) && now - seen <= ONLINE_WINDOW_MS;
}

function dayKey(value, tz) {
    return new Intl.DateTimeFormat('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit', timeZone: tz }).format(new Date(value));
}

export function formatClock(value, tz) {
    if (!value) return '';
    return new Intl.DateTimeFormat('en-IN', { hour: 'numeric', minute: '2-digit', timeZone: tz }).format(new Date(value));
}

/** "Online" · "Last seen just now / 5 min ago / today at 3:10 pm / yesterday at … / 12 Sep" · "Offline". */
export function presenceLabel(user, { now = Date.now(), tz } = {}) {
    if (!user) return '';
    if (user.active === false) return 'Inactive user';
    if (isOnline(user, now)) return 'Online';
    const seen = Date.parse(user.last_seen_at);
    if (!Number.isFinite(seen)) return 'Offline';

    const minutes = Math.floor((now - seen) / 60000);
    if (minutes < 1) return 'Last seen just now';
    if (minutes < 60) return `Last seen ${minutes} min ago`;

    const day = dayKey(seen, tz);
    if (day === dayKey(now, tz)) return `Last seen today at ${formatClock(seen, tz)}`;
    if (day === dayKey(now - 86400000, tz)) return `Last seen yesterday at ${formatClock(seen, tz)}`;
    return `Last seen ${new Intl.DateTimeFormat('en-IN', { day: 'numeric', month: 'short', timeZone: tz }).format(new Date(seen))}`;
}

/** Badge text for an unread count: '' for none, '99+' beyond 99. */
export function unreadBadge(count) {
    const n = Number(count) || 0;
    if (n <= 0) return '';
    return n > 99 ? '99+' : String(n);
}

/** 'read' | 'sent' for the viewer's own messages; null for received ones. */
export function readStatus(message, otherLastReadId) {
    if (!message?.mine || message.deleted) return null;
    return Number(otherLastReadId) >= Number(message.id) ? 'read' : 'sent';
}

/** Sidebar preview line for a conversation's last message. */
export function previewLine(last) {
    if (!last) return 'No messages yet';
    if (last.deleted) return DELETED_TEXT;
    const text = last.text && last.text !== 'Attachment' ? last.text : last.attachment ? '📎 Attachment' : last.text || '';
    return last.mine ? `You: ${text}` : text;
}

/** Merges incoming messages into the list by id (newer copy wins), oldest first. */
export function mergeMessages(existing, incoming) {
    const byId = new Map((existing ?? []).map((m) => [m.id, m]));
    for (const m of incoming ?? []) byId.set(m.id, { ...byId.get(m.id), ...m });
    return [...byId.values()].sort((a, b) => a.id - b.id);
}

/** Replaces only messages the client already holds (edits / deletions). */
export function applyChanges(existing, changed) {
    if (!changed?.length) return existing;
    const updates = new Map(changed.map((m) => [m.id, m]));
    return existing.map((m) => {
        const next = updates.has(m.id) ? { ...m, ...updates.get(m.id) } : m;
        const quoted = next.reply_to && updates.get(next.reply_to.id);
        return quoted ? { ...next, reply_to: { ...next.reply_to, preview: replySnippet(quoted) } } : next;
    });
}

export function lastMessageId(messages) {
    return messages?.length ? messages[messages.length - 1].id : 0;
}

/** Inserts day separators: [{ type: 'day', key, label }, { type: 'message', message }, …]. */
export function withDaySeparators(messages, { now = Date.now(), tz } = {}) {
    const out = [];
    let current = null;
    const today = dayKey(now, tz);
    const yesterday = dayKey(now - 86400000, tz);

    for (const message of messages ?? []) {
        const key = dayKey(message.created_at, tz);
        if (key !== current) {
            current = key;
            const label =
                key === today
                    ? 'Today'
                    : key === yesterday
                      ? 'Yesterday'
                      : new Intl.DateTimeFormat('en-IN', { day: 'numeric', month: 'short', year: 'numeric', timeZone: tz }).format(new Date(message.created_at));
            out.push({ type: 'day', key, label });
        }
        out.push({ type: 'message', key: `m${message.id}`, message });
    }
    return out;
}

function extensionOf(name) {
    const match = /\.([^.]+)$/.exec(String(name ?? ''));
    return match ? match[1].toLowerCase() : '';
}

/** Client-side mirror of the server rules, for instant feedback only (the server re-validates). */
export function validateFiles(files, { maxKb, maxFiles, extensions }) {
    const list = [...(files ?? [])];
    const errors = [];
    if (maxFiles && list.length > maxFiles) errors.push(`You can attach up to ${maxFiles} files.`);
    for (const file of list) {
        if (!extensions.includes(extensionOf(file.name))) errors.push(`${file.name}: this file type is not allowed.`);
        else if (maxKb && file.size > maxKb * 1024) errors.push(`${file.name}: larger than ${Math.round(maxKb / 1024)} MB.`);
    }
    return errors;
}

export function canSend(text, files) {
    return Boolean(String(text ?? '').trim()) || (files?.length ?? 0) > 0;
}

/** Reply preview for the composer and the quoted block. */
export function replySnippet(message, limit = 80) {
    if (!message) return '';
    if (message.deleted) return DELETED_TEXT;
    const body = String(message.body ?? message.preview ?? '').replace(/\s+/g, ' ').trim();
    if (!body) return message.attachments?.length ? '📎 Attachment' : 'Attachment';
    return body.length > limit ? `${body.slice(0, limit - 1)}…` : body;
}

/** History status for a priority broadcast. */
export function broadcastState(broadcast, now = Date.now()) {
    if (!broadcast) return null;
    const expires = Date.parse(broadcast.expires_at);
    const expired = broadcast.expired === true || (Number.isFinite(expires) && expires <= now);
    return expired ? { key: 'expired', label: 'Expired', color: 'slate' } : { key: 'active', label: 'Active', color: 'green' };
}

/** "12 / 40" style ratio with a percentage, safe for zero recipients. */
export function ratio(part, total) {
    const t = Number(total) || 0;
    const p = Number(part) || 0;
    return { text: `${p} / ${t}`, percent: t ? Math.round((p / t) * 100) : 0 };
}
