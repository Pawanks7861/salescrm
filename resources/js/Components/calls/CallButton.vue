<script setup>
import AppIcon from '@/Components/ui/AppIcon.vue';
import { useTelephony } from '@/Composables/useTelephony';
import { computed, ref } from 'vue';

/**
 * Lead "Call" button. Sends only the lead id + contact FIELD; the server
 * resolves and validates the number.
 */
const props = defineProps({
    leadId: { type: Number, required: true },
    calling: { type: Object, default: null }, // { enabled, reason, modes, default_mode, contacts: [{ field, label, number }] }
    size: { type: String, default: 'md' },
});

const phone = useTelephony();
const open = ref(false);
const starting = ref(false);

const contacts = computed(() => props.calling?.contacts ?? []);
const modes = computed(() => props.calling?.modes ?? []);
const disabledReason = computed(() => {
    if (!props.calling?.enabled) return props.calling?.reason || 'Calling is not available.';
    if (phone.state.otherTab) return phone.messages.OTHER_TAB;
    if (phone.busy.value) return 'A call is already in progress.';
    return null;
});
const hasChoices = computed(() => contacts.value.length > 1 || modes.value.length > 1);

const call = async (field = contacts.value[0]?.field, mode = props.calling?.default_mode) => {
    open.value = false;
    if (disabledReason.value || starting.value) return;
    starting.value = true;
    try {
        await phone.startCall({ leadId: props.leadId, contactField: field, mode });
    } finally {
        starting.value = false;
    }
};

const modeLabel = (m) => (m === 'webrtc' ? 'Browser' : 'Phone');
</script>

<template>
    <div v-if="calling" class="relative inline-flex">
        <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-l-md bg-green-600 font-semibold text-white shadow-sm hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-50"
            :class="[size === 'sm' ? 'px-2.5 py-1 text-xs' : 'px-3 py-1.5 text-sm', hasChoices ? '' : 'rounded-r-md']"
            :disabled="!!disabledReason || starting"
            :title="disabledReason || `Call ${contacts[0]?.label.toLowerCase() ?? ''}`"
            @click="call()"
        >
            <AppIcon name="phone" class="h-4 w-4" :class="starting ? 'animate-pulse' : ''" />
            {{ starting ? 'Calling…' : 'Call' }}
        </button>
        <button
            v-if="hasChoices"
            type="button"
            class="rounded-r-md border-l border-green-700 bg-green-600 px-1.5 text-white hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="!!disabledReason || starting"
            aria-label="Choose number"
            @click="open = !open"
        >
            <AppIcon name="chevron" class="h-3.5 w-3.5" />
        </button>
        <div v-if="open" class="absolute right-0 top-full z-30 mt-1 w-64 rounded-md border border-slate-200 bg-white py-1 shadow-lg">
            <template v-for="c in contacts" :key="c.field">
                <button v-for="m in modes" :key="`${c.field}-${m}`" type="button" class="flex w-full items-center justify-between gap-2 px-3 py-1.5 text-left text-xs hover:bg-slate-50" @click="call(c.field, m)">
                    <span>
                        <span class="block font-medium text-slate-800">{{ c.label }}</span>
                        <span class="block text-slate-500">{{ c.number }}</span>
                    </span>
                    <span class="rounded bg-slate-100 px-1.5 py-0.5 text-2xs text-slate-600">{{ modeLabel(m) }}</span>
                </button>
            </template>
        </div>
        <div v-if="open" class="fixed inset-0 z-20" @click="open = false" />
    </div>
</template>
