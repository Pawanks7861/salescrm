<script setup>
import AppIcon from '@/Components/ui/AppIcon.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import { formatDateTime, timeAgo } from '@/utils/format';
import axios from 'axios';
import { ref, watch } from 'vue';

const props = defineProps({
    leadId: { type: Number, required: true },
    initial: { type: Object, required: true }, // { data, has_more }
    compact: { type: Boolean, default: false },
});

const items = ref([...props.initial.data]);
const hasMore = ref(props.initial.has_more);
const loading = ref(false);

watch(
    () => props.initial,
    (v) => {
        items.value = [...v.data];
        hasMore.value = v.has_more;
    },
);

const loadMore = async () => {
    loading.value = true;
    try {
        const before = items.value[items.value.length - 1]?.id;
        const { data } = await axios.get(route('leads.activities', props.leadId), { params: { before } });
        items.value.push(...data.data);
        hasMore.value = data.has_more;
    } finally {
        loading.value = false;
    }
};

const icons = {
    lead_created: ['plus', 'bg-brand-100 text-brand-600'],
    lead_updated: ['edit', 'bg-slate-100 text-slate-600'],
    lead_assigned: ['switch', 'bg-purple-100 text-purple-600'],
    status_changed: ['tag', 'bg-sky-100 text-sky-600'],
    priority_changed: ['warning', 'bg-amber-100 text-amber-600'],
    lead_won: ['trophy', 'bg-emerald-100 text-emerald-600'],
    lead_lost: ['ban', 'bg-red-100 text-red-600'],
    lead_reopened: ['restore', 'bg-sky-100 text-sky-600'],
    lead_archived: ['archive', 'bg-slate-200 text-slate-600'],
    lead_restored: ['restore', 'bg-emerald-100 text-emerald-600'],
    duplicate_flagged: ['duplicate', 'bg-amber-100 text-amber-600'],
    enquiry_received: ['inbox', 'bg-teal-100 text-teal-600'],
    note_added: ['chat', 'bg-slate-100 text-slate-600'],
    note_edited: ['chat', 'bg-slate-100 text-slate-600'],
    note_deleted: ['chat', 'bg-slate-100 text-slate-400'],
    attachment_uploaded: ['paperclip', 'bg-slate-100 text-slate-600'],
    attachment_deleted: ['paperclip', 'bg-slate-100 text-slate-400'],
    custom_fields_updated: ['adjustments', 'bg-slate-100 text-slate-600'],
    meeting_created: ['video', 'bg-purple-100 text-purple-600'],
    meeting_confirmed: ['check', 'bg-sky-100 text-sky-600'],
    meeting_rescheduled: ['clock', 'bg-amber-100 text-amber-600'],
    meeting_cancelled: ['ban', 'bg-slate-200 text-slate-600'],
    meeting_completed: ['check', 'bg-emerald-100 text-emerald-600'],
    meeting_no_show: ['warning', 'bg-red-100 text-red-600'],
    call_started: ['phone-outgoing', 'bg-sky-100 text-sky-600'],
    call_completed: ['phone', 'bg-emerald-100 text-emerald-600'],
    call_unanswered: ['phone-missed', 'bg-slate-200 text-slate-600'],
    call_missed: ['phone-missed', 'bg-red-100 text-red-600'],
    call_outcome: ['check', 'bg-teal-100 text-teal-600'],
};
const iconFor = (type) => icons[type] ?? ['info', 'bg-slate-100 text-slate-500'];
</script>

<template>
    <div>
        <p v-if="!items.length" class="py-6 text-center text-xs text-slate-500">No activity yet.</p>
        <ol class="relative space-y-4">
            <li v-for="(a, i) in items" :key="a.id" class="relative flex gap-3.5">
                <span v-if="i < items.length - 1" class="absolute left-4 top-8 -ml-px h-[calc(100%-1rem)] w-px bg-slate-100" aria-hidden="true" />
                <span class="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ring-4 ring-white" :class="iconFor(a.type)[1]">
                    <AppIcon :name="iconFor(a.type)[0]" class="h-4 w-4" />
                </span>
                <div class="min-w-0 flex-1 pt-0.5">
                    <p class="text-[13px] leading-snug text-slate-800">{{ a.description }}</p>
                    <p class="mt-0.5 text-2xs text-slate-500">
                        {{ a.user?.name ?? 'System' }} · <span :title="formatDateTime(a.created_at)">{{ compact ? timeAgo(a.created_at) : formatDateTime(a.created_at) }}</span>
                    </p>
                </div>
            </li>
        </ol>
        <div v-if="hasMore" class="mt-3 text-center">
            <UiButton size="sm" variant="secondary" :loading="loading" @click="loadMore">Load older activity</UiButton>
        </div>
    </div>
</template>
