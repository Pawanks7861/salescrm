<script setup>
import CallsTable from '@/Components/calls/CallsTable.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import SearchInput from '@/Components/ui/SearchInput.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import UiPagination from '@/Components/ui/UiPagination.vue';
import { useFilters } from '@/Composables/useFilters';
import { useTelephony } from '@/Composables/useTelephony';
import AppLayout from '@/Layouts/AppLayout.vue';
import { computed, ref } from 'vue';

const props = defineProps({
    calls: Object,
    filters: Object,
    counts: Object,
    options: Object,
    can: Object,
    scopeLabel: String,
});

const keys = ['today', 'search', 'from', 'to', 'direction', 'status', 'agent', 'lead', 'disposition', 'has_recording', 'missing_disposition', 'per_page'];
const initial = Object.fromEntries(keys.map((k) => [k, props.filters[k] ?? (k === 'status' ? [] : '')]));
const { filters, reset } = useFilters(initial, route('calls.index'));

const activeCount = computed(() => keys.filter((k) => k !== 'per_page' && (Array.isArray(filters[k]) ? filters[k].length : filters[k] !== '' && filters[k] !== null)).length);
const clearFilters = () => {
    reset();
    filters.status = [];
};
const toggleStatus = (value) => {
    filters.status = filters.status.includes(value) ? filters.status.filter((s) => s !== value) : [...filters.status, value];
};
const toggleFlag = (key) => (filters[key] = filters[key] ? '' : 1);

const phone = useTelephony();
const manualNumber = ref('');
const dialing = ref(false);
const dial = async () => {
    if (!manualNumber.value.trim()) return;
    dialing.value = true;
    try {
        if (await phone.startCall({ number: manualNumber.value.trim() })) manualNumber.value = '';
    } finally {
        dialing.value = false;
    }
};
</script>

<template>
    <AppLayout title="Calls">
        <PageHeader title="Calls" :subtitle="`${scopeLabel} calls · ${counts.today} today · ${counts.missed_today} missed · ${counts.awaiting_disposition} awaiting outcome`">
            <template v-if="can.manualDial" #actions>
                <form class="flex items-center gap-1" @submit.prevent="dial">
                    <input v-model="manualNumber" type="tel" class="form-input w-44" maxlength="30" placeholder="Dial a number…" aria-label="Number to dial" />
                    <UiButton type="submit" icon="phone" :loading="dialing" :disabled="phone.busy.value">Dial</UiButton>
                </form>
            </template>
        </PageHeader>

        <div class="mb-5 grid grid-cols-1 gap-4 min-[420px]:grid-cols-2 xl:grid-cols-4">
            <button type="button" class="panel relative overflow-hidden p-5 text-left transition hover:border-slate-300" :class="filters.today ? 'ring-2 ring-brand-500' : ''" :aria-pressed="!!filters.today" @click="toggleFlag('today')">
                <p class="section-label">Calls today</p>
                <p class="mt-2 text-[30px] font-bold leading-9 tabular-nums text-slate-900">{{ counts.today }}</p>
                <span class="absolute inset-x-0 bottom-0 h-[3px] bg-gradient-to-r from-sky-500/80 to-transparent" aria-hidden="true" />
            </button>
            <div class="panel relative overflow-hidden p-5">
                <p class="section-label">Connected today</p>
                <p class="mt-2 text-[30px] font-bold leading-9 tabular-nums text-emerald-600">{{ counts.connected_today }}</p>
                <span class="absolute inset-x-0 bottom-0 h-[3px] bg-gradient-to-r from-emerald-500/80 to-transparent" aria-hidden="true" />
            </div>
            <button type="button" class="panel relative overflow-hidden p-5 text-left transition hover:border-slate-300" :class="filters.status.includes('missed') ? 'ring-2 ring-brand-500' : ''" :aria-pressed="filters.status.includes('missed')" @click="toggleStatus('missed')">
                <p class="section-label">Missed today</p>
                <p class="mt-2 text-[30px] font-bold leading-9 tabular-nums text-red-600">{{ counts.missed_today }}</p>
                <span class="absolute inset-x-0 bottom-0 h-[3px] bg-gradient-to-r from-red-500/80 to-transparent" aria-hidden="true" />
            </button>
            <button type="button" class="panel relative overflow-hidden p-5 text-left transition hover:border-slate-300" :class="filters.missing_disposition ? 'ring-2 ring-brand-500' : ''" :aria-pressed="!!filters.missing_disposition" @click="toggleFlag('missing_disposition')">
                <p class="section-label">Awaiting outcome</p>
                <p class="mt-2 text-[30px] font-bold leading-9 tabular-nums text-amber-600">{{ counts.awaiting_disposition }}</p>
                <span class="absolute inset-x-0 bottom-0 h-[3px] bg-gradient-to-r from-amber-500/80 to-transparent" aria-hidden="true" />
            </button>
        </div>

        <div class="panel">
            <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-4">
                <SearchInput v-model="filters.search" placeholder="Call # or lead…" class="w-full sm:w-56" />
                <select v-model="filters.direction" class="form-input w-32">
                    <option value="">Any direction</option>
                    <option v-for="d in options.directions" :key="d.value" :value="d.value">{{ d.label }}</option>
                </select>
                <select v-if="options.users.length" v-model="filters.agent" class="form-input w-36">
                    <option value="">Any agent</option>
                    <option v-for="u in options.users" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
                <select v-model="filters.disposition" class="form-input w-36">
                    <option value="">Any disposition</option>
                    <option v-for="d in options.dispositions" :key="d.id" :value="d.id">{{ d.name }}</option>
                </select>
                <label class="flex items-center gap-1 text-xs text-slate-500">From <input v-model="filters.from" type="date" class="form-input w-36" /></label>
                <label class="flex items-center gap-1 text-xs text-slate-500">To <input v-model="filters.to" type="date" class="form-input w-36" /></label>
                <label class="flex items-center gap-1 text-xs text-slate-600"><input type="checkbox" class="rounded border-slate-300 text-brand-600" :checked="!!filters.has_recording" @change="toggleFlag('has_recording')" /> Has recording</label>
                <button v-if="activeCount" class="text-xs text-slate-500 hover:text-slate-700" @click="clearFilters">Clear ({{ activeCount }})</button>
            </div>
            <div class="flex flex-wrap gap-1.5 border-b border-slate-100 px-5 py-3">
                <button
                    v-for="s in options.statusGroups"
                    :key="s.value"
                    type="button"
                    class="rounded-full border px-3 py-1 text-2xs font-medium transition"
                    :class="filters.status.includes(s.value) ? 'border-brand-500 bg-brand-100 text-brand-600' : 'border-slate-200 text-slate-600 hover:bg-slate-100'"
                    :aria-pressed="filters.status.includes(s.value)"
                    @click="toggleStatus(s.value)"
                >
                    {{ s.label }}
                </button>
            </div>

            <CallsTable :rows="calls.data" />
            <EmptyState v-if="!calls.data.length" icon="phone" :title="activeCount ? 'No calls match these filters' : 'No calls yet'" />
            <UiPagination :paginator="calls" />
        </div>
    </AppLayout>
</template>
