<script setup>
import Modal from '@/Components/Modal.vue';
import FormField from '@/Components/ui/FormField.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import { crmParts, formatDue, nextSlot } from '@/utils/format';
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

/** Reschedule keeps the original as history and creates a new pending follow-up. */
const props = defineProps({
    show: Boolean,
    followup: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const form = useForm({ scheduled_date: '', scheduled_time: '', reason: '' });

watch(
    () => props.show,
    (open) => {
        if (!open || !props.followup) return;
        form.reset();
        form.clearErrors();
        const sameTimeTomorrow = new Date(new Date(props.followup.scheduled_at).getTime() + 86400000);
        const slot = sameTimeTomorrow > new Date() ? crmParts(sameTimeTomorrow) : nextSlot(24);
        form.scheduled_date = slot.date;
        form.scheduled_time = slot.time;
    },
);

const submit = () => form.post(route('followups.reschedule', props.followup.id), { preserveScroll: true, onSuccess: () => emit('close') });
</script>

<template>
    <Modal :show="show" max-width="md" @close="emit('close')">
        <form v-if="followup" @submit.prevent="submit">
            <div class="modal-header">
                <h3 class="text-sm font-semibold">Reschedule follow-up</h3>
                <p class="text-2xs text-slate-500">{{ followup.lead?.full_name }} · currently {{ formatDue(followup.scheduled_at) }}</p>
            </div>
            <div class="grid grid-cols-2 gap-3 p-5">
                <FormField label="New date" required :error="form.errors.scheduled_date">
                    <input v-model="form.scheduled_date" type="date" class="form-input" required />
                </FormField>
                <FormField label="New time" required :error="form.errors.scheduled_time">
                    <input v-model="form.scheduled_time" type="time" class="form-input" required />
                </FormField>
                <FormField label="Reason" :error="form.errors.reason" class="col-span-2">
                    <input v-model="form.reason" type="text" class="form-input" maxlength="500" placeholder="e.g. Customer asked to call tomorrow" />
                </FormField>
                <p v-if="form.errors.status" class="form-error col-span-2">{{ form.errors.status }}</p>
            </div>
            <div class="modal-footer">
                <UiButton variant="secondary" @click="emit('close')">Cancel</UiButton>
                <UiButton type="submit" :loading="form.processing" icon="clock">Reschedule</UiButton>
            </div>
        </form>
    </Modal>
</template>
