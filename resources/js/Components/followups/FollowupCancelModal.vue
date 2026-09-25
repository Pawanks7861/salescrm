<script setup>
import Modal from '@/Components/Modal.vue';
import FormField from '@/Components/ui/FormField.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

const props = defineProps({
    show: Boolean,
    followup: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const form = useForm({ reason: '' });

watch(
    () => props.show,
    (open) => {
        if (open) {
            form.reset();
            form.clearErrors();
        }
    },
);

const submit = () => form.post(route('followups.cancel', props.followup.id), { preserveScroll: true, onSuccess: () => emit('close') });
</script>

<template>
    <Modal :show="show" max-width="md" @close="emit('close')">
        <form v-if="followup" @submit.prevent="submit">
            <div class="modal-header">
                <h3 class="text-sm font-semibold">Cancel follow-up</h3>
                <p class="text-2xs text-slate-500">{{ followup.type?.name }} · {{ followup.lead?.full_name }}. The record is kept in history.</p>
            </div>
            <div class="space-y-3 p-5">
                <FormField label="Reason" required :error="form.errors.reason">
                    <textarea v-model="form.reason" rows="2" class="form-input" maxlength="500" placeholder="e.g. Duplicate follow-up" />
                </FormField>
                <p v-if="form.errors.status" class="form-error">{{ form.errors.status }}</p>
            </div>
            <div class="modal-footer">
                <UiButton variant="secondary" @click="emit('close')">Keep</UiButton>
                <UiButton type="submit" variant="danger" :loading="form.processing">Cancel follow-up</UiButton>
            </div>
        </form>
    </Modal>
</template>
