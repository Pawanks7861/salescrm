import { describe, expect, it } from 'vitest';
import { batchCreatePayload, batchDateFields, batchPhase, batchStatusLabel, batchTags, batchUpdatePayload, trainerPayload, trainerSummary } from '../../resources/js/utils/batches.js';
import { formatCalendarDate } from '../../resources/js/utils/format.js';

describe('batchTags', () => {
    it('shows the first batch and counts the rest', () => {
        const tags = batchTags([{ name: 'October Campaign' }, { name: 'Hot Prospects' }, { name: 'Ahmedabad Leads' }]);

        expect(tags.shown.map((b) => b.name)).toEqual(['October Campaign']);
        expect(tags.more).toBe(2);
        expect(tags.title).toBe('Hot Prospects, Ahmedabad Leads');
    });

    it('handles a lead in no batch or a single batch', () => {
        expect(batchTags([])).toEqual({ shown: [], more: 0, title: '' });
        expect(batchTags(undefined)).toEqual({ shown: [], more: 0, title: '' });
        expect(batchTags([{ name: 'Only' }]).more).toBe(0);
    });

    it('never renders more than the requested number of names', () => {
        const many = Array.from({ length: 12 }, (_, i) => ({ name: `Batch ${i + 1}` }));
        const tags = batchTags(many, 2);

        expect(tags.shown).toHaveLength(2);
        expect(tags.more).toBe(10);
    });
});

describe('batchStatusLabel', () => {
    it('labels plain and object statuses', () => {
        expect(batchStatusLabel('archived')).toBe('Archived');
        expect(batchStatusLabel({ value: 'inactive' })).toBe('Inactive');
        expect(batchStatusLabel(null)).toBe('');
    });
});

describe('formatCalendarDate', () => {
    it('formats a calendar date like the rest of the CRM', () => {
        expect(formatCalendarDate('2026-10-01')).toBe('01 Oct 2026');
        expect(formatCalendarDate('2026-10-31')).toBe('31 Oct 2026');
    });

    it('never shifts the day, whatever the process timezone', () => {
        const original = process.env.TZ;
        try {
            for (const tz of ['America/Los_Angeles', 'Pacific/Kiritimati', 'Asia/Kolkata']) {
                process.env.TZ = tz;
                expect(formatCalendarDate('2026-10-01')).toBe('01 Oct 2026');
            }
        } finally {
            process.env.TZ = original;
        }
    });

    it('shows a dash for missing or invalid dates instead of Invalid Date or 1970', () => {
        for (const value of [null, undefined, '', 'not-a-date', 0, {}]) {
            expect(formatCalendarDate(value)).toBe('—');
        }
    });
});

describe('batch form dates', () => {
    it('populates the edit form from existing dates', () => {
        expect(batchDateFields({ start_date: '2026-10-01', end_date: '2026-10-31' })).toEqual({ start_date: '2026-10-01', end_date: '2026-10-31' });
    });

    it('uses empty inputs for a new batch or null dates', () => {
        expect(batchDateFields(null)).toEqual({ start_date: '', end_date: '' });
        expect(batchDateFields({ start_date: null, end_date: undefined })).toEqual({ start_date: '', end_date: '' });
    });

    it('submits both dates with the update, including cleared ones', () => {
        const form = { name: 'October Campaign', description: '', start_date: '2026-10-01', end_date: '', status: 'inactive', lead_ids: [5] };

        expect(batchUpdatePayload(form)).toEqual({ name: 'October Campaign', description: '', start_date: '2026-10-01', end_date: '', status: 'inactive' });
    });

    it('keeps the dates but not the status for an archived batch', () => {
        const form = { name: 'Old', description: null, start_date: '2026-09-01', end_date: '2026-09-30', status: 'active', lead_ids: [] };

        expect(batchUpdatePayload(form, true)).toEqual({ name: 'Old', description: null, start_date: '2026-09-01', end_date: '2026-09-30' });
    });
});

describe('batch trainers', () => {
    const rahul = { id: 31, name: 'Rahul Sharma', active: true };
    const priya = { id: 32, name: 'Priya Patel', active: true };
    const amit = { id: 33, name: 'Amit Shah', active: false };

    it('summarises one trainer as a name and several as "first +N" with all names in the tooltip', () => {
        expect(trainerSummary([rahul])).toEqual({ first: 'Rahul Sharma', more: 0, title: 'Rahul Sharma' });
        expect(trainerSummary([rahul, priya, amit])).toEqual({ first: 'Rahul Sharma', more: 2, title: 'Rahul Sharma\nPriya Patel\nAmit Shah (inactive)' });
        expect(trainerSummary([])).toEqual({ first: null, more: 0, title: '' });
        expect(trainerSummary(undefined)).toEqual({ first: null, more: 0, title: '' });
    });

    it('sends trainer ids only when the user can manage trainers', () => {
        expect(trainerPayload([rahul, priya], true)).toEqual({ trainer_ids: [31, 32] });
        expect(trainerPayload([], true)).toEqual({ trainer_ids: [] });
        expect(trainerPayload([rahul], false)).toEqual({});
    });

    it('turns selected trainer objects into trainer_ids on create', () => {
        const form = { name: 'October Batch', start_date: '2026-10-01', lead_ids: [5, 6], trainers: [rahul, priya] };

        expect(batchCreatePayload(form, true)).toEqual({ name: 'October Batch', start_date: '2026-10-01', lead_ids: [5, 6], trainer_ids: [31, 32] });
        expect(batchCreatePayload(form, false)).toEqual({ name: 'October Batch', start_date: '2026-10-01', lead_ids: [5, 6] });
    });

    it('never sends trainer objects with the edit payload', () => {
        const form = { name: 'X', description: '', start_date: '', end_date: '', status: 'active', lead_ids: [], trainers: [rahul] };

        expect(batchUpdatePayload(form)).not.toHaveProperty('trainers');
        expect({ ...batchUpdatePayload(form), ...trainerPayload(form.trainers, true) }).toMatchObject({ trainer_ids: [31] });
    });
});

describe('batchPhase', () => {
    const today = '2026-10-15';

    it('is upcoming before the start date', () => {
        expect(batchPhase('2026-10-16', '2026-10-31', today).key).toBe('upcoming');
    });

    it('is ongoing from the start date through the end date (inclusive)', () => {
        expect(batchPhase('2026-10-15', '2026-10-15', today).key).toBe('ongoing');
        expect(batchPhase('2026-10-01', '2026-10-31', today).key).toBe('ongoing');
        expect(batchPhase('2026-10-01', null, today).key).toBe('ongoing');
    });

    it('is ended after the end date', () => {
        expect(batchPhase('2026-10-01', '2026-10-14', today).key).toBe('ended');
        expect(batchPhase(null, '2026-10-14', today).key).toBe('ended');
    });

    it('has no label without a start date unless the batch has ended', () => {
        expect(batchPhase(null, null, today)).toBeNull();
        expect(batchPhase(null, '2026-10-31', today)).toBeNull();
    });
});
