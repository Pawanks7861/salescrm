<script setup>
import BatchStatusBadge from '@/Components/batches/BatchStatusBadge.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import SearchInput from '@/Components/ui/SearchInput.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import UiPagination from '@/Components/ui/UiPagination.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { useFilters } from '@/Composables/useFilters';
import AppLayout from '@/Layouts/AppLayout.vue';
import { batchPhase, trainerSummary } from '@/utils/batches';
import { crmParts, formatCalendarDate, formatDate, formatDateTime } from '@/utils/format';
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    batches: Object,
    filters: Object,
    statuses: Array,
    trainerOptions: { type: Array, default: () => [] },
    isTrainer: { type: Boolean, default: false },
    can: Object,
});

const keys = ['search', 'status', 'trainer', 'start_from', 'start_to', 'end_from', 'end_to'];
const { filters, reset } = useFilters(Object.fromEntries(keys.map((k) => [k, props.filters[k] ?? ''])), route('batches.index'));
const filtered = computed(() => keys.some((k) => Boolean(filters[k])));
const today = crmParts().date;
const phases = computed(() => Object.fromEntries(props.batches.data.map((b) => [b.id, batchPhase(b.start_date, b.end_date, today)])));
const trainerCells = computed(() => Object.fromEntries(props.batches.data.map((b) => [b.id, trainerSummary(b.trainers)])));

const { confirm } = useConfirm();
const archive = async (batch) => {
    const ok = await confirm({
        title: `Archive "${batch.name}"?`,
        message: 'The batch stays viewable with all its leads, but no new leads can be added and it is hidden from "Add to batch" pickers.',
        confirmText: 'Archive',
    });
    if (ok) router.post(route('batches.archive', batch.id), {}, { preserveScroll: true });
};
const restore = (batch) => router.post(route('batches.restore', batch.id), {}, { preserveScroll: true });
const destroy = async (batch) => {
    const ok = await confirm({
        title: `Delete "${batch.name}"?`,
        message: 'The batch and its lead memberships are removed. The leads themselves, their owners, follow-ups and meetings are not deleted or changed.',
        confirmText: 'Delete batch',
        danger: true,
    });
    if (ok) router.delete(route('batches.destroy', batch.id), { preserveScroll: true });
};
</script>

<template>
    <AppLayout title="Batches">
        <PageHeader title="Batches" :subtitle="`${batches.total.toLocaleString('en-IN')} batch${batches.total === 1 ? '' : 'es'}`">
            <template #breadcrumb><Link :href="route('leads.index')" class="hover:text-slate-700">Leads</Link> / Batches</template>
            <template #actions>
                <UiButton v-if="can.create" icon="plus" :href="route('batches.create')">Create Batch</UiButton>
            </template>
        </PageHeader>

        <div class="panel">
            <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-4">
                <SearchInput v-model="filters.search" placeholder="Batch name or number…" class="w-full sm:w-72" />
                <select v-model="filters.status" class="form-input w-36" aria-label="Status">
                    <option value="">Any status</option>
                    <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>
                <select v-if="isTrainer || trainerOptions.length" v-model="filters.trainer" class="form-input w-40" aria-label="Trainer">
                    <option value="">Any trainer</option>
                    <option v-if="isTrainer" value="me">My batches</option>
                    <option v-for="t in trainerOptions" :key="t.id" :value="String(t.id)">{{ t.name }}</option>
                </select>
                <label class="flex items-center gap-1 text-xs text-slate-500">Start <input v-model="filters.start_from" type="date" class="form-input w-36" aria-label="Start date from" /></label>
                <label class="flex items-center gap-1 text-xs text-slate-500">to <input v-model="filters.start_to" type="date" class="form-input w-36" aria-label="Start date to" /></label>
                <label class="flex items-center gap-1 text-xs text-slate-500">End <input v-model="filters.end_from" type="date" class="form-input w-36" aria-label="End date from" /></label>
                <label class="flex items-center gap-1 text-xs text-slate-500">to <input v-model="filters.end_to" type="date" class="form-input w-36" aria-label="End date to" /></label>
                <button v-if="filtered" class="text-xs text-slate-500 hover:text-slate-700" @click="reset">Clear</button>
            </div>

            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Batch No.</th>
                            <th>Batch Name</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Trainer</th>
                            <th class="text-right">Leads</th>
                            <th>Status</th>
                            <th>Created By</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="batch in batches.data" :key="batch.id" :class="{ 'opacity-70': batch.status.value === 'archived' }">
                            <td class="whitespace-nowrap font-mono text-xs"><Link :href="route('batches.show', batch.id)" class="hover:text-brand-600">{{ batch.batch_number }}</Link></td>
                            <td>
                                <Link :href="route('batches.show', batch.id)" class="block max-w-[240px] truncate font-semibold text-slate-900 hover:text-brand-700">{{ batch.name }}</Link>
                                <p v-if="batch.description" class="max-w-[240px] truncate text-2xs text-slate-500" :title="batch.description">{{ batch.description }}</p>
                            </td>
                            <td class="whitespace-nowrap text-xs">{{ formatCalendarDate(batch.start_date) }}</td>
                            <td class="whitespace-nowrap text-xs">{{ formatCalendarDate(batch.end_date) }}</td>
                            <td class="text-xs" :title="trainerCells[batch.id].title || undefined" data-testid="trainer-cell">
                                <template v-if="trainerCells[batch.id].first">
                                    <span class="inline-block max-w-[140px] truncate align-middle">{{ trainerCells[batch.id].first }}</span>
                                    <span v-if="trainerCells[batch.id].more" class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-2xs font-medium text-slate-600">+{{ trainerCells[batch.id].more }}</span>
                                </template>
                                <span v-else class="text-slate-400">—</span>
                            </td>
                            <td class="text-right font-medium tabular-nums">{{ batch.leads_count.toLocaleString('en-IN') }}</td>
                            <td>
                                <div class="flex flex-col items-start gap-1">
                                    <BatchStatusBadge :status="batch.status" />
                                    <span v-if="phases[batch.id]" class="text-2xs text-slate-500" title="Based on the batch dates; does not change the status">{{ phases[batch.id].label }}</span>
                                </div>
                            </td>
                            <td class="text-xs">
                                {{ batch.creator?.name ?? '—' }}
                                <p class="whitespace-nowrap text-2xs text-slate-400" :title="formatDateTime(batch.created_at)">{{ formatDate(batch.created_at) }}</p>
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-1">
                                    <UiButton size="sm" variant="ghost" icon="eye" :href="route('batches.show', batch.id)">View</UiButton>
                                    <UiButton v-if="batch.can.update" size="sm" variant="ghost" icon="edit" :href="route('batches.edit', batch.id)">Edit</UiButton>
                                    <UiButton v-if="batch.can.archive" size="sm" variant="ghost" icon="archive" @click="archive(batch)">Archive</UiButton>
                                    <UiButton v-if="batch.can.restore" size="sm" variant="ghost" icon="restore" @click="restore(batch)">Restore</UiButton>
                                    <UiButton v-if="batch.can.delete" size="sm" variant="ghost" icon="trash" @click="destroy(batch)">Delete</UiButton>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState
                    v-if="!batches.data.length"
                    icon="stack"
                    :title="filtered ? 'No batches match these filters' : 'No batches yet'"
                    :description="filtered ? '' : 'Create batches to organise Leads into campaigns, groups or operational lists.'"
                >
                    <UiButton v-if="can.create && !filtered" icon="plus" :href="route('batches.create')">Create Batch</UiButton>
                </EmptyState>
            </div>
            <UiPagination :paginator="batches" />
        </div>
    </AppLayout>
</template>
