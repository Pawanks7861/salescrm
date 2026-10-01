<script setup>
import LeadSelector from '@/Components/batches/LeadSelector.vue';
import Modal from '@/Components/Modal.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

const props = defineProps({
    show: Boolean,
    batch: { type: Object, required: true },
    statuses: { type: Array, default: () => [] },
    sources: { type: Array, default: () => [] },
});
const emit = defineEmits(['close']);

const form = useForm({ lead_ids: [] });

watch(
    () => props.show,
    (open) => {
        if (open) {
            form.reset();
            form.clearErrors();
        }
    },
);

const submit = () => {
    form.post(route('batches.leads.store', props.batch.id), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
};
</script>

<template>
    <Modal :show="show" max-width="2xl" @close="emit('close')">
        <form @submit.prevent="submit">
            <div class="modal-header">
                <h3 class="text-sm font-semibold">Add leads to {{ batch.name }}</h3>
                <p class="text-2xs text-slate-500">Only leads you can already access are listed.</p>
            </div>
            <div class="p-5">
                <LeadSelector v-if="show" v-model="form.lead_ids" :batch-id="batch.id" :statuses="statuses" :sources="sources" />
                <p v-if="form.errors.lead_ids" class="form-error mt-2">{{ form.errors.lead_ids }}</p>
            </div>
            <div class="modal-footer">
                <UiButton variant="secondary" @click="emit('close')">Cancel</UiButton>
                <UiButton type="submit" :loading="form.processing" :disabled="!form.lead_ids.length">
                    Add {{ form.lead_ids.length }} lead{{ form.lead_ids.length === 1 ? '' : 's' }}
                </UiButton>
            </div>
        </form>
    </Modal>
</template>
