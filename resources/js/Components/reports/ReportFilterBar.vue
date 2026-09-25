<script setup>
import AppIcon from '@/Components/ui/AppIcon.vue';
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

// Filters live in the query string; every change reloads the report from the
// server (which re-validates them against the viewer's scope).
const props = defineProps({
    slug: { type: String, required: true },
    filters: { type: Object, required: true },
    options: { type: Object, required: true },
    available: { type: Array, default: () => [] },
});

const emit = defineEmits(['loading']);

const blank = { preset: 'last_30_days', from: '', to: '', user: '', source: '', campaign: '', status: '', priority: '', city: '', state: '', archived: 'include', compare: true };
const state = reactive({ ...blank, ...pick(props.filters) });
const open = ref(false);

function pick(f) {
    return Object.fromEntries(Object.keys(blank).map((k) => [k, f[k] ?? blank[k]]));
}

watch(
    () => props.filters,
    (f) => Object.assign(state, pick(f)),
);

const has = (key) => props.available.includes(key);

const query = computed(() => {
    const q = {};
    for (const [k, v] of Object.entries(state)) {
        if (k === 'compare') {
            if (!v) q.compare = '0';
            continue;
        }
        if (k === 'archived') {
            if (v === 'exclude') q.archived = 'exclude';
            continue;
        }
        if ((k === 'from' || k === 'to') && state.preset !== 'custom') continue;
        if (v !== '' && v !== null && v !== undefined) q[k] = v;
    }
    return q;
});

const activeCount = computed(() => ['user', 'source', 'campaign', 'status', 'priority', 'city', 'state'].filter((k) => has(k) && state[k] !== '').length);

function apply() {
    if (state.preset === 'custom' && (!state.from || !state.to)) return;
    router.get(route('reports.show', props.slug), query.value, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => emit('loading', true),
        onFinish: () => emit('loading', false),
    });
}

function clear() {
    Object.assign(state, { ...blank, preset: state.preset, from: state.from, to: state.to, compare: state.compare });
    apply();
}

defineExpose({ query });
</script>

<template>
    <div class="sticky top-[4.5rem] z-10 mb-5 rounded-xl border border-slate-200/70 bg-white/95 px-4 py-3 shadow-card backdrop-blur">
        <div class="flex flex-wrap items-center gap-2">
            <select v-if="has('date')" v-model="state.preset" class="form-input w-40" aria-label="Date range" @change="state.preset !== 'custom' && apply()">
                <option v-for="p in options.presets" :key="p.value" :value="p.value">{{ p.label }}</option>
            </select>
            <template v-if="has('date') && state.preset === 'custom'">
                <input v-model="state.from" type="date" class="form-input w-36" aria-label="From date" @change="apply" />
                <span class="text-xs text-slate-400">to</span>
                <input v-model="state.to" type="date" class="form-input w-36" aria-label="To date" @change="apply" />
            </template>
            <span v-if="has('date')" class="hidden text-2xs text-slate-500 sm:inline">{{ filters.from }} → {{ filters.to }} ({{ filters.timezone }})</span>
            <span v-else class="text-2xs text-slate-500">Current snapshot — date range does not apply</span>

            <button type="button" class="chip-btn ml-auto h-10 px-4" :aria-expanded="open" @click="open = !open">
                <AppIcon name="filter" class="h-4 w-4" />
                Filters<span v-if="activeCount" class="rounded-full bg-brand-600 px-1.5 text-2xs text-white">{{ activeCount }}</span>
            </button>
        </div>

        <div v-show="open" class="mt-3 grid grid-cols-1 gap-2 border-t border-slate-100 pt-3 sm:grid-cols-3 lg:grid-cols-5">
            <select v-if="has('user') && options.users?.length" v-model="state.user" class="form-input" aria-label="Salesperson" @change="apply">
                <option value="">All salespeople</option>
                <option v-for="u in options.users" :key="u.value" :value="u.value">{{ u.label }}</option>
            </select>
            <select v-if="has('source')" v-model="state.source" class="form-input" aria-label="Source" @change="apply">
                <option value="">All sources</option>
                <option v-for="s in options.sources" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
            <select v-if="has('campaign') && options.campaigns.length" v-model="state.campaign" class="form-input" aria-label="Campaign" @change="apply">
                <option value="">All campaigns</option>
                <option v-for="c in options.campaigns" :key="c.value" :value="c.value">{{ c.label }}</option>
            </select>
            <select v-if="has('status')" v-model="state.status" class="form-input" aria-label="Current lead status" @change="apply">
                <option value="">Any current status</option>
                <option v-for="s in options.statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
            <select v-if="has('priority')" v-model="state.priority" class="form-input" aria-label="Priority" @change="apply">
                <option value="">Any priority</option>
                <option v-for="p in options.priorities" :key="p.value" :value="p.value">{{ p.label }}</option>
            </select>
            <select v-if="has('city') && options.cities.length" v-model="state.city" class="form-input" aria-label="City" @change="apply">
                <option value="">Any city</option>
                <option v-for="c in options.cities" :key="c" :value="c">{{ c }}</option>
            </select>
            <select v-if="has('state') && options.states.length" v-model="state.state" class="form-input" aria-label="State" @change="apply">
                <option value="">Any state</option>
                <option v-for="s in options.states" :key="s" :value="s">{{ s }}</option>
            </select>
            <select v-if="has('archived')" v-model="state.archived" class="form-input" aria-label="Archived leads" @change="apply">
                <option value="include">Include archived leads (history)</option>
                <option value="exclude">Exclude archived leads</option>
            </select>
            <label v-if="has('compare')" class="flex items-center gap-2 text-xs text-slate-600">
                <input v-model="state.compare" type="checkbox" class="rounded border-slate-300 text-brand-600" @change="apply" />
                Compare with previous period
            </label>
            <button v-if="activeCount" type="button" class="justify-self-start text-xs text-slate-500 hover:text-slate-700" @click="clear">Clear filters</button>
        </div>
    </div>
</template>
