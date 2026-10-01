<script setup>
import BatchLookup from '@/Components/batches/BatchLookup.vue';
import TrainerSelector from '@/Components/batches/TrainerSelector.vue';
import Modal from '@/Components/Modal.vue';
import FormField from '@/Components/ui/FormField.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import { batchCreatePayload } from '@/utils/batches';
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

/**
 * "Add to batch" for one or more leads: pick an existing open batch, or
 * create a new one (which opens the new batch page). The server re-checks
 * that every lead is visible to the user.
 */
const props = defineProps({
    show: Boolean,
    leadIds: { type: Array, default: () => [] },
    canCreate: { type: Boolean, default: false },
    canAssignTrainers: { type: Boolean, default: false },
    excludeBatchIds: { type: Array, default: () => [] },
});
const emit = defineEmits(['close', 'added']);

const mode = ref('existing');
const batch = ref(null);
const existing = useForm({ lead_ids: [] });
const created = useForm({ name: '', description: '', start_date: '', end_date: '', status: 'active', lead_ids: [], trainers: [] });

watch(
    () => props.show,
    (open) => {
        if (!open) return;
        mode.value = 'existing';
        batch.value = null;
        existing.reset();
        existing.clearErrors();
        created.reset();
        created.clearErrors();
    },
);

const count = computed(() => props.leadIds.length);
const noun = computed(() => (count.value === 1 ? 'lead' : 'leads'));
const errors = computed(() => (mode.value === 'existing' ? existing.errors : created.errors));
const processing = computed(() => existing.processing || created.processing);

const submit = () => {
    if (mode.value === 'existing') {
        if (!batch.value) return;
        existing.lead_ids = [...props.leadIds];
        existing.post(route('batches.leads.store', batch.value.id), {
            preserveScroll: true,
            onSuccess: () => {
                emit('added');
                emit('close');
            },
        });
    } else {
        created.lead_ids = [...props.leadIds];
        created.transform((data) => batchCreatePayload(data, props.canAssignTrainers)).post(route('batches.store'), { onSuccess: () => emit('close') });
    }
};
</script>

<template>
    <Modal :show="show" max-width="lg" @close="emit('close')">
        <form @submit.prevent="submit">
            <div class="modal-header">
                <h3 class="text-sm font-semibold">Add {{ count }} {{ noun }} to a batch</h3>
                <p class="text-2xs text-slate-500">Batch membership only groups leads. Owner, status, follow-ups and meetings are not changed.</p>
            </div>
            <div class="space-y-4 p-5">
                <div v-if="canCreate" class="inline-flex rounded-md border border-slate-200 p-0.5 text-xs">
                    <button type="button" class="rounded px-3 py-1" :class="mode === 'existing' ? 'bg-slate-100 font-medium text-slate-900' : 'text-slate-500'" @click="mode = 'existing'">Existing batch</button>
                    <button type="button" class="rounded px-3 py-1" :class="mode === 'new' ? 'bg-slate-100 font-medium text-slate-900' : 'text-slate-500'" @click="mode = 'new'">Create new batch</button>
                </div>

                <BatchLookup v-if="mode === 'existing'" v-model="batch" :exclude-ids="excludeBatchIds" />

                <template v-else>
                    <FormField label="Batch name" required :error="created.errors.name">
                        <input v-model="created.name" class="form-input" maxlength="150" required placeholder="e.g. October Campaign" />
                    </FormField>
                    <FormField label="Description" :error="created.errors.description">
                        <textarea v-model="created.description" class="form-input" rows="2" maxlength="2000" placeholder="Optional" />
                    </FormField>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField label="Start date" :error="created.errors.start_date">
                            <input v-model="created.start_date" type="date" class="form-input" />
                        </FormField>
                        <FormField label="End date" :error="created.errors.end_date">
                            <input v-model="created.end_date" type="date" class="form-input" :min="created.start_date || undefined" />
                        </FormField>
                    </div>
                    <FormField v-if="canAssignTrainers" label="Trainer(s)" :error="created.errors.trainer_ids" hint="Optional.">
                        <TrainerSelector v-model="created.trainers" />
                    </FormField>
                </template>

                <p v-if="errors.lead_ids" class="form-error">{{ errors.lead_ids }}</p>
            </div>
            <div class="modal-footer">
                <UiButton variant="secondary" @click="emit('close')">Cancel</UiButton>
                <UiButton type="submit" :loading="processing" :disabled="!count || (mode === 'existing' && !batch)">
                    {{ mode === 'existing' ? `Add ${count} ${noun}` : `Create batch with ${count} ${noun}` }}
                </UiButton>
            </div>
        </form>
    </Modal>
</template>
