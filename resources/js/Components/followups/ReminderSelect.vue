<script setup>
import { computed } from 'vue';

const props = defineProps({
    modelValue: { type: [Number, null], default: null },
    options: { type: Array, required: true },
});
const emit = defineEmits(['update:modelValue']);

const list = computed(() => {
    const known = props.options.some((o) => o.value === props.modelValue);
    return known ? props.options : [...props.options, { value: props.modelValue, label: `${props.modelValue} minutes before` }];
});

const model = computed({
    get: () => (props.modelValue === null ? '' : String(props.modelValue)),
    set: (v) => emit('update:modelValue', v === '' ? null : Number(v)),
});
</script>

<template>
    <select v-model="model" class="form-input">
        <option v-for="o in list" :key="String(o.value)" :value="o.value === null ? '' : String(o.value)">{{ o.label }}</option>
    </select>
</template>
