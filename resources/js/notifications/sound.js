/*
 * NotificationSoundService — the only place that makes notification sounds.
 * Short tones are synthesised with the Web Audio API (no audio files, nothing
 * copyrighted). Browsers only allow audio after a user gesture, so the single
 * shared AudioContext is created/resumed on the first click or key press (or
 * by a "Test sound" button); until then playback is skipped without errors.
 * With the CRM tab closed no page can play audio: the OS notification then
 * uses the system's default notification sound.
 */

export const SOUND_TYPES = Object.freeze({ lead: 'lead', reminder: 'reminder' });

// Each chime stays under one second.
export const CHIMES = Object.freeze({
    // New lead: bright, rising two-tone (E5 → A5).
    lead: {
        wave: 'sine',
        gain: 0.12,
        notes: [
            { freq: 659.25, at: 0, dur: 0.2 },
            { freq: 880.0, at: 0.13, dur: 0.33 },
        ],
    },
    // Follow-up reminder: lower, softer "single … double" knock on A4.
    reminder: {
        wave: 'triangle',
        gain: 0.14,
        notes: [
            { freq: 440.0, at: 0, dur: 0.2 },
            { freq: 440.0, at: 0.32, dur: 0.12 },
            { freq: 440.0, at: 0.47, dur: 0.18 },
        ],
    },
});

let context = null;

const AudioCtor = () => (typeof window !== 'undefined' ? window.AudioContext || window.webkitAudioContext : null);

/** Creates / resumes the shared AudioContext. Call from a user gesture. */
export function unlockAudio(factory = AudioCtor()) {
    if (!factory) return false;
    try {
        context ??= new factory();
        if (context.state === 'suspended') context.resume().catch(() => {});
        return true;
    } catch {
        return false;
    }
}

/** unlockAudio() and wait until the context is running (for test buttons). */
export async function ensureAudio() {
    if (!unlockAudio()) return false;
    try {
        if (context.state !== 'running') await context.resume();
    } catch {
        return false;
    }
    return context.state === 'running';
}

export function canPlay() {
    return Boolean(context && context.state === 'running');
}

/** Installs a one-time listener that unlocks audio on the first interaction. */
export function unlockOnFirstGesture() {
    if (typeof window === 'undefined') return;
    const unlock = () => {
        unlockAudio();
        window.removeEventListener('pointerdown', unlock, true);
        window.removeEventListener('keydown', unlock, true);
    };
    window.addEventListener('pointerdown', unlock, true);
    window.addEventListener('keydown', unlock, true);
}

/**
 * Schedules the chime for `kind` on a running context. Returns false
 * (without throwing or logging) when audio is locked or unsupported.
 */
export function playChime(kind = 'lead', ctx = context) {
    const chime = CHIMES[kind] ?? CHIMES.lead;
    if (!ctx || ctx.state !== 'running') return false;

    try {
        const start = ctx.currentTime + 0.01;
        for (const note of chime.notes) {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = chime.wave;
            osc.frequency.setValueAtTime(note.freq, start + note.at);
            gain.gain.setValueAtTime(0.0001, start + note.at);
            gain.gain.exponentialRampToValueAtTime(chime.gain, start + note.at + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, start + note.at + note.dur);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start(start + note.at);
            osc.stop(start + note.at + note.dur + 0.02);
        }
        return true;
    } catch {
        return false;
    }
}

/**
 * Plays `kind`, resuming a context the browser suspended (e.g. a background
 * tab) when it is allowed to. Resolves true only if the sound was scheduled.
 */
export async function play(kind, ctx = context) {
    if (!ctx) return false;
    if (ctx.state === 'suspended') {
        try {
            await Promise.race([ctx.resume(), new Promise((resolve) => setTimeout(resolve, 300))]);
        } catch {
            return false;
        }
    }
    return playChime(kind, ctx);
}

export function chimeDuration(kind = 'lead') {
    const chime = CHIMES[kind] ?? CHIMES.lead;
    return Math.max(...chime.notes.map((n) => n.at + n.dur));
}

export const playNewLeadSound = () => play(SOUND_TYPES.lead);
export const playFollowupReminderSound = () => play(SOUND_TYPES.reminder);

/** For preference buttons: unlocks audio (user gesture) then plays. */
export async function playTestSound(type) {
    if (!(await ensureAudio())) return false;
    return playChime(type === SOUND_TYPES.reminder ? SOUND_TYPES.reminder : SOUND_TYPES.lead);
}

export const NotificationSoundService = Object.freeze({
    unlockAudio,
    ensureAudio,
    canPlay,
    playNewLeadSound,
    playFollowupReminderSound,
    playTestSound,
    play,
});

export function useNotificationSound() {
    return NotificationSoundService;
}
