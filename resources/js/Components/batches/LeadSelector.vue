<script setup>
import UiBadge from '@/Components/ui/UiBadge.vue';
import AppIcon from '@/Components/ui/AppIcon.vue';
import axios from 'axios';
import { computed, onMounted, reactive, ref, watch } from 'vue';

/**
 * Multi-select lead picker backed by the paginated, visibility-scoped
 * batches.lead-search endpoint (20 per page). Selections survive searching
 * and paging; leads already in `batchId` cannot be picked again.
 */
const props = defineProps({
    batchId: { type: Number, default: null },
    statuses: { type: Array, default: () => [] },
    sources: { type: Array, default: () => [] },
});
const selected = defineModel({ type: Array, default: () => [] });

const query = reactive({ q: '', status: '', source: '' });
const page = ref(1);
const result = ref({ data: [], current_page: 1, last_page: 1, total: 0 });
const loading = ref(false);
const failed = ref(false);
const picked = reactive(new Map());
let timer = null;
let seq = 0;

const load = async () => {
    const current = ++seq;
    loading.value = true;
    failed.value = false;
    try {
        const params = { page: page.value, ...(props.batchId ? { batch: props.batchId } : {}) };
        Object.entries(query).forEach(([k, v]) => v !== '' && (params[k] = v));
        const { data } = await axios.get(route('batches.lead-search'), { params });
        if (current === seq) result.value = data;
    } catch {
        if (current === seq) failed.value = true;
    } finally {
        if (current === seq) loading.value = false;
    }
};

watch(query, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        page.value = 1;
        load();
    }, 300);
});
watch(page, load);
onMounted(load);

watch(selected, (ids) => {
    if (!ids.length) picked.clear();
});

const isPicked = (lead) => picked.has(lead.id);
const toggle = (lead) => {
    if (lead.in_batch) return;
    if (picked.has(lead.id)) picked.delete(lead.id);
    else picked.set(lead.id, lead);
    selected.value = [...picked.keys()];
};

const selectable = computed(() => result.value.data.filter((l) => !l.in_batch));
const allOnPage = computed(() => selectable.value.length > 0 && selectable.value.every((l) => picked.has(l.id)));
const togglePage = () => {
    const select = !allOnPage.value;
    selectable.value.forEach((l) => (select ? picked.set(l.id, l) : picked.delete(l.id)));
    selected.value = [...picked.keys()];
};
const clear = () => {
    picked.clear();
    selected.value = [];
};
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap gap-2">
            <div class="relative min-w-[200px] flex-1">
                <AppIcon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input v-model="query.q" type="search" class="form-input pl-9" placeholder="Lead no., name, company, phone, email…" aria-label="Search leads" autocomplete="off" />
            </div>
            <select v-model="query.status" class="form-input w-36" aria-label="Lead status">
                <option value="">All statuses</option>
                <option v-for="s in statuses" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
            <select v-model="query.source" class="form-input w-36" aria-label="Lead source">
                <option value="">All sources</option>
                <option v-for="s in sources" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
        </div>

        <div class="overflow-hidden rounded-lg border border-slate-200">
            <div class="flex items-center justify-between gap-2 border-b border-slate-100 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                <label class="flex items-center gap-2">
                    <input type="checkbox" class="rounded border-slate-300 text-brand-600" :checked="allOnPage" :disabled="!selectable.length" @change="togglePage" />
                    Select page
                </label>
                <span>{{ result.total.toLocaleString('en-IN') }} lead{{ result.total === 1 ? '' : 's' }} found</span>
            </div>
            <ul class="max-h-80 divide-y divide-slate-100 overflow-y-auto" :class="{ 'opacity-60': loading }">
                <li v-for="lead in result.data" :key="lead.id">
                    <label class="flex cursor-pointer items-start gap-3 px-3 py-2.5 hover:bg-slate-50" :class="{ 'cursor-not-allowed bg-slate-50/60': lead.in_batch }">
                        <input type="checkbox" class="mt-1 rounded border-slate-300 text-brand-600" :checked="lead.in_batch || isPicked(lead)" :disabled="lead.in_batch" @change="toggle(lead)" />
                        <span class="min-w-0 flex-1">
                            <span class="flex flex-wrap items-center gap-x-2 text-2xs text-slate-500">
                                <span class="font-mono font-semibold text-slate-700">{{ lead.lead_number }}</span>
                                <UiBadge v-if="lead.status" :color="lead.status.color" dot>{{ lead.status.name }}</UiBadge>
                                <span v-if="lead.source">Source: {{ lead.source }}</span>
                                <UiBadge v-if="lead.in_batch" color="slate">Already in batch</UiBadge>
                            </span>
                            <span class="block truncate text-sm font-medium text-slate-900">{{ lead.full_name }}</span>
                            <span class="block truncate text-2xs text-slate-500">
                                {{ [lead.company_name, lead.phone, lead.owner ? `Owner: ${lead.owner}` : null].filter(Boolean).join(' · ') || '—' }}
                            </span>
                        </span>
                    </label>
                </li>
                <li v-if="!loading && !result.data.length" class="px-3 py-6 text-center text-xs text-slate-500">{{ failed ? 'Could not load leads. Try again.' : 'No leads match this search.' }}</li>
            </ul>
            <div v-if="result.last_page > 1" class="flex items-center justify-between border-t border-slate-100 px-3 py-2 text-xs text-slate-600">
                <button type="button" class="rounded px-2 py-1 hover:bg-slate-100 disabled:opacity-40" :disabled="page <= 1 || loading" @click="page--">‹ Previous</button>
                <span>Page {{ result.current_page }} of {{ result.last_page }}</span>
                <button type="button" class="rounded px-2 py-1 hover:bg-slate-100 disabled:opacity-40" :disabled="page >= result.last_page || loading" @click="page++">Next ›</button>
            </div>
        </div>

        <div class="flex items-center justify-between text-xs">
            <span class="font-medium text-slate-700">Selected: {{ selected.length }}</span>
            <button v-if="selected.length" type="button" class="text-slate-500 hover:text-slate-800" @click="clear">Clear selection</button>
        </div>
    </div>
</template>
