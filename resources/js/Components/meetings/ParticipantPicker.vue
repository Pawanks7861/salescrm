<script setup>
import AppIcon from '@/Components/ui/AppIcon.vue';
import axios from 'axios';
import { ref, watch } from 'vue';

/**
 * Internal-user autocomplete. The server returns only active users the
 * current user may invite (and who can see the lead, when given).
 * v-model is an array of { id, name }.
 */
const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    leadId: { type: Number, default: null },
    exclude: { type: Array, default: () => [] },
});
const emit = defineEmits(['update:modelValue']);

const term = ref('');
const results = ref([]);
const open = ref(false);
const loading = ref(false);
let timer = null;
let seq = 0;

const load = async () => {
    const current = ++seq;
    loading.value = true;
    try {
        const { data } = await axios.get(route('meetings.participants.search'), { params: { q: term.value.trim(), lead_id: props.leadId || undefined } });
        if (current === seq) {
            const taken = new Set([...props.modelValue.map((u) => u.id), ...props.exclude]);
            results.value = data.data.filter((u) => !taken.has(u.id));
            open.value = true;
        }
    } catch {
        results.value = [];
    } finally {
        if (current === seq) loading.value = false;
    }
};

watch(term, () => {
    clearTimeout(timer);
    timer = setTimeout(load, 250);
});

const close = () => setTimeout(() => (open.value = false), 150);
const pick = (user) => {
    emit('update:modelValue', [...props.modelValue, { id: user.id, name: user.name }]);
    term.value = '';
    open.value = false;
};
const remove = (id) => emit('update:modelValue', props.modelValue.filter((u) => u.id !== id));
</script>

<template>
    <div>
        <div v-if="modelValue.length" class="mb-1.5 flex flex-wrap gap-1">
            <span v-for="u in modelValue" :key="u.id" class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700">
                {{ u.name }}
                <button type="button" class="text-slate-400 hover:text-slate-600" :title="`Remove ${u.name}`" @click="remove(u.id)"><AppIcon name="close" class="h-3 w-3" /></button>
            </span>
        </div>
        <div class="relative">
            <input v-model="term" type="search" class="form-input" placeholder="Add internal user…" autocomplete="off" @focus="load" @blur="close" />
            <div v-if="open" class="absolute left-0 right-0 top-full z-50 mt-1 max-h-56 overflow-y-auto rounded-md border border-slate-200 bg-white shadow-lg">
                <p v-if="loading && !results.length" class="px-3 py-2 text-xs text-slate-500">Searching…</p>
                <p v-else-if="!results.length" class="px-3 py-2 text-xs text-slate-500">No available users.</p>
                <button v-for="u in results" :key="u.id" type="button" class="block w-full px-3 py-1.5 text-left hover:bg-slate-50" @mousedown.prevent="pick(u)">
                    <span class="block truncate text-sm text-slate-900">{{ u.name }}</span>
                    <span class="block truncate text-2xs text-slate-500">{{ u.email }}</span>
                </button>
            </div>
        </div>
    </div>
</template>
