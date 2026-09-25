<script setup>
import CallOutcomeModal from '@/Components/calls/CallOutcomeModal.vue';
import AppIcon from '@/Components/ui/AppIcon.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import { usePermissions } from '@/Composables/usePermissions';
import { useTelephony } from '@/Composables/useTelephony';
import { Link, router } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

/**
 * Floating softphone. Rendered once per page by AppLayout; all state lives in
 * the useTelephony() singleton so calls survive navigation.
 */
const { can } = usePermissions();
const phone = useTelephony();
const { state } = phone;
const expanded = ref(false);
const simulateNumber = ref('+91 98765 43210');

onMounted(() => phone.boot());

const cfg = computed(() => state.config ?? {});
const visible = computed(() => state.booted && (cfg.value.enabled || cfg.value.can_receive || state.call || state.outcomeCallId));
const inCall = computed(() => ['dialing', 'ringing', 'connected', 'on_hold', 'ending'].includes(state.status) || state.finalizing);
const talking = computed(() => ['connected', 'on_hold'].includes(state.status));
const isFake = computed(() => cfg.value.driver === 'fake');
const identity = computed(() => state.incoming?.identity);

const dotColor = computed(
    () =>
        ({
            ready: 'bg-green-500',
            connected: 'bg-green-500',
            on_hold: 'bg-amber-500',
            incoming: 'bg-blue-500 animate-pulse',
            dialing: 'bg-blue-500 animate-pulse',
            ringing: 'bg-blue-500 animate-pulse',
            registering: 'bg-amber-400 animate-pulse',
            error: 'bg-red-500',
        })[state.status] ?? 'bg-slate-400',
);

const callTitle = computed(() => state.call?.lead?.full_name ?? identity.value?.leads?.[0]?.name ?? state.call?.number ?? state.incoming?.number ?? 'Call');
const callSubtitle = computed(() => state.call?.lead?.lead_number ?? state.call?.number ?? '');

const outcomeSaved = () => phone.outcomeSaved();
const openLead = (url) => router.visit(url);
</script>

<template>
    <div v-if="visible" class="fixed bottom-4 right-4 z-40 w-80 max-w-[calc(100vw-2rem)] text-sm" role="region" aria-label="Softphone">
        <!-- Incoming call -->
        <div v-if="state.status === 'incoming' && state.incoming" class="overflow-hidden rounded-2xl border border-emerald-300 bg-slate-50 shadow-pop">
            <div class="flex items-center gap-2 border-b border-emerald-200 bg-emerald-100 px-4 py-3 text-emerald-600">
                <AppIcon name="phone-incoming" class="h-4 w-4 animate-pulse" />
                <span class="font-semibold">Incoming call</span>
            </div>
            <div class="space-y-2.5 p-4">
                <p v-if="state.incoming.loading" class="text-xs text-slate-500">Identifying caller…</p>
                <template v-else-if="identity?.state === 'matched'">
                    <p class="font-semibold text-slate-800">{{ identity.leads[0].name }}</p>
                    <p class="text-2xs text-slate-500">
                        {{ identity.leads[0].lead_number }}
                        <UiBadge v-if="identity.leads[0].status" :color="identity.leads[0].status.color" class="ml-1">{{ identity.leads[0].status.name }}</UiBadge>
                    </p>
                    <p v-if="identity.leads[0].assignee" class="text-2xs text-slate-500">Owner: {{ identity.leads[0].assignee }}</p>
                    <button type="button" class="text-xs font-medium text-brand-600 hover:underline" @click="openLead(identity.leads[0].url)">Open lead</button>
                </template>
                <template v-else-if="identity?.state === 'multiple'">
                    <p class="text-xs text-slate-600">{{ identity.number }} matches {{ identity.leads.length }} leads:</p>
                    <ul class="max-h-32 space-y-1 overflow-y-auto">
                        <li v-for="l in identity.leads" :key="l.id">
                            <button type="button" class="w-full rounded px-2 py-1 text-left text-xs hover:bg-slate-50" @click="openLead(l.url)">
                                <span class="font-medium text-slate-800">{{ l.name }}</span> <span class="text-slate-400">{{ l.lead_number }}</span>
                            </button>
                        </li>
                    </ul>
                </template>
                <template v-else-if="identity?.state === 'restricted'">
                    <p class="font-semibold text-slate-800">{{ identity.number || 'Unknown number' }}</p>
                    <p class="text-xs text-slate-500">{{ identity.message || 'Lead information unavailable.' }}</p>
                </template>
                <template v-else>
                    <p class="font-semibold text-slate-800">{{ identity?.number || state.incoming.number || 'Unknown caller' }}</p>
                    <p class="text-xs text-slate-500">No matching lead.</p>
                    <Link v-if="identity?.can_create_lead" :href="route('leads.create', { phone: identity.number || state.incoming.number })" class="text-xs font-medium text-brand-600 hover:underline">Create lead</Link>
                </template>
                <p v-if="cfg.recording_notice" class="rounded bg-amber-50 px-2 py-1 text-2xs text-amber-800">{{ cfg.recording_notice }}</p>
                <div class="flex gap-2 pt-1">
                    <button type="button" class="flex h-10 flex-1 items-center justify-center rounded-xl bg-green-600 text-sm font-semibold text-white transition hover:bg-green-500" @click="phone.answer()">Answer</button>
                    <button type="button" class="flex h-10 flex-1 items-center justify-center rounded-xl bg-red-600 text-sm font-semibold text-white transition hover:bg-red-500" @click="phone.reject()">Reject</button>
                </div>
            </div>
        </div>

        <!-- Active / finalizing call -->
        <div v-else-if="inCall" class="overflow-hidden rounded-2xl border border-slate-200 bg-slate-50 shadow-pop">
            <div class="flex items-center justify-between border-b border-slate-100 bg-surface-1 px-4 py-3 text-slate-900">
                <span class="flex items-center gap-2">
                    <span class="h-2 w-2 rounded-full" :class="dotColor" />
                    <span class="text-xs font-semibold">{{ phone.statusLabel.value }}</span>
                </span>
                <span v-if="talking" class="font-mono text-xs tabular-nums">{{ phone.timer.value }}</span>
            </div>
            <div class="space-y-2.5 p-4">
                <div>
                    <p class="truncate font-semibold text-slate-800">{{ callTitle }}</p>
                    <p class="truncate text-2xs text-slate-500">
                        {{ callSubtitle }}<template v-if="state.call?.channel"> · {{ state.call.channel === 'webrtc' ? 'Browser' : 'Phone' }}</template>
                    </p>
                </div>
                <p v-if="state.call?.channel === 'pstn' && ['dialing', 'ringing'].includes(state.status)" class="text-2xs text-slate-500">Your phone will ring first — answer it to connect the customer.</p>
                <p v-if="cfg.recording_notice && !state.finalizing" class="rounded bg-amber-50 px-2 py-1 text-2xs text-amber-800">{{ cfg.recording_notice }}</p>
                <p v-if="state.finalizing" class="flex items-center gap-1.5 text-xs text-slate-500">
                    <AppIcon name="refresh" class="h-3.5 w-3.5 animate-spin" /> Finalizing call…
                </p>

                <div v-if="!state.finalizing" class="grid grid-cols-3 gap-2">
                    <button
                        type="button"
                        class="flex flex-col items-center gap-1 rounded-xl border py-2 text-2xs font-medium transition disabled:opacity-40"
                        :class="state.muted ? 'border-amber-300 bg-amber-50 text-amber-700' : 'border-slate-200 text-slate-600 hover:bg-slate-100'"
                        :disabled="!talking || state.call?.channel === 'pstn'"
                        :title="state.call?.channel === 'pstn' ? 'Use your phone to mute' : ''"
                        @click="phone.toggleMute()"
                    >
                        <AppIcon name="microphone" class="h-4 w-4" />{{ state.muted ? 'Unmute' : 'Mute' }}
                    </button>
                    <button
                        type="button"
                        class="flex flex-col items-center gap-1 rounded-xl border py-2 text-2xs font-medium transition disabled:opacity-40"
                        :class="state.held ? 'border-amber-300 bg-amber-50 text-amber-700' : 'border-slate-200 text-slate-600 hover:bg-slate-100'"
                        :disabled="!talking || state.call?.channel === 'pstn'"
                        @click="phone.toggleHold()"
                    >
                        <AppIcon :name="state.held ? 'play' : 'pause'" class="h-4 w-4" />{{ state.held ? 'Resume' : 'Hold' }}
                    </button>
                    <button type="button" class="flex flex-col items-center gap-1 rounded-xl bg-red-600 py-2 text-2xs font-semibold text-white transition hover:bg-red-500 disabled:opacity-50" :disabled="state.status === 'ending'" @click="phone.hangup()">
                        <AppIcon name="phone-end" class="h-4 w-4" />End
                    </button>
                </div>

                <div class="flex items-center justify-between">
                    <button v-if="state.call?.lead" type="button" class="text-xs font-medium text-brand-600 hover:underline" @click="openLead(route('leads.show', state.call.lead.id))">Open lead</button>
                    <Link v-if="state.call" :href="state.call.url" class="text-2xs text-slate-400 hover:text-slate-600">{{ state.call.call_number }}</Link>
                </div>

                <div v-if="isFake && state.call?.is_open && !state.finalizing" class="border-t border-dashed border-slate-200 pt-2">
                    <p class="mb-1 text-2xs font-semibold uppercase tracking-wide text-slate-400">Local simulator</p>
                    <div class="flex flex-wrap gap-1">
                        <button v-for="s in ['busy', 'no_answer', 'failed']" :key="s" type="button" class="rounded border border-slate-200 px-1.5 py-0.5 text-2xs text-slate-600 hover:bg-slate-50" @click="phone.simulate(s)">{{ s.replace('_', ' ') }}</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Idle pill -->
        <div v-else class="flex justify-end">
            <div v-if="expanded" class="w-full space-y-2.5 rounded-2xl border border-slate-200 bg-slate-50 p-4 shadow-pop">
                <div class="flex items-center justify-between">
                    <span class="flex items-center gap-2 text-xs font-semibold text-slate-700"><span class="h-2 w-2 rounded-full" :class="dotColor" /> Softphone · {{ phone.statusLabel.value }}</span>
                    <button type="button" class="text-slate-400 hover:text-slate-600" aria-label="Collapse" @click="expanded = false"><AppIcon name="close" class="h-4 w-4" /></button>
                </div>
                <p v-if="!cfg.enabled && cfg.reason" class="text-xs text-slate-500">{{ cfg.reason }}</p>
                <p v-if="state.otherTab" class="text-xs text-amber-700">{{ phone.messages.OTHER_TAB }}</p>
                <p v-if="cfg.modes?.length" class="text-2xs text-slate-500">Modes: {{ cfg.modes.map((m) => (m === 'webrtc' ? 'Browser' : 'Phone')).join(', ') }}</p>
                <Link v-if="can('call.view', 'call.view_all')" :href="route('calls.index')" class="block text-xs font-medium text-brand-600 hover:underline">View call history</Link>
                <div v-if="isFake && can('call.receive')" class="space-y-1 border-t border-dashed border-slate-200 pt-2">
                    <p class="text-2xs font-semibold uppercase tracking-wide text-slate-400">Local simulator</p>
                    <div class="flex gap-1">
                        <input v-model="simulateNumber" class="form-input flex-1 py-1 text-xs" maxlength="30" aria-label="Caller number" />
                        <button type="button" class="rounded border border-slate-200 px-2 text-2xs text-slate-600 hover:bg-slate-50" @click="phone.simulateIncoming(simulateNumber)">Ring me</button>
                    </div>
                </div>
            </div>
            <button v-else type="button" class="flex h-10 items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-4 text-xs font-medium text-slate-700 shadow-pop transition hover:border-emerald-300 hover:text-slate-900" @click="expanded = true">
                <span class="h-2 w-2 rounded-full" :class="dotColor" />
                <AppIcon name="phone" class="h-4 w-4 text-emerald-600" />
                {{ state.status === 'ended' && state.outcomeCallId ? 'Outcome pending' : phone.statusLabel.value }}
            </button>
        </div>

        <p v-if="state.error" class="mt-2 flex items-start gap-2 rounded-xl border border-red-200 bg-slate-50 px-3 py-2 text-xs text-red-600 shadow-pop" role="alert">
            <span class="flex-1">{{ state.error }}</span>
            <button type="button" class="text-red-400 hover:text-red-600" aria-label="Dismiss" @click="phone.clearError()"><AppIcon name="close" class="h-3.5 w-3.5" /></button>
        </p>

        <CallOutcomeModal :show="!!state.outcomeCallId" :call-id="state.outcomeCallId" @saved="outcomeSaved" @close="phone.dismissOutcome()" />
    </div>
</template>
