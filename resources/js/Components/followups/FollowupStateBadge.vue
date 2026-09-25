<script setup>
import UiBadge from '@/Components/ui/UiBadge.vue';
import { computed } from 'vue';

/** Derived display state from the server: pending | due_soon | overdue | completed | cancelled | rescheduled. */
const props = defineProps({ state: { type: String, required: true } });

const map = {
    pending: ['Pending', 'blue'],
    due_soon: ['Due soon', 'amber'],
    overdue: ['Overdue', 'red'],
    completed: ['Completed', 'green'],
    cancelled: ['Cancelled', 'slate'],
    rescheduled: ['Rescheduled', 'purple'],
};

const entry = computed(() => map[props.state] ?? [props.state, 'slate']);
</script>

<template>
    <UiBadge :color="entry[1]" dot>{{ entry[0] }}</UiBadge>
</template>
