<script setup>
import { useToast } from '@/Composables/useToast';
import AppIcon from './AppIcon.vue';

const { toasts, dismiss } = useToast();

const styles = {
    success: { icon: 'check', color: 'text-emerald-600', accent: 'bg-emerald-500', label: 'Success' },
    error: { icon: 'warning', color: 'text-red-600', accent: 'bg-red-500', label: 'Error' },
    info: { icon: 'info', color: 'text-brand-600', accent: 'bg-brand-500', label: 'Notice' },
};
</script>

<template>
    <div class="pointer-events-none fixed right-4 top-4 z-[60] flex w-[22rem] max-w-[calc(100vw-2rem)] flex-col gap-2" aria-live="polite">
        <TransitionGroup
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="translate-x-4 opacity-0"
            leave-active-class="transition duration-150 ease-in"
            leave-to-class="opacity-0"
        >
            <div
                v-for="toast in toasts"
                :key="toast.id"
                class="pointer-events-auto relative flex items-start gap-3 overflow-hidden rounded-xl border border-slate-200 bg-slate-50 py-3 pl-4 pr-3 shadow-pop"
                role="status"
            >
                <span class="absolute inset-y-0 left-0 w-1" :class="styles[toast.type].accent" aria-hidden="true" />
                <AppIcon :name="styles[toast.type].icon" class="mt-0.5 h-5 w-5 shrink-0" :class="styles[toast.type].color" />
                <p class="flex-1 text-sm text-slate-800"><span class="sr-only">{{ styles[toast.type].label }}: </span>{{ toast.message }}</p>
                <button class="icon-btn -my-1 h-7 w-7" aria-label="Dismiss" @click="dismiss(toast.id)">
                    <AppIcon name="close" class="h-4 w-4" />
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
