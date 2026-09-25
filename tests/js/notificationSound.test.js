import { describe, expect, it } from 'vitest';
import { CHIMES, chimeDuration, play, playChime, useNotificationSound } from '../../resources/js/notifications/sound.js';

/** Minimal AudioContext stand-in that records scheduled notes (no audio hardware). */
function fakeContext(state = 'running') {
    const started = [];
    const param = () => ({ setValueAtTime() {}, exponentialRampToValueAtTime() {} });
    return {
        state,
        currentTime: 0,
        destination: {},
        started,
        resume() {
            this.state = 'running';
            return Promise.resolve();
        },
        createOscillator() {
            const osc = { type: '', frequency: { setValueAtTime: (f) => (osc.freq = f) }, connect() {}, start: (t) => started.push({ t, osc }), stop() {} };
            return osc;
        },
        createGain: () => ({ gain: param(), connect() {} }),
    };
}

describe('notification sounds', () => {
    it('schedules every note of each sound with its own waveform', () => {
        for (const kind of ['lead', 'reminder']) {
            const ctx = fakeContext();
            expect(playChime(kind, ctx)).toBe(true);
            expect(ctx.started).toHaveLength(CHIMES[kind].notes.length);
            expect(ctx.started.every(({ osc }) => osc.type === CHIMES[kind].wave)).toBe(true);
        }
    });

    it('makes the new-lead and follow-up sounds clearly different', () => {
        const lead = CHIMES.lead;
        const reminder = CHIMES.reminder;
        expect(lead.wave).not.toBe(reminder.wave);
        expect(lead.notes[1].freq).toBeGreaterThan(lead.notes[0].freq);
        expect(Math.max(...reminder.notes.map((n) => n.freq))).toBeLessThan(Math.min(...lead.notes.map((n) => n.freq)));
        expect(reminder.notes).toHaveLength(3);
    });

    it('keeps each sound under one second', () => {
        for (const kind of Object.keys(CHIMES)) {
            expect(chimeDuration(kind)).toBeGreaterThanOrEqual(0.3);
            expect(chimeDuration(kind)).toBeLessThan(1);
        }
    });

    it('silently skips when audio is locked or unavailable', () => {
        const ctx = fakeContext('suspended');
        expect(playChime('lead', ctx)).toBe(false);
        expect(ctx.started).toHaveLength(0);
        expect(playChime('lead', null)).toBe(false);
    });

    it('resumes a suspended context when allowed before playing', async () => {
        const ctx = fakeContext('suspended');
        expect(await play('reminder', ctx)).toBe(true);
        expect(ctx.started).toHaveLength(3);
    });

    it('reports false when the context cannot resume', async () => {
        const ctx = fakeContext('suspended');
        ctx.resume = () => Promise.resolve();
        expect(await play('lead', ctx)).toBe(false);
    });

    it('never throws if the audio graph fails', () => {
        const ctx = fakeContext();
        ctx.createOscillator = () => {
            throw new Error('boom');
        };
        expect(playChime('reminder', ctx)).toBe(false);
    });

    it('exposes one shared service', () => {
        const s = useNotificationSound();
        expect(s).toBe(useNotificationSound());
        expect(Object.keys(s)).toEqual(expect.arrayContaining(['playNewLeadSound', 'playFollowupReminderSound', 'playTestSound', 'unlockAudio']));
    });
});
