export const THEME_KEY = 'crm.theme';

export function currentTheme() {
    if (typeof document === 'undefined') return 'dark';
    return document.documentElement.dataset.theme === 'light' ? 'light' : 'dark';
}

/** Dark is the existing look. Light is the white theme. Unknown values stay dark. */
export function applyTheme(theme) {
    const next = theme === 'light' ? 'light' : 'dark';
    document.documentElement.dataset.theme = next;
    try {
        localStorage.setItem(THEME_KEY, next);
    } catch {
        /* storage unavailable */
    }
    window.dispatchEvent(new CustomEvent('crm-theme', { detail: next }));
    return next;
}
