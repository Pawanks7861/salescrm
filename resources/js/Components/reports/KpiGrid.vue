<script setup>
import { formatChange, formatValue } from '@/utils/reportFormat';
import { Link } from '@inertiajs/vue3';

defineProps({
    items: { type: Array, required: true },
    compare: { type: Boolean, default: false },
    currency: { type: String, default: 'INR' },
});

const accents = ['from-brand-500/80', 'from-emerald-500/80', 'from-sky-500/80', 'from-amber-500/80'];

const tone = (item) => {
    if (item.change === null || item.change === undefined || item.change === 0) return 'text-slate-500';
    const good = item.higher_is_better === false ? item.change < 0 : item.change > 0;
    return good ? 'text-emerald-600' : 'text-red-600';
};
</script>

<template>
    <div class="grid grid-cols-1 gap-4 min-[420px]:grid-cols-2 xl:grid-cols-4">
        <component
            :is="item.link ? Link : 'div'"
            v-for="(item, index) in items"
            :key="item.key"
            :href="item.link || undefined"
            class="panel relative block overflow-hidden p-5"
            :class="item.link ? 'transition hover:-translate-y-px hover:border-slate-300' : ''"
            :title="item.hint || undefined"
        >
            <p class="flex items-start gap-1 text-2xs font-semibold uppercase leading-tight tracking-[0.08em] text-slate-400">
                <span>{{ item.label }}</span>
                <span v-if="item.hint" class="cursor-help text-slate-300" aria-hidden="true">ⓘ</span>
            </p>
            <p class="mt-2 break-words text-2xl font-bold tabular-nums tracking-tight text-slate-900 lg:text-[28px] lg:leading-9">{{ formatValue(item.value, item.format, currency) }}</p>
            <span class="absolute inset-x-0 bottom-0 h-[3px] bg-gradient-to-r to-transparent" :class="accents[index % accents.length]" aria-hidden="true" />
            <p v-if="compare && !item.snapshot" class="mt-0.5 text-2xs" :class="tone(item)">
                {{ formatChange(item.change, item.change_unit) }}
                <span class="text-slate-400">vs previous<template v-if="item.previous !== undefined && item.previous !== null"> ({{ formatValue(item.previous, item.format, currency) }})</template></span>
            </p>
            <p v-else-if="item.snapshot" class="mt-0.5 text-2xs text-slate-400">Current snapshot</p>
            <p v-if="item.hint" class="sr-only">{{ item.hint }}</p>
        </component>
    </div>
</template>
