<script setup>
import AppIcon from '@/Components/ui/AppIcon.vue';
import { liveUnread } from '@/notifications/notifier';
import { timeAgo } from '@/utils/format';
import { Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Header bell: unread count (shared Inertia prop) plus a dropdown of recent
 * notifications fetched on open. Opening an item goes through
 * notifications.open, which re-checks access before redirecting.
 */
const page = usePage();
const open = ref(false);
const loading = ref(false);
const items = ref([]);
const localUnread = ref(null);
const root = ref(null);

const unread = computed(() => localUnread.value ?? liveUnread.value ?? page.props.notifications?.unread ?? 0);

const load = async () => {
    loading.value = true;
    try {
        const { data } = await axios.get(route('notifications.recent'));
        items.value = data.data;
        localUnread.value = data.unread;
    } catch {
        items.value = [];
    } finally {
        loading.value = false;
    }
};

const toggle = () => {
    open.value = !open.value;
    if (open.value) load();
};

const markAll = async () => {
    await axios.post(route('notifications.read-all'), {}, { headers: { Accept: 'application/json' } });
    items.value = items.value.map((n) => ({ ...n, read: true }));
    localUnread.value = 0;
};

const go = (n) => {
    open.value = false;
    router.visit(route('notifications.open', n.id));
};

const onClickOutside = (e) => {
    if (open.value && root.value && !root.value.contains(e.target)) open.value = false;
};
let removeNavigateListener = null;
onMounted(() => {
    document.addEventListener('click', onClickOutside);
    removeNavigateListener = router.on('navigate', () => (localUnread.value = null));
});
onBeforeUnmount(() => {
    document.removeEventListener('click', onClickOutside);
    removeNavigateListener?.();
});
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            class="relative flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 text-slate-500 transition hover:border-slate-300 hover:text-slate-900"
            title="Notifications"
            :aria-label="unread > 0 ? `Notifications, ${unread} unread` : 'Notifications'"
            :aria-expanded="open"
            @click="toggle"
        >
            <AppIcon name="bell" class="h-5 w-5" />
            <span v-if="unread > 0" class="absolute -right-1 -top-1 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-red-500 px-1 text-2xs font-semibold text-white ring-2 ring-[var(--app-bg)]">
                {{ unread > 99 ? '99+' : unread }}
            </span>
        </button>

        <div v-if="open" class="absolute right-0 top-full z-50 mt-2 w-[400px] max-w-[calc(100vw-1rem)] overflow-hidden rounded-2xl border border-slate-200 bg-slate-50 shadow-pop">
            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                <span class="text-sm font-semibold text-slate-900">Notifications</span>
                <span class="flex items-center gap-3">
                    <button v-if="unread > 0" type="button" class="text-xs font-medium text-brand-600 hover:underline" @click="markAll">Mark all read</button>
                    <Link :href="`${route('profile.edit')}#notification-preferences`" class="icon-btn h-7 w-7" title="Notification preferences" aria-label="Notification preferences" @click="open = false">
                        <AppIcon name="cog" class="h-4 w-4" />
                    </Link>
                </span>
            </div>
            <ul class="max-h-[420px] divide-y divide-slate-100 overflow-y-auto">
                <li v-if="loading && !items.length" class="space-y-2 px-4 py-4">
                    <div v-for="i in 3" :key="i" class="h-3 animate-pulse rounded-full bg-slate-100" :style="{ width: `${90 - i * 15}%` }" />
                </li>
                <li v-else-if="!items.length" class="px-4 py-8 text-center text-xs text-slate-500">You're all caught up.</li>
                <li v-for="n in items" :key="n.id">
                    <button type="button" class="flex w-full gap-3 px-4 py-3 text-left transition hover:bg-slate-100" :class="{ 'bg-brand-50': !n.read }" :disabled="n.stale" @click="go(n)">
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="n.read ? 'bg-transparent' : 'bg-brand-500'" :aria-label="n.read ? undefined : 'Unread'" />
                        <span class="min-w-0">
                            <span class="block text-[13px] leading-snug" :class="n.stale ? 'italic text-slate-400' : 'text-slate-800'">{{ n.message }}</span>
                            <span class="mt-0.5 block text-2xs text-slate-400">{{ timeAgo(n.created_at) }}</span>
                        </span>
                    </button>
                </li>
            </ul>
            <Link :href="route('notifications.index')" class="block border-t border-slate-100 px-4 py-2.5 text-center text-xs font-medium text-brand-600 hover:bg-slate-100" @click="open = false">View all notifications</Link>
        </div>
    </div>
</template>
