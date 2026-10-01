<script setup>
import AppIcon from '@/Components/ui/AppIcon.vue';
import Avatar from '@/Components/ui/Avatar.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import axios from 'axios';
import { computed, onMounted, ref, watch } from 'vue';

/**
 * Multi-select of trainers. Results come from the server (active Trainers
 * only, capped), so the users table is never sent to the browser. The model is
 * the list of selected trainer objects; `allowAdd` off (archived batch) keeps
 * removal but hides the search.
 */
const props = defineProps({
    excludeIds: { type: Array, default: () => [] },
    allowAdd: { type: Boolean, default: true },
    showSelected: { type: Boolean, default: true },
});
const model = defineModel({ type: Array, default: () => [] });

const term = ref('');
const results = ref([]);
const loading = ref(false);
let timer = null;
let seq = 0;

const load = async () => {
    const current = ++seq;
    loading.value = true;
    try {
        const { data } = await axios.get(route('batches.trainer-search'), { params: term.value.trim() ? { q: term.value.trim() } : {} });
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
onMounted(() => props.allowAdd && load());

const selectedIds = computed(() => model.value.map((t) => t.id));
const options = computed(() => results.value.filter((t) => !props.excludeIds.includes(t.id)));
const isSelected = (trainer) => selectedIds.value.includes(trainer.id);

const toggle = (trainer) => {
    model.value = isSelected(trainer) ? model.value.filter((t) => t.id !== trainer.id) : [...model.value, trainer];
};
const remove = (trainer) => (model.value = model.value.filter((t) => t.id !== trainer.id));
</script>

<template>
    <div class="space-y-2">
        <div v-if="showSelected && model.length" class="flex flex-wrap gap-1.5" data-testid="selected-trainers">
            <span v-for="t in model" :key="t.id" class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-slate-50 py-0.5 pl-1 pr-1.5 text-xs text-slate-800">
                <Avatar :name="t.name" size="xs" />
                <span class="max-w-[160px] truncate font-medium">{{ t.name }}</span>
                <UiBadge v-if="t.active === false" color="slate">Inactive</UiBadge>
                <button type="button" class="rounded-full p-0.5 text-slate-400 hover:bg-slate-200 hover:text-slate-700" :aria-label="`Remove ${t.name}`" @click="remove(t)">
                    <AppIcon name="close" class="h-3 w-3" />
                </button>
            </span>
        </div>

        <template v-if="allowAdd">
            <div class="relative">
                <AppIcon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input v-model="term" type="search" class="form-input pl-9" placeholder="Search trainer by name, email or code…" aria-label="Search trainers" autocomplete="off" />
            </div>
            <ul class="max-h-52 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200" :class="{ 'opacity-60': loading }">
                <li v-for="t in options" :key="t.id">
                    <label class="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-slate-50" :class="{ 'bg-brand-50': isSelected(t) }">
                        <input type="checkbox" class="rounded border-slate-300 text-brand-600" :checked="isSelected(t)" @change="toggle(t)" />
                        <Avatar :name="t.name" size="sm" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-slate-900">{{ t.name }}</span>
                            <span class="block truncate text-2xs text-slate-500">{{ [t.role, t.designation].filter(Boolean).join(' · ') }}</span>
                        </span>
                    </label>
                </li>
                <li v-if="!loading && !options.length" class="px-3 py-4 text-center text-xs text-slate-500">
                    {{ term.trim() ? 'No active trainers match this search.' : 'No other active trainers available.' }}
                </li>
            </ul>
        </template>
    </div>
</template>
