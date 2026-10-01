<script setup>
import AppIcon from '@/Components/ui/AppIcon.vue';
import { DELETED_TEXT, formatClock, readStatus, replySnippet } from '@/utils/chat';
import { formatBytes } from '@/utils/format';
import { computed } from 'vue';

/*
 * One message. Text is rendered with {{ }} (escaped) and whitespace-pre-wrap:
 * no HTML from users is ever interpreted.
 */
const props = defineProps({
    message: { type: Object, required: true },
    otherLastReadId: { type: Number, default: 0 },
    otherName: { type: String, default: '' },
    tz: { type: String, default: undefined },
    canAct: { type: Boolean, default: true },
});
const emit = defineEmits(['reply', 'edit', 'delete']);

const status = computed(() => readStatus(props.message, props.otherLastReadId));
const replyAuthor = computed(() => (props.message.reply_to?.sender_id === props.message.sender_id ? (props.message.mine ? 'You' : props.otherName) : props.message.mine ? props.otherName : 'You'));
const images = computed(() => props.message.attachments.filter((a) => a.is_image));
const files = computed(() => props.message.attachments.filter((a) => !a.is_image));
</script>

<template>
    <div class="group flex" :class="message.mine ? 'justify-end' : 'justify-start'" :data-testid="`message-${message.id}`">
        <div class="flex max-w-[85%] items-end gap-1 sm:max-w-[70%]" :class="message.mine ? 'flex-row-reverse' : ''">
            <div
                class="min-w-0 rounded-2xl px-3 py-2 text-sm shadow-sm"
                :class="[
                    message.mine ? 'rounded-br-md bg-brand-600 text-white' : 'rounded-bl-md border border-slate-200 bg-white text-slate-800',
                    message.deleted ? '!bg-slate-100 !text-slate-500 italic !shadow-none' : '',
                ]"
            >
                <template v-if="message.deleted">
                    <span class="inline-flex items-center gap-1.5"><AppIcon name="ban" class="h-3.5 w-3.5" />{{ DELETED_TEXT }}</span>
                </template>
                <template v-else>
                    <div
                        v-if="message.reply_to"
                        class="mb-1.5 rounded-lg border-l-2 px-2 py-1 text-xs"
                        :class="message.mine ? 'border-white/60 bg-white/15 text-white/90' : 'border-brand-400 bg-slate-50 text-slate-600'"
                        data-testid="reply-quote"
                    >
                        <span class="block font-semibold">{{ replyAuthor }}</span>
                        <span class="line-clamp-2">{{ replySnippet(message.reply_to) }}</span>
                    </div>

                    <div v-if="images.length" class="mb-1 grid w-60 max-w-full gap-1" :class="images.length > 1 ? 'grid-cols-2' : ''">
                        <a v-for="img in images" :key="img.id" :href="img.url" target="_blank" rel="noopener" class="block overflow-hidden rounded-lg" :title="img.name">
                            <img :src="img.preview_url" :alt="img.name" loading="lazy" class="w-full object-cover" :class="images.length > 1 ? 'h-28' : 'h-40'" />
                        </a>
                    </div>

                    <a
                        v-for="file in files"
                        :key="file.id"
                        :href="file.url"
                        class="mb-1 flex items-center gap-2 rounded-lg px-2 py-1.5 text-xs"
                        :class="message.mine ? 'bg-white/15 hover:bg-white/25' : 'bg-slate-50 hover:bg-slate-100'"
                        :data-testid="`attachment-${file.id}`"
                    >
                        <AppIcon name="document" class="h-5 w-5 shrink-0" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium">{{ file.name }}</span>
                            <span class="block opacity-75">{{ formatBytes(file.size) }}</span>
                        </span>
                        <AppIcon name="download" class="h-4 w-4 shrink-0" />
                    </a>

                    <p v-if="message.body" class="whitespace-pre-wrap break-words" data-testid="message-body">{{ message.body }}</p>
                </template>

                <div class="mt-0.5 flex items-center justify-end gap-1 text-[10px]" :class="message.mine && !message.deleted ? 'text-white/70' : 'text-slate-400'">
                    <span v-if="message.edited" data-testid="edited-label">edited ·</span>
                    <span>{{ formatClock(message.created_at, tz) }}</span>
                    <span
                        v-if="status"
                        class="font-semibold tracking-tighter"
                        :class="status === 'read' ? 'text-sky-200' : ''"
                        :aria-label="status === 'read' ? 'Read' : 'Sent'"
                        :title="status === 'read' ? 'Read' : 'Sent'"
                        :data-testid="`status-${status}`"
                    >{{ status === 'read' ? '✓✓' : '✓' }}</span>
                </div>
            </div>

            <div v-if="!message.deleted && canAct" class="flex shrink-0 gap-0.5 opacity-0 transition group-hover:opacity-100 focus-within:opacity-100">
                <button type="button" class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700" title="Reply" aria-label="Reply" @click="emit('reply', message)">
                    <AppIcon name="restore" class="h-3.5 w-3.5" />
                </button>
                <template v-if="message.mine">
                    <button v-if="message.body" type="button" class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700" title="Edit" aria-label="Edit" @click="emit('edit', message)">
                        <AppIcon name="edit" class="h-3.5 w-3.5" />
                    </button>
                    <button type="button" class="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600" title="Delete" aria-label="Delete" @click="emit('delete', message)">
                        <AppIcon name="trash" class="h-3.5 w-3.5" />
                    </button>
                </template>
            </div>
        </div>
    </div>
</template>
