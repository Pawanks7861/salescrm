<script setup>
import MeetingCancelModal from '@/Components/meetings/MeetingCancelModal.vue';
import MeetingRescheduleModal from '@/Components/meetings/MeetingRescheduleModal.vue';
import { router } from '@inertiajs/vue3';
import { watch } from 'vue';

/**
 * Hosts list-page meeting actions. v-model:action = { type, meeting } | null.
 * Reschedule and cancel open inline; complete / no-show open on the meeting
 * page, which has the attendance, follow-up and lead-status options.
 */
const props = defineProps({
    action: { type: Object, default: null },
    canOverride: { type: Boolean, default: false },
});
const emit = defineEmits(['update:action']);
const close = () => emit('update:action', null);

watch(
    () => props.action,
    (a) => {
        if (a && ['complete', 'no_show'].includes(a.type)) {
            close();
            router.visit(route('meetings.show', { meeting: a.meeting.id, action: a.type }));
        }
    },
);
</script>

<template>
    <MeetingRescheduleModal :show="action?.type === 'reschedule'" :meeting="action?.meeting" :can-override="canOverride" stay @close="close" />
    <MeetingCancelModal :show="action?.type === 'cancel'" :meeting="action?.meeting" @close="close" />
</template>
