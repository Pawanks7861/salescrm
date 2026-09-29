<script setup>
import CallOutcomeModal from '@/Components/calls/CallOutcomeModal.vue';
import AppIcon from '@/Components/ui/AppIcon.vue';
import Avatar from '@/Components/ui/Avatar.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import { formatDateTime } from '@/utils/format';
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';

/** Call rows (server-presented; recordings play through the CRM stream route only). */
defineProps({
    rows: { type: Array, required: true },
    showLead: { type: Boolean, default: true },
    showNotes: { type: Boolean, default: false },
});

const playing = ref(null);
const outcomeFor = ref(null);

const directionIcon = (c) => (c.direction === 'inbound' ? (c.status === 'missed' ? 'phone-missed' : 'phone-incoming') : 'phone-outgoing');
</script>

<template>
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Call #</th>
                    <th>Date</th>
                    <th v-if="showLead">Lead</th>
                    <th>Direction</th>
                    <th>Agent</th>
                    <th>Status</th>
                    <th>Duration</th>
                    <th>Disposition</th>
                    <th>Recording</th>
                    <th v-if="showNotes">Notes</th>
                </tr>
            </thead>
            <tbody>
                <template v-for="c in rows" :key="c.id">
                    <tr class="align-top">
                        <td class="whitespace-nowrap">
                            <Link :href="route('calls.show', c.id)" class="font-medium text-brand-700 hover:underline">{{ c.call_number }}</Link>
                        </td>
                        <td class="whitespace-nowrap text-xs text-slate-600">{{ formatDateTime(c.started_at) }}</td>
                        <td v-if="showLead" class="max-w-[12rem] truncate">
                            <Link v-if="c.lead" :href="route('leads.show', c.lead.id)" class="text-slate-800 hover:text-brand-700">{{ c.lead.full_name }}</Link>
                            <p v-if="c.lead" class="text-2xs text-slate-500">ID {{ c.lead.id }} · {{ c.lead.lead_number }}</p>
                            <span v-else class="text-xs text-slate-500">{{ c.customer_number || 'Unknown caller' }}</span>
                        </td>
                        <td class="whitespace-nowrap text-xs text-slate-600">
                            <span class="inline-flex items-center gap-1.5">
                                <span class="flex h-6 w-6 items-center justify-center rounded-md" :class="c.status === 'missed' ? 'bg-red-100 text-red-600' : 'bg-emerald-100 text-emerald-600'"><AppIcon :name="directionIcon(c)" class="h-3.5 w-3.5" /></span>
                                {{ c.direction_label }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap text-xs text-slate-600"><span v-if="c.agent" class="flex items-center gap-2"><Avatar :name="c.agent.name" size="xs" />{{ c.agent.name }}</span><template v-else>—</template></td>
                        <td class="whitespace-nowrap"><UiBadge :color="c.status_color">{{ c.status_label }}</UiBadge></td>
                        <td class="whitespace-nowrap text-xs tabular-nums text-slate-600">{{ c.duration || '—' }}</td>
                        <td class="whitespace-nowrap">
                            <UiBadge v-if="c.disposition" :color="c.disposition.color">{{ c.disposition.name }}</UiBadge>
                            <button v-else-if="c.requires_disposition && c.can.dispose" type="button" class="rounded bg-amber-100 px-1.5 py-0.5 text-2xs font-semibold text-amber-800 hover:bg-amber-200" @click="outcomeFor = c.id">Add outcome</button>
                            <span v-else-if="c.requires_disposition" class="text-2xs text-amber-700">Pending</span>
                            <span v-else class="text-xs text-slate-400">—</span>
                        </td>
                        <td class="whitespace-nowrap text-xs">
                            <button v-if="c.stream_url" type="button" class="inline-flex items-center gap-1 text-brand-600 hover:underline" @click="playing = playing === c.id ? null : c.id">
                                <AppIcon :name="playing === c.id ? 'stop' : 'play'" class="h-3.5 w-3.5" />{{ playing === c.id ? 'Close' : 'Play' }}
                            </button>
                            <span v-else-if="c.recording" class="text-slate-400">{{ c.recording.status === 'available' ? 'Recorded' : c.recording.label }}</span>
                            <span v-else class="text-slate-300">—</span>
                        </td>
                        <td v-if="showNotes" class="max-w-xs text-xs text-slate-600">
                            <p class="line-clamp-2 whitespace-pre-line">{{ c.notes || '—' }}</p>
                        </td>
                    </tr>
                    <tr v-if="playing === c.id && c.stream_url">
                        <td :colspan="showLead ? (showNotes ? 10 : 9) : showNotes ? 9 : 8" class="bg-slate-50 px-4 py-3">
                            <audio :src="c.stream_url" controls autoplay preload="none" controlslist="nodownload noplaybackrate" class="h-8 w-full max-w-lg" @contextmenu.prevent />
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
        <CallOutcomeModal :show="!!outcomeFor" :call-id="outcomeFor" @saved="outcomeFor = null" @close="outcomeFor = null" />
    </div>
</template>
