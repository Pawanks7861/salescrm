import { describe, expect, it } from 'vitest';
import { reorderColumns } from '../../resources/js/utils/leadColumns.js';

describe('reorderColumns', () => {
    const order = ['lead', 'contact', 'created_at', 'status'];

    it('moves a column onto another and leaves the rest in place', () => {
        expect(reorderColumns(order, 'status', 'contact')).toEqual(['lead', 'status', 'contact', 'created_at']);
        expect(reorderColumns(order, 'created_at', 'lead')).toEqual(['created_at', 'lead', 'contact', 'status']);
    });

    it('returns the same order when nothing actually moves', () => {
        expect(reorderColumns(order, 'contact', 'contact')).toBe(order);
        expect(reorderColumns(order, 'missing', 'lead')).toBe(order);
        expect(reorderColumns(order, null, 'lead')).toBe(order);
    });
});
