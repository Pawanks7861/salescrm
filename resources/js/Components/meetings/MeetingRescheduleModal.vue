<script setup>
import ConflictNotice from '@/Components/meetings/ConflictNotice.vue';
import Modal from '@/Components/Modal.vue';
import FormField from '@/Components/ui/FormField.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import { addMinutes, crmParts, durationLabel, formatRange, minutesBetween, nextSlot } from '@/utils/format';
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

/**
 * Reschedule keeps the original as history (status "rescheduled") and creates
 * a new meeting with the same participants. Used by the calendar drag & drop
 * too (preset = dropped time), so every change goes through this workflow.
 */
const props = defineProps({
    show: Boolean,
    meeting: { type: Object, default: null },
    preset: { type: Object, default: null }, // { date, start_time, end_time, end_date }
    canOverride: { type: Boolean, default: false },
    stay: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'done']);

const form = useForm({ scheduled_date: '', start_time: '', end_time: '', end_date: '', reason: '', override_conflict: false, stay: false });

watch(
    () => props.show,
    (open) => {
        if (!open || !props.meeting) return;
        form.reset();
        form.clearErrors();
        const minutes = props.meeting.duration_minutes || 30;
        let start;
        if (props.preset) {
            start = { date: props.preset.date, time: props.preset.start_time };
        } else {
            const sameTimeTomorrow = new Date(new Date(props.meeting.start_at).getTime() + 86400000);
            start = sameTimeTomorrow > new Date() ? crmParts(sameTimeTomorrow) : nextSlot(24);
        }
        const end = props.preset?.end_time ? { date: props.preset.end_date || start.date, time: props.preset.end_time } : addMinutes(start.date, start.time, minutes);
        Object.assign(form, {
            scheduled_date: start.date,
            start_time: start.time,
            end_time: end.time,
            end_date: end.date !== start.date ? end.date : '',
            stay: props.stay,
        });
    },
);

const duration = computed(() => minutesBetween(form.scheduled_date, form.start_time, form.end_date || form.scheduled_date, form.end_time));

const submit = () =>
    form
        .transform((d) => ({ ...d, end_date: d.end_date || null }))
        .post(route('meetings.reschedule', props.meeting.id), {
            preserveScroll: true,
            onSuccess: () => {
                emit('done');
                emit('close');
            },
        });
</script>

<template>
    <Modal :show="show" max-width="md" @close="emit('close')">
        <form v-if="meeting" @submit.prevent="submit">
            <div class="modal-header">
                <h3 class="text-sm font-semibold">Reschedule {{ meeting.meeting_number }}</h3>
                <p class="text-2xs text-slate-500">{{ meeting.title }} · currently {{ formatRange(meeting.start_at, meeting.end_at) }}</p>
            </div>
            <div class="grid grid-cols-3 gap-3 p-5">
                <FormField label="New date" required :error="form.errors.scheduled_date">
                    <input v-model="form.scheduled_date" type="date" class="form-input" required />
                </FormField>
                <FormField label="Start" required :error="form.errors.start_time">
                    <input v-model="form.start_time" type="time" class="form-input" required />
                </FormField>
                <FormField label="End" required :error="form.errors.end_time" :hint="duration > 0 ? durationLabel(duration) : ''">
                    <input v-model="form.end_time" type="time" class="form-input" required />
                </FormField>
                <FormField v-if="form.end_date || duration <= 0" label="End date" :error="form.errors.end_date" class="col-span-3">
                    <input v-model="form.end_date" type="date" class="form-input" />
                </FormField>
                <FormField label="Reason" :error="form.errors.reason" class="col-span-3">
                    <input v-model="form.reason" type="text" class="form-input" maxlength="1000" placeholder="e.g. Client requested a morning slot" />
                </FormField>
                <div class="col-span-3">
                    <ConflictNotice v-model:override="form.override_conflict" :errors="form.errors" :can-override="canOverride" />
                </div>
                <p v-if="form.errors.status" class="form-error col-span-3">{{ form.errors.status }}</p>
            </div>
            <div class="modal-footer">
                <UiButton variant="secondary" @click="emit('close')">Cancel</UiButton>
                <UiButton type="submit" :loading="form.processing" icon="clock">Reschedule</UiButton>
            </div>
        </form>
    </Modal>
</template>
