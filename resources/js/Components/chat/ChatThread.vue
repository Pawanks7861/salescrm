<script setup>
import ChatComposer from '@/Components/chat/ChatComposer.vue';
import MessageBubble from '@/Components/chat/MessageBubble.vue';
import AppIcon from '@/Components/ui/AppIcon.vue';
import Avatar from '@/Components/ui/Avatar.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { useToast } from '@/Composables/useToast';
import { setLiveUnread } from '@/notifications/notifier';
import { applyChanges, lastMessageId, mergeMessages, presenceLabel, withDaySeparators } from '@/utils/chat';
import axios from 'axios';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

/*
 * One open conversation. The database is the source of truth: the thread
 * loads the latest page, then syncs every few seconds while open (and
 * immediately when a chat push arrives), fetching only messages newer than
 * the last one held plus edits/deletions since the last sync.
 */
const props = defineProps({
    conversationId: { type: Number, required: true },
    summary: { type: Object, default: null },
    config: { type: Object, required: true },
    tz: { type: String, default: undefined },
});
const emit = defineEmits(['back', 'changed', 'read']);

const VISIBLE_POLL_MS = 3000;
const HIDDEN_POLL_MS = 15000;

const toast = useToast();
const { confirm } = useConfirm();

const messages = ref([]);
const participant = ref(null);
const canSendHere = ref(true);
const hasMore = ref(false);
const loading = ref(true);
const loadingOlder = ref(false);
const sending = ref(false);
const replyTo = ref(null);
const editing = ref(null);
const scroller = ref(null);
const composer = ref(null);
const now = ref(Date.now());

let syncedAt = null;
let timer = null;
let clock = null;
let generation = 0;
let lastReadSent = 0;

const other = computed(() => participant.value?.user ?? props.summary?.user ?? null);
const otherLastRead = computed(() => Number(participant.value?.last_read_message_id ?? 0));
const rows = computed(() => withDaySeparators(messages.value, { now: now.value, tz: props.tz }));
const statusLine = computed(() => (participant.value?.typing ? 'typing…' : presenceLabel(other.value, { now: now.value, tz: props.tz })));
const disabledReason = computed(() => (canSendHere.value ? '' : 'This user is no longer active. You can read the history but not send new messages.'));
const replyAuthor = computed(() => (replyTo.value?.mine ? 'yourself' : other.value?.name ?? ''));

const visible = () => typeof document === 'undefined' || document.visibilityState === 'visible';
const nearBottom = () => {
    const el = scroller.value;
    return !el || el.scrollHeight - el.scrollTop - el.clientHeight < 120;
};
const scrollToBottom = () => nextTick(() => scroller.value && (scroller.value.scrollTop = scroller.value.scrollHeight));

function applyMeta(data) {
    if (data.participant !== undefined) participant.value = data.participant;
    if (data.can_send !== undefined) canSendHere.value = data.can_send;
    if (data.synced_at) syncedAt = data.synced_at;
}

async function markRead() {
    const latest = lastMessageId(messages.value);
    const unreadFromOther = messages.value.some((m) => !m.mine && m.id > lastReadSent);
    if (!latest || !unreadFromOther || !visible()) return;
    lastReadSent = latest;
    try {
        const { data } = await axios.post(route('chat.conversations.read', props.conversationId));
        emit('read', { conversationId: props.conversationId, unreadTotal: data.unread_total });
        setLiveUnread(data.notifications_unread);
    } catch {
        lastReadSent = 0;
    }
}

async function loadLatest() {
    const mine = ++generation;
    loading.value = true;
    try {
        const { data } = await axios.get(route('chat.messages.index', props.conversationId), { params: { viewing: visible() ? 1 : 0 } });
        if (mine !== generation) return;
        messages.value = data.messages;
        hasMore.value = data.has_more;
        applyMeta(data);
        scrollToBottom();
        markRead();
    } catch {
        if (mine === generation) toast.error('Could not load this conversation.');
    } finally {
        if (mine === generation) loading.value = false;
    }
}

async function loadOlder() {
    if (loadingOlder.value || !hasMore.value || !messages.value.length) return;
    loadingOlder.value = true;
    const el = scroller.value;
    const before = el ? el.scrollHeight : 0;
    try {
        const { data } = await axios.get(route('chat.messages.index', props.conversationId), { params: { before: messages.value[0].id } });
        messages.value = mergeMessages(data.messages, messages.value);
        hasMore.value = data.has_more;
        await nextTick();
        if (el) el.scrollTop = el.scrollHeight - before;
    } catch {
        toast.error('Could not load older messages.');
    } finally {
        loadingOlder.value = false;
    }
}

async function sync() {
    if (loading.value) return;
    const mine = generation;
    try {
        const { data } = await axios.get(route('chat.messages.index', props.conversationId), {
            params: { after: lastMessageId(messages.value), since: syncedAt || undefined, viewing: visible() ? 1 : 0 },
        });
        if (mine !== generation) return;
        const stick = nearBottom();
        const before = messages.value.length;
        messages.value = applyChanges(mergeMessages(messages.value, data.messages), data.changed);
        applyMeta(data);
        if (messages.value.length !== before) {
            if (stick) scrollToBottom();
            emit('changed');
        } else if (data.changed?.length) {
            emit('changed');
        }
        markRead();
    } catch {
        /* transient: next tick retries */
    }
}

function schedule() {
    clearTimeout(timer);
    timer = setTimeout(async () => {
        await sync();
        schedule();
    }, visible() ? VISIBLE_POLL_MS : HIDDEN_POLL_MS);
}

async function send({ text, files }) {
    sending.value = true;
    const form = new FormData();
    if (text.trim()) form.append('message', text);
    if (replyTo.value) form.append('reply_to_message_id', replyTo.value.id);
    files.forEach((f) => form.append('attachments[]', f));
    try {
        const { data } = await axios.post(route('chat.messages.store', props.conversationId), form);
        messages.value = mergeMessages(messages.value, [data.message]);
        composer.value?.clear();
        replyTo.value = null;
        scrollToBottom();
        emit('changed');
    } catch (e) {
        const errors = e.response?.data?.errors;
        toast.error(errors ? Object.values(errors).flat()[0] : e.response?.data?.message || 'Message not sent. Please try again.');
    } finally {
        sending.value = false;
    }
}

async function saveEdit({ message, text }) {
    sending.value = true;
    try {
        const { data } = await axios.put(route('chat.messages.update', message.id), { message: text });
        messages.value = mergeMessages(messages.value, [data.message]);
        editing.value = null;
        composer.value?.clear();
        emit('changed');
    } catch (e) {
        toast.error(e.response?.data?.errors?.message?.[0] || e.response?.data?.message || 'Could not edit the message.');
    } finally {
        sending.value = false;
    }
}

async function remove(message) {
    if (!(await confirm({ title: 'Delete message?', message: 'It will show as “This message was deleted.” for both of you.', confirmText: 'Delete', danger: true }))) return;
    try {
        const { data } = await axios.delete(route('chat.messages.destroy', message.id));
        messages.value = mergeMessages(messages.value, [data.message]);
        if (editing.value?.id === message.id) editing.value = null;
        emit('changed');
    } catch {
        toast.error('Could not delete the message.');
    }
}

function typing() {
    axios.post(route('chat.conversations.typing', props.conversationId)).catch(() => {});
}

function startReply(message) {
    editing.value = null;
    replyTo.value = message;
}

function startEdit(message) {
    replyTo.value = null;
    editing.value = message;
}

function onScroll() {
    if (scroller.value && scroller.value.scrollTop < 60) loadOlder();
}

const onArrival = (e) => ['CHAT_MESSAGE', 'chat_message'].includes(e.detail?.event) && sync();
const onVisibility = () => {
    if (visible()) {
        sync();
        schedule();
    }
};

watch(
    () => props.conversationId,
    () => {
        messages.value = [];
        participant.value = null;
        replyTo.value = null;
        editing.value = null;
        syncedAt = null;
        lastReadSent = 0;
        loadLatest();
        schedule();
    },
);

onMounted(() => {
    loadLatest();
    schedule();
    clock = setInterval(() => (now.value = Date.now()), 30000);
    window.addEventListener('crm:notification', onArrival);
    document.addEventListener('visibilitychange', onVisibility);
});

onUnmounted(() => {
    generation++;
    clearTimeout(timer);
    clearInterval(clock);
    window.removeEventListener('crm:notification', onArrival);
    document.removeEventListener('visibilitychange', onVisibility);
});
</script>

<template>
    <div class="flex h-full min-h-0 flex-col">
        <header class="flex items-center gap-3 border-b border-slate-100 px-3 py-2.5">
            <button type="button" class="icon-btn md:hidden" aria-label="Back to conversations" data-testid="back" @click="emit('back')">
                <AppIcon name="chevron-left" class="h-5 w-5" />
            </button>
            <Avatar :name="other?.name ?? '?'" size="md" />
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-slate-900">{{ other?.name ?? 'Conversation' }}</p>
                <p class="truncate text-2xs" :class="participant?.typing ? 'text-brand-600' : other?.online ? 'text-emerald-600' : 'text-slate-500'" data-testid="presence">
                    {{ statusLine }}<template v-if="other?.designation || other?.role"> · {{ other.designation || other.role }}</template>
                </p>
            </div>
        </header>

        <div ref="scroller" class="min-h-0 flex-1 space-y-1.5 overflow-y-auto bg-slate-50/60 px-3 py-4" data-testid="messages" @scroll.passive="onScroll">
            <div v-if="hasMore" class="flex justify-center pb-2">
                <button type="button" class="rounded-full border border-slate-200 bg-white px-3 py-1 text-2xs text-slate-500 hover:bg-slate-50" :disabled="loadingOlder" @click="loadOlder">
                    {{ loadingOlder ? 'Loading…' : 'Load older messages' }}
                </button>
            </div>
            <p v-if="loading" class="py-10 text-center text-sm text-slate-400">Loading messages…</p>
            <p v-else-if="!messages.length" class="py-10 text-center text-sm text-slate-500">No messages yet. Say hello 👋</p>
            <template v-for="row in rows" :key="row.key">
                <div v-if="row.type === 'day'" class="flex justify-center py-2">
                    <span class="rounded-full bg-white px-3 py-0.5 text-2xs font-medium text-slate-500 shadow-sm">{{ row.label }}</span>
                </div>
                <MessageBubble
                    v-else
                    :message="row.message"
                    :other-last-read-id="otherLastRead"
                    :other-name="other?.name ?? ''"
                    :tz="tz"
                    :can-act="canSendHere"
                    @reply="startReply"
                    @edit="startEdit"
                    @delete="remove"
                />
            </template>
        </div>

        <ChatComposer
            ref="composer"
            :config="config"
            :reply-to="replyTo"
            :reply-author="replyAuthor"
            :editing="editing"
            :disabled="!canSendHere"
            :disabled-reason="disabledReason"
            :sending="sending"
            @send="send"
            @save-edit="saveEdit"
            @cancel-reply="replyTo = null"
            @cancel-edit="editing = null"
            @typing="typing"
        />
    </div>
</template>
