<script setup>
import FollowupActionModals from '@/Components/followups/FollowupActionModals.vue';
import FollowupCards from '@/Components/followups/FollowupCards.vue';
import FollowupFormModal from '@/Components/followups/FollowupFormModal.vue';
import ActivityTimeline from '@/Components/leads/ActivityTimeline.vue';
import AssignModal from '@/Components/leads/AssignModal.vue';
import MeetingActionModals from '@/Components/meetings/MeetingActionModals.vue';
import MeetingCards from '@/Components/meetings/MeetingCards.vue';
import MeetingFormModal from '@/Components/meetings/MeetingFormModal.vue';
import AttachmentsPanel from '@/Components/leads/AttachmentsPanel.vue';
import EnquiriesPanel from '@/Components/leads/EnquiriesPanel.vue';
import NotesPanel from '@/Components/leads/NotesPanel.vue';
import PriorityBadge from '@/Components/leads/PriorityBadge.vue';
import StatusChangeModal from '@/Components/leads/StatusChangeModal.vue';
import AppIcon from '@/Components/ui/AppIcon.vue';
import Avatar from '@/Components/ui/Avatar.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import { useConfirm } from '@/Composables/useConfirm';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatCurrency, formatDate, formatDateTime, formatDue, timeAgo } from '@/utils/format';
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    lead: Object,
    customFields: Array,
    notes: Array,
    activities: Object,
    attachments: Array,
    enquiries: Array,
    facebook: { type: Object, default: null },
    assignments: Array,
    followups: { type: Array, default: () => [] },
    followupForm: { type: Object, default: null },
    meetings: { type: Array, default: () => [] },
    meetingForm: { type: Object, default: null },
    counts: Object,
    options: Object,
    can: Object,
});

const showValue = computed(() => 'estimated_value' in props.lead);

const followupFilter = ref('open');
const visibleFollowups = computed(() =>
    followupFilter.value === 'open' ? props.followups.filter((f) => f.status === 'pending') : props.followups,
);
const followupAction = ref(null);
const addingFollowup = ref(false);
const followupActionOptions = computed(() =>
    props.followupForm ? { ...props.followupForm, statuses: props.options.statuses, lostReasons: props.options.lostReasons, canChangeLeadStatus: props.can.changeStatus } : null,
);

const tabs = computed(() => [
    { key: 'overview', label: 'Overview' },
    { key: 'activity', label: 'Activity' },
    { key: 'notes', label: 'Notes', count: props.counts.notes },
    { key: 'enquiries', label: 'Enquiries', count: props.counts.enquiries },
    ...(props.can.viewAttachments ? [{ key: 'attachments', label: 'Attachments', count: props.counts.attachments }] : []),
    ...(props.followupForm ? [{ key: 'followups', label: 'Follow-ups', count: props.counts.followups }] : []),
    ...(props.meetingForm ? [{ key: 'meetings', label: 'Meetings', count: props.counts.meetings }] : []),
]);

const meetingGroups = computed(() => {
    const now = new Date();
    const open = props.meetings.filter((m) => ['scheduled', 'confirmed', 'in_progress'].includes(m.status));
    return [
        { key: 'upcoming', label: 'Upcoming', rows: open.filter((m) => new Date(m.end_at) > now).reverse() },
        { key: 'past', label: 'Past', rows: open.filter((m) => new Date(m.end_at) <= now) },
        { key: 'completed', label: 'Completed', rows: props.meetings.filter((m) => m.status === 'completed') },
        { key: 'cancelled', label: 'Cancelled', rows: props.meetings.filter((m) => ['cancelled', 'no_show', 'rescheduled'].includes(m.status)) },
    ];
});
const meetingFilter = ref('upcoming');
const visibleMeetings = computed(() => meetingGroups.value.find((g) => g.key === meetingFilter.value)?.rows ?? []);
const meetingAction = ref(null);
const addingMeeting = ref(false);
const initialTab = new URLSearchParams(window.location.search).get('tab');
const tab = ref(tabs.value.some((t) => t.key === initialTab) ? initialTab : 'overview');

const statusChanging = ref(false);
const lostModal = ref({ show: false, status: null });
const changeStatus = (statusId) => {
    const status = props.options.statuses.find((s) => s.id === Number(statusId));
    if (!status || status.id === props.lead.status?.id) return;
    if (status.is_lost) {
        lostModal.value = { show: true, status };
        return;
    }
    statusChanging.value = true;
    router.post(route('leads.status', props.lead.id), { status_id: status.id }, { preserveScroll: true, onFinish: () => (statusChanging.value = false) });
};

const changePriority = (priority) => {
    if (priority === props.lead.priority) return;
    router.post(route('leads.priority', props.lead.id), { priority }, { preserveScroll: true });
};

const showAssign = ref(false);

const { confirm } = useConfirm();
const archive = async () => {
    if (await confirm({ title: `Archive ${props.lead.lead_number}?`, message: 'The lead is hidden from lists and the pipeline. It can be restored by an authorised user; nothing is deleted.', confirmText: 'Archive', danger: true })) {
        router.delete(route('leads.destroy', props.lead.id));
    }
};
const restore = () => router.post(route('leads.restore', props.lead.id));

const filledCustomFields = computed(() => props.customFields.filter((f) => f.display !== null && f.display !== ''));

const details = computed(() => [
    ['Real ID', props.lead.id],
    ['Phone', props.lead.phone],
    ['Alternate phone', props.lead.alternate_phone],
    ['Email', props.lead.email],
    ['Company', props.lead.company_name],
    ['Designation', props.lead.designation],
    ['City', props.lead.city],
    ['State', props.lead.state],
    ['Country', props.lead.country],
    ['Pincode', props.lead.pincode],
    ['Source', props.lead.source?.name],
    ['Campaign', props.lead.campaign?.name],
    ['Created by', props.lead.creator?.name ?? 'System'],
]);

const assignmentType = { manual: 'Manual', automatic: 'Automatic', round_robin: 'Round robin', rule: 'Rule', import: 'Import', facebook: 'Facebook' };
</script>

<template>
    <AppLayout :title="`ID ${lead.id} · ${lead.full_name}`">
        <PageHeader :title="lead.full_name">
            <template #leading><Avatar :name="lead.full_name" size="lg" class="hidden sm:inline-flex" /></template>
            <template #breadcrumb>
                <Link :href="route('leads.index')" class="hover:text-slate-700">Leads</Link> / <span class="font-mono" :title="`Real ID ${lead.id}`">{{ lead.id }}</span> <span class="text-slate-400">·</span> <span class="font-mono">{{ lead.lead_number }}</span>
            </template>
            <template #actions>
                <UiButton v-if="can.update" variant="secondary" icon="edit" :href="route('leads.edit', lead.id)">Edit</UiButton>
                <UiButton v-if="can.assign" variant="secondary" icon="switch" @click="showAssign = true">{{ lead.assignee ? 'Reassign' : 'Assign' }}</UiButton>
                <UiButton v-if="can.delete" variant="ghost" icon="archive" @click="archive">Archive</UiButton>
                <UiButton v-if="can.restore" icon="restore" @click="restore">Restore</UiButton>
            </template>
        </PageHeader>

        <!-- Banners -->
        <div v-if="lead.archived" class="mb-3 flex items-center gap-2 rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-xs text-slate-700">
            <AppIcon name="archive" class="h-4 w-4" /> This lead is archived and read-only.
        </div>
        <div v-if="lead.is_duplicate" class="mb-3 flex items-center gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900">
            <AppIcon name="duplicate" class="h-4 w-4" />
            <span>Flagged as a possible duplicate<template v-if="lead.duplicate_of"> of <Link :href="route('leads.show', lead.duplicate_of.id)" class="link">{{ lead.duplicate_of.id }} · {{ lead.duplicate_of.lead_number }} · {{ lead.duplicate_of.full_name }}</Link></template><template v-else> of an existing lead</template>.</span>
        </div>

        <!-- Header strip -->
        <div class="panel mb-5 grid grid-cols-2 divide-slate-100 sm:grid-cols-3 lg:divide-x [&>div]:px-5 [&>div]:py-4" :class="showValue ? 'lg:grid-cols-6' : 'lg:grid-cols-5'">
            <div>
                <p class="section-label">Status</p>
                <UiBadge v-if="lead.status" :color="lead.status.color" dot class="mt-1.5">{{ lead.status.name }}</UiBadge>
                <p v-if="lead.lost_reason" class="mt-0.5 text-2xs text-red-600">{{ lead.lost_reason.name }}</p>
            </div>
            <div>
                <p class="section-label">Priority</p>
                <PriorityBadge :priority="lead.priority" class="mt-1.5" />
            </div>
            <div>
                <p class="section-label">Owner</p>
                <p class="mt-1.5 flex items-center gap-2 truncate text-sm font-medium text-slate-900"><Avatar v-if="lead.assignee" :name="lead.assignee.name" size="xs" />{{ lead.assignee?.name ?? 'Unassigned' }}</p>
            </div>
            <div>
                <p class="section-label">Lead age</p>
                <p class="mt-1.5 text-base font-semibold text-slate-900">{{ lead.age_days }} day{{ lead.age_days === 1 ? '' : 's' }}</p>
                <p class="text-2xs text-slate-500">since {{ formatDate(lead.created_at) }}</p>
            </div>
            <div v-if="showValue">
                <p class="section-label">Est. value</p>
                <p class="mt-1.5 text-base font-semibold text-slate-900">{{ formatCurrency(lead.estimated_value) }}</p>
                <p v-if="lead.probability !== null && lead.probability !== undefined" class="text-2xs text-slate-500">{{ lead.probability }}% probability</p>
            </div>
            <div>
                <p class="section-label">Last activity</p>
                <p class="mt-1.5 text-base font-semibold text-slate-900">{{ timeAgo(lead.updated_at) }}</p>
                <p class="text-2xs text-slate-500">{{ counts.enquiries }} enquir{{ counts.enquiries === 1 ? 'y' : 'ies' }}</p>
            </div>
        </div>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
            <!-- Main -->
            <div class="panel min-w-0">
                <div class="scrollbar-none flex overflow-x-auto border-b border-slate-100 px-3">
                    <button
                        v-for="t in tabs"
                        :key="t.key"
                        class="tab-btn"
                        :class="tab === t.key ? 'border-brand-500 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-800'"
                        @click="tab = t.key"
                    >
                        {{ t.label }}
                        <span v-if="t.count" class="rounded-full bg-slate-100 px-1.5 text-2xs text-slate-600">{{ t.count }}</span>
                        <span v-if="t.soon" class="rounded bg-slate-100 px-1 text-[10px] uppercase text-slate-400">Soon</span>
                    </button>
                </div>

                <div class="p-5">
                    <div v-if="tab === 'overview'" class="space-y-5">
                        <section>
                            <h3 class="mb-2 section-label">Contact & company</h3>
                            <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-2 lg:grid-cols-3">
                                <div v-for="[label, value] in details" :key="label">
                                    <dt class="text-2xs text-slate-500">{{ label }}</dt>
                                    <dd class="break-words text-sm text-slate-800">{{ value || '—' }}</dd>
                                </div>
                            </dl>
                        </section>

                        <section v-if="customFields.length">
                            <h3 class="mb-2 section-label">Additional details</h3>
                            <dl v-if="filledCustomFields.length" class="grid gap-x-6 gap-y-2 sm:grid-cols-2 lg:grid-cols-3">
                                <div v-for="f in filledCustomFields" :key="f.id">
                                    <dt class="text-2xs text-slate-500">{{ f.name }}</dt>
                                    <dd class="whitespace-pre-line break-words text-sm text-slate-800">{{ f.display }}</dd>
                                </div>
                            </dl>
                            <p v-else class="text-xs text-slate-500">No additional details captured yet.</p>
                        </section>

                        <section v-if="lead.status?.is_won || lead.status?.is_lost">
                            <h3 class="mb-2 section-label">Outcome</h3>
                            <div class="rounded-md p-3 text-sm" :class="lead.status.is_won ? 'bg-emerald-50 text-emerald-900' : 'bg-red-50 text-red-900'">
                                <template v-if="lead.status.is_won">Won on {{ formatDateTime(lead.converted_at) }}</template>
                                <template v-else>
                                    Lost on {{ formatDateTime(lead.lost_at) }}<template v-if="lead.lost_reason"> — {{ lead.lost_reason.name }}</template>
                                    <p v-if="lead.lost_reason_notes" class="mt-1 text-xs">{{ lead.lost_reason_notes }}</p>
                                </template>
                            </div>
                        </section>

                        <section>
                            <div class="mb-2 flex items-center justify-between">
                                <h3 class="section-label">Recent activity</h3>
                                <button class="text-2xs text-brand-700 hover:underline" @click="tab = 'activity'">View all</button>
                            </div>
                            <ActivityTimeline :lead-id="lead.id" :initial="{ data: activities.data.slice(0, 5), has_more: false }" compact />
                        </section>
                    </div>

                    <ActivityTimeline v-else-if="tab === 'activity'" :lead-id="lead.id" :initial="activities" />

                    <NotesPanel v-else-if="tab === 'notes'" :lead-id="lead.id" :notes="notes" :visibilities="options.noteVisibilities" :can-add="can.addNote" />

                    <EnquiriesPanel v-else-if="tab === 'enquiries'" :enquiries="enquiries" />

                    <AttachmentsPanel
                        v-else-if="tab === 'attachments'"
                        :lead-id="lead.id"
                        :attachments="attachments"
                        :can-upload="can.uploadAttachment"
                        :can-download="can.downloadAttachment"
                        :max-kb="options.maxUploadKb"
                        :extensions="options.allowedExtensions"
                    />

                    <div v-else-if="tab === 'followups'" class="-m-5">
                        <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-3">
                            <div class="inline-flex rounded-md border border-slate-200 p-0.5 text-xs">
                                <button type="button" class="rounded px-2 py-0.5" :class="followupFilter === 'open' ? 'bg-slate-100 font-medium text-slate-900' : 'text-slate-500'" @click="followupFilter = 'open'">Pending</button>
                                <button type="button" class="rounded px-2 py-0.5" :class="followupFilter === 'all' ? 'bg-slate-100 font-medium text-slate-900' : 'text-slate-500'" @click="followupFilter = 'all'">All ({{ followups.length }})</button>
                            </div>
                            <UiButton v-if="can.createFollowup" size="sm" icon="plus" class="ml-auto" @click="addingFollowup = true">Add follow-up</UiButton>
                        </div>
                        <FollowupCards :rows="visibleFollowups" :show-lead="false" show-outcome @action="followupAction = $event" />
                        <EmptyState v-if="!visibleFollowups.length" icon="phone" :title="followupFilter === 'open' ? 'No pending follow-ups' : 'No follow-ups yet'" :description="can.createFollowup ? 'Schedule the next call, WhatsApp or visit so this lead is never forgotten.' : ''" />
                    </div>
                    <div v-else-if="tab === 'meetings'" class="-m-5">
                        <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-3">
                            <div class="inline-flex flex-wrap rounded-md border border-slate-200 p-0.5 text-xs">
                                <button
                                    v-for="g in meetingGroups"
                                    :key="g.key"
                                    type="button"
                                    class="rounded px-2 py-0.5"
                                    :class="meetingFilter === g.key ? 'bg-slate-100 font-medium text-slate-900' : 'text-slate-500'"
                                    @click="meetingFilter = g.key"
                                >
                                    {{ g.label }} ({{ g.rows.length }})
                                </button>
                            </div>
                            <UiButton v-if="can.createMeeting" size="sm" icon="plus" class="ml-auto" @click="addingMeeting = true">Schedule meeting</UiButton>
                        </div>
                        <MeetingCards :rows="visibleMeetings" :show-lead="false" @action="meetingAction = $event" />
                        <EmptyState v-if="!visibleMeetings.length" icon="video" :title="`No ${meetingGroups.find((g) => g.key === meetingFilter)?.label.toLowerCase()} meetings`" :description="can.createMeeting ? 'Book a demo, site visit or call with this lead.' : ''" />
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <aside class="space-y-4">
                <div class="panel">
                    <div class="panel-header"><h2 class="panel-title">Quick actions</h2></div>
                    <div class="space-y-4 p-5">
                        <div>
                            <label class="form-label">Status</label>
                            <select class="form-input" :value="lead.status?.id" :disabled="!can.changeStatus || statusChanging" @change="changeStatus($event.target.value); $event.target.value = lead.status?.id">
                                <option v-for="s in options.statuses" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Priority</label>
                            <select class="form-input" :value="lead.priority" :disabled="!can.update" @change="changePriority($event.target.value)">
                                <option v-for="p in options.priorities" :key="p.value" :value="p.value">{{ p.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Owner</label>
                            <div class="flex items-center justify-between gap-2">
                                <span class="truncate text-sm text-slate-800">{{ lead.assignee?.name ?? 'Unassigned' }}</span>
                                <UiButton v-if="can.assign" size="sm" variant="ghost" icon="switch" @click="showAssign = true">Change</UiButton>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2 border-t border-slate-100 pt-3">
                            <a v-if="lead.phone" :href="`tel:${lead.phone}`" class="chip-btn"><AppIcon name="phone" class="h-3.5 w-3.5" /> Call</a>
                            <a v-if="lead.email" :href="`mailto:${lead.email}`" class="chip-btn"><AppIcon name="envelope" class="h-3.5 w-3.5" /> Email</a>
                            <button v-if="can.addNote" class="chip-btn" @click="tab = 'notes'"><AppIcon name="chat" class="h-3.5 w-3.5" /> Add note</button>
                            <button v-if="can.createFollowup" class="chip-btn" @click="addingFollowup = true"><AppIcon name="clock" class="h-3.5 w-3.5" /> Follow-up</button>
                            <button v-if="can.createMeeting" class="chip-btn" @click="addingMeeting = true"><AppIcon name="video" class="h-3.5 w-3.5" /> Meeting</button>
                        </div>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header"><h2 class="panel-title">Key dates</h2></div>
                    <dl class="space-y-1.5 p-4 text-xs">
                        <div class="flex justify-between"><dt class="text-slate-500">Created</dt><dd>{{ formatDateTime(lead.created_at) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Last updated</dt><dd>{{ formatDateTime(lead.updated_at) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Last contacted</dt><dd>{{ formatDateTime(lead.last_contacted_at) }}</dd></div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Next follow-up</dt>
                            <dd :class="lead.next_followup_at && new Date(lead.next_followup_at) < new Date() ? 'font-medium text-red-600' : ''">{{ lead.next_followup_at ? formatDue(lead.next_followup_at) : '—' }}</dd>
                        </div>
                        <div v-if="lead.converted_at" class="flex justify-between"><dt class="text-slate-500">Won</dt><dd>{{ formatDateTime(lead.converted_at) }}</dd></div>
                        <div v-if="lead.lost_at" class="flex justify-between"><dt class="text-slate-500">Lost</dt><dd>{{ formatDateTime(lead.lost_at) }}</dd></div>
                    </dl>
                </div>

                <div v-if="facebook" class="panel">
                    <div class="panel-header">
                        <h2 class="panel-title">Integration · {{ facebook.platform }} Lead Ads</h2>
                        <button v-if="facebook.facebook_enquiries > 1" class="text-2xs text-brand-600 hover:underline" @click="tab = 'enquiries'">{{ facebook.facebook_enquiries }} submissions</button>
                    </div>
                    <dl class="space-y-1.5 p-4 text-xs">
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Page</dt><dd class="truncate text-right">{{ facebook.page_name || facebook.page_id || '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Form</dt><dd class="truncate text-right">{{ facebook.form_name || facebook.form_id || '—' }}</dd></div>
                        <div v-if="facebook.campaign_name || facebook.campaign_id" class="flex justify-between gap-3"><dt class="text-slate-500">Campaign</dt><dd class="truncate text-right">{{ facebook.campaign_name || facebook.campaign_id }}</dd></div>
                        <div v-if="facebook.adset_name || facebook.adset_id" class="flex justify-between gap-3"><dt class="text-slate-500">Ad set</dt><dd class="truncate text-right">{{ facebook.adset_name || facebook.adset_id }}</dd></div>
                        <div v-if="facebook.ad_name || facebook.ad_id" class="flex justify-between gap-3"><dt class="text-slate-500">Ad</dt><dd class="truncate text-right">{{ facebook.ad_name || facebook.ad_id }}</dd></div>
                        <div v-if="facebook.submitted_at" class="flex justify-between gap-3"><dt class="text-slate-500">Submitted</dt><dd>{{ formatDateTime(facebook.submitted_at) }}</dd></div>
                        <div v-if="facebook.leadgen_id" class="flex justify-between gap-3"><dt class="text-slate-500">Meta lead ID</dt><dd class="font-mono">{{ facebook.leadgen_id }}</dd></div>
                        <div v-if="facebook.event" class="flex justify-between gap-3 border-t border-slate-100 pt-1.5">
                            <dt class="text-slate-500">Webhook event</dt>
                            <dd><Link :href="facebook.event.url" class="text-brand-600 hover:underline">{{ facebook.event.status }} · {{ facebook.event.origin }}</Link></dd>
                        </div>
                    </dl>
                </div>

                <div class="panel">
                    <div class="panel-header"><h2 class="panel-title">Assignment history</h2></div>
                    <ul class="divide-y divide-slate-100 text-xs">
                        <li v-if="!assignments.length" class="p-4 text-slate-500">Never assigned.</li>
                        <li v-for="a in assignments" :key="a.id" class="px-4 py-2">
                            <p class="text-slate-800"><template v-if="a.from">{{ a.from }} → </template>{{ a.to ?? 'Unassigned' }}</p>
                            <p class="text-2xs text-slate-500">{{ assignmentType[a.type] ?? a.type }}<template v-if="a.by"> by {{ a.by }}</template> · {{ timeAgo(a.created_at) }}</p>
                            <p v-if="a.reason" class="text-2xs italic text-slate-500">{{ a.reason }}</p>
                        </li>
                    </ul>
                </div>
            </aside>
        </div>

        <StatusChangeModal :show="lostModal.show" :lead-id="lead.id" :status="lostModal.status" :lost-reasons="options.lostReasons" @close="lostModal.show = false" />
        <AssignModal v-if="can.assign" :show="showAssign" :lead="lead" :users="options.assignees" @close="showAssign = false" />
        <template v-if="followupForm">
            <FollowupActionModals v-model:action="followupAction" :options="followupActionOptions" />
            <FollowupFormModal v-if="can.createFollowup" :show="addingFollowup" :options="followupForm" :lead="lead" :default-assignee="followupForm.default_assignee" @close="addingFollowup = false" />
        </template>
        <template v-if="meetingForm">
            <MeetingActionModals v-model:action="meetingAction" :can-override="meetingForm.can_override_conflict" />
            <MeetingFormModal v-if="can.createMeeting" :show="addingMeeting" :options="meetingForm" :lead="lead" :default-host-id="meetingForm.default_host" @close="addingMeeting = false" />
        </template>
    </AppLayout>
</template>
