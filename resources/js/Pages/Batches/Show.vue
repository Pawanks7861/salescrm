<script setup>
import AddLeadsModal from '@/Components/batches/AddLeadsModal.vue';
import AddTrainersModal from '@/Components/batches/AddTrainersModal.vue';
import BatchStatusBadge from '@/Components/batches/BatchStatusBadge.vue';
import AppIcon from '@/Components/ui/AppIcon.vue';
import Avatar from '@/Components/ui/Avatar.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import FilterBar from '@/Components/ui/FilterBar.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import SearchInput from '@/Components/ui/SearchInput.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import UiPagination from '@/Components/ui/UiPagination.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { useFilters } from '@/Composables/useFilters';
import AppLayout from '@/Layouts/AppLayout.vue';
import { batchPhase } from '@/utils/batches';
import { crmParts, formatCalendarDate, formatDate, formatDateTime, formatDue } from '@/utils/format';
import { Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    batch: Object,
    summary: Object,
    leads: Object,
    filters: Object,
    options: Object,
    can: Object,
});

const keys = ['search', 'status', 'source', 'assignee', 'created_from', 'created_to', 'followup', 'per_page'];
const { filters, reset } = useFilters(Object.fromEntries(keys.map((k) => [k, props.filters[k] ?? ''])), route('batches.show', props.batch.id));
const activeCount = computed(() => keys.filter((k) => k !== 'per_page' && filters[k] !== '' && filters[k] !== null).length);

const phase = computed(() => batchPhase(props.batch.start_date, props.batch.end_date, crmParts().date));

const statusTone = (s) => (s.is_won ? 'green' : s.is_lost ? 'red' : 'slate');

const selected = ref([]);
watch(
    () => props.leads,
    () => (selected.value = []),
);
const allSelected = computed(() => props.leads.data.length > 0 && props.leads.data.every((l) => selected.value.includes(l.id)));
const toggleAll = () => (selected.value = allSelected.value ? [] : props.leads.data.map((l) => l.id));

const { confirm } = useConfirm();
const removeOne = async (lead) => {
    const ok = await confirm({
        title: `Remove this Lead from "${props.batch.name}"?`,
        message: `${lead.lead_number} · ${lead.full_name}. The Lead itself will not be deleted, and its owner, status, follow-ups and meetings stay as they are.`,
        confirmText: 'Remove from batch',
        danger: true,
    });
    if (ok) router.delete(route('batches.leads.destroy', [props.batch.id, lead.id]), { preserveScroll: true });
};
const removeSelected = async () => {
    const count = selected.value.length;
    const ok = await confirm({
        title: `Remove ${count} lead${count === 1 ? '' : 's'} from "${props.batch.name}"?`,
        message: 'Only the batch membership is removed. The Leads themselves will not be deleted or changed.',
        confirmText: 'Remove from batch',
        danger: true,
    });
    if (ok) router.delete(route('batches.leads.bulk-destroy', props.batch.id), { data: { lead_ids: selected.value }, preserveScroll: true });
};

const archive = async () => {
    if (await confirm({ title: `Archive "${props.batch.name}"?`, message: 'The batch stays viewable with all its leads, but no new leads can be added.', confirmText: 'Archive' })) {
        router.post(route('batches.archive', props.batch.id), {}, { preserveScroll: true });
    }
};
const restore = () => router.post(route('batches.restore', props.batch.id), {}, { preserveScroll: true });
const destroy = async () => {
    const ok = await confirm({
        title: `Delete "${props.batch.name}"?`,
        message: 'The batch and its lead memberships are removed. The leads themselves are not deleted or changed.',
        confirmText: 'Delete batch',
        danger: true,
    });
    if (ok) router.delete(route('batches.destroy', props.batch.id));
};

const addingLeads = ref(false);
const addingTrainers = ref(false);
const removeTrainer = async (trainer) => {
    const ok = await confirm({
        title: `Remove ${trainer.name} from this Batch?`,
        message: 'This will only remove the Trainer assignment. No Leads or other Batch data will be changed.',
        confirmText: 'Remove trainer',
        danger: true,
    });
    if (ok) router.delete(route('batches.trainers.destroy', [props.batch.id, trainer.id]), { preserveScroll: true });
};
const isOverdue = (value) => value && new Date(value) < new Date();
</script>

<template>
    <AppLayout :title="`${batch.batch_number} · ${batch.name}`">
        <PageHeader :title="batch.name">
            <template #breadcrumb>
                <Link :href="route('batches.index')" class="hover:text-slate-700">Batches</Link> / <span class="font-mono">{{ batch.batch_number }}</span>
            </template>
            <template #actions>
                <UiButton v-if="can.update" variant="secondary" icon="edit" :href="route('batches.edit', batch.id)">Edit</UiButton>
                <UiButton v-if="can.addLeads" icon="plus" @click="addingLeads = true">Add Leads</UiButton>
                <UiButton v-if="can.archive" variant="ghost" icon="archive" @click="archive">Archive</UiButton>
                <UiButton v-if="can.restore" variant="secondary" icon="restore" @click="restore">Restore</UiButton>
                <UiButton v-if="can.delete" variant="ghost" icon="trash" @click="destroy">Delete</UiButton>
            </template>
        </PageHeader>

        <div v-if="batch.status.value === 'archived'" class="mb-3 flex items-center gap-2 rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-xs text-slate-700">
            This batch is archived. It stays viewable, but no new leads or trainers can be added.
        </div>

        <div class="panel mb-5 grid grid-cols-2 divide-slate-100 sm:grid-cols-3 lg:grid-cols-6 lg:divide-x [&>div]:px-5 [&>div]:py-4">
            <div>
                <p class="section-label">Batch number</p>
                <p class="mt-1.5 font-mono text-sm font-semibold text-slate-900">{{ batch.batch_number }}</p>
            </div>
            <div>
                <p class="section-label">Status</p>
                <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                    <BatchStatusBadge :status="batch.status" />
                    <UiBadge v-if="phase" :color="phase.color" title="Based on the batch dates; does not change the status">{{ phase.label }}</UiBadge>
                </div>
            </div>
            <div>
                <p class="section-label">Start date</p>
                <p class="mt-1.5 text-sm font-medium text-slate-900">{{ formatCalendarDate(batch.start_date) }}</p>
            </div>
            <div>
                <p class="section-label">End date</p>
                <p class="mt-1.5 text-sm font-medium text-slate-900">{{ formatCalendarDate(batch.end_date) }}</p>
            </div>
            <div>
                <p class="section-label">Created by</p>
                <p class="mt-1.5 truncate text-sm font-medium text-slate-900">{{ batch.creator?.name ?? '—' }}</p>
            </div>
            <div>
                <p class="section-label">Created</p>
                <p class="mt-1.5 text-sm font-medium text-slate-900" :title="formatDateTime(batch.created_at)">{{ formatDate(batch.created_at) }}</p>
            </div>
            <div class="col-span-2 border-t border-slate-100 sm:col-span-3 lg:col-span-6 lg:border-l-0" data-testid="batch-trainers">
                <div class="flex items-center justify-between gap-2">
                    <p class="section-label">{{ batch.trainers.length === 1 ? 'Trainer' : 'Trainers' }}</p>
                    <UiButton v-if="can.addTrainers" size="sm" variant="ghost" icon="plus" @click="addingTrainers = true">Add Trainer</UiButton>
                </div>
                <div v-if="batch.trainers.length" class="mt-1.5 flex flex-wrap gap-2">
                    <span v-for="t in batch.trainers" :key="t.id" class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white py-1 pl-1 pr-2 text-sm">
                        <Avatar :name="t.name" size="xs" />
                        <span class="font-medium text-slate-900">{{ t.name }}</span>
                        <UiBadge v-if="!t.active" color="slate">Inactive</UiBadge>
                        <button v-if="can.removeTrainers" type="button" class="rounded-full p-0.5 text-slate-400 hover:bg-slate-100 hover:text-red-600" :aria-label="`Remove ${t.name}`" @click="removeTrainer(t)">
                            <AppIcon name="close" class="h-3.5 w-3.5" />
                        </button>
                    </span>
                </div>
                <p v-else class="mt-1.5 text-sm text-slate-500">Not assigned</p>
            </div>
            <div v-if="batch.description" class="col-span-2 border-t border-slate-100 sm:col-span-3 lg:col-span-6 lg:border-l-0">
                <p class="section-label">Description</p>
                <p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ batch.description }}</p>
            </div>
        </div>

        <div class="mb-5 flex flex-wrap gap-2">
            <div class="panel min-w-[110px] px-4 py-3">
                <p class="text-2xs font-semibold uppercase tracking-[0.08em] text-slate-400">Total Leads</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900">{{ summary.total.toLocaleString('en-IN') }}</p>
            </div>
            <button
                v-for="s in summary.statuses"
                :key="s.id"
                type="button"
                class="panel min-w-[110px] px-4 py-3 text-left transition hover:border-slate-300"
                :class="{ 'ring-2 ring-brand-500': String(filters.status) === String(s.id) }"
                :title="`Show only ${s.name} leads`"
                @click="filters.status = String(filters.status) === String(s.id) ? '' : s.id"
            >
                <UiBadge :color="s.color || statusTone(s)" dot>{{ s.name }}</UiBadge>
                <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900">{{ s.count.toLocaleString('en-IN') }}</p>
            </button>
        </div>

        <div class="panel">
            <FilterBar bare :active-count="activeCount" @clear="reset">
                <div class="flex flex-wrap items-center gap-2">
                    <SearchInput v-model="filters.search" placeholder="Lead no., phone, name, email…" class="w-full sm:w-64" />
                    <select v-model="filters.status" class="form-input w-36" aria-label="Lead status">
                        <option value="">All statuses</option>
                        <option v-for="s in options.statuses" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                    <select v-model="filters.source" class="form-input w-32" aria-label="Lead source">
                        <option value="">All sources</option>
                        <option v-for="s in options.sources" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                    <select v-if="can.filterByUser" v-model="filters.assignee" class="form-input w-36" aria-label="Lead owner">
                        <option value="">Any owner</option>
                        <option value="unassigned">Unassigned</option>
                        <option v-for="u in options.users" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                    <select v-model="filters.followup" class="form-input w-40" aria-label="Next follow-up">
                        <option value="">Any follow-up</option>
                        <option value="overdue">Overdue</option>
                        <option value="today">Due today</option>
                        <option value="upcoming">Upcoming</option>
                        <option value="none">No follow-up</option>
                    </select>
                    <label class="flex items-center gap-1 text-xs text-slate-500">Created from <input v-model="filters.created_from" type="date" class="form-input w-36" /></label>
                    <label class="flex items-center gap-1 text-xs text-slate-500">to <input v-model="filters.created_to" type="date" class="form-input w-36" /></label>
                    <button v-if="activeCount" class="h-10 rounded-lg px-2 text-xs text-slate-500 hover:text-slate-800" @click="reset">Clear ({{ activeCount }})</button>
                    <select v-model="filters.per_page" class="form-input ml-auto w-28" aria-label="Rows per page">
                        <option value="">25 / page</option>
                        <option value="50">50 / page</option>
                        <option value="100">100 / page</option>
                    </select>
                </div>
            </FilterBar>

            <div v-if="can.removeLeads && selected.length" class="flex flex-wrap items-center gap-3 border-b border-slate-100 bg-brand-50/60 px-5 py-2.5 text-xs">
                <span class="font-semibold text-slate-800">{{ selected.length }} selected</span>
                <UiButton size="sm" variant="danger" icon="close" @click="removeSelected">Remove From Batch</UiButton>
                <button type="button" class="text-slate-500 hover:text-slate-800" @click="selected = []">Clear</button>
            </div>

            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th v-if="can.removeLeads" class="w-8"><input type="checkbox" class="rounded border-slate-300 text-brand-600" :checked="allSelected" :disabled="!leads.data.length" aria-label="Select all leads on this page" @change="toggleAll" /></th>
                            <th>Lead Number</th>
                            <th>Lead Name</th>
                            <th>Company</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Source</th>
                            <th>Owner</th>
                            <th>Next Follow-up</th>
                            <th>Added</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="lead in leads.data" :key="lead.id">
                            <td v-if="can.removeLeads"><input v-model="selected" type="checkbox" :value="lead.id" class="rounded border-slate-300 text-brand-600" :aria-label="`Select ${lead.lead_number}`" /></td>
                            <td class="whitespace-nowrap font-mono text-xs"><Link :href="route('leads.show', lead.id)" class="hover:text-brand-600">{{ lead.lead_number }}</Link></td>
                            <td><Link :href="route('leads.show', lead.id)" class="block max-w-[200px] truncate font-semibold text-slate-900 hover:text-brand-700">{{ lead.full_name }}</Link></td>
                            <td class="max-w-[160px] truncate text-xs">{{ lead.company_name ?? '—' }}</td>
                            <td class="whitespace-nowrap text-xs">{{ lead.phone ?? '—' }}</td>
                            <td class="max-w-[180px] truncate text-xs">{{ lead.email ?? '—' }}</td>
                            <td><UiBadge v-if="lead.status" :color="lead.status.color" dot>{{ lead.status.name }}</UiBadge></td>
                            <td class="text-xs">{{ lead.source?.name ?? '—' }}</td>
                            <td class="text-xs">
                                <span v-if="lead.assignee" class="truncate">{{ lead.assignee.name }}</span>
                                <UiBadge v-else color="amber">Unassigned</UiBadge>
                            </td>
                            <td class="whitespace-nowrap text-xs" :class="{ 'font-medium text-red-600': isOverdue(lead.next_followup_at) }">{{ lead.next_followup_at ? formatDue(lead.next_followup_at) : '—' }}</td>
                            <td class="whitespace-nowrap text-xs text-slate-500" :title="formatDateTime(lead.added_at)">{{ formatDate(lead.added_at) }}</td>
                            <td class="text-right">
                                <div class="flex justify-end gap-1">
                                    <UiButton size="sm" variant="ghost" icon="eye" :href="route('leads.show', lead.id)">View Lead</UiButton>
                                    <UiButton v-if="can.removeLeads" size="sm" variant="ghost" icon="close" @click="removeOne(lead)">Remove</UiButton>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState
                    v-if="!leads.data.length"
                    icon="users"
                    :title="activeCount ? 'No leads match these filters' : 'No Leads in this Batch'"
                    :description="activeCount ? '' : 'Only leads you can access are shown here.'"
                >
                    <UiButton v-if="can.addLeads && !activeCount" icon="plus" @click="addingLeads = true">Add Leads</UiButton>
                </EmptyState>
            </div>
            <UiPagination :paginator="leads" />
        </div>

        <AddLeadsModal v-if="can.addLeads" :show="addingLeads" :batch="batch" :statuses="options.activeStatuses" :sources="options.sources" @close="addingLeads = false" />
        <AddTrainersModal v-if="can.addTrainers" :show="addingTrainers" :batch="batch" @close="addingTrainers = false" />
    </AppLayout>
</template>
