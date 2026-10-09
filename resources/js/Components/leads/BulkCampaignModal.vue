<script setup>
import Modal from '@/Components/Modal.vue';
import FormField from '@/Components/ui/FormField.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({
    show: Boolean,
    leadIds: { type: Array, default: () => [] },
    campaigns: { type: Array, default: () => [] },
});
const emit = defineEmits(['close', 'updated']);

const form = useForm({ lead_ids: [], campaign_id: '' });
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
    form.post(route('leads.bulk-campaign'), {
        preserveScroll: true,
        onSuccess: () => {
            emit('updated');
            emit('close');
        },
    });
};
</script>

<template>
    <Modal :show="show" max-width="md" @close="emit('close')">
        <form @submit.prevent="submit">
            <div class="modal-header">
                <h3 class="text-sm font-semibold">Set campaign</h3>
                <p class="text-2xs text-slate-500">{{ countLabel }} will use the selected campaign.</p>
            </div>
            <div class="space-y-3 p-5">
                <FormField label="Campaign" required :error="form.errors.campaign_id" hint="Leads already on this campaign stay as they are.">
                    <select v-model="form.campaign_id" class="form-input" required data-testid="bulk-campaign">
                        <option value="" disabled>Select a campaign…</option>
                        <option v-for="c in campaigns" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </FormField>
                <p v-if="form.errors.lead_ids" class="form-error">{{ form.errors.lead_ids }}</p>
            </div>
            <div class="modal-footer">
                <UiButton variant="secondary" @click="emit('close')">Cancel</UiButton>
                <UiButton type="submit" :loading="form.processing" data-testid="bulk-campaign-save">Update</UiButton>
            </div>
        </form>
    </Modal>
</template>
