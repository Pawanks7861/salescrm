<script setup>
import FormField from '@/Components/ui/FormField.vue';
import { computed } from 'vue';

const props = defineProps({
    field: { type: Object, required: true },
    modelValue: { default: null },
    error: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

const value = computed({
    get: () => props.modelValue,
    set: (v) => emit('update:modelValue', v),
});

const toggleOption = (option) => {
    const current = Array.isArray(value.value) ? [...value.value] : [];
    const i = current.indexOf(option);
    i === -1 ? current.push(option) : current.splice(i, 1);
    value.value = current;
};
</script>

<template>
    <FormField :label="field.type === 'checkbox' ? '' : field.name" :required="field.is_required" :error="error" :hint="field.help_text ?? ''" :class="{ 'sm:col-span-2': field.type === 'textarea' || field.type === 'multiselect' }">
        <input v-if="field.type === 'text'" v-model="value" class="form-input" maxlength="255" />
        <input v-else-if="field.type === 'number'" v-model="value" type="number" step="any" class="form-input" />
        <input v-else-if="field.type === 'date'" v-model="value" type="date" class="form-input" />
        <input v-else-if="field.type === 'datetime'" v-model="value" type="datetime-local" class="form-input" />
        <textarea v-else-if="field.type === 'textarea'" v-model="value" rows="3" class="form-input" maxlength="5000" />
        <select v-else-if="field.type === 'dropdown'" v-model="value" class="form-input">
            <option :value="null">—</option>
            <option v-for="o in field.options" :key="o" :value="o">{{ o }}</option>
        </select>
        <div v-else-if="field.type === 'multiselect'" class="flex flex-wrap gap-1.5">
            <button
                v-for="o in field.options"
                :key="o"
                type="button"
                class="rounded-full border px-2.5 py-0.5 text-xs"
                :class="(value ?? []).includes(o) ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-slate-300 text-slate-600 hover:bg-slate-50'"
                @click="toggleOption(o)"
            >
                {{ o }}
            </button>
        </div>
        <label v-else-if="field.type === 'checkbox'" class="mt-5 flex items-center gap-2 text-sm text-slate-700">
            <input v-model="value" type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
            {{ field.name }}<span v-if="field.is_required" class="text-red-500"> *</span>
        </label>
    </FormField>
</template>
