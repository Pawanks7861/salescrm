// Presentation-only formatting for report values. All metrics and rates are
// computed on the server; nothing here derives business numbers.

const numberFmt = new Intl.NumberFormat('en-IN');
const currencyCache = {};

export function formatNumber(value) {
    if (value === null || value === undefined || value === '') return '—';
    return numberFmt.format(value);
}

export function formatCurrency(value, currency = 'INR') {
    if (value === null || value === undefined || value === '') return '—';
    currencyCache[currency] ??= new Intl.NumberFormat('en-IN', { style: 'currency', currency, maximumFractionDigits: 0 });
    return currencyCache[currency].format(value);
}

export function formatPercent(value) {
    if (value === null || value === undefined) return 'N/A';
    return `${Number(value).toFixed(1)}%`;
}

/** Seconds → "45s", "12m", "2h 18m", "3d 4h". */
export function formatDuration(seconds) {
    if (seconds === null || seconds === undefined || seconds === '') return '—';
    const s = Math.max(0, Math.round(Number(seconds)));
    if (s < 60) return `${s}s`;
    const m = Math.floor(s / 60);
    if (m < 60) return `${m}m`;
    const h = Math.floor(m / 60);
    if (h < 24) return m % 60 ? `${h}h ${m % 60}m` : `${h}h`;
    const d = Math.floor(h / 24);
    return h % 24 ? `${d}d ${h % 24}h` : `${d}d`;
}

export function formatDays(value) {
    if (value === null || value === undefined) return '—';
    return `${Number(value).toFixed(1)} d`;
}

export function formatValue(value, format = 'number', currency = 'INR') {
    switch (format) {
        case 'currency':
            return formatCurrency(value, currency);
        case 'percent':
            return formatPercent(value);
        case 'duration':
            return formatDuration(value);
        case 'days':
            return formatDays(value);
        case 'text':
            return value === null || value === undefined || value === '' ? '—' : String(value);
        default:
            return formatNumber(value);
    }
}

/** "+12.5%" / "−3.0 pts" / "N/A" for comparison badges. */
export function formatChange(change, unit = '%') {
    if (change === null || change === undefined) return 'N/A';
    const sign = change > 0 ? '+' : change < 0 ? '−' : '';
    return unit === 'pts' ? `${sign}${Math.abs(change).toFixed(1)} pts` : `${sign}${Math.abs(change).toFixed(1)}%`;
}

// Tailwind palette names used by lead statuses / sources, plus semantic won/lost.
// Values mirror the dark-theme tokens in resources/css/theme.css (canvas needs literal colours).
export const NAMED_COLORS = {
    won: '#4ed7a8', lost: '#f35c78', slate: '#5e6986', gray: '#5e6986', red: '#f35c78', orange: '#fb925a', amber: '#f4b646',
    yellow: '#f4b646', lime: '#a3e36b', green: '#4ed7a8', emerald: '#4ed7a8', teal: '#38d4be', cyan: '#45c6e8', sky: '#55b8ff',
    blue: '#55b8ff', indigo: '#7c74ff', violet: '#a66cff', purple: '#a66cff', fuchsia: '#e279f9', pink: '#f472b6', rose: '#f35c78',
};

export const CHART_THEME = {
    title: '#f5f7ff',
    text: '#a5aec4',
    muted: '#747f99',
    grid: 'rgba(148, 163, 184, 0.08)',
    surface: '#151b2b',
    tooltip: '#1e2639',
    border: '#273149',
};

/** Canvas colours follow the active theme (dark or white). */
export function chartTheme() {
    if (typeof document === 'undefined') return CHART_THEME;
    const style = getComputedStyle(document.documentElement);
    const pick = (name, fallback) => style.getPropertyValue(name).trim() || fallback;
    return {
        title: pick('--text-primary', CHART_THEME.title),
        text: pick('--text-secondary', CHART_THEME.text),
        muted: pick('--text-muted', CHART_THEME.muted),
        grid: pick('--chart-grid', CHART_THEME.grid),
        surface: pick('--surface-1', CHART_THEME.surface),
        tooltip: pick('--surface-4', CHART_THEME.tooltip),
        border: pick('--border', CHART_THEME.border),
    };
}

export const colorFor = (name, index) => NAMED_COLORS[name] ?? (name?.startsWith?.('#') ? name : CHART_COLORS[index % CHART_COLORS.length]);

export const CHART_COLORS = ['#7c74ff', '#4ed7a8', '#f4b646', '#f35c78', '#55b8ff', '#a66cff', '#5e6986', '#38d4be', '#fb925a', '#a3e36b'];
