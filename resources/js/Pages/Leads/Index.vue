<script setup>
import AddToBatchModal from '@/Components/batches/AddToBatchModal.vue';
import PriorityBadge from '@/Components/leads/PriorityBadge.vue';
import AppIcon from '@/Components/ui/AppIcon.vue';
import Avatar from '@/Components/ui/Avatar.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import FilterBar from '@/Components/ui/FilterBar.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import SearchInput from '@/Components/ui/SearchInput.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import UiPagination from '@/Components/ui/UiPagination.vue';
import { useFilters } from '@/Composables/useFilters';
import AppLayout from '@/Layouts/AppLayout.vue';
import { batchTags } from '@/utils/batches';
import { formatCurrency, formatDate, formatDateTime, timeAgo } from '@/utils/format';
import { Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    leads: Object,
    filters: Object,
    options: Object,
    can: Object,
});

const showValue = computed(() => props.leads.data.some((l) => 'estimated_value' in l));

const keys = ['search', 'status', 'source', 'campaign', 'facebook_page', 'facebook_form', 'assignee', 'priority', 'city', 'state', 'on', 'created_from', 'created_to', 'age', 'duplicates', 'archived', 'batch', 'sort', 'direction', 'per_page'];
const { filters, reset } = useFilters(Object.fromEntries(keys.map((k) => [k, props.filters[k] ?? ''])), route('leads.index'));

const advancedKeys = ['campaign', 'facebook_page', 'facebook_form', 'city', 'state', 'created_from', 'created_to', 'duplicates', 'archived'];
const showAdvanced = ref(advancedKeys.some((k) => props.filters[k]));
const activeCount = computed(() => keys.filter((k) => !['sort', 'direction', 'per_page'].includes(k) && filters[k] !== '' && filters[k] !== null).length);

const sortBy = (column) => {
    if (filters.sort === column) {
        filters.direction = filters.direction === 'asc' ? 'desc' : 'asc';
    } else {
        filters.sort = column;
        filters.direction = column === 'full_name' || column === 'lead_number' ? 'asc' : 'desc';
    }
};
const sortIcon = (column) => (filters.sort === column || (!filters.sort && column === 'created_at') ? ((filters.direction || 'desc') === 'asc' ? '↑' : '↓') : '');

const ageClass = (days) => (days <= 1 ? 'text-emerald-600' : days <= 3 ? 'text-slate-600' : days <= 7 ? 'text-amber-600' : 'text-red-600');
const pickDay = (day) => {
    filters.on = filters.on === day ? '' : day;
};
const colSpan = computed(() => 9 + (showValue.value ? 1 : 0) + (props.can.viewBatches ? 1 : 0) + (props.can.addToBatch ? 1 : 0));

const selected = ref([]);
watch(
    () => props.leads,
    () => (selected.value = []),
);
const selectableIds = computed(() => props.leads.data.filter((l) => !l.archived).map((l) => l.id));
const allSelected = computed(() => selectableIds.value.length > 0 && selectableIds.value.every((id) => selected.value.includes(id)));
const toggleAll = () => (selected.value = allSelected.value ? [] : [...selectableIds.value]);
const addingToBatch = ref(false);
const groupedRows = computed(() => {
    const rows = props.leads.data;
    const byDate = !filters.sort || filters.sort === 'created_at';
    const leadRow = (lead) => ({ type: 'lead', lead, batches: batchTags(lead.batches) });
    if (!byDate) return rows.map(leadRow);

    const out = [];
    let last = '';
    for (const lead of rows) {
        const label = formatDate(lead.created_at);
        if (label !== last) {
            out.push({ type: 'date', label });
            last = label;
        }
        out.push(leadRow(lead));
    }
    return out;
});
</script>

<template>
    <AppLayout title="Leads">
        <PageHeader title="Leads" :subtitle="`${leads.total.toLocaleString('en-IN')} lead${leads.total === 1 ? '' : 's'}${filters.archived === 'only' ? ' in archive' : ''}`">
            <template #actions>
                <UiButton variant="secondary" icon="columns" :href="route('leads.pipeline')">Pipeline</UiButton>
                <UiButton v-if="can.create" icon="plus" :href="route('leads.create')">New lead</UiButton>
            </template>
        </PageHeader>

        <div class="panel">
            <FilterBar bare :active-count="activeCount" @clear="reset">
                <div class="flex flex-wrap items-center gap-2">
                    <SearchInput v-model="filters.search" placeholder="Real ID, lead no., phone, name, email…" class="w-full sm:w-72" />
                    <select v-model="filters.status" class="form-input w-36">
                        <option value="">All statuses</option>
                        <option v-for="s in options.statuses" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                    <select v-model="filters.source" class="form-input w-32">
                        <option value="">All sources</option>
                        <option v-for="s in options.sources" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                    <select v-if="can.filterByUser" v-model="filters.assignee" class="form-input w-36">
                        <option value="">Any owner</option>
                        <option value="unassigned">Unassigned</option>
                        <option v-for="u in options.users" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                    <select v-if="can.viewBatches && options.batches?.length" v-model="filters.batch" class="form-input w-40" aria-label="Batch">
                        <option value="">Any batch</option>
                        <option v-for="b in options.batches" :key="b.id" :value="b.id">{{ b.name }}{{ b.archived ? ' (archived)' : '' }}</option>
                    </select>
                    <select v-model="filters.priority" class="form-input w-28">
                        <option value="">Any priority</option>
                        <option v-for="p in options.priorities" :key="p.value" :value="p.value">{{ p.label }}</option>
                    </select>
                    <select v-model="filters.age" class="form-input w-28">
                        <option value="">Any age</option>
                        <option v-for="b in options.ageBuckets" :key="b.value" :value="b.value">{{ b.label }}</option>
                    </select>
                    <label class="flex items-center gap-1 text-xs text-slate-500">Date <input v-model="filters.on" type="date" class="form-input w-36" /></label>
                    <button type="button" class="h-10 rounded-lg px-2.5 text-xs font-medium" :class="filters.on === options.today ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:bg-slate-100'" @click="pickDay(options.today)">Today</button>
                    <button type="button" class="h-10 rounded-lg px-2.5 text-xs font-medium" :class="filters.on === options.yesterday ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:bg-slate-100'" @click="pickDay(options.yesterday)">Yesterday</button>
                    <button class="inline-flex h-10 items-center gap-1.5 rounded-lg px-3 text-xs font-medium text-slate-600 transition hover:bg-slate-100 hover:text-slate-900" :aria-expanded="showAdvanced" @click="showAdvanced = !showAdvanced">
                        <AppIcon name="filter" class="h-4 w-4" /> More filters
                    </button>
                    <button v-if="activeCount" class="h-10 rounded-lg px-2 text-xs text-slate-500 hover:text-slate-800" @click="reset">Clear ({{ activeCount }})</button>
                    <select v-model="filters.per_page" class="form-input ml-auto w-28">
                        <option value="">25 / page</option>
                        <option value="50">50 / page</option>
                        <option value="100">100 / page</option>
                    </select>
                </div>
                <div v-if="showAdvanced" class="flex flex-wrap items-center gap-2">
                    <select v-model="filters.campaign" class="form-input w-44">
                        <option value="">All campaigns</option>
                        <option v-for="c in options.campaigns" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <select v-if="options.facebookPages?.length" v-model="filters.facebook_page" class="form-input w-44">
                        <option value="">All Facebook Pages</option>
                        <option v-for="p in options.facebookPages" :key="p.page_id" :value="p.page_id">{{ p.page_name }}</option>
                    </select>
                    <select v-if="options.facebookForms?.length" v-model="filters.facebook_form" class="form-input w-44">
                        <option value="">All Facebook forms</option>
                        <option v-for="f in options.facebookForms" :key="f.form_id" :value="f.form_id">{{ f.form_name }}</option>
                    </select>
                    <input v-model="filters.city" class="form-input w-28" placeholder="City" />
                    <input v-model="filters.state" class="form-input w-28" placeholder="State" />
                    <label class="flex items-center gap-1 text-xs text-slate-500">From <input v-model="filters.created_from" type="date" class="form-input w-36" /></label>
                    <label class="flex items-center gap-1 text-xs text-slate-500">To <input v-model="filters.created_to" type="date" class="form-input w-36" /></label>
                    <label class="flex items-center gap-1.5 text-xs text-slate-600">
                        <input v-model="filters.duplicates" type="checkbox" true-value="1" false-value="" class="rounded border-slate-300 text-brand-600" /> Duplicates only
                    </label>
                    <label v-if="can.seeArchived" class="flex items-center gap-1.5 text-xs text-slate-600">
                        <input v-model="filters.archived" type="checkbox" true-value="only" false-value="" class="rounded border-slate-300 text-brand-600" /> Archived
                    </label>
                </div>
            </FilterBar>

            <div v-if="can.addToBatch && selected.length" class="flex flex-wrap items-center gap-3 border-b border-slate-100 bg-brand-50/60 px-5 py-2.5 text-xs">
                <span class="font-semibold text-slate-800">{{ selected.length }} selected</span>
                <UiButton size="sm" icon="stack" @click="addingToBatch = true">Add to Batch</UiButton>
                <button type="button" class="text-slate-500 hover:text-slate-800" @click="selected = []">Clear</button>
            </div>

            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th v-if="can.addToBatch" class="w-8">
                                <input type="checkbox" class="rounded border-slate-300 text-brand-600" :checked="allSelected" :disabled="!selectableIds.length" aria-label="Select all leads on this page" @change="toggleAll" />
                            </th>
                            <th>
                                <button type="button" class="uppercase tracking-[0.08em] hover:text-slate-700" @click="sortBy('full_name')">Lead {{ sortIcon('full_name') }}</button>
                                <span class="mx-1 text-slate-300">/</span>
                                <button type="button" class="uppercase tracking-[0.08em] hover:text-slate-700" @click="sortBy('lead_number')">No. {{ sortIcon('lead_number') }}</button>
                            </th>
                            <th>Contact</th>
                            <th>Status</th>
                            <th class="cursor-pointer select-none" @click="sortBy('priority')">Priority {{ sortIcon('priority') }}</th>
                            <th>Source</th>
                            <th>Owner</th>
                            <th v-if="can.viewBatches">Batches</th>
                            <th>City</th>
                            <th v-if="showValue" class="text-right">Value</th>
                            <th class="cursor-pointer select-none" @click="sortBy('created_at')">Date {{ sortIcon('created_at') }}</th>
                            <th class="cursor-pointer select-none" @click="sortBy('updated_at')">Updated {{ sortIcon('updated_at') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="row in groupedRows" :key="row.type === 'date' ? `d-${row.label}` : row.lead.id">
                        <tr v-if="row.type === 'date'" class="bg-slate-50">
                            <td :colspan="colSpan" class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ row.label }}</td>
                        </tr>
                        <tr v-else :class="{ 'opacity-60': row.lead.archived }">
                            <td v-if="can.addToBatch">
                                <input v-if="!row.lead.archived" v-model="selected" type="checkbox" :value="row.lead.id" class="rounded border-slate-300 text-brand-600" :aria-label="`Select ${row.lead.lead_number}`" />
                            </td>
                            <td>
                                <div class="flex items-center gap-3">
                                    <Avatar :name="row.lead.full_name" size="md" />
                                    <div class="min-w-0">
                                        <Link :href="route('leads.show', row.lead.id)" class="block max-w-[220px] truncate font-semibold text-slate-900 hover:text-brand-700">{{ row.lead.full_name }}</Link>
                                        <p class="flex items-center gap-1.5 text-2xs text-slate-500">
                                            <Link :href="route('leads.show', row.lead.id)" class="font-mono font-semibold text-slate-700 hover:text-brand-600" :title="`Real ID ${row.lead.id}`">{{ row.lead.id }}</Link>
                                            <span class="text-slate-300">·</span>
                                            <Link :href="route('leads.show', row.lead.id)" class="font-mono hover:text-brand-600">{{ row.lead.lead_number }}</Link>
                                            <span v-if="row.lead.company_name" class="max-w-[140px] truncate">· {{ row.lead.company_name }}</span>
                                            <UiBadge v-if="row.lead.is_duplicate" color="amber">Dup</UiBadge>
                                            <UiBadge v-if="row.lead.archived" color="slate">Archived</UiBadge>
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="text-xs">
                                <p class="whitespace-nowrap">{{ row.lead.phone ?? '—' }}</p>
                                <p v-if="row.lead.email" class="max-w-[180px] truncate text-2xs text-slate-500">{{ row.lead.email }}</p>
                            </td>
                            <td><UiBadge v-if="row.lead.status" :color="row.lead.status.color" dot>{{ row.lead.status.name }}</UiBadge></td>
                            <td><PriorityBadge :priority="row.lead.priority" /></td>
                            <td class="text-xs">
                                {{ row.lead.source?.name ?? '—' }}
                                <p v-if="row.lead.campaign" class="max-w-[140px] truncate text-2xs text-slate-500">{{ row.lead.campaign.name }}</p>
                            </td>
                            <td class="text-xs">
                                <div v-if="row.lead.assignee" class="flex items-center gap-2">
                                    <Avatar :name="row.lead.assignee.name" size="xs" />
                                    <p class="min-w-0 truncate text-slate-800">{{ row.lead.assignee.name }}</p>
                                </div>
                                <UiBadge v-else color="amber">Unassigned</UiBadge>
                            </td>
                            <td v-if="can.viewBatches" class="text-xs">
                                <span v-if="row.batches.shown.length" class="inline-flex max-w-[160px] items-center gap-1">
                                    <span v-for="b in row.batches.shown" :key="b.id" class="truncate rounded bg-slate-100 px-1.5 py-0.5 text-2xs text-slate-700" :title="b.name">{{ b.name }}</span>
                                    <span v-if="row.batches.more" class="shrink-0 cursor-help rounded bg-slate-100 px-1.5 py-0.5 text-2xs font-semibold text-slate-600" :title="row.batches.title">+{{ row.batches.more }}</span>
                                </span>
                                <span v-else class="text-slate-400">—</span>
                            </td>
                            <td class="text-xs">{{ row.lead.city ?? '—' }}</td>
                            <td v-if="showValue" class="whitespace-nowrap text-right text-xs">{{ formatCurrency(row.lead.estimated_value) }}</td>
                            <td class="whitespace-nowrap text-xs" :title="formatDateTime(row.lead.created_at)">
                                <p class="font-medium text-slate-800">{{ formatDate(row.lead.created_at) }}</p>
                                <p class="font-medium" :class="ageClass(row.lead.age_days)">{{ row.lead.age_days }}d</p>
                            </td>
                            <td class="whitespace-nowrap text-xs text-slate-500" :title="formatDateTime(row.lead.updated_at)">{{ timeAgo(row.lead.updated_at) }}</td>
                        </tr>
                        </template>
                    </tbody>
                </table>
                <EmptyState v-if="!leads.data.length" icon="users" :title="activeCount ? 'No leads match these filters' : 'No leads yet'" :description="can.create && !activeCount ? 'Create your first lead to get started.' : ''" />
            </div>
            <UiPagination :paginator="leads" />
        </div>

        <AddToBatchModal v-if="can.addToBatch" :show="addingToBatch" :lead-ids="selected" :can-create="can.createBatch" :can-assign-trainers="can.manageBatchTrainers" @close="addingToBatch = false" @added="selected = []" />
    </AppLayout>
</template>
