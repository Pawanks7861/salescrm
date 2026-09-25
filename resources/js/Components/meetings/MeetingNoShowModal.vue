<script setup>
import Modal from '@/Components/Modal.vue';
import FormField from '@/Components/ui/FormField.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

/** Records a no-show and who did not attend. Never changes the lead status. */
const props = defineProps({
    show: Boolean,
    meeting: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const form = useForm({ absent_participant_ids: [], notes: '' });

watch(
    () => props.show,
    (open) => {
        if (!open || !props.meeting) return;
        form.reset();
        form.clearErrors();
        form.absent_participant_ids = (props.meeting.participants ?? []).filter((p) => p.type === 'lead' || p.type === 'external').map((p) => p.id);
    },
);

const submit = () => form.post(route('meetings.no-show', props.meeting.id), { preserveScroll: true, onSuccess: () => emit('close') });
</script>

<template>
    <Modal :show="show" max-width="md" @close="emit('close')">
        <form v-if="meeting" @submit.prevent="submit">
            <div class="modal-header">
                <h3 class="text-sm font-semibold">Mark {{ meeting.meeting_number }} as no-show</h3>
                <p class="text-2xs text-slate-500">The lead status is not changed — decide the next step yourself.</p>
            </div>
            <div class="space-y-3 p-5">
                <div v-if="meeting.participants?.length">
                    <p class="form-label">Who did not attend?</p>
                    <label v-for="p in meeting.participants" :key="p.id" class="flex items-center gap-2 py-0.5 text-sm text-slate-700">
                        <input v-model="form.absent_participant_ids" type="checkbox" :value="p.id" class="rounded border-slate-300 text-brand-600" />
                        {{ p.name }} <span class="text-2xs text-slate-400">({{ p.type }})</span>
                    </label>
                </div>
                <FormField label="Notes" :error="form.errors.notes">
                    <textarea v-model="form.notes" rows="2" class="form-input" maxlength="5000" placeholder="e.g. Customer did not pick up; will call tomorrow" />
                </FormField>
                <p v-if="form.errors.status" class="form-error">{{ form.errors.status }}</p>
            </div>
            <div class="modal-footer">
                <UiButton variant="secondary" @click="emit('close')">Back</UiButton>
                <UiButton type="submit" variant="danger" :loading="form.processing">Mark no-show</UiButton>
            </div>
        </form>
    </Modal>
</template>
