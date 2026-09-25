<script setup>
import { formatValue } from '@/utils/reportFormat';
import { Link } from '@inertiajs/vue3';

defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, required: true },
    totals: { type: Object, default: null },
    currency: { type: String, default: 'INR' },
    empty: { type: String, default: 'No data for the selected filters.' },
});

const align = (col) => (col.format === 'text' ? 'text-left' : 'text-right');
</script>

<template>
    <div v-if="rows.length" class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50/60 section-label">
                <tr>
                    <th v-for="col in columns" :key="col.key" scope="col" class="whitespace-nowrap px-4 py-3 font-semibold" :class="align(col)">{{ col.label }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="(row, i) in rows" :key="i" class="transition-colors hover:bg-slate-50/70" :class="row._muted ? 'text-slate-400' : 'text-slate-700'">
                    <td v-for="col in columns" :key="col.key" class="whitespace-nowrap px-4 py-3" :class="[align(col), col.format === 'text' ? '' : 'tabular-nums']">
                        <Link v-if="row._links?.[col.key]" :href="row._links[col.key]" class="text-brand-700 hover:underline">{{ formatValue(row[col.key], col.format, currency) }}</Link>
                        <template v-else>{{ formatValue(row[col.key], col.format, currency) }}</template>
                    </td>
                </tr>
            </tbody>
            <tfoot v-if="totals" class="border-t border-slate-200 bg-slate-50/60 font-semibold text-slate-900">
                <tr>
                    <td v-for="col in columns" :key="col.key" class="whitespace-nowrap px-4 py-3" :class="[align(col), col.format === 'text' ? '' : 'tabular-nums']">
                        {{ totals[col.key] === undefined ? '' : formatValue(totals[col.key], col.format, currency) }}
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
    <p v-else class="px-4 py-8 text-center text-xs text-slate-500">{{ empty }}</p>
</template>
