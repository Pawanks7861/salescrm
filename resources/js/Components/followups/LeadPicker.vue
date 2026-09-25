<script setup>
import AppIcon from '@/Components/ui/AppIcon.vue';
import axios from 'axios';
import { ref, watch } from 'vue';

/** Visibility-scoped lead autocomplete (uses the existing /search/leads endpoint). */
const props = defineProps({ modelValue: { type: Object, default: null } });
const emit = defineEmits(['update:modelValue']);

const term = ref('');
const results = ref([]);
const open = ref(false);
const loading = ref(false);
let timer = null;
let seq = 0;

watch(term, (value) => {
    clearTimeout(timer);
    if (value.trim().length < 2) {
        results.value = [];
        return;
    }
    timer = setTimeout(async () => {
        const current = ++seq;
        loading.value = true;
        try {
            const { data } = await axios.get(route('search.leads'), { params: { q: value.trim() } });
            if (current === seq) {
                results.value = data.results;
                open.value = true;
            }
        } catch {
            results.value = [];
        } finally {
            if (current === seq) loading.value = false;
        }
    }, 300);
});

const close = () => setTimeout(() => (open.value = false), 150);

const pick = (lead) => {
    emit('update:modelValue', lead);
    term.value = '';
    open.value = false;
};
</script>

<template>
    <div v-if="modelValue" class="flex items-center justify-between rounded-md border border-slate-300 bg-slate-50 px-2.5 py-1.5 text-sm">
        <span class="min-w-0 truncate">
            <span class="font-medium text-slate-900">{{ modelValue.full_name }}</span>
            <span class="ml-1 text-2xs text-slate-500">{{ modelValue.lead_number }}</span>
        </span>
        <button type="button" class="text-slate-400 hover:text-slate-600" title="Change lead" @click="emit('update:modelValue', null)">
            <AppIcon name="close" class="h-4 w-4" />
        </button>
    </div>
    <div v-else class="relative">
        <input v-model="term" type="search" class="form-input" placeholder="Search lead by name, number or phone…" autocomplete="off" @focus="open = results.length > 0" @blur="close" />
        <div v-if="open && term.trim().length >= 2" class="absolute left-0 right-0 top-full z-50 mt-1 max-h-60 overflow-y-auto rounded-md border border-slate-200 bg-white shadow-lg">
            <p v-if="loading && !results.length" class="px-3 py-2 text-xs text-slate-500">Searching…</p>
            <p v-else-if="!results.length" class="px-3 py-2 text-xs text-slate-500">No matching leads.</p>
            <button v-for="lead in results" :key="lead.id" type="button" class="block w-full px-3 py-2 text-left hover:bg-slate-50" @mousedown.prevent="pick(lead)">
                <span class="block truncate text-sm font-medium text-slate-900">{{ lead.full_name }}</span>
                <span class="block truncate text-2xs text-slate-500">{{ lead.lead_number }} · {{ lead.phone ?? '—' }}</span>
            </button>
        </div>
    </div>
</template>
