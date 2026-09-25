<script setup>
import FollowupCancelModal from '@/Components/followups/FollowupCancelModal.vue';
import FollowupCompleteModal from '@/Components/followups/FollowupCompleteModal.vue';
import FollowupRescheduleModal from '@/Components/followups/FollowupRescheduleModal.vue';

/**
 * Hosts the complete / reschedule / cancel modals for a page.
 * v-model:action = { type: 'complete' | 'reschedule' | 'cancel', followup } | null
 */
defineProps({
    action: { type: Object, default: null },
    options: { type: Object, required: true },
});
const emit = defineEmits(['update:action']);
const close = () => emit('update:action', null);
</script>

<template>
    <FollowupCompleteModal :show="action?.type === 'complete'" :followup="action?.followup" :options="options" @close="close" />
    <FollowupRescheduleModal :show="action?.type === 'reschedule'" :followup="action?.followup" @close="close" />
    <FollowupCancelModal :show="action?.type === 'cancel'" :followup="action?.followup" @close="close" />
</template>
