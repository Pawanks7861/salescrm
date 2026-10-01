<script setup>
import BatchStatusBadge from '@/Components/batches/BatchStatusBadge.vue';
import AppIcon from '@/Components/ui/AppIcon.vue';
import axios from 'axios';
import { computed, onMounted, ref, watch } from 'vue';

/** Single-select search over batches that can receive leads (archived ones are excluded server-side). */
const props = defineProps({ excludeIds: { type: Array, default: () => [] } });
const model = defineModel({ type: Object, default: null });

const term = ref('');
const results = ref([]);
const loading = ref(false);
let timer = null;
let seq = 0;

const load = async () => {
    const current = ++seq;
    loading.value = true;
    try {
        const { data } = await axios.get(route('batches.lookup'), { params: term.value.trim() ? { q: term.value.trim() } : {} });
        if (current === seq) results.value = data.results ?? [];
    } catch {
        if (current === seq) results.value = [];
    } finally {
        if (current === seq) loading.value = false;
    }
};

watch(term, () => {
    clearTimeout(timer);
    timer = setTimeout(load, 300);
});
onMounted(load);

const options = computed(() => results.value.filter((b) => !props.excludeIds.includes(b.id)));
</script>

<template>
    <div class="space-y-2">
        <div class="relative">
            <AppIcon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <input v-model="term" type="search" class="form-input pl-9" placeholder="Search batch by name or number…" aria-label="Search batches" autocomplete="off" />
        </div>
        <ul class="max-h-56 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200" :class="{ 'opacity-60': loading }">
            <li v-for="b in options" :key="b.id">
                <label class="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-slate-50" :class="{ 'bg-brand-50': model?.id === b.id }">
                    <input type="radio" name="batch-lookup" class="border-slate-300 text-brand-600" :checked="model?.id === b.id" @change="model = b" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-slate-900">{{ b.name }}</span>
                        <span class="block text-2xs text-slate-500"><span class="font-mono">{{ b.batch_number }}</span> · {{ b.leads_count }} lead{{ b.leads_count === 1 ? '' : 's' }}</span>
                    </span>
                    <BatchStatusBadge v-if="b.status !== 'active'" :status="b.status" />
                </label>
            </li>
            <li v-if="!loading && !options.length" class="px-3 py-4 text-center text-xs text-slate-500">No open batches found.</li>
        </ul>
    </div>
</template>
