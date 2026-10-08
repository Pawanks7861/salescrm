import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/*
 * Dark theme mapping. The CRM markup uses the standard Tailwind palette
 * (slate / white / tinted accents); each utility family is remapped here onto
 * the CSS variables in resources/css/theme.css so the whole app shares one
 * token set:
 *   - backgrounds: white → card surface, slate-50/100 → raised surfaces,
 *     accent-50/100/200 → translucent tints
 *   - text: slate-900 → primary text … slate-400 → muted, accent-600+ → light accent
 *   - borders: slate-200/300 → border tokens, accent-100…400 → translucent accent
 */
const v = (name) => `rgb(var(--rgb-${name}) / <alpha-value>)`;
const tint = (name, amount) => `rgb(var(--rgb-${name}) / calc(${amount} * <alpha-value>))`;

const surfaceScale = {
    50: v('surface-2'),
    100: v('surface-3'),
    200: v('border'),
    300: v('border-strong'),
    400: v('slate-400'),
    500: v('slate-500'),
    600: v('text-muted'),
    700: v('surface-4'),
    800: v('surface-3'),
    900: v('app-bg'),
    950: v('slate-950'),
};

const textScale = {
    50: v('text-50'),
    100: v('border'),
    200: v('text-200'),
    300: v('text-faint'),
    400: v('text-muted'),
    500: v('text-subtle'),
    600: v('text-secondary'),
    700: v('text-body'),
    800: v('text-strong'),
    900: v('text-primary'),
    950: v('text-primary'),
};

const borderScale = {
    50: 'rgb(148 163 184 / calc(0.08 * <alpha-value>))',
    100: 'rgb(148 163 184 / calc(0.11 * <alpha-value>))',
    200: v('border'),
    300: v('border-strong'),
    400: v('slate-400'),
    500: v('slate-500'),
    600: v('text-muted'),
    700: v('border-strong'),
    800: v('border'),
    900: v('app-bg'),
    950: v('app-bg'),
};

const accents = {
    red: 'danger', rose: 'danger',
    amber: 'warning', yellow: 'warning',
    orange: 'orange',
    emerald: 'success', green: 'success',
    lime: 'lime', teal: 'teal',
    cyan: 'cyan',
    sky: 'info', blue: 'info',
    indigo: 'primary-light', brand: 'primary-light',
    violet: 'purple', purple: 'purple',
    fuchsia: 'fuchsia', pink: 'pink',
};

const map = (fn) => Object.fromEntries(Object.entries(accents).map(([family, token]) => [family, fn(token)]));

const accentBg = map((t) => ({ 50: tint(t, 0.1), 100: tint(t, 0.16), 200: tint(t, 0.24), 500: v(t) }));
const accentText = map((t) => ({ 500: v(t), 600: v(t), 700: v(t), 800: v(t), 900: v(t) }));
const accentBorder = map((t) => ({ 50: tint(t, 0.12), 100: tint(t, 0.18), 200: tint(t, 0.3), 300: tint(t, 0.42), 400: tint(t, 0.6) }));

// Solid brand scale (buttons, active states); tints come from accentBg.
const brand = {
    50: '#eef0ff',
    100: '#e0e2ff',
    200: '#c7c9ff',
    300: '#b2adff',
    400: '#9a94ff',
    500: v('primary-light'),
    600: v('primary'),
    700: '#5457e6',
    800: '#4649c9',
    900: '#373a9e',
    950: '#23256b',
};

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                brand,
                slate: surfaceScale,
                gray: surfaceScale,
                app: v('app-bg'),
                content: v('content-bg'),
                sidebar: v('sidebar-bg'),
                surface: { 1: v('surface-1'), 2: v('surface-2'), 3: v('surface-3'), 4: v('surface-4') },
                line: { DEFAULT: v('border'), strong: v('border-strong') },
                ink: { DEFAULT: v('text-primary'), body: v('text-body'), secondary: v('text-secondary'), muted: v('text-muted') },
                success: v('success'),
                warning: v('warning'),
                danger: v('danger'),
                info: v('info'),
            },
            backgroundColor: {
                white: v('surface-1'),
                ...accentBg,
                brand: { ...brand, ...accentBg.brand },
                red: { ...accentBg.red, 500: '#f35c78', 600: '#e0445f', 700: '#c9384f' },
            },
            textColor: {
                white: '#ffffff',
                slate: textScale,
                gray: textScale,
                ...accentText,
                brand: { ...brand, 500: v('primary-text'), 600: v('primary-text'), 700: v('brand-text-700'), 800: v('brand-text-800'), 900: v('brand-text-900') },
            },
            borderColor: {
                white: v('surface-1'),
                slate: borderScale,
                gray: borderScale,
                ...accentBorder,
                brand: { ...brand, ...accentBorder.brand },
            },
            ringColor: {
                white: v('surface-1'),
                slate: borderScale,
                gray: borderScale,
                ...accentBorder,
                brand: { ...brand, ...accentBorder.brand },
            },
            gradientColorStops: {
                ...map((t) => ({ 500: v(t) })),
                brand,
            },
            ringOffsetColor: {
                white: v('surface-1'),
            },
            borderRadius: {
                sm: 'var(--radius-sm)',
                md: '10px',
                lg: 'var(--radius-md)',
                xl: 'var(--radius-lg)',
                '2xl': 'var(--radius-xl)',
            },
            boxShadow: {
                card: 'var(--shadow-card)',
                pop: 'var(--shadow-pop)',
                glow: 'var(--shadow-glow)',
            },
            fontSize: {
                '2xs': ['0.6875rem', { lineHeight: '1rem' }],
            },
            width: {
                sidebar: 'var(--sidebar-width)',
            },
            padding: {
                sidebar: 'var(--sidebar-width)',
            },
            transitionDuration: {
                DEFAULT: '160ms',
            },
        },
    },

    plugins: [forms],
};
