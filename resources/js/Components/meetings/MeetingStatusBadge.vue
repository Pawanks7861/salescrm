<script setup>
import UiBadge from '@/Components/ui/UiBadge.vue';
import { computed } from 'vue';

/** Server-derived state: upcoming | live | past_due | completed | cancelled | no_show | rescheduled (+ raw statuses). */
const props = defineProps({ state: { type: String, required: true }, status: { type: String, default: null } });

const map = {
    upcoming: ['Scheduled', 'blue'],
    live: ['In progress', 'green'],
    past_due: ['Awaiting outcome', 'amber'],
    scheduled: ['Scheduled', 'blue'],
    confirmed: ['Confirmed', 'indigo'],
    in_progress: ['In progress', 'green'],
    completed: ['Completed', 'green'],
    cancelled: ['Cancelled', 'slate'],
    no_show: ['No-show', 'red'],
    rescheduled: ['Rescheduled', 'purple'],
};

const entry = computed(() => {
    if (props.state === 'upcoming' && props.status === 'confirmed') return map.confirmed;
    return map[props.state] ?? [props.state, 'slate'];
});
</script>

<template>
    <UiBadge :color="entry[1]" dot>{{ entry[0] }}</UiBadge>
</template>
