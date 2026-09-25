<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppIcon from './AppIcon.vue';

const props = defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], default: '—' },
    icon: { type: String, default: null },
    tone: { type: String, default: 'brand' }, // brand | blue | cyan | green | amber | red | purple | slate
    href: { type: String, default: null },
    hint: { type: String, default: null },
    compact: { type: Boolean, default: false },
});

const tones = {
    brand: { box: 'bg-brand-100 text-brand-600', line: 'from-brand-500/80' },
    blue: { box: 'bg-sky-100 text-sky-600', line: 'from-sky-500/80' },
    cyan: { box: 'bg-cyan-100 text-cyan-600', line: 'from-cyan-500/80' },
    green: { box: 'bg-emerald-100 text-emerald-600', line: 'from-emerald-500/80' },
    amber: { box: 'bg-amber-100 text-amber-600', line: 'from-amber-500/80' },
    red: { box: 'bg-red-100 text-red-600', line: 'from-red-500/80' },
    purple: { box: 'bg-purple-100 text-purple-600', line: 'from-purple-500/80' },
    slate: { box: 'bg-slate-100 text-slate-500', line: 'from-slate-500/60' },
};

const t = computed(() => tones[props.tone] ?? tones.brand);
const display = computed(() => (typeof props.value === 'number' ? props.value.toLocaleString() : props.value));
</script>

<template>
    <component
        :is="href ? Link : 'div'"
        :href="href || undefined"
        :title="hint || undefined"
        class="panel group relative block overflow-hidden"
        :class="[compact ? 'p-4' : 'p-5', href ? 'transition hover:-translate-y-px hover:border-slate-300' : '']"
    >
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="flex items-center gap-1 truncate text-2xs font-semibold uppercase tracking-[0.08em] text-slate-400">
                    <span class="truncate">{{ label }}</span>
                    <span v-if="hint" class="cursor-help text-slate-300" aria-hidden="true">ⓘ</span>
                </p>
                <p class="mt-2 truncate font-bold tabular-nums tracking-tight text-slate-900" :class="compact ? 'text-2xl' : 'text-[30px] leading-9'">{{ display }}</p>
            </div>
            <div v-if="icon" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl" :class="t.box">
                <AppIcon :name="icon" class="h-5 w-5" />
            </div>
        </div>
        <div v-if="$slots.default" class="mt-1.5 text-2xs text-slate-500"><slot /></div>
        <p v-if="hint" class="sr-only">{{ hint }}</p>
        <span class="absolute inset-x-0 bottom-0 h-[3px] bg-gradient-to-r to-transparent" :class="t.line" aria-hidden="true" />
    </component>
</template>
