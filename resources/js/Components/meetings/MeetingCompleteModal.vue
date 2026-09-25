<script setup>
import ReminderSelect from '@/Components/followups/ReminderSelect.vue';
import Modal from '@/Components/Modal.vue';
import FormField from '@/Components/ui/FormField.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import { nextSlot } from '@/utils/format';
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

/**
 * Complete a meeting: outcome, notes, attendance, optional lead status change
 * and optional next follow-up. The server runs it all in one transaction
 * through LeadService and FollowupService.
 */
const props = defineProps({
    show: Boolean,
    meeting: { type: Object, default: null },
    options: { type: Object, required: true }, // meeting options + statuses, lostReasons, followup
    canChangeLeadStatus: { type: Boolean, default: false },
    canScheduleFollowup: { type: Boolean, default: false },
});
const emit = defineEmits(['close']);

const blankFollowup = () => ({
    followup_type_id: '',
    scheduled_date: '',
    scheduled_time: '',
    assigned_to: '',
    priority: 'medium',
    reminder_minutes: null,
    title: '',
    confirm_duplicate: false,
});

const form = useForm({
    outcome: '',
    notes: '',
    attendance: {},
    status_id: '',
    lost_reason_id: '',
    lost_reason_notes: '',
    schedule_followup: false,
    followup: blankFollowup(),
});

const followupOptions = computed(() => props.options.followup);

watch(
    () => props.show,
    (open) => {
        if (!open || !props.meeting) return;
        form.reset();
        form.clearErrors();
        form.attendance = Object.fromEntries((props.meeting.participants ?? []).map((p) => [p.id, '']));
        const slot = nextSlot(24);
        form.followup = {
            ...blankFollowup(),
            followup_type_id: followupOptions.value?.types?.[0]?.id ?? '',
            scheduled_date: slot.date,
            scheduled_time: slot.time,
            priority: props.meeting.priority ?? 'medium',
            reminder_minutes: followupOptions.value?.default_reminder ?? null,
            title: `Follow up after ${props.meeting.type?.name ?? 'meeting'}`,
        };
    },
);

const statuses = computed(() => props.options.statuses ?? []);
const selectedStatus = computed(() => statuses.value.find((s) => s.id === form.status_id));
const err = (key) => form.errors[key];

const submit = (confirmDuplicate = false) => {
    form.followup.confirm_duplicate = confirmDuplicate;
    form.transform((data) => ({
        ...data,
        outcome: data.outcome || null,
        status_id: data.status_id || null,
        lost_reason_id: data.lost_reason_id || null,
        attendance: Object.fromEntries(Object.entries(data.attendance).filter(([, v]) => v)),
        followup: data.schedule_followup ? { ...data.followup, assigned_to: data.followup.assigned_to || null } : null,
    })).post(route('meetings.complete', props.meeting.id), { preserveScroll: true, onSuccess: () => emit('close') });
};
</script>

<template>
    <Modal :show="show" max-width="lg" @close="emit('close')">
        <form v-if="meeting" @submit.prevent="submit(false)">
            <div class="modal-header">
                <h3 class="text-sm font-semibold">Complete {{ meeting.meeting_number }}</h3>
                <p class="text-2xs text-slate-500">{{ meeting.type?.name }}<template v-if="meeting.lead"> · {{ meeting.lead.full_name }}</template></p>
            </div>

            <div class="max-h-[70vh] space-y-3 overflow-y-auto p-5">
                <FormField label="Outcome" required :error="err('outcome')">
                    <select v-model="form.outcome" class="form-input">
                        <option value="" disabled>Select outcome…</option>
                        <option v-for="o in options.outcomes" :key="o.value" :value="o.value">{{ o.label }}</option>
                    </select>
                </FormField>
                <FormField label="Meeting notes" :error="err('notes')" hint="Stored on the meeting; the lead timeline only shows the outcome.">
                    <textarea v-model="form.notes" rows="3" class="form-input" maxlength="10000" placeholder="What was discussed? What was agreed?" />
                </FormField>

                <div v-if="meeting.participants?.length">
                    <p class="form-label">Attendance (optional)</p>
                    <div v-for="p in meeting.participants" :key="p.id" class="flex items-center justify-between gap-2 py-0.5 text-sm">
                        <span class="truncate text-slate-700">{{ p.name }} <span class="text-2xs text-slate-400">({{ p.type }})</span></span>
                        <select v-model="form.attendance[p.id]" class="form-input w-32 py-1 text-xs">
                            <option value="">Not recorded</option>
                            <option value="attended">Attended</option>
                            <option value="absent">Absent</option>
                        </select>
                    </div>
                </div>

                <template v-if="canScheduleFollowup && followupOptions">
                    <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                        <input v-model="form.schedule_followup" type="checkbox" class="rounded border-slate-300 text-brand-600" />
                        Schedule follow-up
                    </label>
                    <p v-if="err('schedule_followup')" class="form-error">{{ err('schedule_followup') }}</p>
                    <div v-if="form.schedule_followup" class="grid gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 sm:grid-cols-2">
                        <FormField label="Type" required :error="err('followup.followup_type_id')">
                            <select v-model="form.followup.followup_type_id" class="form-input">
                                <option v-for="t in followupOptions.types" :key="t.id" :value="t.id">{{ t.name }}</option>
                            </select>
                        </FormField>
                        <FormField label="Priority" :error="err('followup.priority')">
                            <select v-model="form.followup.priority" class="form-input">
                                <option v-for="p in followupOptions.priorities" :key="p.value" :value="p.value">{{ p.label }}</option>
                            </select>
                        </FormField>
                        <FormField label="Date" required :error="err('followup.scheduled_date')">
                            <input v-model="form.followup.scheduled_date" type="date" class="form-input" />
                        </FormField>
                        <FormField label="Time" required :error="err('followup.scheduled_time')">
                            <input v-model="form.followup.scheduled_time" type="time" class="form-input" />
                        </FormField>
                        <FormField v-if="followupOptions.assignees?.length" label="Assigned to" :error="err('followup.assigned_to')">
                            <select v-model="form.followup.assigned_to" class="form-input">
                                <option value="">Lead owner / me</option>
                                <option v-for="u in followupOptions.assignees" :key="u.id" :value="u.id">{{ u.name }}</option>
                            </select>
                        </FormField>
                        <FormField label="Reminder" :error="err('followup.reminder_minutes')">
                            <ReminderSelect v-model="form.followup.reminder_minutes" :options="followupOptions.reminders" />
                        </FormField>
                        <FormField label="Title" :error="err('followup.title')" class="sm:col-span-2">
                            <input v-model="form.followup.title" type="text" class="form-input" maxlength="191" />
                        </FormField>
                        <div v-if="err('followup.duplicate')" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 sm:col-span-2">
                            {{ err('followup.duplicate') }}
                            <button type="button" class="ml-1 font-semibold underline" @click="submit(true)">Schedule anyway</button>
                        </div>
                    </div>
                </template>

                <div v-if="canChangeLeadStatus && statuses.length" class="grid gap-3 sm:grid-cols-2">
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
                <p v-if="err('status')" class="form-error">{{ err('status') }}</p>
            </div>

            <div class="modal-footer">
                <UiButton variant="secondary" @click="emit('close')">Cancel</UiButton>
                <UiButton type="submit" :loading="form.processing" icon="check">Complete meeting</UiButton>
            </div>
        </form>
    </Modal>
</template>
