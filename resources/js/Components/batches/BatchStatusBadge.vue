<script setup>
import UiBadge from '@/Components/ui/UiBadge.vue';
import { batchStatusColor, batchStatusLabel } from '@/utils/batches';
import { computed } from 'vue';

const props = defineProps({ status: { type: [String, Object], required: true } });

const value = computed(() => (typeof props.status === 'object' ? props.status.value : props.status));
</script>

<template>
    <UiBadge :color="batchStatusColor[value] ?? 'slate'" dot>{{ batchStatusLabel(status) }}</UiBadge>
</template>
