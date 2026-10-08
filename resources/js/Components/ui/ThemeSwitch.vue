<script setup>
import { applyTheme, currentTheme } from '@/utils/theme';
import { onMounted, ref } from 'vue';

const theme = ref('dark');

onMounted(() => {
    theme.value = currentTheme();
});

const choose = (next) => {
    theme.value = applyTheme(next);
};
</script>

<template>
    <div class="inline-flex shrink-0 rounded-lg border border-slate-200 bg-slate-50 p-0.5" role="group" aria-label="Theme">
        <button
            type="button"
            class="h-7 rounded-md px-2 text-2xs font-semibold transition"
            :class="theme === 'dark' ? 'bg-white text-slate-900 shadow-card' : 'text-slate-500 hover:text-slate-800'"
            :aria-pressed="theme === 'dark'"
            @click="choose('dark')"
        >
            Dark
        </button>
        <button
            type="button"
            class="h-7 rounded-md px-2 text-2xs font-semibold transition"
            :class="theme === 'light' ? 'bg-white text-slate-900 shadow-card' : 'text-slate-500 hover:text-slate-800'"
            :aria-pressed="theme === 'light'"
            @click="choose('light')"
        >
            White
        </button>
    </div>
</template>
