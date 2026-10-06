<script setup>
import Avatar from '@/Components/ui/Avatar.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import SearchInput from '@/Components/ui/SearchInput.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import UiPagination from '@/Components/ui/UiPagination.vue';
import { useFilters } from '@/Composables/useFilters';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDate, formatDateTime } from '@/utils/format';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    leads: Object,
    filters: Object,
    counts: Object,
    options: Object,
});

const keys = ['tab', 'search', 'status', 'source', 'campaign', 'assignee', 'per_page'];
const { filters, reset } = useFilters(Object.fromEntries(keys.map((k) => [k, props.filters[k] ?? ''])), route('leads.follow-up-required'));

const tabs = computed(() => [
    { key: 'all', label: 'All', count: props.counts.all },
    { key: 'none', label: 'No Follow-up — 5+ Days', count: props.counts.none, tone: 'amber' },
    { key: 'missing', label: 'Missing Next Follow-up', count: props.counts.missing, tone: 'purple' },
]);

const activeCount = computed(() => keys.filter((k) => !['tab', 'per_page'].includes(k) && filters[k] !== '' && filters[k] !== null).length);
const clearFilters = () => {
    const tab = filters.tab;
    reset();
    filters.tab = tab;
};

const ageClass = (days) => (days <= 1 ? 'text-emerald-600' : days <= 3 ? 'text-slate-600' : days <= 7 ? 'text-amber-600' : 'text-red-600');
const tabDot = { amber: 'bg-amber-500', purple: 'bg-purple-500' };
const tabCount = { amber: 'bg-amber-100 text-amber-600', purple: 'bg-purple-100 text-purple-600' };
const when = (value) => (value ? formatDateTime(value) : '—');
</script>

<template>
    <AppLayout title="Leads Requiring Follow-up">
        <PageHeader
            title="Leads Requiring Follow-up"
            :subtitle="`All required ${counts.all} · No follow-up 5+ days ${counts.none} · Missing next follow-up ${counts.missing}`"
        />

        <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <button type="button" class="panel px-4 py-3 text-left" @click="filters.tab = 'all'">
                <p class="text-2xs font-semibold uppercase tracking-[0.08em] text-slate-400">All required</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900">{{ counts.all.toLocaleString() }}</p>
            </button>
            <button type="button" class="panel px-4 py-3 text-left" @click="filters.tab = 'none'">
                <p class="text-2xs font-semibold uppercase tracking-[0.08em] text-slate-400">No follow-up 5+ days</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900">{{ counts.none.toLocaleString() }}</p>
            </button>
            <button type="button" class="panel px-4 py-3 text-left" @click="filters.tab = 'missing'">
                <p class="text-2xs font-semibold uppercase tracking-[0.08em] text-slate-400">Missing next follow-up</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900">{{ counts.missing.toLocaleString() }}</p>
            </button>
        </div>

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
                    <span v-if="t.tone" class="h-1.5 w-1.5 rounded-full" :class="tabDot[t.tone]" aria-hidden="true" />
                    {{ t.label }}
                    <span class="rounded-full px-1.5 text-2xs font-semibold" :class="t.count && t.tone ? tabCount[t.tone] : 'bg-slate-100 text-slate-600'">{{ t.count }}</span>
                </button>
            </nav>

            <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-4">
                <SearchInput v-model="filters.search" placeholder="Real ID, lead no., phone, name, email…" class="w-full sm:w-72" />
                <select v-model="filters.status" class="form-input w-36">
                    <option value="">Any status</option>
                    <option v-for="s in options.statuses" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
                <select v-model="filters.source" class="form-input w-36">
                    <option value="">Any source</option>
                    <option v-for="s in options.sources" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
                <select v-model="filters.campaign" class="form-input w-44">
                    <option value="">All campaigns</option>
                    <option v-for="c in options.campaigns" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <select v-if="options.users.length" v-model="filters.assignee" class="form-input w-40">
                    <option value="">Any assignee</option>
                    <option value="unassigned">Unassigned</option>
                    <option v-for="u in options.users" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
                <button v-if="activeCount" type="button" class="h-10 rounded-lg px-2 text-xs text-slate-500 hover:text-slate-800" @click="clearFilters">Clear ({{ activeCount }})</button>
                <select v-model="filters.per_page" class="form-input ml-auto w-28">
                    <option value="">25 / page</option>
                    <option value="50">50 / page</option>
                    <option value="100">100 / page</option>
                </select>
            </div>

            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Lead</th>
                            <th>Contact</th>
                            <th>Source</th>
                            <th>Campaign</th>
                            <th>Status</th>
                            <th>Owner</th>
                            <th>Created</th>
                            <th>Age</th>
                            <th>Completed</th>
                            <th>Last follow-up</th>
                            <th>Next follow-up</th>
                            <th class="text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="lead in leads.data" :key="lead.id">
                            <td>
                                <div class="flex items-center gap-3">
                                    <Avatar :name="lead.full_name" size="md" />
                                    <div class="min-w-0">
                                        <Link :href="route('leads.show', lead.id)" class="block max-w-[220px] truncate font-semibold text-slate-900 hover:text-brand-700">{{ lead.full_name }}</Link>
                                        <p class="text-2xs text-slate-500">
                                            <span class="font-mono font-semibold text-slate-700">{{ lead.id }}</span>
                                            <span class="text-slate-300"> · </span>
                                            <span class="font-mono">{{ lead.lead_number }}</span>
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <p>{{ lead.phone ?? '—' }}</p>
                                <p v-if="lead.email" class="max-w-[180px] truncate text-2xs text-slate-500">{{ lead.email }}</p>
                            </td>
                            <td>{{ lead.source?.name ?? '—' }}</td>
                            <td class="max-w-[140px] truncate">{{ lead.campaign?.name ?? '—' }}</td>
                            <td>
                                <UiBadge v-if="lead.status" :color="lead.status.color" dot>{{ lead.status.name }}</UiBadge>
                                <template v-else>—</template>
                            </td>
                            <td>
                                <div v-if="lead.assignee" class="flex items-center gap-2">
                                    <Avatar :name="lead.assignee.name" size="xs" />
                                    <p class="min-w-0 truncate text-slate-800">{{ lead.assignee.name }}</p>
                                </div>
                                <UiBadge v-else color="amber">Unassigned</UiBadge>
                            </td>
                            <td class="whitespace-nowrap text-xs" :title="formatDateTime(lead.created_at)">{{ formatDate(lead.created_at) }}</td>
                            <td class="whitespace-nowrap text-xs font-medium" :class="ageClass(lead.age_days)">{{ lead.age_days }}d</td>
                            <td class="text-xs tabular-nums">{{ lead.completed_followups_count }}</td>
                            <td class="whitespace-nowrap text-xs text-slate-600">{{ when(lead.last_followup_at) }}</td>
                            <td class="whitespace-nowrap text-xs text-slate-600">{{ when(lead.next_followup_at) }}</td>
                            <td class="text-right">
                                <Link :href="route('leads.show', lead.id)" class="link text-xs">View</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-if="!leads.data.length" icon="clock" :title="activeCount ? 'No leads match these filters' : 'No leads need a follow-up'" />
            </div>
            <UiPagination :paginator="leads" />
        </div>
    </AppLayout>
</template>
