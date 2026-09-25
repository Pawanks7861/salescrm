<script setup>
import { computed } from 'vue';

const props = defineProps({
    color: { type: String, default: 'slate' }, // slate | green | red | amber | blue | indigo | purple | orange | teal | pink
    dot: { type: Boolean, default: false },
});

const palette = {
    slate: 'bg-slate-100 text-slate-700 ring-slate-300/60',
    gray: 'bg-slate-100 text-slate-700 ring-slate-300/60',
    green: 'bg-emerald-100 text-emerald-600 ring-emerald-200',
    emerald: 'bg-emerald-100 text-emerald-600 ring-emerald-200',
    red: 'bg-red-100 text-red-600 ring-red-200',
    amber: 'bg-amber-100 text-amber-600 ring-amber-200',
    yellow: 'bg-amber-100 text-amber-600 ring-amber-200',
    blue: 'bg-sky-100 text-sky-600 ring-sky-200',
    sky: 'bg-sky-100 text-sky-600 ring-sky-200',
    cyan: 'bg-cyan-100 text-cyan-600 ring-cyan-200',
    indigo: 'bg-brand-100 text-brand-600 ring-brand-200',
    purple: 'bg-purple-100 text-purple-600 ring-purple-200',
    violet: 'bg-purple-100 text-purple-600 ring-purple-200',
    orange: 'bg-orange-100 text-orange-600 ring-orange-200',
    teal: 'bg-teal-100 text-teal-600 ring-teal-200',
    pink: 'bg-pink-100 text-pink-600 ring-pink-200',
};

const dotColor = {
    slate: 'bg-slate-600', gray: 'bg-slate-600', green: 'bg-emerald-500', emerald: 'bg-emerald-500', red: 'bg-red-500',
    amber: 'bg-amber-500', yellow: 'bg-amber-500', blue: 'bg-sky-500', sky: 'bg-sky-500', cyan: 'bg-cyan-500',
    indigo: 'bg-brand-500', purple: 'bg-purple-500', violet: 'bg-purple-500',
    orange: 'bg-orange-500', teal: 'bg-teal-500', pink: 'bg-pink-500',
};

const classes = computed(() => palette[props.color] ?? palette.slate);
</script>

<template>
    <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2 py-0.5 text-2xs font-semibold ring-1 ring-inset" :class="classes">
        <span v-if="dot" class="h-1.5 w-1.5 shrink-0 rounded-full" :class="dotColor[color] ?? dotColor.slate" aria-hidden="true" />
        <slot />
    </span>
</template>
