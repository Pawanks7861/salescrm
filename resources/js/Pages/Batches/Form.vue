<script setup>
import LeadSelector from '@/Components/batches/LeadSelector.vue';
import TrainerSelector from '@/Components/batches/TrainerSelector.vue';
import FormField from '@/Components/ui/FormField.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { batchCreatePayload, batchDateFields, batchUpdatePayload, trainerPayload } from '@/utils/batches';
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    batch: { type: Object, default: null },
    statuses: Array,
    leadOptions: Object,
    can: Object,
});

const editing = computed(() => Boolean(props.batch));
const form = useForm({
    name: props.batch?.name ?? '',
    description: props.batch?.description ?? '',
    ...batchDateFields(props.batch),
    status: props.batch && !props.batch.archived ? props.batch.status : 'active',
    lead_ids: [],
    trainers: [...(props.batch?.trainers ?? [])],
});
const trainerError = computed(() => form.errors.trainer_ids ?? Object.entries(form.errors).find(([key]) => key.startsWith('trainer_ids.'))?.[1]);

const submit = () => {
    if (editing.value) {
        form.transform((data) => ({ ...batchUpdatePayload(data, props.batch.archived), ...trainerPayload(data.trainers, props.can.manageTrainers) })).put(route('batches.update', props.batch.id));
    } else {
        form.transform((data) => batchCreatePayload(data, props.can.manageTrainers)).post(route('batches.store'));
    }
};
</script>

<template>
    <AppLayout :title="editing ? `Edit ${batch.name}` : 'Create Batch'">
        <PageHeader :title="editing ? `Edit ${batch.name}` : 'Create Batch'">
            <template #breadcrumb>
                <Link :href="route('batches.index')" class="hover:text-slate-700">Batches</Link>
                <template v-if="editing"> / <Link :href="route('batches.show', batch.id)" class="font-mono hover:text-slate-700">{{ batch.batch_number }}</Link></template>
                / {{ editing ? 'Edit' : 'New' }}
            </template>
        </PageHeader>

        <form class="space-y-5" @submit.prevent="submit">
            <div class="panel">
                <div class="panel-header"><h2 class="panel-title">Batch details</h2></div>
                <div class="grid gap-4 p-5 sm:grid-cols-2">
                    <FormField label="Batch Name" required :error="form.errors.name" class="sm:col-span-2">
                        <input v-model="form.name" class="form-input" maxlength="150" required placeholder="e.g. October Campaign Leads" />
                    </FormField>
                    <FormField label="Start Date" :error="form.errors.start_date">
                        <input v-model="form.start_date" type="date" class="form-input" />
                    </FormField>
                    <FormField label="End Date" :error="form.errors.end_date" hint="Optional. Dates do not change the status.">
                        <input v-model="form.end_date" type="date" class="form-input" :min="form.start_date || undefined" />
                    </FormField>
                    <FormField
                        v-if="can.manageTrainers"
                        label="Trainer(s)"
                        :error="trainerError"
                        :hint="batch?.archived ? 'Archived — trainers can be removed but not added.' : 'Optional. Trainers do not get access to any lead.'"
                        class="sm:col-span-2"
                    >
                        <TrainerSelector v-model="form.trainers" :allow-add="!batch?.archived" />
                    </FormField>
                    <FormField label="Description" :error="form.errors.description" class="sm:col-span-2">
                        <textarea v-model="form.description" class="form-input" rows="3" maxlength="2000" placeholder="What is this batch for?" />
                    </FormField>
                    <FormField label="Status" :error="form.errors.status" :hint="batch?.archived ? 'Archived — restore the batch from its page to change the status.' : 'Inactive batches still accept leads; archive a batch to close it.'">
                        <select v-model="form.status" class="form-input" :disabled="batch?.archived">
                            <option v-if="batch?.archived" value="active">Archived</option>
                            <option v-for="s in statuses" v-else :key="s.value" :value="s.value">{{ s.label }}</option>
                        </select>
                    </FormField>
                </div>
            </div>

            <div v-if="!editing && can.manageLeads" class="panel">
                <div class="panel-header">
                    <h2 class="panel-title">Add Leads</h2>
                    <span class="text-2xs text-slate-500">Optional — you can also add leads later.</span>
                </div>
                <div class="p-5">
                    <LeadSelector v-model="form.lead_ids" :statuses="leadOptions.statuses" :sources="leadOptions.sources" />
                    <p v-if="form.errors.lead_ids" class="form-error mt-2">{{ form.errors.lead_ids }}</p>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <UiButton variant="secondary" :href="editing ? route('batches.show', batch.id) : route('batches.index')">Cancel</UiButton>
                <UiButton type="submit" :loading="form.processing">
                    {{ editing ? 'Save changes' : form.lead_ids.length ? `Create batch with ${form.lead_ids.length} lead${form.lead_ids.length === 1 ? '' : 's'}` : 'Create batch' }}
                </UiButton>
            </div>
        </form>
    </AppLayout>
</template>
