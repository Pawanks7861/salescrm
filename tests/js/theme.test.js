// @vitest-environment happy-dom
import { beforeEach, describe, expect, it } from 'vitest';
import { applyTheme, currentTheme, THEME_KEY } from '../../resources/js/utils/theme.js';

describe('theme', () => {
    beforeEach(() => {
        localStorage.clear();
        delete document.documentElement.dataset.theme;
    });

    it('keeps dark as the default and stores a white choice', () => {
        expect(currentTheme()).toBe('dark');

        applyTheme('light');
        expect(document.documentElement.dataset.theme).toBe('light');
        expect(localStorage.getItem(THEME_KEY)).toBe('light');
        expect(currentTheme()).toBe('light');

        applyTheme('dark');
        expect(currentTheme()).toBe('dark');
        expect(localStorage.getItem(THEME_KEY)).toBe('dark');
    });

    it('ignores an unknown theme', () => {
        applyTheme('blue');
        expect(currentTheme()).toBe('dark');
    });
});
