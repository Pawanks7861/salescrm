<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppIcon from './AppIcon.vue';

const props = defineProps({
    variant: { type: String, default: 'primary' }, // primary | secondary | danger | ghost | icon
    size: { type: String, default: 'md' }, // sm | md | lg
    href: { type: String, default: null },
    type: { type: String, default: 'button' },
    icon: { type: String, default: null },
    loading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    label: { type: String, default: null }, // accessible name / tooltip for icon-only buttons
});

const sizes = {
    sm: 'h-8 gap-1.5 rounded-lg px-3 text-xs',
    md: 'h-10 gap-2 rounded-lg px-4 text-sm',
    lg: 'h-11 gap-2 rounded-xl px-5 text-sm',
};

const iconSizes = { sm: 'h-8 w-8 rounded-lg', md: 'h-10 w-10 rounded-lg', lg: 'h-11 w-11 rounded-xl' };

const classes = computed(() => [
    'inline-flex shrink-0 items-center justify-center whitespace-nowrap font-medium transition duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50',
    props.variant === 'icon' ? iconSizes[props.size] ?? iconSizes.md : sizes[props.size] ?? sizes.md,
    {
        primary: 'bg-brand-600 text-white shadow-glow hover:bg-brand-500 focus-visible:ring-brand-500',
        secondary: 'border border-slate-200 bg-slate-50 text-slate-800 hover:border-slate-300 hover:bg-slate-100 focus-visible:ring-brand-500',
        danger: 'bg-red-600 text-white hover:bg-red-500 focus-visible:ring-red-500',
        ghost: 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 focus-visible:ring-brand-500',
        icon: 'border border-slate-200 bg-slate-50 text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus-visible:ring-brand-500',
    }[props.variant],
]);

const iconClass = computed(() => (props.size === 'sm' ? 'h-3.5 w-3.5' : 'h-4 w-4'));
</script>

<template>
    <Link v-if="href" :href="href" :class="classes" :title="label || undefined" :aria-label="label || undefined">
        <AppIcon v-if="icon" :name="icon" :class="iconClass" />
        <slot />
    </Link>
    <button v-else :type="type" :class="classes" :disabled="disabled || loading" :title="label || undefined" :aria-label="label || undefined">
        <svg v-if="loading" class="animate-spin" :class="iconClass" viewBox="0 0 24 24" fill="none">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
        </svg>
        <AppIcon v-else-if="icon" :name="icon" :class="iconClass" />
        <slot />
    </button>
</template>
