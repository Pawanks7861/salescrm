<script setup>
import CallOutcomeModal from '@/Components/calls/CallOutcomeModal.vue';
import AppIcon from '@/Components/ui/AppIcon.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import { useTelephony } from '@/Composables/useTelephony';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDateTime } from '@/utils/format';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    call: Object,
    events: { type: Array, default: null },
    outcomeOptions: { type: Object, default: null },
});

const outcomeOpen = ref(false);
const editingNotes = ref(false);
const notesForm = useForm({ notes: props.call.notes ?? '' });
const saveNotes = () =>
    notesForm.patch(route('calls.notes', props.call.id), {
        preserveScroll: true,
        onSuccess: () => (editingNotes.value = false),
    });

const phone = useTelephony();
const callBack = () =>
    phone.startCall({ leadId: props.call.lead.id, contactField: ['phone', 'alternate_phone'].includes(props.call.contact_field) ? props.call.contact_field : 'phone' });

const seconds = (s) => (s || s === 0 ? `${Math.floor(s / 60)}m ${s % 60}s` : '—');
const facts = computed(() => [
    ['Direction', props.call.direction_label],
    ['Channel', props.call.channel_label],
    ['Agent', props.call.agent?.name ?? '—'],
    ['Started', formatDateTime(props.call.started_at)],
    ['Answered', formatDateTime(props.call.answered_at)],
    ['Ended', formatDateTime(props.call.ended_at)],
    ['Talk time', props.call.duration || '—'],
    ['Ring time', seconds(props.call.ring_seconds)],
    ['Total', seconds(props.call.total_seconds)],
]);
</script>

<template>
    <AppLayout :title="call.call_number">
        <PageHeader :title="call.call_number" :subtitle="`${call.direction_label} call · ${formatDateTime(call.started_at)}`">
            <template #actions>
                <UiButton :href="route('calls.index')" variant="secondary">All calls</UiButton>
                <UiButton v-if="call.can.callBack" icon="phone" :disabled="phone.busy.value" @click="callBack">Call back</UiButton>
                <UiButton v-if="call.can.dispose && outcomeOptions" :variant="call.requires_disposition ? 'primary' : 'secondary'" icon="check" @click="outcomeOpen = true">
                    {{ call.disposition ? 'Change outcome' : 'Add outcome' }}
                </UiButton>
            </template>
        </PageHeader>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <div class="panel p-4">
                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        <UiBadge :color="call.status_color">{{ call.status_label }}</UiBadge>
                        <UiBadge v-if="call.disposition" :color="call.disposition.color">{{ call.disposition.name }}</UiBadge>
                        <UiBadge v-else-if="call.requires_disposition" color="amber">Outcome required</UiBadge>
                        <span v-if="call.failure_code" class="text-2xs text-slate-500">({{ call.failure_code.replace(/_/g, ' ') }})</span>
                    </div>
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-3">
                        <div v-for="[label, value] in facts" :key="label">
                            <dt class="text-2xs uppercase text-slate-500">{{ label }}</dt>
                            <dd class="text-slate-800">{{ value }}</dd>
                        </div>
                    </dl>
                </div>

                <div class="panel p-4">
                    <h3 class="mb-2 text-sm font-semibold text-slate-800">Recording</h3>
                    <template v-if="call.recording">
                        <audio v-if="call.recording.stream_url" :src="call.recording.stream_url" controls preload="none" controlslist="nodownload" class="w-full" @contextmenu.prevent />
                        <p v-else class="text-sm text-slate-500">{{ call.recording.status === 'available' ? 'You do not have permission to play recordings.' : call.recording.label }}</p>
                        <a v-if="call.recording.download_url" :href="call.recording.download_url" class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:underline">
                            <AppIcon name="download" class="h-3.5 w-3.5" /> Download recording
                        </a>
                    </template>
                    <p v-else class="text-sm text-slate-500">No recording for this call.</p>
                </div>

                <div class="panel p-4">
                    <div class="mb-2 flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-slate-800">Notes</h3>
                        <button v-if="call.can.editNotes && !editingNotes" type="button" class="text-xs font-medium text-brand-600 hover:underline" @click="editingNotes = true">Edit</button>
                    </div>
                    <form v-if="editingNotes" class="space-y-2" @submit.prevent="saveNotes">
                        <textarea v-model="notesForm.notes" rows="4" class="form-input" maxlength="5000" />
                        <p v-if="notesForm.errors.notes" class="form-error">{{ notesForm.errors.notes }}</p>
                        <div class="flex justify-end gap-2">
                            <UiButton variant="secondary" size="sm" @click="editingNotes = false">Cancel</UiButton>
                            <UiButton type="submit" size="sm" :loading="notesForm.processing">Save notes</UiButton>
                        </div>
                    </form>
                    <p v-else class="whitespace-pre-line text-sm text-slate-700">{{ call.notes || 'No notes.' }}</p>
                    <p v-if="call.notes_updated_at" class="mt-1 text-2xs text-slate-400">Updated {{ formatDateTime(call.notes_updated_at) }}</p>
                </div>

                <div v-if="events" class="panel p-4">
                    <h3 class="mb-2 text-sm font-semibold text-slate-800">Provider events</h3>
                    <ol class="space-y-1.5 text-xs">
                        <li v-for="e in events" :key="e.id" class="flex flex-wrap items-center gap-2">
                            <span class="w-40 shrink-0 text-slate-500">{{ formatDateTime(e.received_at) }}</span>
                            <span class="font-medium text-slate-700">{{ e.type }}</span>
                            <span v-if="e.provider_status" class="text-slate-500">{{ e.provider_status }}</span>
                            <UiBadge :color="e.processing_status === 'failed' ? 'red' : e.processing_status === 'processed' ? 'green' : 'slate'">{{ e.processing_status }}</UiBadge>
                            <span v-if="e.error" class="text-red-600">{{ e.error }}</span>
                        </li>
                        <li v-if="!events.length" class="text-slate-500">No provider events recorded.</li>
                    </ol>
                </div>
            </div>

            <div class="space-y-4">
                <div class="panel p-4">
                    <h3 class="mb-2 text-sm font-semibold text-slate-800">Lead</h3>
                    <template v-if="call.lead">
                        <Link :href="route('leads.show', call.lead.id)" class="font-medium text-brand-700 hover:underline">{{ call.lead.full_name }}</Link>
                        <p class="text-2xs text-slate-500">ID {{ call.lead.id }} · {{ call.lead.lead_number }}</p>
                    </template>
                    <p v-else class="text-sm text-slate-500">{{ call.customer_number || 'Unknown caller' }} — not linked to a lead.</p>
                </div>
                <div v-if="call.followup || call.meeting || call.next_action" class="panel p-4">
                    <h3 class="mb-2 text-sm font-semibold text-slate-800">Next action</h3>
                    <Link v-if="call.followup" :href="call.followup.url" class="block text-sm text-brand-700 hover:underline">Follow-up: {{ call.followup.title }}</Link>
                    <Link v-if="call.meeting" :href="call.meeting.url" class="block text-sm text-brand-700 hover:underline">Meeting {{ call.meeting.meeting_number }}</Link>
                    <p v-if="!call.followup && !call.meeting" class="text-sm text-slate-500">{{ call.next_action }}</p>
                </div>
                <div v-if="call.disposition_by" class="panel p-4 text-xs text-slate-500">Outcome recorded by {{ call.disposition_by }} · {{ formatDateTime(call.disposition_at) }}</div>
            </div>
        </div>

        <CallOutcomeModal v-if="outcomeOptions" :show="outcomeOpen" :preloaded="{ call, options: outcomeOptions }" @saved="outcomeOpen = false" @close="outcomeOpen = false" />
    </AppLayout>
</template>
