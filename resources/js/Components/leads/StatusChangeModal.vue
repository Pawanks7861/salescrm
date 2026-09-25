<script setup>
import Modal from '@/Components/Modal.vue';
import FormField from '@/Components/ui/FormField.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

/** Captures the mandatory lost reason before a lead is moved to a "lost" status. */
const props = defineProps({
    show: Boolean,
    leadId: { type: Number, default: null },
    status: { type: Object, default: null },
    lostReasons: { type: Array, default: () => [] },
});
const emit = defineEmits(['close', 'saved']);

const form = useForm({ status_id: null, lost_reason_id: '', lost_reason_notes: '' });

watch(
    () => props.show,
    (open) => {
        if (open) {
            form.reset();
            form.clearErrors();
            form.status_id = props.status?.id ?? null;
        }
    },
);

const submit = () => {
    form.post(route('leads.status', props.leadId), {
        preserveScroll: true,
        onSuccess: () => {
            emit('saved');
            emit('close');
        },
    });
};
</script>

<template>
    <Modal :show="show" max-width="md" @close="emit('close')">
        <form @submit.prevent="submit">
            <div class="modal-header">
                <h3 class="text-sm font-semibold">Mark lead as {{ status?.name ?? 'lost' }}</h3>
                <p class="text-2xs text-slate-500">A reason is required so lost-lead reporting stays accurate.</p>
            </div>
            <div class="space-y-3 p-5">
                <FormField label="Lost reason" required :error="form.errors.lost_reason_id">
                    <select v-model="form.lost_reason_id" class="form-input" required>
                        <option value="" disabled>Select a reason…</option>
                        <option v-for="r in lostReasons" :key="r.id" :value="r.id">{{ r.name }}</option>
                    </select>
                </FormField>
                <FormField label="Notes" :error="form.errors.lost_reason_notes">
                    <textarea v-model="form.lost_reason_notes" rows="3" class="form-input" maxlength="1000" placeholder="Optional details" />
                </FormField>
                <p v-if="form.errors.status_id" class="form-error">{{ form.errors.status_id }}</p>
            </div>
            <div class="modal-footer">
                <UiButton variant="secondary" @click="emit('close')">Cancel</UiButton>
                <UiButton type="submit" variant="danger" :loading="form.processing">Mark as lost</UiButton>
            </div>
        </form>
    </Modal>
</template>
