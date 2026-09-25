<script setup>
defineProps({
    label: { type: String, default: '' },
    error: { type: String, default: '' },
    hint: { type: String, default: '' },
    required: { type: Boolean, default: false },
    for: { type: String, default: undefined },
});
</script>

<template>
    <div>
        <label v-if="label" class="form-label" :for="$props.for">
            {{ label }}<span v-if="required" class="text-red-500"> *</span>
        </label>
        <slot />
        <p v-if="error" class="form-error">{{ error }}</p>
        <p v-else-if="hint" class="mt-1 text-2xs text-slate-500">{{ hint }}</p>
    </div>
</template>
