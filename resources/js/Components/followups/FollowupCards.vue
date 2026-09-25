<script setup>
import FollowupStateBadge from '@/Components/followups/FollowupStateBadge.vue';
import PriorityBadge from '@/Components/leads/PriorityBadge.vue';
import AppIcon from '@/Components/ui/AppIcon.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import { formatDue } from '@/utils/format';
import { Link } from '@inertiajs/vue3';

/**
 * Compact, phone-friendly follow-up rows with one-tap actions. Actions are
 * shown only when the server-computed `can` flags allow them.
 */
defineProps({
    rows: { type: Array, required: true },
    showLead: { type: Boolean, default: true },
    showAssignee: { type: Boolean, default: true },
    showOutcome: { type: Boolean, default: false },
});
const emit = defineEmits(['action']);

const dueClass = (state) => ({ overdue: 'text-red-600', due_soon: 'text-amber-700', completed: 'text-emerald-600' })[state] ?? 'text-slate-700';
const edgeClass = (state) =>
    ({ overdue: 'before:bg-red-500', due_soon: 'before:bg-amber-500', completed: 'before:bg-emerald-500', cancelled: 'before:bg-slate-500', missed: 'before:bg-slate-500' })[state] ?? 'before:bg-sky-500';
</script>

<template>
    <ul class="divide-y divide-slate-100">
        <li v-for="f in rows" :key="f.id" class="accent-edge flex flex-wrap items-start gap-x-3 gap-y-1.5 px-5 py-3.5 transition-colors hover:bg-slate-50/60" :class="edgeClass(f.state)">
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="whitespace-nowrap text-xs font-semibold" :class="dueClass(f.state)">{{ formatDue(f.scheduled_at) }}</span>
                    <UiBadge v-if="f.type" :color="f.type.color">
                        <AppIcon v-if="f.type.icon" :name="f.type.icon" class="h-3 w-3" />{{ f.type.name }}
                    </UiBadge>
                    <FollowupStateBadge :state="f.state" />
                    <PriorityBadge v-if="f.priority !== 'medium'" :priority="f.priority" />
                </div>
                <Link :href="route('followups.show', f.id)" class="mt-1 block truncate text-sm font-medium text-slate-900 hover:text-brand-700">{{ f.title }}</Link>
                <p class="truncate text-2xs text-slate-500">
                    <template v-if="showLead && f.lead">
                        <Link :href="route('leads.show', f.lead.id)" class="font-medium text-slate-700 hover:text-brand-700">{{ f.lead.full_name }}</Link>
                        <a v-if="f.lead.phone" :href="`tel:${f.lead.phone}`" class="ml-1 hover:text-brand-700">{{ f.lead.phone }}</a>
                    </template>
                    <template v-if="showAssignee && f.assignee"><span v-if="showLead && f.lead"> · </span>{{ f.assignee.name }}</template>
                    <template v-if="f.creator"> · by {{ f.creator.name }}</template>
                    <template v-if="showOutcome && f.outcome"> · Outcome: {{ f.outcome }}</template>
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-1">
                <button v-if="f.can?.complete" type="button" class="btn-complete" @click="emit('action', { type: 'complete', followup: f })">
                    Complete
                </button>
                <button v-if="f.can?.reschedule" type="button" class="icon-btn h-8 w-8 border border-slate-200" title="Reschedule" @click="emit('action', { type: 'reschedule', followup: f })">
                    <AppIcon name="clock" class="h-4 w-4" />
                </button>
                <button v-if="f.can?.cancel" type="button" class="icon-btn h-8 w-8 border border-slate-200" title="Cancel" @click="emit('action', { type: 'cancel', followup: f })">
                    <AppIcon name="ban" class="h-4 w-4" />
                </button>
                <Link v-if="showLead && f.lead" :href="route('leads.show', f.lead.id)" class="icon-btn h-8 w-8 border border-slate-200" title="Open lead">
                    <AppIcon name="eye" class="h-4 w-4" />
                </Link>
            </div>
        </li>
    </ul>
</template>
