<script setup>
import AppIcon from '@/Components/ui/AppIcon.vue';
import { computed } from 'vue';

/**
 * Shows server-side calendar conflicts (errors keyed conflicts.N). The server
 * only includes meeting details the viewer may see. Users holding
 * meeting.override_conflict can explicitly confirm an override.
 */
const props = defineProps({
    errors: { type: Object, required: true },
    canOverride: { type: Boolean, default: false },
    override: { type: Boolean, default: false },
});
const emit = defineEmits(['update:override']);

const messages = computed(() =>
    Object.entries(props.errors)
        .filter(([key]) => key.startsWith('conflicts.'))
        .sort(([a], [b]) => a.localeCompare(b, undefined, { numeric: true }))
        .map(([, message]) => message),
);
</script>

<template>
    <div v-if="messages.length" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
        <p class="flex items-center gap-1 font-semibold"><AppIcon name="warning" class="h-4 w-4" /> Scheduling conflict</p>
        <ul class="mt-1 list-disc space-y-0.5 pl-5">
            <li v-for="(m, i) in messages" :key="i">{{ m }}</li>
        </ul>
        <label v-if="canOverride" class="mt-2 flex items-center gap-2 font-medium">
            <input type="checkbox" class="rounded border-amber-400 text-amber-600" :checked="override" @change="emit('update:override', $event.target.checked)" />
            I understand — schedule anyway (override is audited)
        </label>
        <p v-else class="mt-1">Choose another time or remove the unavailable participant.</p>
    </div>
</template>
