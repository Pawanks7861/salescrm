<script setup>
import AppIcon from './AppIcon.vue';

defineProps({
    icon: { type: String, default: 'info' },
    title: { type: String, default: 'Nothing here yet' },
    description: { type: String, default: '' },
});
</script>

<template>
    <div class="flex flex-col items-center justify-center px-6 py-10 text-center">
        <div class="mb-3 flex h-11 w-11 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 text-slate-400">
            <AppIcon :name="icon" class="h-5 w-5" />
        </div>
        <p class="text-sm font-medium text-slate-800">{{ title }}</p>
        <p v-if="description" class="mt-1 max-w-sm text-xs text-slate-500">{{ description }}</p>
        <div v-if="$slots.default" class="mt-4"><slot /></div>
    </div>
</template>
