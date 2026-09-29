<script setup>
import Modal from '@/Components/Modal.vue';
import FormField from '@/Components/ui/FormField.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

/** Options are already limited server-side to users the actor may assign to. */
const props = defineProps({
    show: Boolean,
    lead: { type: Object, required: true },
    users: { type: Array, default: () => [] },
});
const emit = defineEmits(['close']);

const form = useForm({ assigned_to: '', reason: '' });

watch(
    () => props.show,
    (open) => {
        if (open) {
            form.reset();
            form.clearErrors();
            form.assigned_to = props.lead.assignee?.id ?? '';
        }
    },
);

const submit = () => {
    form.post(route('leads.assign', props.lead.id), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
};
</script>

<template>
    <Modal :show="show" max-width="md" @close="emit('close')">
        <form @submit.prevent="submit">
            <div class="modal-header">
                <h3 class="text-sm font-semibold">{{ lead.assignee ? 'Reassign lead' : 'Assign lead' }}</h3>
                <p class="text-2xs text-slate-500">ID {{ lead.id }} · {{ lead.lead_number }} · currently {{ lead.assignee?.name ?? 'unassigned' }}</p>
            </div>
            <div class="space-y-3 p-5">
                <FormField label="Assign to" required :error="form.errors.assigned_to" hint="The new owner gets access immediately; the previous owner loses it.">
                    <select v-model="form.assigned_to" class="form-input" required>
                        <option value="" disabled>Select a user…</option>
                        <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                </FormField>
                <FormField label="Reason" :error="form.errors.reason">
                    <input v-model="form.reason" class="form-input" maxlength="500" placeholder="Optional" />
                </FormField>
            </div>
            <div class="modal-footer">
                <UiButton variant="secondary" @click="emit('close')">Cancel</UiButton>
                <UiButton type="submit" :loading="form.processing">Save</UiButton>
            </div>
        </form>
    </Modal>
</template>
