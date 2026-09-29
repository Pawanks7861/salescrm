<script setup>
import PriorityBadge from '@/Components/leads/PriorityBadge.vue';
import MeetingActionModals from '@/Components/meetings/MeetingActionModals.vue';
import MeetingCards from '@/Components/meetings/MeetingCards.vue';
import MeetingFormModal from '@/Components/meetings/MeetingFormModal.vue';
import MeetingStatusBadge from '@/Components/meetings/MeetingStatusBadge.vue';
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
import { formatDateTime, formatDue, formatTime } from '@/utils/format';
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    meetings: Object,
    filters: Object,
    counts: Object,
    options: Object,
    can: Object,
    scopeLabel: String,
});

const keys = ['tab', 'search', 'scope', 'host', 'type', 'status', 'priority', 'location_type', 'lead', 'from', 'to', 'per_page'];
const { filters, reset } = useFilters(Object.fromEntries(keys.map((k) => [k, props.filters[k] ?? ''])), route('meetings.index'));

const tabs = computed(() => [
    { key: 'upcoming', label: 'Upcoming', count: props.counts.upcoming },
    { key: 'today', label: 'Today', count: props.counts.today },
    { key: 'past', label: 'Past' },
    { key: 'completed', label: 'Completed' },
    { key: 'cancelled', label: 'Cancelled' },
    { key: 'no_show', label: 'No-show' },
    { key: 'all', label: 'All' },
]);

const activeCount = computed(() => keys.filter((k) => !['tab', 'per_page'].includes(k) && filters[k] !== '' && filters[k] !== null).length);
const clearFilters = () => {
    const tab = filters.tab;
    reset();
    filters.tab = tab;
};

const action = ref(null);
const canCreate = computed(() => props.can.create);
const creating = ref(canCreate.value && new URLSearchParams(window.location.search).has('new'));
</script>

<template>
    <AppLayout title="Meetings">
        <PageHeader title="Meetings" :subtitle="`${scopeLabel} meetings · ${counts.today} today · ${counts.upcoming} upcoming`">
            <template #actions>
                <UiButton :href="route('calendar.index')" variant="secondary" icon="calendar">Calendar</UiButton>
                <UiButton v-if="canCreate" icon="plus" @click="creating = true">Schedule meeting</UiButton>
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
                    @click="filters.tab = t.key"
                >
                    {{ t.label }}
                    <span v-if="t.count !== undefined" class="rounded-full bg-slate-100 px-1.5 text-2xs text-slate-600">{{ t.count }}</span>
                </button>
            </nav>

            <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-4">
                <SearchInput v-model="filters.search" placeholder="Meeting #, title, lead or participant…" class="w-full sm:w-64" />
                <select v-if="can.filterByUser" v-model="filters.scope" class="form-input w-32">
                    <option value="">{{ scopeLabel }}</option>
                    <option value="mine">Mine only</option>
                </select>
                <select v-if="options.users.length" v-model="filters.host" class="form-input w-36">
                    <option value="">Any host</option>
                    <option v-for="u in options.users" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
                <select v-model="filters.type" class="form-input w-36">
                    <option value="">Any type</option>
                    <option v-for="t in options.types" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>
                <select v-model="filters.status" class="form-input w-32">
                    <option value="">Any status</option>
                    <option v-for="s in options.statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>
                <select v-model="filters.priority" class="form-input w-28">
                    <option value="">Any priority</option>
                    <option v-for="p in options.priorities" :key="p.value" :value="p.value">{{ p.label }}</option>
                </select>
                <select v-model="filters.location_type" class="form-input w-32">
                    <option value="">Any location</option>
                    <option v-for="l in options.location_types" :key="l.value" :value="l.value">{{ l.label }}</option>
                </select>
                <label class="flex items-center gap-1 text-xs text-slate-500">From <input v-model="filters.from" type="date" class="form-input w-36" /></label>
                <label class="flex items-center gap-1 text-xs text-slate-500">To <input v-model="filters.to" type="date" class="form-input w-36" /></label>
                <button v-if="activeCount" class="text-xs text-slate-500 hover:text-slate-700" @click="clearFilters">Clear ({{ activeCount }})</button>
            </div>

            <div class="md:hidden">
                <MeetingCards :rows="meetings.data" @action="action = $event" />
            </div>

            <div class="hidden overflow-x-auto md:block">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Meeting #</th>
                            <th>Title</th>
                            <th>Lead</th>
                            <th>Type</th>
                            <th>Host</th>
                            <th>Start</th>
                            <th>End</th>
                            <th>Location</th>
                            <th>Participants</th>
                            <th>Status</th>
                            <th>Priority</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in meetings.data" :key="m.id" :class="{ 'bg-amber-50/60': m.state === 'past_due' }">
                            <td class="whitespace-nowrap text-xs font-medium"><Link :href="route('meetings.show', m.id)" class="text-brand-700 hover:underline">{{ m.meeting_number }}</Link></td>
                            <td class="max-w-[200px]">
                                <Link :href="route('meetings.show', m.id)" class="block truncate text-sm text-slate-800 hover:text-brand-700">{{ m.title }}</Link>
                                <p v-if="m.outcome" class="text-2xs text-slate-500">Outcome: {{ m.outcome }}</p>
                            </td>
                            <td>
                                <template v-if="m.lead">
                                    <Link :href="route('leads.show', m.lead.id)" class="text-sm font-medium text-slate-900 hover:text-brand-700">{{ m.lead.full_name }}</Link>
                                    <p class="text-2xs text-slate-500">ID {{ m.lead.id }} · {{ m.lead.lead_number }}</p>
                                </template>
                                <span v-else class="text-2xs text-slate-400">Internal</span>
                            </td>
                            <td><UiBadge v-if="m.type" :color="m.type.color"><AppIcon v-if="m.type.icon" :name="m.type.icon" class="h-3 w-3" />{{ m.type.name }}</UiBadge></td>
                            <td class="text-xs">
                                <span v-if="m.host" class="flex items-center gap-2 whitespace-nowrap"><Avatar :name="m.host.name" size="xs" />{{ m.host.name }}</span>
                                <template v-else>—</template>
                            </td>
                            <td class="whitespace-nowrap text-xs font-semibold text-slate-800" :title="formatDateTime(m.start_at)">{{ formatDue(m.start_at) }}</td>
                            <td class="whitespace-nowrap text-xs text-slate-600" :title="formatDateTime(m.end_at)">{{ formatTime(m.end_at) }}</td>
                            <td class="max-w-[140px] text-xs">
                                <span class="block truncate">{{ m.location_type_label }}<template v-if="m.location"> · {{ m.location }}</template></span>
                                <span v-if="m.has_link" class="text-2xs text-brand-700">Online link</span>
                            </td>
                            <td class="max-w-[160px] text-xs">
                                <span class="block truncate" :title="m.participants.map((p) => p.name).join(', ')">{{ m.participants.length ? m.participants.map((p) => p.name).join(', ') : '—' }}</span>
                            </td>
                            <td><MeetingStatusBadge :state="m.state" :status="m.status" /></td>
                            <td><PriorityBadge :priority="m.priority" /></td>
                            <td class="whitespace-nowrap text-right">
                                <div class="inline-flex items-center gap-1">
                                    <button v-if="m.can.complete" type="button" class="btn-complete" @click="action = { type: 'complete', meeting: m }">Complete</button>
                                    <button v-if="m.can.reschedule" type="button" class="icon-btn" title="Reschedule" @click="action = { type: 'reschedule', meeting: m }"><AppIcon name="clock" class="h-4 w-4" /></button>
                                    <Link v-if="m.can.update" :href="route('meetings.show', m.id)" class="icon-btn" title="Edit"><AppIcon name="edit" class="h-4 w-4" /></Link>
                                    <button v-if="m.can.cancel" type="button" class="icon-btn" title="Cancel" @click="action = { type: 'cancel', meeting: m }"><AppIcon name="ban" class="h-4 w-4" /></button>
                                    <Link :href="route('meetings.show', m.id)" class="icon-btn" title="View"><AppIcon name="eye" class="h-4 w-4" /></Link>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <EmptyState v-if="!meetings.data.length" icon="video" :title="activeCount ? 'No meetings match these filters' : 'No meetings here'" />
            <UiPagination :paginator="meetings" />
        </div>

        <MeetingActionModals v-model:action="action" :can-override="options.can_override_conflict" />
        <MeetingFormModal :show="creating" :options="options" @close="creating = false" />
    </AppLayout>
</template>
