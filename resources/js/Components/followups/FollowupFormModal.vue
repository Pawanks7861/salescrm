<script setup>
import LeadPicker from '@/Components/followups/LeadPicker.vue';
import ReminderSelect from '@/Components/followups/ReminderSelect.vue';
import Modal from '@/Components/Modal.vue';
import FormField from '@/Components/ui/FormField.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import { nextSlot } from '@/utils/format';
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

/**
 * Create a follow-up (fixed lead from Lead 360, or picked from the module) or
 * edit a pending one. The schedule of an existing follow-up is changed only
 * through Reschedule, so edit mode hides date/time.
 */
const props = defineProps({
    show: Boolean,
    options: { type: Object, required: true },
    lead: { type: Object, default: null },
    followup: { type: Object, default: null },
    defaultAssignee: { type: Number, default: null },
});
const emit = defineEmits(['close']);

const editing = computed(() => !!props.followup);
const pickedLead = ref(null);

const form = useForm({
    lead_id: null,
    followup_type_id: '',
    scheduled_date: '',
    scheduled_time: '',
    assigned_to: '',
    priority: 'medium',
    reminder_minutes: null,
    title: '',
    description: '',
    confirm_duplicate: false,
});

watch(
    () => props.show,
    (open) => {
        if (!open) return;
        form.clearErrors();
        const f = props.followup;
        const slot = nextSlot(1);
        pickedLead.value = props.lead ?? null;
        Object.assign(form, {
            lead_id: props.lead?.id ?? null,
            followup_type_id: f?.type_id ?? props.options.types[0]?.id ?? '',
            scheduled_date: slot.date,
            scheduled_time: slot.time,
            assigned_to: f?.assigned_to ?? props.defaultAssignee ?? '',
            priority: f?.priority ?? 'medium',
            reminder_minutes: f ? f.reminder_minutes : props.options.default_reminder,
            title: f?.raw_title ?? '',
            description: f?.description ?? '',
            confirm_duplicate: false,
        });
    },
);

watch(pickedLead, (lead) => (form.lead_id = lead?.id ?? null));

const canAssign = computed(() => props.options.assignees?.length > 0);

const submit = (confirmDuplicate = false) => {
    form.confirm_duplicate = confirmDuplicate;
    const opts = { preserveScroll: true, onSuccess: () => emit('close') };
    const payload = (data) => ({ ...data, assigned_to: data.assigned_to || null });
    if (editing.value) {
        form.transform((data) => {
            const { lead_id, scheduled_date, scheduled_time, confirm_duplicate, ...rest } = payload(data);
            return rest;
        }).put(route('followups.update', props.followup.id), opts);
    } else {
        form.transform(payload).post(route('followups.store'), opts);
    }
};
</script>

<template>
    <Modal :show="show" max-width="lg" @close="emit('close')">
        <form @submit.prevent="submit(false)">
            <div class="modal-header">
                <h3 class="text-sm font-semibold">{{ editing ? 'Edit follow-up' : 'Schedule follow-up' }}</h3>
                <p v-if="lead" class="text-2xs text-slate-500">{{ lead.full_name }} · ID {{ lead.id }} · {{ lead.lead_number }}</p>
            </div>

            <div class="grid gap-3 p-5 sm:grid-cols-2">
                <FormField v-if="!editing && !lead" label="Lead" required :error="form.errors.lead_id" class="sm:col-span-2">
                    <LeadPicker v-model="pickedLead" />
                </FormField>

                <FormField label="Type" required :error="form.errors.followup_type_id">
                    <select v-model="form.followup_type_id" class="form-input" required>
                        <option v-for="t in options.types" :key="t.id" :value="t.id">{{ t.name }}</option>
                    </select>
                </FormField>
                <FormField label="Priority" :error="form.errors.priority">
                    <select v-model="form.priority" class="form-input">
                        <option v-for="p in options.priorities" :key="p.value" :value="p.value">{{ p.label }}</option>
                    </select>
                </FormField>

                <template v-if="!editing">
                    <FormField label="Date" required :error="form.errors.scheduled_date">
                        <input v-model="form.scheduled_date" type="date" class="form-input" required />
                    </FormField>
                    <FormField label="Time" required :error="form.errors.scheduled_time">
                        <input v-model="form.scheduled_time" type="time" class="form-input" required />
                    </FormField>
                </template>

                <FormField v-if="canAssign" label="Assigned to" :error="form.errors.assigned_to">
                    <select v-model="form.assigned_to" class="form-input">
                        <option value="">Lead owner / me</option>
                        <option v-for="u in options.assignees" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                </FormField>
                <FormField label="Reminder" :error="form.errors.reminder_minutes">
                    <ReminderSelect v-model="form.reminder_minutes" :options="options.reminders" />
                </FormField>

                <FormField label="Title" :error="form.errors.title" class="sm:col-span-2">
                    <input v-model="form.title" type="text" class="form-input" maxlength="191" placeholder="e.g. Share quotation" />
                </FormField>
                <FormField label="Description" :error="form.errors.description" class="sm:col-span-2">
                    <textarea v-model="form.description" rows="2" class="form-input" maxlength="5000" />
                </FormField>

                <div v-if="form.errors.duplicate" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 sm:col-span-2">
                    {{ form.errors.duplicate }}
                    <button type="button" class="ml-1 font-semibold underline" @click="submit(true)">Schedule anyway</button>
                </div>
                <p v-if="form.errors.status" class="form-error sm:col-span-2">{{ form.errors.status }}</p>
            </div>

            <div class="modal-footer">
                <UiButton variant="secondary" @click="emit('close')">Cancel</UiButton>
                <UiButton type="submit" :loading="form.processing" :disabled="!editing && !form.lead_id">{{ editing ? 'Save' : 'Schedule' }}</UiButton>
            </div>
        </form>
    </Modal>
</template>
