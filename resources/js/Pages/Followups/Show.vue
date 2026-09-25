<script setup>
import FollowupActionModals from '@/Components/followups/FollowupActionModals.vue';
import FollowupFormModal from '@/Components/followups/FollowupFormModal.vue';
import FollowupStateBadge from '@/Components/followups/FollowupStateBadge.vue';
import PriorityBadge from '@/Components/leads/PriorityBadge.vue';
import AppIcon from '@/Components/ui/AppIcon.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import { useConfirm } from '@/Composables/useConfirm';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDateTime, formatDue } from '@/utils/format';
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    followup: Object,
    history: Array,
    options: Object,
    can: Object,
});

const f = computed(() => props.followup);
const action = ref(null);
const editing = ref(false);
const actionOptions = computed(() => ({ ...props.options, canChangeLeadStatus: props.can.changeLeadStatus }));

const { confirm } = useConfirm();
const destroy = async () => {
    if (await confirm({ title: 'Delete this follow-up?', message: 'Deleting hides the record from all lists (it can be restored). To stop a planned follow-up normally, cancel it instead so the reason is kept.', confirmText: 'Delete', danger: true })) {
        router.delete(route('followups.destroy', f.value.id));
    }
};
const restore = () => router.post(route('followups.restore', f.value.id));

const details = computed(() => [
    ['Scheduled', `${formatDateTime(f.value.scheduled_at)}`],
    ['Assigned to', f.value.assignee?.name ?? '—'],
    ['Reminder', f.value.reminder_label],
    ['Created by', f.value.creator ? `${f.value.creator.name} · ${formatDateTime(f.value.created_at)}` : formatDateTime(f.value.created_at)],
]);
</script>

<template>
    <AppLayout :title="f.title">
        <PageHeader :title="f.title">
            <template #breadcrumb>
                <Link :href="route('followups.index')" class="hover:text-slate-700">Follow-ups</Link>
                <span class="mx-1">/</span>
                <Link v-if="f.lead" :href="route('leads.show', f.lead.id)" class="hover:text-slate-700">{{ f.lead.full_name }}</Link>
            </template>
            <template #actions>
                <UiButton v-if="f.can.complete" icon="check" @click="action = { type: 'complete', followup: f }">Complete</UiButton>
                <UiButton v-if="f.can.reschedule" variant="secondary" icon="clock" @click="action = { type: 'reschedule', followup: f }">Reschedule</UiButton>
                <UiButton v-if="f.can.update" variant="secondary" icon="edit" @click="editing = true">Edit</UiButton>
                <UiButton v-if="f.can.cancel" variant="secondary" icon="ban" @click="action = { type: 'cancel', followup: f }">Cancel</UiButton>
                <UiButton v-if="f.can.delete" variant="ghost" icon="trash" @click="destroy">Delete</UiButton>
                <UiButton v-if="f.can_restore" variant="secondary" icon="restore" @click="restore">Restore</UiButton>
            </template>
        </PageHeader>

        <div v-if="f.deleted" class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">This follow-up was deleted. It is hidden from lists and reminders.</div>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <div class="panel">
                    <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 px-4 py-3">
                        <UiBadge v-if="f.type" :color="f.type.color"><AppIcon v-if="f.type.icon" :name="f.type.icon" class="h-3 w-3" />{{ f.type.name }}</UiBadge>
                        <FollowupStateBadge :state="f.state" />
                        <PriorityBadge :priority="f.priority" />
                        <span class="ml-auto text-sm font-semibold" :class="f.state === 'overdue' ? 'text-red-600' : 'text-slate-700'">{{ formatDue(f.scheduled_at) }}</span>
                    </div>
                    <dl class="grid gap-x-6 gap-y-3 p-5 text-sm sm:grid-cols-2">
                        <div v-for="[label, value] in details" :key="label">
                            <dt class="text-2xs uppercase tracking-wide text-slate-400">{{ label }}</dt>
                            <dd class="text-slate-800">{{ value }}</dd>
                        </div>
                    </dl>
                    <div v-if="f.description" class="border-t border-slate-100 px-4 py-3">
                        <p class="text-2xs uppercase tracking-wide text-slate-400">Description</p>
                        <p class="whitespace-pre-line text-sm text-slate-700">{{ f.description }}</p>
                    </div>
                </div>

                <div v-if="f.status === 'completed'" class="panel">
                    <div class="panel-header"><h2 class="panel-title">Completion</h2></div>
                    <dl class="grid gap-x-6 gap-y-3 p-5 text-sm sm:grid-cols-2">
                        <div><dt class="text-2xs uppercase tracking-wide text-slate-400">Outcome</dt><dd>{{ f.outcome ?? '—' }}</dd></div>
                        <div><dt class="text-2xs uppercase tracking-wide text-slate-400">Completed</dt><dd>{{ f.completer?.name }} · {{ formatDateTime(f.completed_at) }}</dd></div>
                        <div v-if="f.next_action" class="sm:col-span-2"><dt class="text-2xs uppercase tracking-wide text-slate-400">Next action</dt><dd>{{ f.next_action }}</dd></div>
                        <div v-if="f.notes" class="sm:col-span-2"><dt class="text-2xs uppercase tracking-wide text-slate-400">Notes</dt><dd class="whitespace-pre-line">{{ f.notes }}</dd></div>
                    </dl>
                </div>

                <div v-if="f.status === 'cancelled'" class="panel">
                    <div class="panel-header"><h2 class="panel-title">Cancellation</h2></div>
                    <p class="p-4 text-sm text-slate-700">
                        Cancelled by {{ f.canceller?.name ?? '—' }} · {{ formatDateTime(f.cancelled_at) }}
                        <span v-if="f.cancellation_reason" class="mt-1 block text-slate-600">Reason: {{ f.cancellation_reason }}</span>
                    </p>
                </div>
            </div>

            <div class="space-y-4">
                <div v-if="f.lead" class="panel">
                    <div class="panel-header"><h2 class="panel-title">Lead</h2></div>
                    <div class="p-4 text-sm">
                        <Link :href="route('leads.show', f.lead.id)" class="font-medium text-slate-900 hover:text-brand-700">{{ f.lead.full_name }}</Link>
                        <p class="text-2xs text-slate-500">{{ f.lead.lead_number }}<template v-if="f.lead.company_name"> · {{ f.lead.company_name }}</template></p>
                        <a v-if="f.lead.phone" :href="`tel:${f.lead.phone}`" class="mt-2 inline-flex items-center gap-1 text-sm text-brand-700"><AppIcon name="phone" class="h-4 w-4" />{{ f.lead.phone }}</a>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header"><h2 class="panel-title">History</h2></div>
                    <ol class="space-y-4 p-5">
                        <li v-for="h in history" :key="h.id" class="flex gap-2 text-xs">
                            <span class="mt-1 h-2 w-2 shrink-0 rounded-full" :class="h.current ? 'bg-brand-600' : 'bg-slate-300'" />
                            <div class="min-w-0">
                                <Link v-if="!h.current" :href="route('followups.show', h.id)" class="font-medium text-slate-700 hover:text-brand-700">{{ formatDateTime(h.scheduled_at) }}</Link>
                                <span v-else class="font-semibold text-slate-900">{{ formatDateTime(h.scheduled_at) }} (this)</span>
                                <div class="mt-0.5 flex flex-wrap items-center gap-1">
                                    <FollowupStateBadge :state="h.state" />
                                    <span v-if="h.outcome" class="text-slate-500">{{ h.outcome }}</span>
                                </div>
                                <p v-if="h.reschedule_reason" class="text-slate-500">Reason: {{ h.reschedule_reason }}</p>
                            </div>
                        </li>
                    </ol>
                </div>
            </div>
        </div>

        <FollowupActionModals v-model:action="action" :options="actionOptions" />
        <FollowupFormModal :show="editing" :options="options" :followup="f" :lead="f.lead" @close="editing = false" />
    </AppLayout>
</template>
