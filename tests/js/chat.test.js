import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import {
    applyChanges,
    broadcastState,
    canSend,
    DELETED_TEXT,
    isOnline,
    lastMessageId,
    mergeMessages,
    presenceLabel,
    previewLine,
    ratio,
    readStatus,
    replySnippet,
    unreadBadge,
    validateFiles,
    withDaySeparators,
} from '../../resources/js/utils/chat.js';
import { EVENTS, normalizeEvent, soundKindFor } from '../../resources/js/notifications/rules.js';
import { CHIMES, SOUND_TYPES, chimeDuration } from '../../resources/js/notifications/sound.js';

const TZ = 'Asia/Kolkata';
const NOW = Date.parse('2026-10-01T10:00:00Z'); // 15:30 IST

describe('presence', () => {
    it('trusts the server online flag and falls back to the 2-minute window', () => {
        expect(isOnline({ online: true })).toBe(true);
        expect(isOnline({ online: false, last_seen_at: new Date(NOW).toISOString() }, NOW)).toBe(false);
        expect(isOnline({ last_seen_at: new Date(NOW - 90_000).toISOString() }, NOW)).toBe(true);
        expect(isOnline({ last_seen_at: new Date(NOW - 150_000).toISOString() }, NOW)).toBe(false);
        expect(isOnline({ active: false, online: true })).toBe(false);
        expect(isOnline(null)).toBe(false);
    });

    it('formats last seen in the CRM timezone', () => {
        const at = (ms) => ({ online: false, last_seen_at: new Date(NOW - ms).toISOString() });
        expect(presenceLabel({ online: true }, { now: NOW, tz: TZ })).toBe('Online');
        expect(presenceLabel(at(30_000), { now: NOW, tz: TZ })).toBe('Last seen just now');
        expect(presenceLabel(at(5 * 60_000), { now: NOW, tz: TZ })).toBe('Last seen 5 min ago');
        expect(presenceLabel(at(3 * 3600_000), { now: NOW, tz: TZ })).toMatch(/^Last seen today at 12:30\s?pm$/i);
        expect(presenceLabel(at(20 * 3600_000), { now: NOW, tz: TZ })).toMatch(/^Last seen yesterday at/);
        expect(presenceLabel(at(5 * 86400_000), { now: NOW, tz: TZ })).toBe('Last seen 26 Sept');
        expect(presenceLabel({ online: false, last_seen_at: null }, { now: NOW })).toBe('Offline');
        expect(presenceLabel({ active: false })).toBe('Inactive user');
    });
});

describe('unread and read status', () => {
    it('renders unread badges', () => {
        expect(unreadBadge(0)).toBe('');
        expect(unreadBadge(undefined)).toBe('');
        expect(unreadBadge(7)).toBe('7');
        expect(unreadBadge(150)).toBe('99+');
    });

    it('shows sent / read only on my own live messages', () => {
        expect(readStatus({ id: 5, mine: true }, 4)).toBe('sent');
        expect(readStatus({ id: 5, mine: true }, 5)).toBe('read');
        expect(readStatus({ id: 5, mine: true }, null)).toBe('sent');
        expect(readStatus({ id: 5, mine: false }, 9)).toBeNull();
        expect(readStatus({ id: 5, mine: true, deleted: true }, 9)).toBeNull();
    });
});

describe('previews and replies', () => {
    it('builds the conversation preview line', () => {
        expect(previewLine(null)).toBe('No messages yet');
        expect(previewLine({ text: 'Hi', mine: true })).toBe('You: Hi');
        expect(previewLine({ text: 'Hi', mine: false })).toBe('Hi');
        expect(previewLine({ text: 'Attachment', attachment: true, mine: false })).toBe('📎 Attachment');
        expect(previewLine({ text: 'x', deleted: true })).toBe(DELETED_TEXT);
    });

    it('builds reply snippets', () => {
        expect(replySnippet({ body: 'Hello   there' })).toBe('Hello there');
        expect(replySnippet({ preview: 'From server' })).toBe('From server');
        expect(replySnippet({ deleted: true, body: 'x' })).toBe(DELETED_TEXT);
        expect(replySnippet({ body: '', attachments: [{}] })).toBe('📎 Attachment');
        expect(replySnippet({ body: 'a'.repeat(100) }, 10)).toHaveLength(10);
    });
});

describe('message list merging', () => {
    const list = [
        { id: 1, body: 'a' },
        { id: 2, body: 'b' },
    ];

    it('merges by id, keeps order and lets the newer copy win', () => {
        const merged = mergeMessages(list, [{ id: 3, body: 'c' }, { id: 2, body: 'B', edited: true }]);
        expect(merged.map((m) => m.id)).toEqual([1, 2, 3]);
        expect(merged[1]).toEqual({ id: 2, body: 'B', edited: true });
        expect(mergeMessages([{ id: 9 }], [{ id: 4 }]).map((m) => m.id)).toEqual([4, 9]);
    });

    it('applies edits and deletions only to messages already held', () => {
        const changed = applyChanges(list, [{ id: 2, deleted: true, body: null }, { id: 99, body: 'unknown' }]);
        expect(changed).toHaveLength(2);
        expect(changed[1]).toMatchObject({ deleted: true, body: null });
        expect(applyChanges(list, [])).toBe(list);
    });

    it('refreshes quotes of an edited or deleted message', () => {
        const thread = [{ id: 1, body: 'old' }, { id: 2, body: 'reply', reply_to: { id: 1, sender_id: 5, preview: 'old' } }];

        expect(applyChanges(thread, [{ id: 1, body: 'new text', edited: true }])[1].reply_to).toEqual({ id: 1, sender_id: 5, preview: 'new text' });
        expect(applyChanges(thread, [{ id: 1, body: null, deleted: true }])[1].reply_to.preview).toBe(DELETED_TEXT);
    });

    it('finds the cursor', () => {
        expect(lastMessageId([])).toBe(0);
        expect(lastMessageId(list)).toBe(2);
    });

    it('inserts day separators in the CRM timezone', () => {
        const rows = withDaySeparators(
            [
                { id: 1, created_at: '2026-09-30T05:00:00Z' },
                { id: 2, created_at: '2026-09-30T06:00:00Z' },
                { id: 3, created_at: '2026-09-30T19:00:00Z' }, // 00:30 IST on 1 Oct
            ],
            { now: NOW, tz: TZ },
        );
        expect(rows.map((r) => (r.type === 'day' ? r.label : r.message.id))).toEqual(['Yesterday', 1, 2, 'Today', 3]);
    });
});

describe('composer rules', () => {
    const rules = { maxKb: 10240, maxFiles: 2, extensions: ['pdf', 'png', 'zip'] };

    it('requires text or a file', () => {
        expect(canSend('', [])).toBe(false);
        expect(canSend('   ', [])).toBe(false);
        expect(canSend('hi', [])).toBe(true);
        expect(canSend('', [{}])).toBe(true);
    });

    it('validates type, size and count like the server', () => {
        expect(validateFiles([{ name: 'a.PDF', size: 1000 }], rules)).toEqual([]);
        expect(validateFiles([{ name: 'run.exe', size: 10 }], rules)[0]).toContain('not allowed');
        expect(validateFiles([{ name: 'big.zip', size: 11 * 1024 * 1024 }], rules)[0]).toContain('larger than 10 MB');
        expect(validateFiles([{ name: 'a.pdf', size: 1 }, { name: 'b.pdf', size: 1 }, { name: 'c.pdf', size: 1 }], rules)[0]).toContain('up to 2 files');
        expect(validateFiles([{ name: 'noext', size: 1 }], rules)).toHaveLength(1);
    });
});

describe('priority broadcasts', () => {
    it('derives active / expired', () => {
        expect(broadcastState({ expires_at: null }, NOW).key).toBe('active');
        expect(broadcastState({ expires_at: '2026-10-01T09:00:00Z' }, NOW).key).toBe('expired');
        expect(broadcastState({ expires_at: '2026-10-02T09:00:00Z' }, NOW).key).toBe('active');
        expect(broadcastState({ expired: true }, NOW).label).toBe('Expired');
    });

    it('computes safe ratios', () => {
        expect(ratio(3, 4)).toEqual({ text: '3 / 4', percent: 75 });
        expect(ratio(0, 0)).toEqual({ text: '0 / 0', percent: 0 });
    });
});

describe('notification events and sounds', () => {
    it('maps chat and priority events to their own chimes', () => {
        expect(EVENTS.CHAT_MESSAGE).toBe('CHAT_MESSAGE');
        expect(normalizeEvent('chat_message')).toBe('CHAT_MESSAGE');
        expect(normalizeEvent('priority_broadcast')).toBe('PRIORITY_BROADCAST');
        expect(soundKindFor('CHAT_MESSAGE')).toBe('chat');
        expect(soundKindFor('priority_broadcast')).toBe('priority');
        expect(soundKindFor('lead_assigned')).toBe('lead');
    });

    it('every server push event (App\\Support\\PushEvent) is known to the browser rules', () => {
        const php = readFileSync(new URL('../../app/Support/PushEvent.php', import.meta.url), 'utf8');
        const serverEvents = [...php.matchAll(/public const ([A-Z_]+) = '([A-Z_]+)';/g)].map((m) => m[2]);
        expect(serverEvents).toEqual(expect.arrayContaining(['CHAT_MESSAGE', 'PRIORITY_BROADCAST']));
        for (const event of serverEvents) expect(Object.values(EVENTS)).toContain(event);
    });

    it('chat and priority chimes are distinct, short and play once', () => {
        expect(SOUND_TYPES.chat).toBe('chat');
        expect(SOUND_TYPES.priority).toBe('priority');
        expect(CHIMES.chat).not.toEqual(CHIMES.lead);
        expect(CHIMES.priority).not.toEqual(CHIMES.lead);
        expect(CHIMES.priority.notes.length).toBeGreaterThan(CHIMES.lead.notes.length);
        for (const kind of ['chat', 'priority']) {
            expect(chimeDuration(kind)).toBeGreaterThanOrEqual(0.3);
            expect(chimeDuration(kind)).toBeLessThan(1);
            expect(CHIMES[kind]).not.toHaveProperty('loop');
        }
    });
});
