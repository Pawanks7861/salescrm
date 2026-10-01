<script setup>
import TrainerSelector from '@/Components/batches/TrainerSelector.vue';
import Modal from '@/Components/Modal.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

/** Adds one or more trainers to an existing batch; the server re-checks every id is an active Trainer. */
const props = defineProps({
    show: Boolean,
    batch: { type: Object, required: true },
});
const emit = defineEmits(['close']);

const selected = ref([]);
const form = useForm({ trainer_ids: [] });

watch(
    () => props.show,
    (open) => {
        if (!open) return;
        selected.value = [];
        form.reset();
        form.clearErrors();
    },
);

const submit = () => {
    form.trainer_ids = selected.value.map((t) => t.id);
    form.post(route('batches.trainers.store', props.batch.id), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
};
</script>

<template>
    <Modal :show="show" max-width="lg" @close="emit('close')">
        <form @submit.prevent="submit">
            <div class="modal-header">
                <h3 class="text-sm font-semibold">Add Trainer</h3>
                <p class="text-2xs text-slate-500">Only active trainers are listed. Assigning a trainer does not give access to any lead.</p>
            </div>
            <div class="p-5">
                <TrainerSelector v-if="show" v-model="selected" :exclude-ids="(batch.trainers ?? []).map((t) => t.id)" :show-selected="false" />
                <p v-if="form.errors.trainer_ids" class="form-error mt-2">{{ form.errors.trainer_ids }}</p>
            </div>
            <div class="modal-footer">
                <UiButton variant="secondary" @click="emit('close')">Cancel</UiButton>
                <UiButton type="submit" :loading="form.processing" :disabled="!selected.length">
                    {{ selected.length > 1 ? `Add ${selected.length} trainers` : 'Add Selected' }}
                </UiButton>
            </div>
        </form>
    </Modal>
</template>
