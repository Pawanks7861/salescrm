<script setup>
import AppIcon from '@/Components/ui/AppIcon.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { ref, watch } from 'vue';

const term = ref('');
const results = ref([]);
const open = ref(false);
const loading = ref(false);
const active = ref(-1);
let timer = null;
let seq = 0;

watch(term, (value) => {
    clearTimeout(timer);
    active.value = -1;
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

const go = (lead) => {
    open.value = false;
    term.value = '';
    router.visit(route('leads.show', lead.id));
};

const onKey = (e) => {
    if (!open.value || !results.value.length) return;
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        active.value = (active.value + 1) % results.value.length;
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        active.value = (active.value - 1 + results.value.length) % results.value.length;
    } else if (e.key === 'Enter' && active.value >= 0) {
        e.preventDefault();
        go(results.value[active.value]);
    } else if (e.key === 'Escape') {
        open.value = false;
    }
};

const close = () => setTimeout(() => (open.value = false), 150);
</script>

<template>
    <div class="relative w-full max-w-lg">
        <AppIcon name="search" class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
        <input
            v-model="term"
            type="search"
            aria-label="Search leads"
            class="form-input rounded-xl border-slate-200/80 bg-slate-50/70 pl-10"
            placeholder="Search leads by number, phone, name, email…"
            autocomplete="off"
            @focus="open = results.length > 0"
            @blur="close"
            @keydown="onKey"
        />
        <div v-if="open && term.trim().length >= 2" class="absolute left-0 right-0 top-full z-50 mt-2 overflow-hidden rounded-xl border border-slate-200 bg-slate-50 py-1 shadow-pop">
            <p v-if="loading && !results.length" class="px-4 py-2.5 text-xs text-slate-500">Searching…</p>
            <p v-else-if="!results.length" class="px-4 py-2.5 text-xs text-slate-500">No matching leads.</p>
            <button
                v-for="(lead, i) in results"
                :key="lead.id"
                type="button"
                class="flex w-full items-center justify-between gap-2 px-4 py-2.5 text-left hover:bg-slate-100"
                :class="{ 'bg-slate-100': i === active }"
                @mousedown.prevent="go(lead)"
            >
                <span class="min-w-0">
                    <span class="block truncate text-sm font-medium text-slate-900">{{ lead.full_name }}</span>
                    <span class="block truncate text-2xs text-slate-500">{{ lead.lead_number }} · {{ lead.phone ?? '—' }}<template v-if="lead.company_name"> · {{ lead.company_name }}</template></span>
                </span>
                <UiBadge v-if="lead.status" :color="lead.status.color">{{ lead.status.name }}</UiBadge>
            </button>
        </div>
    </div>
</template>
