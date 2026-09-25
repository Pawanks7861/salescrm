<script setup>
import AppIcon from '@/Components/ui/AppIcon.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    reports: { type: Array, required: true },
    categories: { type: Array, required: true },
    context: { type: Object, required: true },
});

const grouped = computed(() =>
    props.categories.map((c) => ({ name: c, reports: props.reports.filter((r) => r.category === c) })).filter((g) => g.reports.length),
);
</script>

<template>
    <AppLayout title="Reports">
        <PageHeader title="Report centre" :subtitle="`${context.scope} data · every figure follows your access rules`" />

        <EmptyState v-if="!reports.length" icon="chart" title="No reports available" description="Ask an administrator for report access." />

        <div v-for="group in grouped" :key="group.name" class="mb-6">
            <h2 class="mb-3 section-label">{{ group.name }}</h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <Link v-for="r in group.reports" :key="r.slug" :href="route('reports.show', r.slug)" class="panel group flex gap-4 p-5 transition hover:-translate-y-px hover:border-brand-300">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-100 text-brand-600"><AppIcon name="chart" class="h-5 w-5" /></div>
                    <div class="min-w-0">
                        <p class="text-[15px] font-semibold text-slate-900 group-hover:text-brand-600">{{ r.title }}</p>
                        <p class="mt-0.5 text-xs text-slate-500">{{ r.description }}</p>
                    </div>
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
