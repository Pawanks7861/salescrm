<script setup>
import AddToBatchModal from '@/Components/batches/AddToBatchModal.vue';
import BulkAssignModal from '@/Components/leads/BulkAssignModal.vue';
import BulkCampaignModal from '@/Components/leads/BulkCampaignModal.vue';
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
import { useToast } from '@/Composables/useToast';
import AppLayout from '@/Layouts/AppLayout.vue';
import { batchTags } from '@/utils/batches';
import { crmParts, formatCurrency, formatDate, formatDateTime, formatDayHeading, formatTime, timeAgo } from '@/utils/format';
import { reorderColumns } from '@/utils/leadColumns';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    leads: Object,
    filters: Object,
    options: Object,
    can: Object,
    columnOrder: { type: Array, default: () => [] },
    columnDefault: { type: Array, default: () => [] },
});

const toast = useToast();
const columns = ref([...props.columnOrder]);
watch(() => props.columnOrder, (order) => (columns.value = [...order]));
const columnsCustomized = computed(() => columns.value.join() !== props.columnDefault.join());

const COLUMN_LABEL = {
    lead: 'Lead',
    contact: 'Contact',
    created_at: 'Date',
    status: 'Status',
    priority: 'Priority',
    source: 'Source',
    owner: 'Owner',
    batches: 'Batches',
    city: 'City',
    value: 'Value',
    updated_at: 'Updated',
};
const SORTABLE = { priority: 'priority', created_at: 'created_at', updated_at: 'updated_at' };

const dragging = ref(null);
const dragOver = ref(null);
const startDrag = (key, event) => {
    dragging.value = key;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', key);
};
const endDrag = () => {
    dragging.value = null;
    dragOver.value = null;
};
let savedColumns = [...props.columnOrder];
/** Prefer Ziggy; fall back when the server route cache is stale after deploy. */
const columnsUrl = () => {
    try {
        return route('leads.columns');
    } catch {
        return '/leads/columns';
    }
};
const persistColumns = async (next, { reset = false } = {}) => {
    const previous = savedColumns;
    columns.value = next;
    try {
        const { data } = await axios.put(columnsUrl(), reset ? { reset: true } : { columns: next });
        columns.value = data.columns;
        savedColumns = [...data.columns];
    } catch {
        columns.value = previous;
        toast.error('Could not save the column order.');
    }
};
const dropColumn = (key) => {
    const next = reorderColumns(columns.value, dragging.value, key);
    const changed = next.join() !== columns.value.join();
    endDrag();
    if (changed) persistColumns(next);
};
const resetColumns = () => persistColumns([...props.columnDefault], { reset: true });

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
const sortIcon = (column) => ((filters.sort || 'created_at') === column ? ((filters.direction || 'desc') === 'asc' ? '↑' : '↓') : '');
const sortedByCreated = computed(() => !filters.sort || filters.sort === 'created_at');

const statusDot = {
    slate: 'bg-slate-500', gray: 'bg-slate-500', green: 'bg-emerald-500', emerald: 'bg-emerald-500', red: 'bg-red-500',
    amber: 'bg-amber-500', yellow: 'bg-amber-500', blue: 'bg-sky-500', sky: 'bg-sky-500', cyan: 'bg-cyan-500',
    indigo: 'bg-brand-500', purple: 'bg-purple-500', violet: 'bg-purple-500', orange: 'bg-orange-500', teal: 'bg-teal-500', pink: 'bg-pink-500',
};
const statusActive = (id) => String(filters.status ?? '') === String(id);
const pickStatus = (id) => {
    filters.status = id === '' ? '' : String(id);
};
const ageClass = (days) => (days <= 1 ? 'text-emerald-600' : days <= 3 ? 'text-slate-600' : days <= 7 ? 'text-amber-600' : 'text-red-600');
const pickDay = (day) => {
    filters.on = filters.on === day ? '' : day;
};
const canSelect = computed(() => props.can.addToBatch || props.can.bulkAssign || props.can.bulkCampaign);
const colSpan = computed(() => columns.value.length + (canSelect.value ? 1 : 0));

const selected = ref([]);
watch(
    () => props.leads,
    () => (selected.value = []),
);
const selectableIds = computed(() => props.leads.data.filter((l) => !l.archived).map((l) => l.id));
const allSelected = computed(() => selectableIds.value.length > 0 && selectableIds.value.every((id) => selected.value.includes(id)));
const toggleAll = () => (selected.value = allSelected.value ? [] : [...selectableIds.value]);
const addingToBatch = ref(false);
const assigning = ref(false);
const settingCampaign = ref(false);
const groupedRows = computed(() => {
    const rows = props.leads.data;
    const leadRow = (lead) => ({ type: 'lead', lead, batches: batchTags(lead.batches) });
    if (!sortedByCreated.value) return rows.map(leadRow);

    const out = [];
    let last = '';
    for (const lead of rows) {
        const key = crmParts(lead.created_at).date;
        if (key && key !== last) {
            out.push({ type: 'date', key, label: formatDayHeading(lead.created_at) });
            last = key;
        }
        out.push(leadRow(lead));
    }
    return out;
});
const clearDateSort = () => {
    filters.sort = 'created_at';
    filters.direction = 'desc';
};
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

            <div v-if="canSelect && selected.length" class="flex flex-wrap items-center gap-3 border-b border-slate-100 bg-brand-50/60 px-5 py-2.5 text-xs">
                <span class="font-semibold text-slate-800">{{ selected.length }} selected</span>
                <UiButton v-if="can.bulkAssign" size="sm" icon="switch" data-testid="bulk-assign" @click="assigning = true">Assign</UiButton>
                <UiButton v-if="can.bulkCampaign" size="sm" icon="tag" data-testid="bulk-campaign-open" @click="settingCampaign = true">Campaign</UiButton>
                <UiButton v-if="can.addToBatch" size="sm" icon="stack" @click="addingToBatch = true">Add to Batch</UiButton>
                <button type="button" class="text-slate-500 hover:text-slate-800" @click="selected = []">Clear</button>
            </div>

            <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-1.5 text-2xs text-slate-400">
                <span>Drag a column heading to rearrange. This order is saved for you.</span>
                <button v-if="columnsCustomized" type="button" class="font-medium text-slate-500 hover:text-slate-800" @click="resetColumns">Reset columns</button>
            </div>

            <nav class="scrollbar-none flex gap-1 overflow-x-auto border-b border-slate-100 px-3" aria-label="Status">
                <button type="button" class="tab-btn" :class="statusActive('') ? 'border-brand-500 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-800'" @click="pickStatus('')">All</button>
                <button
                    v-for="s in options.statuses"
                    :key="s.id"
                    type="button"
                    class="tab-btn"
                    :class="statusActive(s.id) ? 'border-brand-500 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-800'"
                    @click="pickStatus(s.id)"
                >
                    <span class="h-1.5 w-1.5 rounded-full" :class="statusDot[s.color] || 'bg-slate-500'" aria-hidden="true" />
                    {{ s.name }}
                </button>
            </nav>

            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th v-if="canSelect" class="w-8">
                                <input type="checkbox" class="rounded border-slate-300 text-brand-600" :checked="allSelected" :disabled="!selectableIds.length" aria-label="Select all leads on this page" @change="toggleAll" />
                            </th>
                            <th
                                v-for="key in columns"
                                :key="key"
                                :class="[key === 'value' ? 'text-right' : '', dragOver === key ? 'bg-brand-50' : '', dragging === key ? 'opacity-50' : '']"
                                @dragover.prevent="dragOver = key"
                                @dragleave="dragOver = dragOver === key ? null : dragOver"
                                @drop.prevent="dropColumn(key)"
                            >
                                <span class="inline-flex items-center gap-1" :class="key === 'value' ? 'justify-end' : ''">
                                    <button
                                        type="button"
                                        class="cursor-grab text-slate-300 hover:text-slate-500 active:cursor-grabbing"
                                        draggable="true"
                                        :aria-label="`Move ${COLUMN_LABEL[key]} column`"
                                        @dragstart="startDrag(key, $event)"
                                        @dragend="endDrag"
                                    >
                                        <AppIcon name="menu" class="h-3.5 w-3.5" />
                                    </button>
                                    <template v-if="key === 'lead'">
                                        <button type="button" class="hover:text-slate-700" @click="sortBy('full_name')">Lead {{ sortIcon('full_name') }}</button>
                                        <span class="text-slate-300">/</span>
                                        <button type="button" class="hover:text-slate-700" @click="sortBy('lead_number')">No. {{ sortIcon('lead_number') }}</button>
                                    </template>
                                    <button v-else-if="SORTABLE[key]" type="button" class="hover:text-slate-700" @click="sortBy(SORTABLE[key])">{{ COLUMN_LABEL[key] }} {{ sortIcon(SORTABLE[key]) }}</button>
                                    <template v-else>{{ COLUMN_LABEL[key] }}</template>
                                </span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!sortedByCreated && leads.data.length" class="bg-amber-50/80">
                            <td :colspan="colSpan" class="text-2xs text-amber-800">
                                Date grouping is off while sorted by another column.
                                <button type="button" class="ml-1 font-semibold underline hover:text-amber-950" @click="clearDateSort">Group by date</button>
                            </td>
                        </tr>
                        <template v-for="row in groupedRows" :key="row.type === 'date' ? `d-${row.key}` : row.lead.id">
                        <tr v-if="row.type === 'date'" class="sticky top-0 z-[1] bg-slate-100/95 backdrop-blur">
                            <td :colspan="colSpan" class="py-2.5 text-sm font-semibold tracking-tight text-slate-800">{{ row.label }}</td>
                        </tr>
                        <tr v-else :class="{ 'opacity-60': row.lead.archived }">
                            <td v-if="canSelect">
                                <input v-if="!row.lead.archived" v-model="selected" type="checkbox" :value="row.lead.id" class="rounded border-slate-300 text-brand-600" :aria-label="`Select ${row.lead.lead_number}`" />
                            </td>
                            <td v-for="key in columns" :key="key" :class="key === 'value' ? 'text-right' : ''">
                                <template v-if="key === 'lead'">
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
                                </template>
                                <template v-else-if="key === 'contact'">
                                    <p>{{ row.lead.phone ?? '—' }}</p>
                                    <p v-if="row.lead.email" class="max-w-[180px] truncate text-2xs text-slate-500">{{ row.lead.email }}</p>
                                </template>
                                <UiBadge v-else-if="key === 'status' && row.lead.status" :color="row.lead.status.color" dot>{{ row.lead.status.name }}</UiBadge>
                                <PriorityBadge v-else-if="key === 'priority'" :priority="row.lead.priority" />
                                <template v-else-if="key === 'source'">
                                    <span class="block max-w-[140px] truncate" :title="row.lead.campaign?.name ?? row.lead.source?.name">{{ row.lead.campaign?.name ?? row.lead.source?.name ?? '—' }}</span>
                                    <p v-if="row.lead.campaign && row.lead.source" class="max-w-[140px] truncate text-2xs text-slate-500">{{ row.lead.source.name }}</p>
                                </template>
                                <template v-else-if="key === 'owner'">
                                    <div v-if="row.lead.assignee" class="flex items-center gap-2">
                                        <Avatar :name="row.lead.assignee.name" size="xs" />
                                        <p class="min-w-0 truncate text-slate-800">{{ row.lead.assignee.name }}</p>
                                    </div>
                                    <UiBadge v-else color="amber">Unassigned</UiBadge>
                                </template>
                                <template v-else-if="key === 'batches'">
                                    <span v-if="row.batches.shown.length" class="inline-flex max-w-[160px] items-center gap-1">
                                        <span v-for="b in row.batches.shown" :key="b.id" class="truncate rounded bg-slate-100 px-1.5 py-0.5 text-2xs text-slate-700" :title="b.name">{{ b.name }}</span>
                                        <span v-if="row.batches.more" class="shrink-0 cursor-help rounded bg-slate-100 px-1.5 py-0.5 text-2xs font-semibold text-slate-600" :title="row.batches.title">+{{ row.batches.more }}</span>
                                    </span>
                                    <span v-else class="text-slate-400">—</span>
                                </template>
                                <template v-else-if="key === 'city'">{{ row.lead.city ?? '—' }}</template>
                                <template v-else-if="key === 'value'">{{ formatCurrency(row.lead.estimated_value) }}</template>
                                <span v-else-if="key === 'created_at'" :title="formatDateTime(row.lead.created_at)">
                                    <p class="font-medium text-slate-800">{{ formatDate(row.lead.created_at) }}</p>
                                    <p class="text-2xs text-slate-500">{{ formatTime(row.lead.created_at) }}</p>
                                    <p class="font-medium" :class="ageClass(row.lead.age_days)">{{ row.lead.age_days }}d</p>
                                </span>
                                <span v-else-if="key === 'updated_at'" class="text-slate-500" :title="formatDateTime(row.lead.updated_at)">{{ timeAgo(row.lead.updated_at) }}</span>
                            </td>
                        </tr>
                        </template>
                    </tbody>
                </table>
                <EmptyState v-if="!leads.data.length" icon="users" :title="activeCount ? 'No leads match these filters' : 'No leads yet'" :description="can.create && !activeCount ? 'Create your first lead to get started.' : ''" />
            </div>
            <UiPagination :paginator="leads" />
        </div>

        <AddToBatchModal v-if="can.addToBatch" :show="addingToBatch" :lead-ids="selected" :can-create="can.createBatch" :can-assign-trainers="can.manageBatchTrainers" @close="addingToBatch = false" @added="selected = []" />
        <BulkAssignModal v-if="can.bulkAssign" :show="assigning" :lead-ids="selected" :users="options.assignees" @close="assigning = false" @assigned="selected = []" />
        <BulkCampaignModal v-if="can.bulkCampaign" :show="settingCampaign" :lead-ids="selected" :campaigns="options.campaigns" @close="settingCampaign = false" @updated="selected = []" />
    </AppLayout>
</template>
