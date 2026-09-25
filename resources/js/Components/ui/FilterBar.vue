<script setup>
import { ref } from 'vue';
import AppIcon from './AppIcon.vue';

defineProps({
    activeCount: { type: Number, default: 0 },
    bare: { type: Boolean, default: false }, // render without its own container (inside a panel)
});
defineEmits(['clear']);

const open = ref(false);
</script>

<template>
    <div :class="bare ? 'border-b border-slate-100 p-4' : 'mb-4 rounded-xl border border-slate-200/70 bg-white p-3 shadow-card sm:p-4'">
        <div class="flex items-center gap-2 md:hidden">
            <button type="button" class="inline-flex h-10 flex-1 items-center justify-center gap-2 rounded-lg border border-slate-200 bg-slate-50 text-sm font-medium text-slate-700" :aria-expanded="open" @click="open = !open">
                <AppIcon name="filter" class="h-4 w-4" />
                Filters<span v-if="activeCount" class="rounded-full bg-brand-100 px-1.5 text-2xs text-brand-600">{{ activeCount }}</span>
                <AppIcon name="chevron" class="h-4 w-4 transition" :class="open ? 'rotate-180' : ''" />
            </button>
            <button v-if="activeCount" type="button" class="h-10 rounded-lg px-3 text-xs text-slate-500 hover:text-slate-800" @click="$emit('clear')">Clear</button>
        </div>
        <div class="space-y-3 md:mt-0 md:block" :class="open ? 'mt-3 block' : 'hidden'">
            <slot />
        </div>
    </div>
</template>
