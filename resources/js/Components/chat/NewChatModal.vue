<script setup>
import Modal from '@/Components/Modal.vue';
import AppIcon from '@/Components/ui/AppIcon.vue';
import Avatar from '@/Components/ui/Avatar.vue';
import { isOnline, presenceLabel } from '@/utils/chat';
import axios from 'axios';
import { nextTick, ref, watch } from 'vue';

/** Server-side user search; only active, chat-enabled colleagues are returned. */
const props = defineProps({
    show: Boolean,
    tz: { type: String, default: undefined },
});
const emit = defineEmits(['close', 'start']);

const term = ref('');
const users = ref([]);
const loading = ref(false);
const starting = ref(null);
const error = ref('');
const input = ref(null);
let timer = null;
let seq = 0;

async function search() {
    const mine = ++seq;
    loading.value = true;
    try {
        const { data } = await axios.get(route('chat.users.index'), { params: { q: term.value.trim() || undefined } });
        if (mine === seq) users.value = data.users ?? [];
    } catch {
        if (mine === seq) error.value = 'Could not load users.';
    } finally {
        if (mine === seq) loading.value = false;
    }
}

watch(
    () => props.show,
    (open) => {
        if (!open) return;
        term.value = '';
        error.value = '';
        search();
        nextTick(() => input.value?.focus());
    },
);

watch(term, () => {
    clearTimeout(timer);
    timer = setTimeout(search, 250);
});

async function start(user) {
    starting.value = user.id;
    error.value = '';
    try {
        const { data } = await axios.post(route('chat.users.start', user.id));
        emit('start', data.conversation_id);
    } catch (e) {
        error.value = e.response?.data?.message || 'Could not start the conversation.';
    } finally {
        starting.value = null;
    }
}
</script>

<template>
    <Modal :show="show" max-width="md" @close="emit('close')">
        <div class="modal-header">
            <h3 class="text-sm font-semibold">New chat</h3>
            <p class="text-2xs text-slate-500">Start a private one-to-one conversation with a colleague.</p>
        </div>
        <div class="p-4">
            <div class="relative">
                <AppIcon name="search" class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input ref="input" v-model="term" type="search" class="form-input !pl-8" placeholder="Search by name or designation" aria-label="Search users" maxlength="100" />
            </div>
            <p v-if="error" class="form-error mt-2">{{ error }}</p>
            <ul class="mt-3 max-h-80 divide-y divide-slate-100 overflow-y-auto" role="list">
                <li v-for="user in users" :key="user.id">
                    <button type="button" class="flex w-full items-center gap-3 px-1 py-2 text-left hover:bg-slate-50 disabled:opacity-60" :disabled="starting !== null" :data-testid="`user-${user.id}`" @click="start(user)">
                        <span class="relative">
                            <Avatar :name="user.name" size="md" />
                            <span v-if="isOnline(user)" class="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full bg-emerald-500 ring-2 ring-white" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-slate-900">{{ user.name }}</span>
                            <span class="block truncate text-2xs text-slate-500">{{ [user.designation || user.role, presenceLabel(user, { tz })].filter(Boolean).join(' · ') }}</span>
                        </span>
                        <span v-if="starting === user.id" class="text-2xs text-slate-400">Opening…</span>
                    </button>
                </li>
            </ul>
            <p v-if="!loading && !users.length" class="py-6 text-center text-sm text-slate-500">No colleagues found.</p>
            <p v-if="loading && !users.length" class="py-6 text-center text-sm text-slate-400">Searching…</p>
        </div>
    </Modal>
</template>
