<script setup>
import EmptyState from '@/Components/ui/EmptyState.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDateTime, timeAgo } from '@/utils/format';
import { Link, router } from '@inertiajs/vue3';

defineProps({
    notifications: Object,
    unread: Number,
});

const markAll = () => router.post(route('notifications.read-all'), {}, { preserveScroll: true });
const markRead = (n) => router.post(route('notifications.read', n.id), {}, { preserveScroll: true });
</script>

<template>
    <AppLayout title="Notifications">
        <PageHeader title="Notifications" :subtitle="`${unread} unread`">
            <template #actions>
                <UiButton v-if="unread" variant="secondary" icon="check" @click="markAll">Mark all read</UiButton>
            </template>
        </PageHeader>

        <div class="panel">
            <ul class="divide-y divide-slate-100">
                <li v-for="n in notifications.data" :key="n.id" class="flex items-start gap-3 px-4 py-3" :class="{ 'bg-brand-50/40': !n.read }">
                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="n.read ? 'bg-slate-200' : 'bg-brand-600'" />
                    <div class="min-w-0 flex-1">
                        <Link v-if="!n.stale" :href="route('notifications.open', n.id)" class="text-sm text-slate-800 hover:text-brand-700">{{ n.message }}</Link>
                        <p v-else class="text-sm italic text-slate-400">{{ n.message }}</p>
                        <p class="text-2xs text-slate-400" :title="formatDateTime(n.created_at)">{{ timeAgo(n.created_at) }}</p>
                    </div>
                    <button v-if="!n.read" type="button" class="text-2xs text-slate-500 hover:text-slate-800" @click="markRead(n)">Mark read</button>
                </li>
            </ul>
            <EmptyState v-if="!notifications.data.length" icon="bell" title="No notifications yet" description="Follow-up reminders, overdue alerts and assignments will appear here." />

            <div v-if="notifications.meta.last_page > 1" class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 px-4 py-2 text-xs text-slate-500">
                <span>Showing {{ notifications.meta.from }}–{{ notifications.meta.to }} of {{ notifications.meta.total }}</span>
                <nav class="flex items-center gap-0.5">
                    <template v-for="(link, i) in notifications.links" :key="i">
                        <Link v-if="link.url" :href="link.url" preserve-scroll class="min-w-[1.75rem] rounded px-2 py-1 text-center" :class="link.active ? 'bg-brand-600 font-semibold text-white' : 'text-slate-600 hover:bg-slate-100'" v-html="link.label" />
                        <span v-else class="min-w-[1.75rem] px-2 py-1 text-center text-slate-300" v-html="link.label" />
                    </template>
                </nav>
            </div>
        </div>
    </AppLayout>
</template>
