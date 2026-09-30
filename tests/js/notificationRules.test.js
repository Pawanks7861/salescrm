import { describe, expect, it, vi } from 'vitest';
import { createAnnouncer, createDeduper, isFresh, normalizeEvent, shouldPlaySound, soundKindFor } from '../../resources/js/notifications/rules.js';

const ON = { sound_allowed: true, sound: true };

function setup(prefs = ON, deduper = createDeduper()) {
    const play = vi.fn(() => true);
    const toast = vi.fn();
    const announce = createAnnouncer({ deduper, prefs: () => prefs, play, toast });
    return { announce, play, toast, deduper };
}

describe('event rules', () => {
    it('normalizes in-app and push event names', () => {
        expect(normalizeEvent('lead_assigned')).toBe('NEW_LEAD_ASSIGNED');
        expect(normalizeEvent('facebook_lead_assigned')).toBe('NEW_LEAD_ASSIGNED');
        expect(normalizeEvent('NEW_LEAD_ASSIGNED')).toBe('NEW_LEAD_ASSIGNED');
        expect(normalizeEvent('followup_reminder')).toBe('FOLLOWUP_REMINDER');
        expect(normalizeEvent('FOLLOWUP_REMINDER')).toBe('FOLLOWUP_REMINDER');
        expect(normalizeEvent('lead_note')).toBe('COMMENT');
        expect(normalizeEvent('meeting_note')).toBe('COMMENT');
        expect(normalizeEvent('followup_overdue')).toBeNull();
        expect(normalizeEvent('toString')).toBeNull();
        expect(normalizeEvent(undefined)).toBeNull();
    });

    it('maps important events to their own sound', () => {
        expect(soundKindFor('NEW_LEAD_ASSIGNED')).toBe('lead');
        expect(soundKindFor('lead_assigned')).toBe('lead');
        expect(soundKindFor('FOLLOWUP_REMINDER')).toBe('reminder');
        expect(soundKindFor('lead_note')).toBe('lead');
        expect(soundKindFor('meeting_note')).toBe('lead');
        expect(soundKindFor('import_completed')).toBeNull();
    });

    it('requires both the admin switch and the user preference', () => {
        expect(shouldPlaySound('lead_assigned', ON)).toBe(true);
        expect(shouldPlaySound('lead_assigned', { sound_allowed: false, sound: true })).toBe(false);
        expect(shouldPlaySound('lead_assigned', { sound_allowed: true, sound: false })).toBe(false);
        expect(shouldPlaySound('import_completed', ON)).toBe(false);
        expect(shouldPlaySound('lead_assigned', undefined)).toBe(false);
    });
});

describe('announcer', () => {
    it('toasts and plays the lead sound for a new lead', async () => {
        const { announce, play, toast } = setup();
        expect(await announce({ id: 'a', event: 'NEW_LEAD_ASSIGNED', message: 'New lead' })).toEqual({ handled: true, played: true });
        expect(play).toHaveBeenCalledExactlyOnceWith('lead');
        expect(toast).toHaveBeenCalledExactlyOnceWith('New lead');
    });

    it('plays the reminder sound for a follow-up reminder', async () => {
        const { announce, play } = setup();
        await announce({ id: 'b', event: 'followup_reminder', message: 'Call due' });
        expect(play).toHaveBeenCalledExactlyOnceWith('reminder');
    });

    it('stays silent for other notifications', async () => {
        const { announce, play, toast } = setup();
        expect((await announce({ id: 'c', event: 'import_completed', message: 'Import done' })).handled).toBe(false);
        expect(play).not.toHaveBeenCalled();
        expect(toast).not.toHaveBeenCalled();
    });

    it('does not play when the user or the admin turned sound off', async () => {
        const user = setup({ sound_allowed: true, sound: false });
        expect(await user.announce({ id: 'd', event: 'lead_assigned', message: 'x' })).toEqual({ handled: true, played: false });
        expect(user.play).not.toHaveBeenCalled();
        expect(user.toast).toHaveBeenCalledOnce();

        const admin = setup({ sound_allowed: false, sound: true });
        await admin.announce({ id: 'e', event: 'followup_reminder', message: 'x' });
        expect(admin.play).not.toHaveBeenCalled();
    });

    it('reports played=false when the browser blocked audio', async () => {
        const { announce, play } = setup();
        play.mockReturnValue(false);
        expect(await announce({ id: 'f', event: 'lead_assigned', message: 'x' })).toEqual({ handled: true, played: false });
    });

    it('announces once when the same id arrives by poll and by push', async () => {
        const { announce, play, toast } = setup();
        await announce({ id: 'g', event: 'lead_assigned', message: 'x' });
        expect((await announce({ id: 'g', event: 'NEW_LEAD_ASSIGNED', message: 'x', toastIt: false })).duplicate).toBe(true);
        expect(play).toHaveBeenCalledOnce();
        expect(toast).toHaveBeenCalledOnce();
    });

    it('can play without a toast (background tab)', async () => {
        const { announce, play, toast } = setup();
        await announce({ id: 'h', event: 'lead_assigned', message: 'x', toastIt: false });
        expect(toast).not.toHaveBeenCalled();
        expect(play).toHaveBeenCalledOnce();
    });
});

describe('in-memory deduper', () => {
    it('claims each id once, and seeding marks ids without claiming', () => {
        const d = createDeduper();
        d.seed(['1', '2']);
        expect(d.claim('1')).toBe(false);
        expect(d.claim('3')).toBe(true);
        expect(d.claim('3')).toBe(false);
        expect(d.claim('')).toBe(false);
    });

    it('forgets ids after the TTL and caps its size', () => {
        let t = 0;
        const d = createDeduper({ ttlMs: 1000, max: 2, now: () => t });
        d.claim('a');
        d.claim('b');
        d.claim('c');
        expect(d.size()).toBe(2);
        expect(d.has('a')).toBe(false);
        t = 1001;
        expect(d.has('b')).toBe(false);
        expect(d.size()).toBe(0);
    });

    it('does not touch localStorage', () => {
        const spy = vi.fn();
        globalThis.localStorage = { setItem: spy, getItem: spy };
        createDeduper().claim('x');
        expect(spy).not.toHaveBeenCalled();
        delete globalThis.localStorage;
    });

    it('treats only unread, recent notifications as fresh', () => {
        const now = Date.parse('2026-09-24T10:00:00Z');
        expect(isFresh({ created_at: '2026-09-24T09:55:00Z', read: false }, now)).toBe(true);
        expect(isFresh({ created_at: '2026-09-24T09:40:00Z', read: false }, now)).toBe(false);
        expect(isFresh({ created_at: '2026-09-24T09:59:00Z', read: true }, now)).toBe(false);
        expect(isFresh({ created_at: 'nope' }, now)).toBe(false);
    });
});
