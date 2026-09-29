<script setup>
import Modal from '@/Components/Modal.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import UiPagination from '@/Components/ui/UiPagination.vue';
import { useFilters } from '@/Composables/useFilters';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDateTime } from '@/utils/format';
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    events: Object,
    filters: Object,
    statuses: Array,
    categories: Array,
    pages: Array,
    forms: Array,
    counts: Object,
});

const { filters, reset } = useFilters(
    {
        status: props.filters.status ?? '',
        category: props.filters.category ?? '',
        fb_page: props.filters.fb_page ?? '',
        fb_form: props.filters.fb_form ?? '',
        search: props.filters.search ?? '',
        from: props.filters.from ?? '',
        to: props.filters.to ?? '',
    },
    route('admin.integrations.facebook.events.index'),
);

const detail = ref(null);
const retrying = ref(null);
const retry = (event) => {
    retrying.value = event.id;
    router.post(route('admin.integrations.facebook.events.retry', event.id), {}, { preserveScroll: true, onFinish: () => (retrying.value = null), onSuccess: () => (detail.value = null) });
};
const reasonLabel = (reason) => (reason ? reason.replaceAll('_', ' ') : '');
</script>

<template>
    <AppLayout title="Meta Webhook Events">
        <PageHeader title="Webhook events" subtitle="One row per Meta lead (leadgen_id). Contains Meta ids and processing state only — never lead answers or tokens.">
            <template #breadcrumb>
                <Link :href="route('admin.integrations.facebook.index')" class="hover:underline">Facebook integration</Link> / Events
            </template>
        </PageHeader>

        <div class="mb-3 flex flex-wrap gap-1.5">
            <button
                v-for="s in statuses"
                :key="s.value"
                class="rounded-full border px-2.5 py-0.5 text-xs"
                :class="filters.status === s.value ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'"
                @click="filters.status = filters.status === s.value ? '' : s.value"
            >
                {{ s.label }} <span class="text-slate-400">{{ counts[s.value] ?? 0 }}</span>
            </button>
        </div>

        <div class="panel">
            <div class="flex flex-wrap items-end gap-2 border-b border-slate-200 p-3">
                <input v-model="filters.search" class="form-input w-44 py-1 text-xs" placeholder="leadgen_id" inputmode="numeric" />
                <select v-model="filters.category" class="form-input w-40 py-1 text-xs">
                    <option value="">Any error category</option>
                    <option v-for="c in categories" :key="c.value" :value="c.value">{{ c.label }}</option>
                </select>
                <select v-model="filters.fb_page" class="form-input w-44 py-1 text-xs">
                    <option value="">All Pages</option>
                    <option v-for="p in pages" :key="p.page_id" :value="p.page_id">{{ p.page_name }}</option>
                </select>
                <select v-model="filters.fb_form" class="form-input w-44 py-1 text-xs">
                    <option value="">All forms</option>
                    <option v-for="f in forms" :key="f.form_id" :value="f.form_id">{{ f.form_name }}</option>
                </select>
                <input v-model="filters.from" type="date" class="form-input w-36 py-1 text-xs" title="Received from" />
                <input v-model="filters.to" type="date" class="form-input w-36 py-1 text-xs" title="Received to" />
                <UiButton size="sm" variant="ghost" @click="reset">Clear</UiButton>
            </div>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Received</th>
                            <th>leadgen_id</th>
                            <th>Page / form</th>
                            <th>Status</th>
                            <th>Attempts</th>
                            <th>Lead</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="e in events.data" :key="e.id">
                            <td class="whitespace-nowrap text-xs">{{ formatDateTime(e.received_at) }}<div class="text-2xs text-slate-400">{{ e.origin }}</div></td>
                            <td class="font-mono text-xs">{{ e.leadgen_id }}</td>
                            <td class="text-xs">
                                <div class="truncate">{{ e.page_name || e.page_id }}</div>
                                <div class="truncate text-2xs text-slate-500">{{ e.form_name || e.form_id || '—' }}</div>
                            </td>
                            <td>
                                <UiBadge :color="e.status_color">{{ e.status_label }}</UiBadge>
                                <div v-if="e.category" class="mt-0.5 text-2xs text-red-600">{{ e.category.replaceAll('_', ' ') }}</div>
                                <div v-else-if="e.reason" class="mt-0.5 text-2xs text-slate-500">{{ reasonLabel(e.reason) }}</div>
                                <div v-else-if="e.outcome" class="mt-0.5 text-2xs text-slate-500">{{ e.outcome }}</div>
                            </td>
                            <td class="text-xs">{{ e.attempts }}<span v-if="e.deliveries > 1" class="text-slate-400"> · {{ e.deliveries }} deliveries</span></td>
                            <td class="text-xs">
                                <Link v-if="e.lead" :href="e.lead.url" class="text-brand-600 hover:underline">ID {{ e.lead.id }} · {{ e.lead.lead_number }}</Link>
                                <span v-else class="text-slate-400">—</span>
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-1">
                                    <UiButton size="sm" variant="ghost" icon="eye" @click="detail = e">Details</UiButton>
                                    <UiButton v-if="e.can_retry" size="sm" variant="secondary" icon="refresh" :loading="retrying === e.id" @click="retry(e)">Retry</UiButton>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-if="!events.data.length" icon="inbox" title="No events" description="Webhook deliveries and synced leads will appear here." />
            </div>
            <UiPagination :paginator="events" />
        </div>

        <Modal :show="!!detail" max-width="lg" @close="detail = null">
            <div v-if="detail">
                <div class="flex items-center justify-between modal-header">
                    <h3 class="text-sm font-semibold">Event {{ detail.leadgen_id }}</h3>
                    <UiBadge :color="detail.status_color">{{ detail.status_label }}</UiBadge>
                </div>
                <dl class="grid grid-cols-[140px_1fr] gap-x-3 gap-y-1.5 p-5 text-xs">
                    <dt class="text-slate-500">Origin</dt><dd>{{ detail.origin }}</dd>
                    <dt class="text-slate-500">Page</dt><dd>{{ detail.page_name || '—' }} <span class="font-mono text-slate-400">{{ detail.page_id }}</span></dd>
                    <dt class="text-slate-500">Form</dt><dd>{{ detail.form_name || '—' }} <span class="font-mono text-slate-400">{{ detail.form_id }}</span></dd>
                    <dt class="text-slate-500">Ad</dt><dd class="font-mono">{{ detail.ad_id || '—' }}</dd>
                    <dt class="text-slate-500">Submitted (Meta)</dt><dd>{{ detail.meta_created_at ? formatDateTime(detail.meta_created_at) : '—' }}</dd>
                    <dt class="text-slate-500">Received</dt><dd>{{ formatDateTime(detail.received_at) }}</dd>
                    <dt class="text-slate-500">Processed</dt><dd>{{ detail.processed_at ? formatDateTime(detail.processed_at) : '—' }}</dd>
                    <dt class="text-slate-500">Attempts / deliveries</dt><dd>{{ detail.attempts }} / {{ detail.deliveries }}</dd>
                    <template v-if="detail.next_attempt_at"><dt class="text-slate-500">Next attempt</dt><dd>{{ formatDateTime(detail.next_attempt_at) }}</dd></template>
                    <template v-if="detail.outcome"><dt class="text-slate-500">Outcome</dt><dd>{{ detail.outcome }}</dd></template>
                    <template v-if="detail.reason"><dt class="text-slate-500">Reason</dt><dd>{{ reasonLabel(detail.reason) }}</dd></template>
                    <template v-if="detail.category">
                        <dt class="text-slate-500">Error</dt>
                        <dd>
                            <UiBadge color="red">{{ detail.category.replaceAll('_', ' ') }}</UiBadge>
                            <p class="mt-1 text-slate-700">{{ detail.error_message }}</p>
                            <p class="mt-1 text-slate-500">{{ detail.category_help }}</p>
                        </dd>
                    </template>
                    <dt class="text-slate-500">Lead</dt>
                    <dd><Link v-if="detail.lead" :href="detail.lead.url" class="text-brand-600 hover:underline">ID {{ detail.lead.id }} · {{ detail.lead.lead_number }}</Link><span v-else>—</span></dd>
                </dl>
                <div class="modal-footer">
                    <UiButton variant="secondary" @click="detail = null">Close</UiButton>
                    <UiButton v-if="detail.can_retry" icon="refresh" :loading="retrying === detail.id" @click="retry(detail)">Retry</UiButton>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
