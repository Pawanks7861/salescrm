<script setup>
import AppIcon from '@/Components/ui/AppIcon.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import StatCard from '@/Components/ui/StatCard.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import UiPagination from '@/Components/ui/UiPagination.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { broadcastState, ratio } from '@/utils/chat';
import { formatDateTime } from '@/utils/format';
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    broadcast: { type: Object, required: true },
    recipient: { type: Object, default: null },
    recipients: { type: Object, default: null },
    canViewHistory: { type: Boolean, default: false },
});

const state = computed(() => broadcastState(props.broadcast));
const acknowledging = ref(false);

const acknowledge = () =>
    router.post(route('priority-broadcasts.acknowledge', props.broadcast.id), {}, {
        preserveScroll: true,
        onStart: () => (acknowledging.value = true),
        onFinish: () => (acknowledging.value = false),
    });
</script>

<template>
    <AppLayout :title="broadcast.title">
        <PageHeader :title="broadcast.title" subtitle="Priority message">
            <template v-if="canViewHistory" #breadcrumb>
                <Link :href="route('priority-broadcasts.index')" class="link">Priority messages</Link> / #{{ broadcast.id }}
            </template>
        </PageHeader>

        <div class="grid gap-5 xl:grid-cols-3">
            <div class="space-y-5 xl:col-span-2">
                <article class="panel overflow-hidden border-red-200">
                    <div class="flex flex-wrap items-center gap-2 border-b border-red-100 bg-red-50 px-5 py-3">
                        <span class="rounded-full bg-red-600 px-2 py-0.5 text-2xs font-bold uppercase tracking-wide text-white">Urgent</span>
                        <UiBadge :color="state.color" dot>{{ state.label }}</UiBadge>
                        <span class="text-xs text-red-900/70">From {{ broadcast.sender || 'Administrator' }} · {{ formatDateTime(broadcast.sent_at) }}</span>
                        <span v-if="broadcast.expires_at" class="text-xs text-red-900/70">· Expires {{ formatDateTime(broadcast.expires_at) }}</span>
                    </div>
                    <p class="whitespace-pre-line break-words px-5 py-4 text-sm text-slate-800">{{ broadcast.message }}</p>
                    <div v-if="recipient" class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-3">
                        <p v-if="recipient.acknowledged_at" class="inline-flex items-center gap-1.5 text-sm text-emerald-700">
                            <AppIcon name="check" class="h-4 w-4" />You acknowledged this on {{ formatDateTime(recipient.acknowledged_at) }}
                        </p>
                        <p v-else class="text-sm text-slate-600">Please confirm you have read this message.</p>
                        <UiButton v-if="!recipient.acknowledged_at" variant="danger" size="sm" icon="check" :loading="acknowledging" @click="acknowledge">Acknowledge</UiButton>
                    </div>
                </article>

                <div v-if="recipients" class="panel overflow-hidden">
                    <div class="panel-header"><h2 class="panel-title">Recipients</h2></div>
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Delivered</th>
                                    <th>Read</th>
                                    <th>Acknowledged</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in recipients.data" :key="r.id">
                                    <td>
                                        <span class="block font-medium text-slate-900">{{ r.name }}</span>
                                        <span v-if="r.designation" class="text-2xs text-slate-500">{{ r.designation }}</span>
                                    </td>
                                    <td class="whitespace-nowrap">{{ r.delivered_at ? formatDateTime(r.delivered_at) : 'Pending' }}</td>
                                    <td class="whitespace-nowrap">{{ r.read_at ? formatDateTime(r.read_at) : '—' }}</td>
                                    <td class="whitespace-nowrap">
                                        <UiBadge v-if="r.acknowledged_at" color="green" dot>{{ formatDateTime(r.acknowledged_at) }}</UiBadge>
                                        <UiBadge v-else color="amber" dot>Not yet</UiBadge>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <UiPagination :paginator="recipients" />
                </div>
            </div>

            <div v-if="recipients" class="grid content-start gap-4 sm:grid-cols-3 xl:grid-cols-1">
                <StatCard label="Recipients" :value="broadcast.recipients_count" icon="users" tone="brand" />
                <StatCard label="Read" :value="ratio(broadcast.read_count, broadcast.recipients_count).text" icon="eye" tone="blue" />
                <StatCard label="Acknowledged" :value="ratio(broadcast.acknowledged_count, broadcast.recipients_count).text" icon="check" tone="green" />
            </div>
        </div>
    </AppLayout>
</template>
