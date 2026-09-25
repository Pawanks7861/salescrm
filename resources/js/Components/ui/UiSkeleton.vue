<script setup>
defineProps({ lines: { type: Number, default: 3 } });
</script>

<template>
    <div class="animate-pulse space-y-3 p-5" aria-busy="true" aria-label="Loading">
        <div v-for="i in lines" :key="i" class="h-3 rounded-full bg-slate-100" :style="{ width: `${100 - ((i * 13) % 40)}%` }" />
    </div>
</template>
