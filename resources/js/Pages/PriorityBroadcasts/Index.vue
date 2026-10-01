<script setup>
import PriorityMessageModal from '@/Components/chat/PriorityMessageModal.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import UiPagination from '@/Components/ui/UiPagination.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { broadcastState, ratio } from '@/utils/chat';
import { formatDateTime } from '@/utils/format';
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    broadcasts: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    canSend: { type: Boolean, default: false },
});

const showSend = ref(false);
const status = ref(props.filters.status ?? '');

const applyStatus = () => router.get(route('priority-broadcasts.index'), status.value ? { status: status.value } : {}, { preserveState: true, replace: true });
</script>

<template>
    <AppLayout title="Priority messages">
        <PageHeader title="Priority messages" subtitle="Urgent messages sent to the whole team, with read and acknowledgement tracking.">
            <template #actions>
                <select v-model="status" class="form-input !h-8 !w-auto !py-0 text-xs" aria-label="Status" @change="applyStatus">
                    <option value="">All</option>
                    <option value="active">Active</option>
                    <option value="expired">Expired</option>
                </select>
                <UiButton v-if="canSend" variant="danger" size="sm" icon="warning" @click="showSend = true">Send Priority Message</UiButton>
            </template>
        </PageHeader>

        <div class="panel overflow-hidden">
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Sent by</th>
                            <th>Sent at</th>
                            <th class="text-right">Recipients</th>
                            <th class="text-right">Read</th>
                            <th class="text-right">Acknowledged</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="b in broadcasts.data" :key="b.id">
                            <td class="max-w-xs">
                                <Link :href="route('priority-broadcasts.show', b.id)" class="block truncate font-medium text-slate-900 hover:text-brand-700">{{ b.title }}</Link>
                                <span v-if="b.expires_at" class="text-2xs text-slate-500">Expires {{ formatDateTime(b.expires_at) }}</span>
                            </td>
                            <td>{{ b.sender || '—' }}</td>
                            <td class="whitespace-nowrap">{{ formatDateTime(b.sent_at) }}</td>
                            <td class="text-right tabular-nums">{{ b.recipients_count }}</td>
                            <td class="text-right tabular-nums">{{ ratio(b.read_count, b.recipients_count).text }}</td>
                            <td class="text-right tabular-nums">{{ ratio(b.acknowledged_count, b.recipients_count).text }} <span class="text-2xs text-slate-400">({{ ratio(b.acknowledged_count, b.recipients_count).percent }}%)</span></td>
                            <td><UiBadge :color="broadcastState(b).color" dot>{{ broadcastState(b).label }}</UiBadge></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-if="!broadcasts.data.length" icon="warning" title="No priority messages yet" />
            <UiPagination :paginator="broadcasts" />
        </div>

        <PriorityMessageModal v-if="canSend" :show="showSend" @close="showSend = false" />
    </AppLayout>
</template>
