<script setup>
import ReminderSelect from '@/Components/followups/ReminderSelect.vue';
import Modal from '@/Components/Modal.vue';
import FormField from '@/Components/ui/FormField.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import { nextSlot } from '@/utils/format';
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

/**
 * Complete a follow-up; optionally schedule the next one and/or change the
 * lead status. The server does all of it in one transaction.
 */
const props = defineProps({
    show: Boolean,
    followup: { type: Object, default: null },
    options: { type: Object, required: true },
});
const emit = defineEmits(['close']);

const blankNext = () => ({
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
    next_action: '',
    schedule_next: false,
    next: blankNext(),
    status_id: '',
    lost_reason_id: '',
    lost_reason_notes: '',
});

watch(
    () => props.show,
    (open) => {
        if (!open || !props.followup) return;
        form.reset();
        form.clearErrors();
        const slot = nextSlot(24);
        const assignee = props.followup.assignee?.id;
        form.next = {
            ...blankNext(),
            followup_type_id: props.followup.type?.id ?? props.options.types[0]?.id ?? '',
            scheduled_date: slot.date,
            scheduled_time: slot.time,
            priority: props.followup.priority ?? 'medium',
            reminder_minutes: props.options.default_reminder,
            assigned_to: props.options.assignees?.some((u) => u.id === assignee) ? assignee : '',
        };
    },
);

const statuses = computed(() => props.options.statuses ?? []);
const selectedStatus = computed(() => statuses.value.find((s) => s.id === form.status_id));
const canAssign = computed(() => props.options.assignees?.length > 0);
const err = (key) => form.errors[key];

const submit = (confirmDuplicate = false) => {
    form.next.confirm_duplicate = confirmDuplicate;
    form.transform((data) => ({
        ...data,
        outcome: data.outcome || null,
        status_id: data.status_id || null,
        lost_reason_id: data.lost_reason_id || null,
        next: data.schedule_next ? { ...data.next, assigned_to: data.next.assigned_to || null } : null,
    })).post(route('followups.complete', props.followup.id), { preserveScroll: true, onSuccess: () => emit('close') });
};
</script>

<template>
    <Modal :show="show" max-width="lg" @close="emit('close')">
        <form v-if="followup" @submit.prevent="submit(false)">
            <div class="modal-header">
                <h3 class="text-sm font-semibold">Complete follow-up</h3>
                <p class="text-2xs text-slate-500">{{ followup.type?.name }} · {{ followup.lead?.full_name }}</p>
            </div>

            <div class="space-y-3 p-5">
                <FormField label="Outcome" required :error="err('outcome')">
                    <select v-model="form.outcome" class="form-input">
                        <option value="" disabled>Select outcome…</option>
                        <option v-for="o in options.outcomes" :key="o.value" :value="o.value">{{ o.label }}</option>
                    </select>
                </FormField>
                <FormField label="Notes" :error="err('notes')">
                    <textarea v-model="form.notes" rows="2" class="form-input" maxlength="5000" placeholder="What happened?" />
                </FormField>
                <FormField label="Next action" :error="err('next_action')">
                    <input v-model="form.next_action" type="text" class="form-input" maxlength="255" placeholder="e.g. Send quotation" />
                </FormField>

                <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                    <input v-model="form.schedule_next" type="checkbox" class="rounded border-slate-300 text-brand-600" />
                    Schedule next follow-up
                </label>

                <div v-if="form.schedule_next" class="grid gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 sm:grid-cols-2">
                    <FormField label="Type" required :error="err('next.followup_type_id')">
                        <select v-model="form.next.followup_type_id" class="form-input">
                            <option v-for="t in options.types" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                    </FormField>
                    <FormField label="Priority" :error="err('next.priority')">
                        <select v-model="form.next.priority" class="form-input">
                            <option v-for="p in options.priorities" :key="p.value" :value="p.value">{{ p.label }}</option>
                        </select>
                    </FormField>
                    <FormField label="Date" required :error="err('next.scheduled_date')">
                        <input v-model="form.next.scheduled_date" type="date" class="form-input" />
                    </FormField>
                    <FormField label="Time" required :error="err('next.scheduled_time')">
                        <input v-model="form.next.scheduled_time" type="time" class="form-input" />
                    </FormField>
                    <FormField v-if="canAssign" label="Assigned to" :error="err('next.assigned_to')">
                        <select v-model="form.next.assigned_to" class="form-input">
                            <option value="">Lead owner / me</option>
                            <option v-for="u in options.assignees" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </select>
                    </FormField>
                    <FormField label="Reminder" :error="err('next.reminder_minutes')">
                        <ReminderSelect v-model="form.next.reminder_minutes" :options="options.reminders" />
                    </FormField>
                    <FormField label="Title" :error="err('next.title')" class="sm:col-span-2">
                        <input v-model="form.next.title" type="text" class="form-input" maxlength="191" />
                    </FormField>
                    <div v-if="err('next.duplicate')" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 sm:col-span-2">
                        {{ err('next.duplicate') }}
                        <button type="button" class="ml-1 font-semibold underline" @click="submit(true)">Schedule anyway</button>
                    </div>
                </div>

                <div v-if="options.canChangeLeadStatus" class="grid gap-3 sm:grid-cols-2">
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
                <UiButton type="submit" :loading="form.processing" icon="check">Complete</UiButton>
            </div>
        </form>
    </Modal>
</template>
