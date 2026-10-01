<script setup>
import PriorityMessageModal from '@/Components/chat/PriorityMessageModal.vue';
import FollowupActionModals from '@/Components/followups/FollowupActionModals.vue';
import { usePermissions } from '@/Composables/usePermissions';
import FollowupCards from '@/Components/followups/FollowupCards.vue';
import MeetingActionModals from '@/Components/meetings/MeetingActionModals.vue';
import MeetingCards from '@/Components/meetings/MeetingCards.vue';
import KpiGrid from '@/Components/reports/KpiGrid.vue';
import AppIcon from '@/Components/ui/AppIcon.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import StatCard from '@/Components/ui/StatCard.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDateTime, formatDue, timeAgo } from '@/utils/format';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    stats: { type: Object, default: () => ({}) },
    recentAudit: { type: Array, default: null },
    lastLogin: { type: Object, default: null },
    sales: { type: Object, default: null },
    meetings: { type: Object, default: null },
    facebook: { type: Object, default: null },
    reportKpis: { type: Object, default: null },
});

const action = ref(null);
const meetingAction = ref(null);
const showPriority = ref(false);
const { can } = usePermissions();

const salesCards = computed(() => {
    const s = props.sales;
    if (!s) return [];
    const scope = s.scope;
    return [
        s.leads !== null && { key: 'leads', label: scope === 'Company' ? 'All leads' : 'My leads', value: s.leads, icon: 'users', tone: 'brand', href: route('leads.index') },
        s.unassigned !== null && s.unassigned !== undefined && { key: 'unassigned', label: 'Unassigned leads', value: s.unassigned, icon: 'user', tone: 'amber', href: route('leads.index', { assignee: 'unassigned' }) },
        { key: 'today', label: 'Follow-ups today', value: s.counts.today, icon: 'calendar', tone: 'blue', href: route('followups.index', { tab: 'today' }) },
        { key: 'overdue', label: 'Overdue follow-ups', value: s.counts.overdue, icon: 'warning', tone: 'red', href: route('followups.index', { tab: 'overdue' }) },
        { key: 'upcoming', label: 'Upcoming follow-ups', value: s.counts.upcoming, icon: 'clock', tone: 'amber', href: route('followups.index', { tab: 'upcoming' }) },
        { key: 'completed', label: 'Completed today', value: s.counts.completed_today, icon: 'check', tone: 'green', href: route('followups.index', { tab: 'completed' }) },
    ].filter(Boolean);
});

const user = computed(() => usePage().props.auth.user);

const cards = computed(() =>
    [
        { key: 'users_active', label: 'Active users', icon: 'user', tone: 'brand' },
        { key: 'users_inactive', label: 'Inactive users', icon: 'ban', tone: 'slate' },
        { key: 'failed_logins_today', label: 'Failed logins today', icon: 'lock', tone: 'red' },
    ].filter((c) => props.stats[c.key] !== undefined),
);

const greeting = computed(() => {
    const h = new Date().getHours();
    return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
});
</script>

<template>
    <AppLayout title="Dashboard">
        <PageHeader :title="`${greeting}, ${user.name.split(' ')[0]}`" subtitle="Here is what is happening in your CRM today.">
            <template v-if="can('priority_broadcast.send', 'priority_broadcast.view_history')" #actions>
                <UiButton v-if="can('priority_broadcast.view_history')" variant="secondary" size="sm" icon="clock" :href="route('priority-broadcasts.index')">Priority history</UiButton>
                <UiButton v-if="can('priority_broadcast.send')" variant="danger" size="sm" icon="warning" data-testid="send-priority" @click="showPriority = true">Send Priority Message</UiButton>
            </template>
        </PageHeader>
        <PriorityMessageModal v-if="can('priority_broadcast.send')" :show="showPriority" @close="showPriority = false" />

        <div v-if="lastLogin" class="mb-5 inline-flex max-w-full items-center gap-2 rounded-lg border border-slate-200/70 bg-white px-3 py-2 text-xs text-slate-500">
            <AppIcon name="key" class="h-4 w-4 shrink-0 text-slate-400" />
            <span class="truncate">Previous sign-in {{ timeAgo(lastLogin.created_at) }} from {{ lastLogin.browser }} on {{ lastLogin.platform }} ({{ lastLogin.ip_address }})</span>
        </div>

        <div v-if="cards.length" class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard v-for="card in cards" :key="card.key" :label="card.label" :value="stats[card.key]" :icon="card.icon" :tone="card.tone" />
        </div>

        <div v-if="sales" class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2" :class="salesCards.length > 4 ? 'lg:grid-cols-3 xl:grid-cols-5' : 'xl:grid-cols-4'">
            <StatCard v-for="card in salesCards" :key="card.key" :label="card.label" :value="card.value" :icon="card.icon" :tone="card.tone" :href="card.href" />
        </div>

        <section v-if="reportKpis" class="mb-6" aria-label="Sales performance this month">
            <div class="mb-3 flex items-center justify-between gap-3">
                <h2 class="section-label">{{ reportKpis.scope }} sales · {{ reportKpis.period }}</h2>
                <Link :href="reportKpis.link" class="link text-xs">View reports →</Link>
            </div>
            <KpiGrid :items="reportKpis.kpis" compare :currency="reportKpis.currency" />
        </section>

        <div class="grid gap-5 xl:grid-cols-3">
            <div v-if="sales" class="space-y-5 xl:col-span-2">
                <div class="panel">
                    <div class="panel-header">
                        <h2 class="panel-title">
                            {{ sales.scope }} overdue follow-ups
                            <span v-if="sales.counts.overdue" class="ml-1 rounded-full bg-red-100 px-1.5 text-2xs text-red-700">{{ sales.counts.overdue }}</span>
                        </h2>
                        <Link :href="route('followups.index', { tab: 'overdue' })" class="link text-xs">View all</Link>
                    </div>
                    <FollowupCards :rows="sales.overdue" :show-assignee="sales.scope !== 'My'" @action="action = $event" />
                    <EmptyState v-if="!sales.overdue.length" icon="check" title="Nothing overdue" />
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <h2 class="panel-title">{{ sales.scope === 'My' ? "Today's follow-ups" : `${sales.scope} follow-ups today` }}</h2>
                        <Link :href="route('followups.index', { tab: 'today' })" class="link text-xs">View all</Link>
                    </div>
                    <FollowupCards :rows="sales.today" :show-assignee="sales.scope !== 'My'" @action="action = $event" />
                    <EmptyState v-if="!sales.today.length" icon="calendar" title="No more follow-ups today" />
                </div>

                <div v-if="sales.recentlyAssigned" class="panel">
                    <div class="panel-header">
                        <h2 class="panel-title">Recently assigned to me</h2>
                        <Link :href="route('leads.index')" class="link text-xs">All leads</Link>
                    </div>
                    <ul class="divide-y divide-slate-100">
                        <li v-for="l in sales.recentlyAssigned" :key="l.id" class="flex items-center justify-between gap-2 px-4 py-2">
                            <div class="min-w-0">
                                <Link :href="route('leads.show', l.id)" class="block truncate text-sm font-medium text-slate-900 hover:text-brand-700">{{ l.full_name }}</Link>
                                <p class="truncate text-2xs text-slate-500">ID {{ l.id }} · {{ l.lead_number }}<template v-if="l.company_name"> · {{ l.company_name }}</template> · assigned {{ timeAgo(l.assigned_at) }}</p>
                            </div>
                            <UiBadge v-if="l.status" :color="l.status.color">{{ l.status.name }}</UiBadge>
                        </li>
                    </ul>
                    <EmptyState v-if="!sales.recentlyAssigned.length" icon="users" title="No leads assigned to you yet" />
                </div>
            </div>

            <div v-if="meetings || recentAudit || facebook" class="space-y-5">
                <div v-if="facebook" class="panel">
                    <div class="panel-header">
                        <h2 class="panel-title">Facebook Lead Ads</h2>
                        <UiBadge :color="facebook.status_color" dot>{{ facebook.status_label }}</UiBadge>
                    </div>
                    <div class="grid grid-cols-2 divide-x divide-slate-100 text-center">
                        <Link :href="route('leads.index')" class="px-2 py-2.5 hover:bg-slate-50">
                            <p class="text-lg font-semibold text-slate-900">{{ facebook.leads_today }}</p>
                            <p class="text-2xs text-slate-500">Meta leads today</p>
                        </Link>
                        <Link :href="route('admin.integrations.facebook.events.index', { status: 'failed' })" class="px-2 py-2.5 hover:bg-slate-50">
                            <p class="text-lg font-semibold" :class="facebook.failed_events ? 'text-red-600' : 'text-slate-900'">{{ facebook.failed_events }}</p>
                            <p class="text-2xs text-slate-500">Failed events</p>
                        </Link>
                    </div>
                    <div class="space-y-0.5 border-t border-slate-100 px-4 py-2 text-2xs text-slate-500">
                        <p>Last lead: {{ facebook.last_lead_at ? timeAgo(facebook.last_lead_at) : 'never' }} · Last webhook: {{ facebook.last_webhook_at ? timeAgo(facebook.last_webhook_at) : 'never' }}</p>
                        <p v-if="facebook.token_expiring" class="text-amber-700">The Meta token expires soon — reconnect.</p>
                        <p v-if="facebook.pages_unsubscribed" class="text-amber-700">{{ facebook.pages_unsubscribed }} Page(s) are not subscribed to leads.</p>
                        <Link :href="route('admin.integrations.facebook.index')" class="link">Manage integration</Link>
                    </div>
                </div>

                <template v-if="meetings">
                    <div class="panel">
                        <div class="panel-header">
                            <h2 class="panel-title">{{ meetings.scope === 'My' ? 'My meetings' : `${meetings.scope} meetings` }}</h2>
                            <div class="flex items-center gap-2">
                                <Link :href="route('calendar.index')" class="link text-xs">Calendar</Link>
                                <UiButton v-if="meetings.canCreate" size="sm" icon="plus" :href="route('meetings.index', { new: 1 })">Schedule</UiButton>
                            </div>
                        </div>
                        <div class="grid grid-cols-3 divide-x divide-slate-100 border-b border-slate-100 text-center">
                            <Link :href="route('meetings.index', { tab: 'today' })" class="px-2 py-2.5 hover:bg-slate-50">
                                <p class="text-lg font-semibold text-slate-900">{{ meetings.counts.today }}</p>
                                <p class="text-2xs text-slate-500">Today</p>
                            </Link>
                            <Link :href="route('meetings.index', { tab: 'upcoming' })" class="px-2 py-2.5 hover:bg-slate-50">
                                <p class="text-lg font-semibold text-slate-900">{{ meetings.counts.upcoming }}</p>
                                <p class="text-2xs text-slate-500">Upcoming</p>
                            </Link>
                            <Link :href="route('meetings.index', { tab: 'completed' })" class="px-2 py-2.5 hover:bg-slate-50">
                                <p class="text-lg font-semibold text-slate-900">{{ meetings.counts.completed_today }}</p>
                                <p class="text-2xs text-slate-500">Completed today</p>
                            </Link>
                        </div>
                        <div v-if="meetings.next" class="border-b border-slate-100 bg-brand-50/40 px-4 py-2.5">
                            <p class="text-2xs font-medium uppercase tracking-wide text-brand-700">Next meeting</p>
                            <Link :href="route('meetings.show', meetings.next.id)" class="block truncate text-sm font-medium text-slate-900 hover:text-brand-700">{{ meetings.next.title }}</Link>
                            <p class="truncate text-2xs text-slate-600">
                                {{ formatDue(meetings.next.start_at) }}<template v-if="meetings.next.lead"> · {{ meetings.next.lead.full_name }} · ID {{ meetings.next.lead.id }}</template><template v-if="meetings.scope !== 'My' && meetings.next.host"> · {{ meetings.next.host.name }}</template>
                            </p>
                        </div>
                        <p class="px-4 pt-2 text-2xs font-medium uppercase tracking-wide text-slate-500">Today</p>
                        <MeetingCards :rows="meetings.today" :show-host="meetings.scope !== 'My'" @action="meetingAction = $event" />
                        <EmptyState v-if="!meetings.today.length" icon="video" title="No meetings today" />
                    </div>
                </template>

                <div v-if="recentAudit" class="panel">
                    <div class="panel-header">
                        <h2 class="panel-title">Recent activity (audit)</h2>
                        <Link :href="route('admin.audit-logs.index')" class="link text-xs">View all</Link>
                    </div>
                    <ul class="divide-y divide-slate-100">
                        <li v-for="log in recentAudit" :key="log.id" class="px-4 py-2">
                            <div class="flex items-center justify-between gap-2">
                                <UiBadge color="indigo">{{ log.action }}</UiBadge>
                                <span class="text-2xs text-slate-400" :title="formatDateTime(log.created_at)">{{ timeAgo(log.created_at) }}</span>
                            </div>
                            <p class="mt-0.5 truncate text-xs text-slate-600">{{ log.description }}</p>
                            <p class="text-2xs text-slate-400">{{ log.user ?? 'System' }}</p>
                        </li>
                        <li v-if="!recentAudit.length"><EmptyState title="No audit entries yet" /></li>
                    </ul>
                </div>
            </div>
        </div>

        <FollowupActionModals v-if="sales" v-model:action="action" :options="sales.form" />
        <MeetingActionModals v-if="meetings" v-model:action="meetingAction" :can-override="meetings.canOverride" />
    </AppLayout>
</template>
