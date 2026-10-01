<script setup>
import AppIcon from '@/Components/ui/AppIcon.vue';
import Avatar from '@/Components/ui/Avatar.vue';
import { formatClock, isOnline, previewLine, unreadBadge } from '@/utils/chat';
import { computed, ref } from 'vue';

const props = defineProps({
    conversations: { type: Array, default: () => [] },
    activeId: { type: Number, default: null },
    tz: { type: String, default: undefined },
});
const emit = defineEmits(['select', 'new']);

const term = ref('');

const filtered = computed(() => {
    const q = term.value.trim().toLowerCase();
    if (!q) return props.conversations;
    return props.conversations.filter((c) => (c.user?.name ?? '').toLowerCase().includes(q));
});

function stamp(value) {
    if (!value) return '';
    const date = new Date(value);
    const sameDay = date.toDateString() === new Date().toDateString();
    return sameDay ? formatClock(value, props.tz) : new Intl.DateTimeFormat('en-IN', { day: 'numeric', month: 'short', timeZone: props.tz }).format(date);
}
</script>

<template>
    <div class="flex h-full min-h-0 flex-col">
        <div class="flex items-center gap-2 border-b border-slate-100 p-3">
            <div class="relative min-w-0 flex-1">
                <AppIcon name="search" class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input v-model="term" type="search" class="form-input !pl-8" placeholder="Search conversations" aria-label="Search conversations" />
            </div>
            <button type="button" class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-lg bg-brand-600 px-3 text-xs font-medium text-white hover:bg-brand-500" data-testid="new-chat" @click="emit('new')">
                <AppIcon name="plus" class="h-4 w-4" />New Chat
            </button>
        </div>

        <ul class="min-h-0 flex-1 overflow-y-auto" role="list" aria-label="Conversations">
            <li v-for="c in filtered" :key="c.id">
                <button
                    type="button"
                    class="flex w-full items-center gap-3 px-3 py-2.5 text-left transition hover:bg-slate-50"
                    :class="c.id === activeId ? 'bg-brand-50' : ''"
                    :aria-current="c.id === activeId ? 'true' : undefined"
                    :data-testid="`conversation-${c.id}`"
                    @click="emit('select', c.id)"
                >
                    <span class="relative">
                        <Avatar :name="c.user?.name ?? '?'" size="md" />
                        <span
                            v-if="isOnline(c.user)"
                            class="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full bg-emerald-500 ring-2 ring-white"
                            aria-label="Online"
                            data-testid="online-dot"
                        />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center justify-between gap-2">
                            <span class="truncate text-sm font-medium text-slate-900" :class="c.unread ? 'font-semibold' : ''">{{ c.user?.name ?? 'Unknown user' }}</span>
                            <span class="shrink-0 text-2xs text-slate-400">{{ stamp(c.last_message_at) }}</span>
                        </span>
                        <span class="flex items-center justify-between gap-2">
                            <span class="truncate text-xs" :class="c.unread ? 'font-medium text-slate-800' : 'text-slate-500'" :data-testid="`preview-${c.id}`">
                                {{ previewLine(c.last_message) }}
                            </span>
                            <span
                                v-if="unreadBadge(c.unread)"
                                class="shrink-0 rounded-full bg-brand-500 px-1.5 text-2xs font-semibold leading-5 text-white"
                                :aria-label="`${c.unread} unread`"
                                :data-testid="`unread-${c.id}`"
                            >{{ unreadBadge(c.unread) }}</span>
                        </span>
                    </span>
                </button>
            </li>
        </ul>

        <div v-if="!filtered.length" class="flex flex-1 flex-col items-center justify-center gap-2 p-6 text-center text-sm text-slate-500" data-testid="no-conversations">
            <AppIcon name="chat" class="h-8 w-8 text-slate-300" />
            <p v-if="term">No conversations match “{{ term }}”.</p>
            <p v-else>No conversations yet. Start one with <strong>New Chat</strong>.</p>
        </div>
    </div>
</template>
