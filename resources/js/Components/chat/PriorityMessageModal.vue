<script setup>
import Modal from '@/Components/Modal.vue';
import FormField from '@/Components/ui/FormField.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

/*
 * Sends an urgent message to every active user. Expiry is entered in the CRM
 * timezone; the server converts it and re-checks the permission.
 */
const props = defineProps({ show: Boolean });
const emit = defineEmits(['close']);

const form = useForm({ title: '', message: '', expires_at: '' });

watch(
    () => props.show,
    (open) => {
        if (!open) return;
        form.reset();
        form.clearErrors();
    },
);

const submit = () =>
    form.post(route('priority-broadcasts.store'), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
</script>

<template>
    <Modal :show="show" max-width="lg" @close="emit('close')">
        <form @submit.prevent="submit">
            <div class="modal-header">
                <h3 class="text-sm font-semibold text-red-700">Send priority message</h3>
                <p class="text-2xs text-slate-500">Goes to every active user immediately: in-app banner, notification bell and browser push. Recipients must acknowledge it.</p>
            </div>
            <div class="space-y-4 p-5">
                <FormField label="Title" required :error="form.errors.title">
                    <input v-model="form.title" class="form-input" maxlength="150" required placeholder="e.g. Office closed tomorrow" data-testid="priority-title" />
                </FormField>
                <FormField label="Message" required :error="form.errors.message" :hint="`${form.message.length} / 5000`">
                    <textarea v-model="form.message" class="form-input" rows="5" maxlength="5000" required data-testid="priority-message" />
                </FormField>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Priority">
                        <select class="form-input" disabled>
                            <option>Urgent</option>
                        </select>
                    </FormField>
                    <FormField label="Expires at" :error="form.errors.expires_at" hint="Optional. The banner hides after this time.">
                        <input v-model="form.expires_at" type="datetime-local" class="form-input" data-testid="priority-expires" />
                    </FormField>
                </div>
            </div>
            <div class="modal-footer">
                <UiButton variant="secondary" @click="emit('close')">Cancel</UiButton>
                <UiButton type="submit" variant="danger" icon="warning" :loading="form.processing" :disabled="!form.title.trim() || !form.message.trim()">Send to everyone</UiButton>
            </div>
        </form>
    </Modal>
</template>
