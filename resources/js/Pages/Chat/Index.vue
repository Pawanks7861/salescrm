<script setup>
import ChatThread from '@/Components/chat/ChatThread.vue';
import ConversationList from '@/Components/chat/ConversationList.vue';
import NewChatModal from '@/Components/chat/NewChatModal.vue';
import AppIcon from '@/Components/ui/AppIcon.vue';
import { setChatUnread } from '@/chat/live';
import AppLayout from '@/Layouts/AppLayout.vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, onUnmounted, ref } from 'vue';

const props = defineProps({
    conversations: { type: Array, default: () => [] },
    activeId: { type: Number, default: null },
    config: { type: Object, required: true },
});

const LIST_POLL_MS = 10000;

const page = usePage();
const tz = computed(() => page.props.app?.timezone || undefined);
const list = ref(props.conversations);
const active = ref(props.activeId);
const showNew = ref(false);
const activeSummary = computed(() => list.value.find((c) => c.id === active.value) ?? null);

let timer = null;

/** Keeps the address bar deep-linkable (/chat/{id}) without adding history entries Inertia would replay. */
function syncUrl() {
    const url = active.value ? route('chat.show', active.value) : route('chat.index');
    window.history.replaceState(window.history.state, '', url);
}

async function refreshList() {
    try {
        const { data } = await axios.get(route('chat.conversations.index'), { params: { include: active.value || undefined } });
        list.value = data.conversations;
        setChatUnread(data.unread_total);
    } catch {
        /* next tick retries */
    }
}

function open(id) {
    if (id === active.value) return;
    active.value = id;
    syncUrl();
}

function back() {
    active.value = null;
    syncUrl();
}

async function started(conversationId) {
    showNew.value = false;
    active.value = conversationId;
    syncUrl();
    await refreshList();
}

function onRead({ conversationId, unreadTotal }) {
    const item = list.value.find((c) => c.id === conversationId);
    if (item) item.unread = 0;
    setChatUnread(unreadTotal);
}

const onArrival = (e) => ['CHAT_MESSAGE', 'chat_message'].includes(e.detail?.event) && refreshList();

onMounted(() => {
    timer = setInterval(() => document.visibilityState === 'visible' && refreshList(), LIST_POLL_MS);
    window.addEventListener('crm:notification', onArrival);
});

onUnmounted(() => {
    clearInterval(timer);
    window.removeEventListener('crm:notification', onArrival);
});
</script>

<template>
    <AppLayout title="Chat">
        <div class="panel flex h-[calc(100dvh-8rem)] min-h-[28rem] overflow-hidden lg:h-[calc(100dvh-9rem)]">
            <aside class="w-full shrink-0 border-r border-slate-100 md:block md:w-80 lg:w-96" :class="active ? 'hidden' : 'block'" aria-label="Conversations">
                <ConversationList :conversations="list" :active-id="active" :tz="tz" @select="open" @new="showNew = true" />
            </aside>

            <section class="min-w-0 flex-1 md:block" :class="active ? 'block' : 'hidden'">
                <ChatThread
                    v-if="active"
                    :key="active"
                    :conversation-id="active"
                    :summary="activeSummary"
                    :config="config"
                    :tz="tz"
                    @back="back"
                    @changed="refreshList"
                    @read="onRead"
                />
                <div v-else class="flex h-full flex-col items-center justify-center gap-3 p-8 text-center">
                    <span class="flex h-14 w-14 items-center justify-center rounded-full bg-brand-50 text-brand-500"><AppIcon name="chat" class="h-7 w-7" /></span>
                    <h2 class="text-sm font-semibold text-slate-900">Internal chat</h2>
                    <p class="max-w-xs text-sm text-slate-500">Pick a conversation or start a new private chat with a colleague.</p>
                    <button type="button" class="mt-1 inline-flex h-9 items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-medium text-white hover:bg-brand-500" @click="showNew = true">
                        <AppIcon name="plus" class="h-4 w-4" />New Chat
                    </button>
                </div>
            </section>
        </div>

        <NewChatModal :show="showNew" :tz="tz" @close="showNew = false" @start="started" />
    </AppLayout>
</template>
