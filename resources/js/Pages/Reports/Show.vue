<script setup>
import KpiGrid from '@/Components/reports/KpiGrid.vue';
import ReportChart from '@/Components/reports/ReportChart.vue';
import ReportFilterBar from '@/Components/reports/ReportFilterBar.vue';
import ReportTable from '@/Components/reports/ReportTable.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import UiSkeleton from '@/Components/ui/UiSkeleton.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

// Generic renderer for the declarative report payload. Every number, rate
// and link comes from the server; this page only lays it out.
const props = defineProps({
    report: { type: Object, required: true },
    sections: { type: Array, required: true },
    filters: { type: Object, required: true },
    comparison: { type: Object, default: null },
    options: { type: Object, required: true },
    context: { type: Object, required: true },
    reports: { type: Array, required: true },
    exports: { type: Array, default: () => [] },
});

const loading = ref(false);
const exporting = ref(null);
const filterBar = ref(null);
const currency = computed(() => props.context.currency);

const page = usePage();
watch(
    () => page.props.flash?.download,
    (url) => {
        if (url) window.location.href = url;
    },
    { immediate: true },
);

const exportTable = (section) => {
    exporting.value = section;
    router.post(
        route('reports.export', props.report.slug),
        { section, filters: filterBar.value?.query ?? {} },
        { preserveScroll: true, preserveState: true, onFinish: () => (exporting.value = null) },
    );
};

const pending = computed(() => props.exports.some((e) => ['queued', 'processing'].includes(e.status)));
let poll = null;
watch(
    pending,
    (p) => {
        clearInterval(poll);
        if (p) poll = setInterval(() => router.reload({ only: ['exports'] }), 5000);
    },
    { immediate: true },
);

const reportTitle = (slug) => props.reports.find((r) => r.slug === slug)?.title ?? slug;
</script>

<template>
    <AppLayout :title="report.title">
        <PageHeader :title="report.title" :subtitle="`${context.scope} · ${report.description}`">
            <template #breadcrumb><Link :href="route('reports.index')" class="hover:text-slate-700">Reports</Link> /</template>
            <template #actions>
                <select class="form-input w-full sm:w-56" aria-label="Switch report" :value="report.slug" @change="router.get(route('reports.show', $event.target.value), filterBar?.query ?? {})">
                    <option v-for="r in reports" :key="r.slug" :value="r.slug">{{ r.title }}</option>
                </select>
            </template>
        </PageHeader>

        <ReportFilterBar ref="filterBar" :slug="report.slug" :filters="filters" :options="options" :available="report.filters" @loading="loading = $event" />

        <p v-if="comparison && report.filters.includes('compare')" class="-mt-2 mb-3 text-2xs text-slate-500">Compared with {{ comparison.from }} → {{ comparison.to }}.</p>

        <div v-if="loading" class="panel mb-4"><UiSkeleton :lines="6" /></div>

        <div :class="loading ? 'pointer-events-none opacity-50' : ''" class="space-y-5">
            <template v-for="section in sections" :key="section.key">
                <section v-if="section.type === 'kpis'" :aria-label="section.title">
                    <h2 class="mb-2 section-label">{{ section.title }}</h2>
                    <KpiGrid :items="section.items" :compare="section.compare" :currency="currency" />
                    <p v-if="section.note" class="mt-2 text-2xs text-slate-500">{{ section.note }}</p>
                </section>

                <section v-else-if="section.type === 'chart'" class="panel p-5" :aria-label="section.title">
                    <h2 class="panel-title mb-3">{{ section.title }}</h2>
                    <p v-if="section.empty" class="py-10 text-center text-xs text-slate-500">Nothing to chart for the selected filters.</p>
                    <ReportChart v-else :chart="section.chart" :labels="section.labels" :datasets="section.datasets" :format="section.format" :currency="currency" />
                    <p v-if="section.note" class="mt-2 text-2xs text-slate-500">{{ section.note }}</p>
                </section>

                <section v-else-if="section.type === 'table'" class="panel" :aria-label="section.title">
                    <div class="flex min-h-[52px] flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-3">
                        <h2 class="panel-title">{{ section.title }}</h2>
                        <div class="flex items-center gap-2">
                            <Link v-if="section.more" :href="section.more" class="text-xs text-brand-700 hover:underline">Full report →</Link>
                            <UiButton
                                v-if="context.can_export && section.exportable && section.rows.length"
                                size="sm"
                                variant="secondary"
                                icon="download"
                                :loading="exporting === section.key"
                                @click="exportTable(section.key)"
                            >
                                Export CSV
                            </UiButton>
                        </div>
                    </div>
                    <ReportTable :columns="section.columns" :rows="section.rows" :totals="section.totals" :currency="currency" :empty="section.empty" />
                    <p v-if="section.note" class="border-t border-slate-100 px-5 py-3 text-2xs text-slate-500">{{ section.note }}</p>
                </section>

                <section v-else-if="section.type === 'note'" class="rounded-xl border border-slate-200/70 bg-white p-4 text-xs text-slate-600">
                    <strong class="text-slate-700">{{ section.title }}.</strong> {{ section.text }}
                </section>
            </template>
        </div>

        <section v-if="context.can_export && exports.length" class="panel mt-6" aria-label="My exports">
            <h2 class="border-b border-slate-200 px-4 py-2 text-sm font-semibold text-slate-800">My exports</h2>
            <ul class="divide-y divide-slate-100 text-xs">
                <li v-for="e in exports" :key="e.uuid" class="flex flex-wrap items-center justify-between gap-2 px-4 py-2">
                    <span class="text-slate-700">{{ reportTitle(e.report) }} · {{ e.section.replaceAll('_', ' ') }} · {{ e.row_count ?? '?' }} rows</span>
                    <a v-if="e.download_url" :href="e.download_url" class="font-medium text-brand-700 hover:underline">Download</a>
                    <span v-else class="capitalize text-slate-500">{{ e.status }}</span>
                </li>
            </ul>
            <p class="border-t border-slate-100 px-5 py-3 text-2xs text-slate-500">Files are private to you and expire automatically.</p>
        </section>

        <p class="mt-6 text-2xs text-slate-400">Generated {{ context.generated_at }} ({{ context.timezone }}). Figures are read-only and computed on the server.</p>
    </AppLayout>
</template>
