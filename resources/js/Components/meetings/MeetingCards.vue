<script setup>
import MeetingStatusBadge from '@/Components/meetings/MeetingStatusBadge.vue';
import PriorityBadge from '@/Components/leads/PriorityBadge.vue';
import AppIcon from '@/Components/ui/AppIcon.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import { durationLabel, formatRange } from '@/utils/format';
import { Link } from '@inertiajs/vue3';

/** Phone-friendly meeting rows with one-tap actions (server-computed `can` flags). */
defineProps({
    rows: { type: Array, required: true },
    showLead: { type: Boolean, default: true },
    showHost: { type: Boolean, default: true },
});
const emit = defineEmits(['action']);

const edgeClass = (m) => {
    if (m.state === 'past_due') return 'before:bg-amber-500';
    return { completed: 'before:bg-emerald-500', cancelled: 'before:bg-slate-500', no_show: 'before:bg-red-500' }[m.status] ?? 'before:bg-purple-500';
};
</script>

<template>
    <ul class="divide-y divide-slate-100">
        <li v-for="m in rows" :key="m.id" class="accent-edge flex flex-wrap items-start gap-x-3 gap-y-1.5 px-5 py-3.5 transition-colors hover:bg-slate-50/60" :class="edgeClass(m)">
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="whitespace-nowrap text-xs font-semibold text-slate-800">{{ formatRange(m.start_at, m.end_at) }}</span>
                    <UiBadge v-if="m.type" :color="m.type.color"><AppIcon v-if="m.type.icon" :name="m.type.icon" class="h-3 w-3" />{{ m.type.name }}</UiBadge>
                    <MeetingStatusBadge :state="m.state" :status="m.status" />
                    <PriorityBadge v-if="m.priority && m.priority !== 'medium'" :priority="m.priority" />
                </div>
                <Link :href="route('meetings.show', m.id)" class="mt-1 block truncate text-sm font-medium text-slate-900 hover:text-brand-700">{{ m.title }}</Link>
                <p class="truncate text-2xs text-slate-500">
                    {{ m.meeting_number }} · {{ durationLabel(m.duration_minutes) }}
                    <template v-if="showLead && m.lead"> · <Link :href="route('leads.show', m.lead.id)" class="font-medium text-slate-700 hover:text-brand-700">{{ m.lead.full_name }}</Link> · ID {{ m.lead.id }}</template>
                    <template v-if="showHost && m.host"> · {{ m.host.name }}</template>
                    <template v-if="m.outcome"> · {{ m.outcome }}</template>
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-1">
                <button v-if="m.can?.complete" type="button" class="btn-complete" @click="emit('action', { type: 'complete', meeting: m })">Complete</button>
                <button v-if="m.can?.reschedule" type="button" class="icon-btn h-8 w-8 border border-slate-200" title="Reschedule" @click="emit('action', { type: 'reschedule', meeting: m })">
                    <AppIcon name="clock" class="h-4 w-4" />
                </button>
                <button v-if="m.can?.cancel" type="button" class="icon-btn h-8 w-8 border border-slate-200" title="Cancel" @click="emit('action', { type: 'cancel', meeting: m })">
                    <AppIcon name="ban" class="h-4 w-4" />
                </button>
                <Link :href="route('meetings.show', m.id)" class="icon-btn h-8 w-8 border border-slate-200" title="Open"><AppIcon name="eye" class="h-4 w-4" /></Link>
            </div>
        </li>
    </ul>
</template>
