<script setup>
import { initials } from '@/utils/format';
import { computed } from 'vue';

const props = defineProps({
    name: { type: String, default: '' },
    size: { type: String, default: 'sm' }, // xs | sm | md | lg
});

const tones = [
    'bg-brand-100 text-brand-600 ring-brand-200',
    'bg-sky-100 text-sky-600 ring-sky-200',
    'bg-emerald-100 text-emerald-600 ring-emerald-200',
    'bg-amber-100 text-amber-600 ring-amber-200',
    'bg-purple-100 text-purple-600 ring-purple-200',
    'bg-cyan-100 text-cyan-600 ring-cyan-200',
    'bg-pink-100 text-pink-600 ring-pink-200',
];

const sizes = { xs: 'h-6 w-6 text-[10px]', sm: 'h-7 w-7 text-2xs', md: 'h-9 w-9 text-xs', lg: 'h-12 w-12 text-sm' };

const tone = computed(() => {
    let hash = 0;
    for (const ch of props.name || '?') hash = (hash * 31 + ch.charCodeAt(0)) >>> 0;
    return tones[hash % tones.length];
});
</script>

<template>
    <span class="inline-flex shrink-0 select-none items-center justify-center rounded-full font-semibold ring-1 ring-inset" :class="[sizes[size] ?? sizes.sm, tone]" aria-hidden="true">
        {{ initials(name) || '?' }}
    </span>
</template>
