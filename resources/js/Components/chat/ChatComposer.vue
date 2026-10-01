<script setup>
import AppIcon from '@/Components/ui/AppIcon.vue';
import { canSend, replySnippet, validateFiles } from '@/utils/chat';
import { formatBytes } from '@/utils/format';
import { computed, nextTick, ref, watch } from 'vue';

const props = defineProps({
    config: { type: Object, required: true },
    replyTo: { type: Object, default: null },
    replyAuthor: { type: String, default: '' },
    editing: { type: Object, default: null },
    disabled: { type: Boolean, default: false },
    disabledReason: { type: String, default: '' },
    sending: { type: Boolean, default: false },
});
const emit = defineEmits(['send', 'save-edit', 'cancel-reply', 'cancel-edit', 'typing']);

const text = ref('');
const files = ref([]);
const errors = ref([]);
const input = ref(null);
const picker = ref(null);

const accept = computed(() => props.config.allowedExtensions.map((e) => `.${e}`).join(','));
const ready = computed(() => !props.disabled && !props.sending && (props.editing ? Boolean(text.value.trim()) : canSend(text.value, files.value)));

watch(
    () => props.editing,
    (message) => {
        text.value = message ? message.body ?? '' : '';
        files.value = [];
        errors.value = [];
        if (message) nextTick(() => input.value?.focus());
    },
);
watch(
    () => props.replyTo,
    (message) => message && nextTick(() => input.value?.focus()),
);

function pick(event) {
    const chosen = [...files.value, ...(event.target.files ?? [])];
    errors.value = validateFiles(chosen, { maxKb: props.config.maxAttachmentKb, maxFiles: props.config.maxAttachments, extensions: props.config.allowedExtensions });
    if (!errors.value.length) files.value = chosen;
    event.target.value = '';
}

function removeFile(index) {
    files.value = files.value.filter((_, i) => i !== index);
    errors.value = [];
}

let lastTyping = 0;
function onInput() {
    const now = Date.now();
    if (!props.editing && text.value.trim() && now - lastTyping > 3000) {
        lastTyping = now;
        emit('typing');
    }
}

function submit() {
    if (!ready.value) return;
    if (props.editing) {
        emit('save-edit', { message: props.editing, text: text.value });
        return;
    }
    emit('send', { text: text.value, files: [...files.value] });
}

/** Called by the parent once the server accepted the message. */
function clear() {
    text.value = '';
    files.value = [];
    errors.value = [];
    lastTyping = 0;
}

function onKeydown(event) {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        submit();
    } else if (event.key === 'Escape') {
        if (props.editing) emit('cancel-edit');
        else if (props.replyTo) emit('cancel-reply');
    }
}

defineExpose({ clear, focus: () => input.value?.focus() });
</script>

<template>
    <div class="border-t border-slate-100 bg-white p-3">
        <p v-if="disabled && disabledReason" class="rounded-lg bg-slate-50 px-3 py-2 text-center text-xs text-slate-500" data-testid="composer-disabled">{{ disabledReason }}</p>
        <template v-else>
            <div v-if="editing" class="mb-2 flex items-center justify-between gap-2 rounded-lg bg-amber-50 px-3 py-1.5 text-xs text-amber-800" data-testid="editing-bar">
                <span class="inline-flex items-center gap-1.5"><AppIcon name="edit" class="h-3.5 w-3.5" />Editing message</span>
                <button type="button" class="text-amber-700 hover:underline" @click="emit('cancel-edit')">Cancel</button>
            </div>
            <div v-else-if="replyTo" class="mb-2 flex items-start justify-between gap-2 rounded-lg border-l-2 border-brand-400 bg-slate-50 px-3 py-1.5 text-xs" data-testid="reply-bar">
                <span class="min-w-0">
                    <span class="block font-semibold text-slate-700">Replying to {{ replyAuthor }}</span>
                    <span class="block truncate text-slate-500">{{ replySnippet(replyTo) }}</span>
                </span>
                <button type="button" class="shrink-0 text-slate-400 hover:text-slate-700" aria-label="Cancel reply" @click="emit('cancel-reply')"><AppIcon name="close" class="h-4 w-4" /></button>
            </div>

            <ul v-if="files.length" class="mb-2 flex flex-wrap gap-2" data-testid="pending-files">
                <li v-for="(file, i) in files" :key="`${file.name}-${i}`" class="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-slate-50 px-2 py-1 text-xs">
                    <AppIcon name="paperclip" class="h-3.5 w-3.5 text-slate-400" />
                    <span class="max-w-[10rem] truncate">{{ file.name }}</span>
                    <span class="text-slate-400">{{ formatBytes(file.size) }}</span>
                    <button type="button" class="text-slate-400 hover:text-red-600" :aria-label="`Remove ${file.name}`" @click="removeFile(i)"><AppIcon name="close" class="h-3.5 w-3.5" /></button>
                </li>
            </ul>
            <p v-for="error in errors" :key="error" class="form-error mb-1" data-testid="file-error">{{ error }}</p>

            <form class="flex items-end gap-2" @submit.prevent="submit">
                <template v-if="!editing">
                    <input ref="picker" type="file" class="hidden" multiple :accept="accept" data-testid="file-input" @change="pick" />
                    <button type="button" class="icon-btn shrink-0" aria-label="Attach files" title="Attach files" :disabled="sending" @click="picker?.click()">
                        <AppIcon name="paperclip" class="h-5 w-5" />
                    </button>
                </template>
                <textarea
                    ref="input"
                    v-model="text"
                    rows="1"
                    class="form-input max-h-40 min-h-[2.5rem] flex-1 resize-none"
                    :maxlength="config.maxLength"
                    placeholder="Type a message…"
                    aria-label="Message"
                    data-testid="composer-input"
                    @input="onInput"
                    @keydown="onKeydown"
                />
                <button
                    type="submit"
                    class="inline-flex h-10 shrink-0 items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-medium text-white hover:bg-brand-500 disabled:opacity-50"
                    :disabled="!ready"
                    data-testid="send-button"
                >
                    {{ editing ? 'Save' : sending ? 'Sending…' : 'Send' }}
                </button>
            </form>
        </template>
    </div>
</template>
