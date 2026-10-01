<script setup>
import AppIcon from '@/Components/ui/AppIcon.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import { dismissPriority } from '@/chat/live';
import { useToast } from '@/Composables/useToast';
import { setLiveUnread } from '@/notifications/notifier';
import { formatDateTime } from '@/utils/format';
import axios from 'axios';
import { ref, watch } from 'vue';

/*
 * Persistent urgent-message banner. Stays until the recipient acknowledges or
 * the message expires (the server stops returning it). Sound is played once
 * by the notifier when the message arrives, never here.
 */
const props = defineProps({
    alerts: { type: Array, required: true },
});

const toast = useToast();
const busy = ref(null);
const expanded = ref({});
const reported = new Set();

// Marks each banner as read once it has actually been shown.
watch(
    () => props.alerts.map((a) => a.id),
    () => {
        for (const alert of props.alerts) {
            if (alert.read || reported.has(alert.id)) continue;
            reported.add(alert.id);
            axios
                .post(route('priority-broadcasts.read', alert.id))
                .then(({ data }) => setLiveUnread(data?.notifications_unread))
                .catch(() => reported.delete(alert.id));
        }
    },
    { immediate: true },
);

async function acknowledge(alert) {
    busy.value = alert.id;
    try {
        const { data } = await axios.post(route('priority-broadcasts.acknowledge', alert.id), {}, { headers: { Accept: 'application/json' } });
        dismissPriority(alert.id);
        setLiveUnread(data?.notifications_unread);
        toast.success('Acknowledged.');
    } catch {
        toast.error('Could not acknowledge the message. Please try again.');
    } finally {
        busy.value = null;
    }
}

const isLong = (alert) => (alert.message?.length ?? 0) > 220;
</script>

<template>
    <section class="space-y-3" aria-label="Urgent team messages" role="region">
        <article
            v-for="alert in alerts"
            :key="alert.id"
            class="relative overflow-hidden rounded-xl border border-red-200 bg-red-50 p-4 shadow-sm"
            role="alert"
            :data-testid="`priority-banner-${alert.id}`"
        >
            <span class="absolute inset-y-0 left-0 w-1 bg-red-500" aria-hidden="true" />
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600">
                    <AppIcon name="warning" class="h-5 w-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full bg-red-600 px-2 py-0.5 text-2xs font-bold uppercase tracking-wide text-white">Urgent</span>
                        <h2 class="text-sm font-semibold text-red-900">{{ alert.title }}</h2>
                    </div>
                    <p class="mt-1.5 whitespace-pre-line break-words text-sm text-red-900/90" :class="isLong(alert) && !expanded[alert.id] ? 'line-clamp-3' : ''">{{ alert.message }}</p>
                    <button v-if="isLong(alert)" type="button" class="mt-1 text-xs font-medium text-red-700 hover:underline" @click="expanded[alert.id] = !expanded[alert.id]">
                        {{ expanded[alert.id] ? 'Show less' : 'Read more' }}
                    </button>
                    <p class="mt-2 text-2xs text-red-800/70">
                        From {{ alert.sender || 'Administrator' }} · {{ formatDateTime(alert.sent_at) }}
                        <template v-if="alert.expires_at"> · until {{ formatDateTime(alert.expires_at) }}</template>
                    </p>
                </div>
                <UiButton variant="danger" size="sm" icon="check" :loading="busy === alert.id" class="self-start" @click="acknowledge(alert)">Acknowledge</UiButton>
            </div>
        </article>
    </section>
</template>
