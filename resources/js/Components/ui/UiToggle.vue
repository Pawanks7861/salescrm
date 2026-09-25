<script setup>
const model = defineModel({ type: Boolean, default: false });
defineProps({
    label: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
});
</script>

<template>
    <label class="inline-flex cursor-pointer items-center gap-2" :class="{ 'cursor-not-allowed opacity-60': disabled }">
        <button
            type="button"
            role="switch"
            :aria-checked="model"
            :disabled="disabled"
            class="relative inline-flex h-5 w-9 shrink-0 rounded-full border-2 border-transparent transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
            :class="model ? 'bg-brand-600' : 'bg-slate-300'"
            @click="model = !model"
        >
            <span class="inline-block h-4 w-4 transform rounded-full bg-ink shadow transition" :class="model ? 'translate-x-4' : 'translate-x-0'" />
        </button>
        <span v-if="label" class="text-sm text-slate-700">{{ label }}</span>
    </label>
</template>
