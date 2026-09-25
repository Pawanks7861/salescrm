<script setup>
import ReminderSelect from '@/Components/followups/ReminderSelect.vue';
import Modal from '@/Components/Modal.vue';
import FormField from '@/Components/ui/FormField.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import { addMinutes, formatDateTime, nextSlot } from '@/utils/format';
import { useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';

/**
 * Post-call outcome: disposition, notes, next action (follow-up / meeting)
 * and optional lead status. The server applies everything in one transaction
 * through FollowupService, MeetingService and LeadService.
 */
const props = defineProps({
    show: Boolean,
    callId: { type: Number, default: null },
    preloaded: { type: Object, default: null }, // { call, options } when the page already has them
    dismissible: { type: Boolean, default: true },
});
const emit = defineEmits(['close', 'saved']);

const call = ref(null);
const options = ref(null);
const loading = ref(false);
const loadError = ref(null);

const blankFollowup = () => ({ followup_type_id: '', scheduled_date: '', scheduled_time: '', priority: 'medium', reminder_minutes: null, title: '', confirm_duplicate: false });
const blankMeeting = () => ({ meeting_type_id: '', title: '', scheduled_date: '', start_time: '', end_time: '', location_type: '', location: '', meeting_url: '', reminders: [], override_conflict: false });

const form = useForm({
    disposition_id: '',
    notes: '',
    next_action: 'none',
    status_id: '',
    lost_reason_id: '',
    lost_reason_notes: '',
    followup: blankFollowup(),
    meeting: blankMeeting(),
});

const prepare = (data) => {
    call.value = data.call;
    options.value = data.options;
    form.reset();
    form.clearErrors();
    form.notes = data.call?.notes ?? '';
    form.disposition_id = data.call?.disposition?.id ?? '';

    const slot = nextSlot(24);
    const name = data.call?.lead?.full_name ?? 'lead';
    const f = data.options.followup;
    form.followup = { ...blankFollowup(), followup_type_id: f?.types?.[0]?.id ?? '', scheduled_date: slot.date, scheduled_time: slot.time, reminder_minutes: f?.default_reminder ?? null, title: `Call back ${name}` };
    const m = data.options.meeting;
    const type = m?.types?.[0];
    const duration = type?.default_duration_minutes ?? m?.default_duration ?? 30;
    form.meeting = {
        ...blankMeeting(),
        meeting_type_id: type?.id ?? '',
        title: `Meeting with ${name}`,
        scheduled_date: slot.date,
        start_time: slot.time,
        end_time: addMinutes(slot.date, slot.time, duration).time,
        location_type: type?.default_location_type ?? '',
        reminders: m?.default_reminders ?? [],
    };
};

watch(
    () => [props.show, props.callId],
    async ([open]) => {
        if (!open) return;
        loadError.value = null;
        if (props.preloaded) return prepare(props.preloaded);
        if (!props.callId) return;
        loading.value = true;
        try {
            const { data } = await axios.get(route('calls.outcome.options', props.callId));
            prepare(data);
        } catch (e) {
            loadError.value = e?.response?.status === 403 || e?.response?.status === 404 ? 'This call is no longer available to you.' : 'Could not load the call. Please try again.';
        } finally {
            loading.value = false;
        }
    },
    { immediate: true },
);

const dispositions = computed(() => options.value?.dispositions ?? []);
const selected = computed(() => dispositions.value.find((d) => d.id === form.disposition_id));
const statuses = computed(() => options.value?.statuses ?? []);
const selectedStatus = computed(() => statuses.value.find((s) => s.id === form.status_id));
const needsNextAction = computed(() => !!selected.value?.requires_next_action && form.next_action === 'none');
const err = (key) => form.errors[key];
const meetingConflicts = computed(() => Object.entries(form.errors).filter(([k]) => k.startsWith('meeting.conflicts.')).map(([, v]) => v));

const submit = (confirmDuplicate = false, overrideConflict = false) => {
    form.followup.confirm_duplicate = confirmDuplicate;
    form.meeting.override_conflict = overrideConflict;
    form.transform((data) => ({
        disposition_id: data.disposition_id || null,
        notes: data.notes || null,
        next_action: data.next_action,
        status_id: data.status_id || null,
        lost_reason_id: data.lost_reason_id || null,
        lost_reason_notes: data.lost_reason_notes || null,
        followup: data.next_action === 'followup' ? data.followup : null,
        meeting: data.next_action === 'meeting' ? { ...data.meeting, location_type: data.meeting.location_type || null, meeting_url: data.meeting.meeting_url || null } : null,
    })).post(route('calls.outcome', call.value.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('saved'),
    });
};
</script>

<template>
    <Modal :show="show" max-width="lg" :closeable="dismissible" @close="dismissible && emit('close')">
        <div v-if="loading" class="p-6 text-center text-sm text-slate-500">Loading call…</div>
        <div v-else-if="loadError" class="space-y-3 p-6 text-center text-sm text-slate-600">
            <p>{{ loadError }}</p>
            <UiButton variant="secondary" @click="emit('close')">Close</UiButton>
        </div>
        <form v-else-if="call && options" @submit.prevent="submit(false)">
            <div class="modal-header">
                <h3 class="text-sm font-semibold">Call outcome · {{ call.call_number }}</h3>
                <p class="text-2xs text-slate-500">
                    <template v-if="call.lead">{{ call.lead.full_name }} · </template>{{ call.direction_label }} · {{ formatDateTime(call.started_at) }}
                </p>
            </div>

            <div class="max-h-[70vh] space-y-3 overflow-y-auto p-5">
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="text-slate-500">Duration</span>
                    <span class="font-semibold text-slate-800">{{ call.duration || '0s' }}</span>
                    <UiBadge :color="call.status_color">{{ call.status_label }}</UiBadge>
                </div>

                <FormField label="Disposition" required :error="err('disposition_id')">
                    <select v-model="form.disposition_id" class="form-input" required>
                        <option value="" disabled>Select outcome…</option>
                        <option v-for="d in dispositions" :key="d.id" :value="d.id">{{ d.name }}</option>
                    </select>
                </FormField>

                <FormField label="Notes" :required="!!selected?.requires_note" :error="err('notes')">
                    <textarea v-model="form.notes" rows="3" class="form-input" maxlength="5000" placeholder="What was discussed?" />
                </FormField>

                <div v-if="options.can.scheduleFollowup || options.can.scheduleMeeting">
                    <p class="form-label">Next action<span v-if="selected?.requires_next_action" class="text-red-500"> *</span></p>
                    <div class="flex flex-wrap gap-3 text-sm">
                        <label class="flex items-center gap-1.5"><input v-model="form.next_action" type="radio" value="none" class="text-brand-600" /> None</label>
                        <label v-if="options.can.scheduleFollowup" class="flex items-center gap-1.5"><input v-model="form.next_action" type="radio" value="followup" class="text-brand-600" /> Schedule follow-up</label>
                        <label v-if="options.can.scheduleMeeting" class="flex items-center gap-1.5"><input v-model="form.next_action" type="radio" value="meeting" class="text-brand-600" /> Schedule meeting</label>
                    </div>
                    <p v-if="needsNextAction" class="mt-1 text-2xs text-amber-700">“{{ selected.name }}” needs a follow-up or meeting.</p>
                    <p v-if="err('next_action')" class="form-error">{{ err('next_action') }}</p>
                </div>

                <div v-if="form.next_action === 'followup' && options.followup" class="grid gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 sm:grid-cols-2">
                    <FormField label="Type" required :error="err('followup.followup_type_id')">
                        <select v-model="form.followup.followup_type_id" class="form-input">
                            <option v-for="t in options.followup.types" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                    </FormField>
                    <FormField label="Priority" :error="err('followup.priority')">
                        <select v-model="form.followup.priority" class="form-input">
                            <option v-for="p in options.followup.priorities" :key="p.value" :value="p.value">{{ p.label }}</option>
                        </select>
                    </FormField>
                    <FormField label="Date" required :error="err('followup.scheduled_date')">
                        <input v-model="form.followup.scheduled_date" type="date" class="form-input" />
                    </FormField>
                    <FormField label="Time" required :error="err('followup.scheduled_time')">
                        <input v-model="form.followup.scheduled_time" type="time" class="form-input" />
                    </FormField>
                    <FormField label="Reminder" :error="err('followup.reminder_minutes')">
                        <ReminderSelect v-model="form.followup.reminder_minutes" :options="options.followup.reminders" />
                    </FormField>
                    <FormField label="Title" :error="err('followup.title')">
                        <input v-model="form.followup.title" type="text" class="form-input" maxlength="191" />
                    </FormField>
                    <p v-if="err('followup.assigned_to')" class="form-error sm:col-span-2">{{ err('followup.assigned_to') }}</p>
                    <div v-if="err('followup.duplicate')" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 sm:col-span-2">
                        {{ err('followup.duplicate') }}
                        <button type="button" class="ml-1 font-semibold underline" @click="submit(true)">Schedule anyway</button>
                    </div>
                </div>

                <div v-if="form.next_action === 'meeting' && options.meeting" class="grid gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 sm:grid-cols-3">
                    <FormField label="Meeting type" required :error="err('meeting.meeting_type_id')" class="sm:col-span-3">
                        <select v-model="form.meeting.meeting_type_id" class="form-input">
                            <option v-for="t in options.meeting.types" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                    </FormField>
                    <FormField label="Date" required :error="err('meeting.scheduled_date')">
                        <input v-model="form.meeting.scheduled_date" type="date" class="form-input" />
                    </FormField>
                    <FormField label="Start" required :error="err('meeting.start_time')">
                        <input v-model="form.meeting.start_time" type="time" class="form-input" />
                    </FormField>
                    <FormField label="End" required :error="err('meeting.end_time')">
                        <input v-model="form.meeting.end_time" type="time" class="form-input" />
                    </FormField>
                    <FormField label="Location type" :error="err('meeting.location_type')">
                        <select v-model="form.meeting.location_type" class="form-input">
                            <option value="">Default</option>
                            <option v-for="l in options.meeting.location_types" :key="l.value" :value="l.value">{{ l.label }}</option>
                        </select>
                    </FormField>
                    <FormField v-if="form.meeting.location_type === 'online'" label="Meeting link" :error="err('meeting.meeting_url')" class="sm:col-span-2">
                        <input v-model="form.meeting.meeting_url" type="url" class="form-input" maxlength="500" placeholder="https://" />
                    </FormField>
                    <FormField v-else label="Location" :error="err('meeting.location')" class="sm:col-span-2">
                        <input v-model="form.meeting.location" type="text" class="form-input" maxlength="191" />
                    </FormField>
                    <FormField label="Title" :error="err('meeting.title')" class="sm:col-span-3">
                        <input v-model="form.meeting.title" type="text" class="form-input" maxlength="191" />
                    </FormField>
                    <div v-if="meetingConflicts.length" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 sm:col-span-3">
                        <p v-for="(c, i) in meetingConflicts" :key="i">{{ c }}</p>
                        <button v-if="err('meeting.conflict_override')" type="button" class="mt-1 font-semibold underline" @click="submit(false, true)">Schedule anyway</button>
                    </div>
                </div>

                <div v-if="options.can.changeLeadStatus && statuses.length" class="grid gap-3 sm:grid-cols-2">
                    <FormField label="Update lead status (optional)" :error="err('status_id')" class="sm:col-span-2">
                        <select v-model="form.status_id" class="form-input">
                            <option value="">Keep current status</option>
                            <option v-for="s in statuses" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </select>
                    </FormField>
                    <template v-if="selectedStatus?.is_lost">
                        <FormField label="Lost reason" required :error="err('lost_reason_id')">
                            <select v-model="form.lost_reason_id" class="form-input">
                                <option value="" disabled>Select a reason…</option>
                                <option v-for="r in options.lostReasons" :key="r.id" :value="r.id">{{ r.name }}</option>
                            </select>
                        </FormField>
                        <FormField label="Lost notes" :error="err('lost_reason_notes')">
                            <input v-model="form.lost_reason_notes" type="text" class="form-input" maxlength="1000" />
                        </FormField>
                    </template>
                </div>
                <p v-if="err('call')" class="form-error">{{ err('call') }}</p>
                <p v-if="err('status')" class="form-error">{{ err('status') }}</p>
            </div>

            <div class="modal-footer">
                <UiButton v-if="dismissible" variant="secondary" @click="emit('close')">Later</UiButton>
                <UiButton type="submit" :loading="form.processing" icon="check">Save outcome</UiButton>
            </div>
        </form>
    </Modal>
</template>
