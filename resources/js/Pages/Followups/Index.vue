<script setup>
import FollowupActionModals from '@/Components/followups/FollowupActionModals.vue';
import FollowupCards from '@/Components/followups/FollowupCards.vue';
import FollowupFormModal from '@/Components/followups/FollowupFormModal.vue';
import FollowupStateBadge from '@/Components/followups/FollowupStateBadge.vue';
import PriorityBadge from '@/Components/leads/PriorityBadge.vue';
import AppIcon from '@/Components/ui/AppIcon.vue';
import Avatar from '@/Components/ui/Avatar.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import SearchInput from '@/Components/ui/SearchInput.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import UiPagination from '@/Components/ui/UiPagination.vue';
import { useFilters } from '@/Composables/useFilters';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDateTime, formatDue, timeAgo } from '@/utils/format';
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    followups: Object,
    filters: Object,
    counts: Object,
    options: Object,
    can: Object,
    scopeLabel: String,
});

const keys = ['tab', 'search', 'scope', 'assigned_to', 'type', 'priority', 'lead', 'from', 'to', 'per_page'];
const { filters, reset } = useFilters(Object.fromEntries(keys.map((k) => [k, props.filters[k] ?? ''])), route('followups.index'));

const tabs = computed(() => [
    { key: 'due', label: 'Due', count: props.counts.overdue + props.counts.today, hint: 'Overdue + today' },
    { key: 'overdue', label: 'Overdue', count: props.counts.overdue, tone: 'red' },
    { key: 'today', label: 'Today', count: props.counts.today, tone: 'amber' },
    { key: 'upcoming', label: 'Upcoming', count: props.counts.upcoming },
    { key: 'completed', label: 'Completed', tone: 'green' },
    { key: 'cancelled', label: 'Cancelled' },
    { key: 'all', label: 'All' },
]);

const activeCount = computed(() => keys.filter((k) => !['tab', 'per_page'].includes(k) && filters[k] !== '' && filters[k] !== null).length);
const clearFilters = () => {
    const tab = filters.tab;
    reset();
    filters.tab = tab;
};

const action = ref(null);
const creating = ref(false);
const actionOptions = computed(() => ({ ...props.options, canChangeLeadStatus: props.can.changeLeadStatus }));
const dueClass = (state) => ({ overdue: 'text-red-600', due_soon: 'text-amber-700', completed: 'text-emerald-600' })[state] ?? 'text-slate-700';
const tabDot = { red: 'bg-red-500', amber: 'bg-amber-500', green: 'bg-emerald-500' };
const tabCount = { red: 'bg-red-100 text-red-600', amber: 'bg-amber-100 text-amber-600', green: 'bg-emerald-100 text-emerald-600' };
</script>

<template>
    <AppLayout title="Follow-ups">
        <PageHeader title="Follow-ups" :subtitle="`${scopeLabel} follow-ups · ${counts.overdue} overdue · ${counts.today} due later today`">
            <template #actions>
                <UiButton v-if="can.create" icon="plus" @click="creating = true">New follow-up</UiButton>
            </template>
        </PageHeader>

        <div class="panel">
            <nav class="scrollbar-none flex gap-1 overflow-x-auto border-b border-slate-100 px-3">
                <button
                    v-for="t in tabs"
                    :key="t.key"
                    type="button"
                    class="tab-btn"
                    :class="filters.tab === t.key ? 'border-brand-500 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-800'"
                    :title="t.hint"
                    @click="filters.tab = t.key"
                >
                    <span v-if="t.tone" class="h-1.5 w-1.5 rounded-full" :class="tabDot[t.tone]" aria-hidden="true" />
                    {{ t.label }}
                    <span v-if="t.count !== undefined" class="rounded-full px-1.5 text-2xs font-semibold" :class="t.count && t.tone ? tabCount[t.tone] : 'bg-slate-100 text-slate-600'">{{ t.count }}</span>
                </button>
            </nav>

            <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-4">
                <SearchInput v-model="filters.search" placeholder="Lead name, number, phone or title…" class="w-full sm:w-64" />
                <select v-if="can.filterByUser" v-model="filters.scope" class="form-input w-32">
                    <option value="">{{ scopeLabel }}</option>
                    <option value="mine">Mine only</option>
                </select>
                <select v-if="options.users.length" v-model="filters.assigned_to" class="form-input w-36">
                    <option value="">Any assignee</option>
                    <option v-for="u in options.users" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
                <select v-model="filters.type" class="form-input w-28">
                    <option value="">Any type</option>
                    <option v-for="t in options.types" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>
                <select v-model="filters.priority" class="form-input w-28">
                    <option value="">Any priority</option>
                    <option v-for="p in options.priorities" :key="p.value" :value="p.value">{{ p.label }}</option>
                </select>
                <label class="flex items-center gap-1 text-xs text-slate-500">From <input v-model="filters.from" type="date" class="form-input w-36" /></label>
                <label class="flex items-center gap-1 text-xs text-slate-500">To <input v-model="filters.to" type="date" class="form-input w-36" /></label>
                <button v-if="activeCount" class="text-xs text-slate-500 hover:text-slate-700" @click="clearFilters">Clear ({{ activeCount }})</button>
            </div>

            <!-- Phones: action-first cards -->
            <div class="md:hidden">
                <FollowupCards :rows="followups.data" @action="action = $event" />
            </div>

            <div class="hidden overflow-x-auto md:block">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Due</th>
                            <th>Lead</th>
                            <th>Type</th>
                            <th>Title</th>
                            <th>Assigned to</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Updated</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="f in followups.data" :key="f.id" :class="{ 'bg-red-50/60': f.state === 'overdue' }">
                            <td class="whitespace-nowrap text-xs font-semibold" :class="dueClass(f.state)" :title="formatDateTime(f.scheduled_at)">{{ formatDue(f.scheduled_at) }}</td>
                            <td>
                                <Link v-if="f.lead" :href="route('leads.show', f.lead.id)" class="font-medium text-slate-900 hover:text-brand-700">{{ f.lead.full_name }}</Link>
                                <p v-if="f.lead" class="text-2xs text-slate-500">ID {{ f.lead.id }} · {{ f.lead.lead_number }} · {{ f.lead.phone ?? '—' }}</p>
                            </td>
                            <td>
                                <UiBadge v-if="f.type" :color="f.type.color"><AppIcon v-if="f.type.icon" :name="f.type.icon" class="h-3 w-3" />{{ f.type.name }}</UiBadge>
                            </td>
                            <td class="max-w-[260px]">
                                <Link :href="route('followups.show', f.id)" class="block truncate text-sm text-slate-800 hover:text-brand-700">{{ f.title }}</Link>
                                <p v-if="f.description" class="mt-0.5 line-clamp-2 text-2xs text-slate-500">{{ f.description }}</p>
                                <p v-if="f.outcome" class="text-2xs text-slate-500">Outcome: {{ f.outcome }}</p>
                            </td>
                            <td class="text-xs">
                                <span v-if="f.assignee" class="flex items-center gap-2"><Avatar :name="f.assignee.name" size="xs" />{{ f.assignee.name }}</span>
                                <template v-else>—</template>
                            </td>
                            <td><PriorityBadge :priority="f.priority" /></td>
                            <td><FollowupStateBadge :state="f.state" /></td>
                            <td class="whitespace-nowrap text-xs text-slate-500" :title="formatDateTime(f.updated_at)">{{ timeAgo(f.updated_at) }}</td>
                            <td class="whitespace-nowrap text-right">
                                <div class="inline-flex items-center gap-1">
                                    <button v-if="f.can.complete" type="button" class="btn-complete" @click="action = { type: 'complete', followup: f }">Complete</button>
                                    <button v-if="f.can.reschedule" type="button" class="icon-btn" title="Reschedule" @click="action = { type: 'reschedule', followup: f }"><AppIcon name="clock" class="h-4 w-4" /></button>
                                    <Link v-if="f.can.update" :href="route('followups.show', f.id)" class="icon-btn" title="Edit"><AppIcon name="edit" class="h-4 w-4" /></Link>
                                    <button v-if="f.can.cancel" type="button" class="icon-btn" title="Cancel" @click="action = { type: 'cancel', followup: f }"><AppIcon name="ban" class="h-4 w-4" /></button>
                                    <Link v-if="f.lead" :href="route('leads.show', f.lead.id)" class="icon-btn" title="View lead"><AppIcon name="eye" class="h-4 w-4" /></Link>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <EmptyState
                v-if="!followups.data.length"
                icon="phone"
                :title="activeCount ? 'No follow-ups match these filters' : filters.tab === 'due' || filters.tab === 'overdue' ? 'Nothing due — you are all caught up' : 'No follow-ups here'"
            />
            <UiPagination :paginator="followups" />
        </div>

        <FollowupActionModals v-model:action="action" :options="actionOptions" />
        <FollowupFormModal :show="creating" :options="options" @close="creating = false" />
    </AppLayout>
</template>
