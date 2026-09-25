<script setup>
import AppIcon from './AppIcon.vue';

const model = defineModel({ type: String, default: '' });
defineProps({ placeholder: { type: String, default: 'Search…' } });
</script>

<template>
    <div class="relative">
        <AppIcon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
        <input v-model="model" type="search" class="form-input pl-9" :placeholder="placeholder" :aria-label="placeholder" />
    </div>
</template>
