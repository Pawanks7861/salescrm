<script setup>
import UiBadge from '@/Components/ui/UiBadge.vue';

defineProps({ priority: { type: String, default: null } });

const colors = { low: 'slate', medium: 'blue', high: 'amber', urgent: 'red' };
</script>

<template>
    <UiBadge v-if="priority" :color="colors[priority] ?? 'slate'">{{ priority.charAt(0).toUpperCase() + priority.slice(1) }}</UiBadge>
</template>
