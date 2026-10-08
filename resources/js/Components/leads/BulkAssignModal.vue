<script setup>
import Modal from '@/Components/Modal.vue';
import FormField from '@/Components/ui/FormField.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

/** Options are already limited server-side to users the actor may assign to. */
const props = defineProps({
    show: Boolean,
    leadIds: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
});
const emit = defineEmits(['close', 'assigned']);

const form = useForm({ lead_ids: [], assigned_to: '', reason: '' });
const countLabel = computed(() => `${props.leadIds.length} lead${props.leadIds.length === 1 ? '' : 's'}`);

watch(
    () => props.show,
    (open) => {
        if (open) {
            form.reset();
            form.clearErrors();
            form.lead_ids = [...props.leadIds];
        }
    },
);

const submit = () => {
    form.lead_ids = [...props.leadIds];
    form.post(route('leads.bulk-assign'), {
        preserveScroll: true,
        onSuccess: () => {
            emit('assigned');
            emit('close');
        },
    });
};
</script>

<template>
    <Modal :show="show" max-width="md" @close="emit('close')">
        <form @submit.prevent="submit">
            <div class="modal-header">
                <h3 class="text-sm font-semibold">Assign leads</h3>
                <p class="text-2xs text-slate-500">{{ countLabel }} will be assigned to the selected owner.</p>
            </div>
            <div class="space-y-3 p-5">
                <FormField label="Assign to" required :error="form.errors.assigned_to" hint="The new owner gets access immediately. Leads already owned by them stay as they are.">
                    <select v-model="form.assigned_to" class="form-input" required data-testid="bulk-assign-user">
                        <option value="" disabled>Select a user…</option>
                        <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                </FormField>
                <FormField label="Reason" :error="form.errors.reason">
                    <input v-model="form.reason" class="form-input" maxlength="500" placeholder="Optional" />
                </FormField>
                <p v-if="form.errors.lead_ids" class="form-error">{{ form.errors.lead_ids }}</p>
            </div>
            <div class="modal-footer">
                <UiButton variant="secondary" @click="emit('close')">Cancel</UiButton>
                <UiButton type="submit" :loading="form.processing" data-testid="bulk-assign-save">Assign</UiButton>
            </div>
        </form>
    </Modal>
</template>
