<script setup>
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const platform = computed(() => usePage().props.platform ?? { name: 'Buildify360', url: 'https://buildify360.com' });
</script>

<template>
    <p class="text-[11px] leading-snug text-slate-400 opacity-[0.65]">
        Powered by
        <a :href="platform.url" target="_blank" rel="noopener noreferrer" class="font-medium text-slate-500 transition-colors hover:text-slate-700">{{ platform.name }}</a>
    </p>
</template>
