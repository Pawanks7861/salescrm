<script setup>
import PriorityBadge from '@/Components/leads/PriorityBadge.vue';
import ConflictNotice from '@/Components/meetings/ConflictNotice.vue';
import MeetingCancelModal from '@/Components/meetings/MeetingCancelModal.vue';
import MeetingCompleteModal from '@/Components/meetings/MeetingCompleteModal.vue';
import MeetingFormModal from '@/Components/meetings/MeetingFormModal.vue';
import MeetingNoShowModal from '@/Components/meetings/MeetingNoShowModal.vue';
import MeetingRescheduleModal from '@/Components/meetings/MeetingRescheduleModal.vue';
import MeetingStatusBadge from '@/Components/meetings/MeetingStatusBadge.vue';
import ParticipantPicker from '@/Components/meetings/ParticipantPicker.vue';
import Modal from '@/Components/Modal.vue';
import AppIcon from '@/Components/ui/AppIcon.vue';
import FormField from '@/Components/ui/FormField.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import { useConfirm } from '@/Composables/useConfirm';
import AppLayout from '@/Layouts/AppLayout.vue';
import { durationLabel, formatDateTime, formatRange, formatTime } from '@/utils/format';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';

const props = defineProps({
    meeting: Object,
    history: Array,
    notes: { type: Array, default: () => [] },
    options: Object,
    can: Object,
});

const m = computed(() => props.meeting);
const modal = ref(null); // edit | reschedule | cancel | complete | no_show | participant
const { confirm } = useConfirm();

onMounted(() => {
    const requested = new URLSearchParams(window.location.search).get('action');
    if (requested === 'complete' && m.value.can.complete) modal.value = 'complete';
    if (requested === 'no_show' && m.value.can.no_show) modal.value = 'no_show';
});

const post = (name) => router.post(route(name, m.value.id), {}, { preserveScroll: true });
const destroy = async () => {
    if (await confirm({ title: `Delete ${m.value.meeting_number}?`, message: 'Deleting hides the meeting from all lists and stops its reminders (it can be restored). To call off a meeting normally, cancel it so the reason is kept.', confirmText: 'Delete', danger: true })) {
        router.delete(route('meetings.destroy', m.value.id));
    }
};
const restore = () => router.post(route('meetings.restore', m.value.id));
const respond = (status) => router.post(route('meetings.respond', m.value.id), { attendance_status: status }, { preserveScroll: true });
const removeParticipant = async (p) => {
    if (await confirm({ title: `Remove ${p.name}?`, message: 'They will no longer receive reminders for this meeting.', confirmText: 'Remove', danger: true })) {
        router.delete(route('meetings.participants.destroy', { meeting: m.value.id, participant: p.id }), { preserveScroll: true });
    }
};

// Add participant
const pForm = useForm({ type: 'user', user_id: null, name: '', email: '', phone: '', override_conflict: false });
const picked = ref([]);
watch(picked, (v) => {
    if (v.length > 1) picked.value = [v[v.length - 1]];
    pForm.user_id = picked.value[0]?.id ?? null;
});
const openParticipant = () => {
    pForm.reset();
    pForm.clearErrors();
    picked.value = [];
    modal.value = 'participant';
};
const leadIsParticipant = computed(() => m.value.participants.some((p) => p.type === 'lead'));
const addParticipant = () => pForm.post(route('meetings.participants.store', m.value.id), { preserveScroll: true, onSuccess: () => (modal.value = null) });
const existingUserIds = computed(() => [m.value.host_user_id, ...m.value.participants.filter((p) => p.user_id).map((p) => p.user_id)]);

const attendanceColor = { pending: 'slate', confirmed: 'indigo', attended: 'green', absent: 'red', declined: 'amber' };

const noteForm = useForm({ body: '' });
const saveNote = () => noteForm.post(route('meetings.notes.store', m.value.id), { preserveScroll: true, onSuccess: () => noteForm.reset() });

const details = computed(() => [
    ['Date', formatRange(m.value.start_at, m.value.end_at)],
    ['Duration', durationLabel(m.value.duration_minutes)],
    ['Timezone', m.value.timezone],
    ['Host', m.value.host?.name ?? '—'],
    ['Reminders', m.value.reminder_labels.length ? m.value.reminder_labels.join(', ') : 'None'],
    ['Created by', m.value.creator ? `${m.value.creator.name} · ${formatDateTime(m.value.created_at)}` : formatDateTime(m.value.created_at)],
]);
</script>

<template>
    <AppLayout :title="`${m.meeting_number} · ${m.title}`">
        <PageHeader :title="m.title" :subtitle="m.meeting_number">
            <template #breadcrumb>
                <Link :href="route('meetings.index')" class="hover:text-slate-700">Meetings</Link>
                <template v-if="m.lead">
                    <span class="mx-1">/</span>
                    <Link :href="route('leads.show', m.lead.id)" class="hover:text-slate-700">{{ m.lead.full_name }} · ID {{ m.lead.id }}</Link>
                </template>
            </template>
            <template #actions>
                <UiButton v-if="m.can.complete" icon="check" @click="modal = 'complete'">Complete</UiButton>
                <UiButton v-if="m.can.confirm" variant="secondary" icon="check" @click="post('meetings.confirm')">Confirm</UiButton>
                <UiButton v-if="m.can.start" variant="secondary" icon="play" @click="post('meetings.start')">Start</UiButton>
                <UiButton v-if="m.can.reschedule" variant="secondary" icon="clock" @click="modal = 'reschedule'">Reschedule</UiButton>
                <UiButton v-if="m.can.update" variant="secondary" icon="edit" @click="modal = 'edit'">Edit</UiButton>
                <UiButton v-if="m.can.no_show" variant="ghost" icon="warning" @click="modal = 'no_show'">No-show</UiButton>
                <UiButton v-if="m.can.cancel" variant="ghost" icon="ban" @click="modal = 'cancel'">Cancel</UiButton>
                <UiButton v-if="m.can.delete" variant="ghost" icon="trash" @click="destroy">Delete</UiButton>
                <UiButton v-if="m.can_restore" variant="secondary" icon="restore" @click="restore">Restore</UiButton>
            </template>
        </PageHeader>

        <div v-if="m.deleted" class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">This meeting was deleted. It is hidden from lists, the calendar and reminders.</div>

        <div v-if="m.can_respond && m.my_participant_id" class="mb-4 flex flex-wrap items-center gap-2 rounded-md border border-brand-200 bg-brand-50 px-3 py-2 text-xs text-brand-800">
            <span>You are invited to this meeting<template v-if="m.my_attendance !== 'pending'"> · {{ m.my_attendance }}</template>.</span>
            <UiButton size="sm" :variant="m.my_attendance === 'confirmed' ? 'primary' : 'secondary'" @click="respond('confirmed')">Attend</UiButton>
            <UiButton size="sm" :variant="m.my_attendance === 'declined' ? 'danger' : 'secondary'" @click="respond('declined')">Decline</UiButton>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <div class="panel">
                    <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 px-4 py-3">
                        <UiBadge v-if="m.type" :color="m.type.color"><AppIcon v-if="m.type.icon" :name="m.type.icon" class="h-3 w-3" />{{ m.type.name }}</UiBadge>
                        <MeetingStatusBadge :state="m.state" :status="m.status" />
                        <PriorityBadge :priority="m.priority" />
                        <span class="ml-auto text-sm font-semibold text-slate-700">{{ formatRange(m.start_at, m.end_at) }}</span>
                    </div>
                    <dl class="grid gap-x-6 gap-y-3 p-5 text-sm sm:grid-cols-2">
                        <div v-for="[label, value] in details" :key="label">
                            <dt class="text-2xs uppercase tracking-wide text-slate-400">{{ label }}</dt>
                            <dd class="text-slate-800">{{ value }}</dd>
                        </div>
                    </dl>
                    <div class="border-t border-slate-100 px-4 py-3 text-sm">
                        <p class="text-2xs uppercase tracking-wide text-slate-400">Location</p>
                        <p class="text-slate-800">{{ m.location_type_label }}<template v-if="m.location"> · {{ m.location }}</template></p>
                        <p v-if="m.address" class="text-slate-600">{{ m.address }}</p>
                        <a v-if="m.meeting_url" :href="m.meeting_url" target="_blank" rel="noopener noreferrer" class="mt-1 inline-flex items-center gap-1 break-all text-brand-700 hover:underline">
                            <AppIcon name="link" class="h-4 w-4 shrink-0" />{{ m.meeting_url }}
                        </a>
                    </div>
                    <div v-if="m.agenda" class="border-t border-slate-100 px-4 py-3">
                        <p class="text-2xs uppercase tracking-wide text-slate-400">Agenda</p>
                        <p class="whitespace-pre-line text-sm text-slate-700">{{ m.agenda }}</p>
                    </div>
                    <div v-if="m.description" class="border-t border-slate-100 px-4 py-3">
                        <p class="text-2xs uppercase tracking-wide text-slate-400">Description</p>
                        <p class="whitespace-pre-line text-sm text-slate-700">{{ m.description }}</p>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header flex items-center justify-between">
                        <h2 class="panel-title">Participants</h2>
                        <UiButton v-if="m.can.participants" size="sm" variant="secondary" icon="plus" @click="openParticipant">Add</UiButton>
                    </div>
                    <ul class="divide-y divide-slate-100">
                        <li class="flex items-center gap-2 px-4 py-2 text-sm">
                            <span class="font-medium text-slate-900">{{ m.host?.name ?? '—' }}</span>
                            <UiBadge color="indigo">Host</UiBadge>
                        </li>
                        <li v-for="p in m.participants" :key="p.id" class="flex flex-wrap items-center gap-2 px-4 py-2 text-sm">
                            <span class="font-medium text-slate-900">{{ p.name }}</span>
                            <span class="text-2xs text-slate-500">{{ p.type_label }}</span>
                            <span v-if="p.email || p.phone" class="text-2xs text-slate-500">{{ [p.email, p.phone].filter(Boolean).join(' · ') }}</span>
                            <UiBadge :color="attendanceColor[p.attendance_status] ?? 'slate'" class="ml-auto">{{ p.attendance_label }}</UiBadge>
                            <button v-if="m.can.participants" type="button" class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" :title="`Remove ${p.name}`" @click="removeParticipant(p)"><AppIcon name="close" class="h-4 w-4" /></button>
                        </li>
                    </ul>
                    <p v-if="!m.participants.length" class="px-4 pb-3 text-xs text-slate-500">No other participants.</p>
                    <p class="px-4 pb-3 text-2xs text-slate-400">External contacts and leads are not sent invitations automatically.</p>
                </div>

                <div class="panel">
                    <div class="panel-header"><h2 class="panel-title">Admin notes</h2></div>
                    <ul v-if="notes.length" class="divide-y divide-slate-100">
                        <li v-for="n in notes" :key="n.id" class="px-4 py-3 text-sm">
                            <p class="whitespace-pre-line text-slate-800">{{ n.body }}</p>
                            <p class="mt-1 text-2xs text-slate-500">{{ n.author }} · {{ formatDateTime(n.created_at) }}</p>
                        </li>
                    </ul>
                    <p v-else class="px-4 py-3 text-xs text-slate-500">No notes yet.</p>
                    <form v-if="can.addNote" class="space-y-2 border-t border-slate-100 p-4" @submit.prevent="saveNote">
                        <FormField label="Note for the salesperson" :error="noteForm.errors.body">
                            <textarea v-model="noteForm.body" rows="3" class="form-input" maxlength="5000" placeholder="What should the salesperson know?" />
                        </FormField>
                        <div class="flex justify-end">
                            <UiButton type="submit" size="sm" :loading="noteForm.processing">Save and notify</UiButton>
                        </div>
                    </form>
                </div>

                <div v-if="m.status === 'completed' || m.status === 'no_show'" class="panel">
                    <div class="panel-header"><h2 class="panel-title">{{ m.status === 'completed' ? 'Outcome' : 'No-show' }}</h2></div>
                    <dl class="grid gap-x-6 gap-y-3 p-5 text-sm sm:grid-cols-2">
                        <div><dt class="text-2xs uppercase tracking-wide text-slate-400">Outcome</dt><dd>{{ m.outcome ?? '—' }}</dd></div>
                        <div v-if="m.completed_at"><dt class="text-2xs uppercase tracking-wide text-slate-400">Completed</dt><dd>{{ m.completer?.name }} · {{ formatDateTime(m.completed_at) }}</dd></div>
                        <div v-if="m.outcome_notes" class="sm:col-span-2"><dt class="text-2xs uppercase tracking-wide text-slate-400">Notes</dt><dd class="whitespace-pre-line">{{ m.outcome_notes }}</dd></div>
                    </dl>
                </div>

                <div v-if="m.status === 'cancelled'" class="panel">
                    <div class="panel-header"><h2 class="panel-title">Cancellation</h2></div>
                    <p class="p-4 text-sm text-slate-700">
                        Cancelled by {{ m.canceller?.name ?? '—' }} · {{ formatDateTime(m.cancelled_at) }}
                        <span v-if="m.cancellation_reason" class="mt-1 block text-slate-600">Reason: {{ m.cancellation_reason }}</span>
                    </p>
                </div>
            </div>

            <div class="space-y-4">
                <div v-if="m.lead" class="panel">
                    <div class="panel-header"><h2 class="panel-title">Lead</h2></div>
                    <div class="p-4 text-sm">
                        <Link :href="route('leads.show', m.lead.id)" class="font-medium text-slate-900 hover:text-brand-700">{{ m.lead.full_name }}</Link>
                        <p class="text-2xs text-slate-500">ID {{ m.lead.id }} · {{ m.lead.lead_number }}<template v-if="m.lead.company_name"> · {{ m.lead.company_name }}</template></p>
                    </div>
                </div>

                <div v-if="history.length" class="panel">
                    <div class="panel-header"><h2 class="panel-title">Reschedule history</h2></div>
                    <ol class="space-y-4 p-5">
                        <li v-for="h in history" :key="h.id" class="flex gap-2 text-xs">
                            <span class="mt-1 h-2 w-2 shrink-0 rounded-full" :class="h.current ? 'bg-brand-600' : 'bg-slate-300'" />
                            <div class="min-w-0">
                                <Link v-if="!h.current" :href="route('meetings.show', h.id)" class="font-medium text-slate-700 hover:text-brand-700">{{ formatDateTime(h.start_at) }} – {{ formatTime(h.end_at) }}</Link>
                                <span v-else class="font-semibold text-slate-900">{{ formatDateTime(h.start_at) }} – {{ formatTime(h.end_at) }} (this)</span>
                                <div class="mt-0.5 flex flex-wrap items-center gap-1">
                                    <span class="text-slate-500">{{ h.meeting_number }}</span>
                                    <MeetingStatusBadge :state="h.status" />
                                </div>
                                <p v-if="h.reschedule_reason" class="text-slate-500">Reason: {{ h.reschedule_reason }}</p>
                            </div>
                        </li>
                    </ol>
                </div>
            </div>
        </div>

        <MeetingFormModal :show="modal === 'edit'" :options="options" :meeting="m" :lead="m.lead" @close="modal = null" />
        <MeetingRescheduleModal :show="modal === 'reschedule'" :meeting="m" :can-override="options.can_override_conflict" @close="modal = null" />
        <MeetingCancelModal :show="modal === 'cancel'" :meeting="m" @close="modal = null" />
        <MeetingNoShowModal :show="modal === 'no_show'" :meeting="m" @close="modal = null" />
        <MeetingCompleteModal
            :show="modal === 'complete'"
            :meeting="m"
            :options="options"
            :can-change-lead-status="can.changeLeadStatus"
            :can-schedule-followup="can.scheduleFollowup"
            @close="modal = null"
        />

        <Modal :show="modal === 'participant'" max-width="md" @close="modal = null">
            <form @submit.prevent="addParticipant">
                <div class="modal-header"><h3 class="text-sm font-semibold">Add participant</h3></div>
                <div class="space-y-3 p-5">
                    <div class="flex gap-3 text-sm">
                        <label class="flex items-center gap-1"><input v-model="pForm.type" type="radio" value="user" /> Internal user</label>
                        <label v-if="m.lead && !leadIsParticipant" class="flex items-center gap-1"><input v-model="pForm.type" type="radio" value="lead" /> The lead</label>
                        <label class="flex items-center gap-1"><input v-model="pForm.type" type="radio" value="external" /> External</label>
                    </div>
                    <FormField v-if="pForm.type === 'user'" label="Internal user" :error="pForm.errors.user_id">
                        <ParticipantPicker v-model="picked" :lead-id="m.lead?.id ?? null" :exclude="existingUserIds" />
                    </FormField>
                    <p v-if="pForm.type === 'lead'" class="text-xs text-slate-600">{{ m.lead.full_name }} will be listed with their current contact details.</p>
                    <template v-if="pForm.type === 'external'">
                        <FormField label="Name" required :error="pForm.errors.name"><input v-model="pForm.name" type="text" class="form-input" maxlength="191" /></FormField>
                        <FormField label="Email" :error="pForm.errors.email"><input v-model="pForm.email" type="email" class="form-input" maxlength="191" /></FormField>
                        <FormField label="Phone" :error="pForm.errors.phone"><input v-model="pForm.phone" type="tel" class="form-input" maxlength="30" /></FormField>
                    </template>
                    <p v-if="pForm.errors.type" class="form-error">{{ pForm.errors.type }}</p>
                    <ConflictNotice v-model:override="pForm.override_conflict" :errors="pForm.errors" :can-override="options.can_override_conflict" />
                    <p v-if="pForm.errors.status" class="form-error">{{ pForm.errors.status }}</p>
                </div>
                <div class="modal-footer">
                    <UiButton variant="secondary" @click="modal = null">Cancel</UiButton>
                    <UiButton type="submit" :loading="pForm.processing" :disabled="pForm.type === 'user' && !pForm.user_id">Add</UiButton>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
