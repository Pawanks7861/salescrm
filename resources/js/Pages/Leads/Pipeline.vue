<script setup>
import PriorityBadge from '@/Components/leads/PriorityBadge.vue';
import StatusChangeModal from '@/Components/leads/StatusChangeModal.vue';
import Avatar from '@/Components/ui/Avatar.vue';
import FilterBar from '@/Components/ui/FilterBar.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import SearchInput from '@/Components/ui/SearchInput.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import { useFilters } from '@/Composables/useFilters';
import { useToast } from '@/Composables/useToast';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatCurrency } from '@/utils/format';
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { ref, watch } from 'vue';

const props = defineProps({
    columns: Array,
    filters: Object,
    perColumn: Number,
    options: Object,
    can: Object,
});

const keys = ['search', 'source', 'campaign', 'assignee', 'priority'];
const { filters, reset } = useFilters(Object.fromEntries(keys.map((k) => [k, props.filters[k] ?? ''])), route('leads.pipeline'));

const board = ref([]);
const sync = () => (board.value = props.columns.map((c) => ({ ...c, cards: [...c.cards], loading: false })));
sync();
watch(() => props.columns, sync);

const toast = useToast();
const dragging = ref(null);
const overColumn = ref(null);
const lostModal = ref({ show: false, status: null, leadId: null });

const onDragStart = (card, fromStatusId, e) => {
    if (!props.can.changeStatus) return;
    dragging.value = { card, fromStatusId };
    e.dataTransfer.effectAllowed = 'move';
};

const onDrop = (column) => {
    overColumn.value = null;
    const drag = dragging.value;
    dragging.value = null;
    if (!drag || drag.fromStatusId === column.status.id) return;

    if (column.status.is_lost) {
        lostModal.value = { show: true, status: column.status, leadId: drag.card.id };
        return;
    }

    const from = board.value.find((c) => c.status.id === drag.fromStatusId);
    from.cards = from.cards.filter((c) => c.id !== drag.card.id);
    from.count--;
    column.cards.unshift({ ...drag.card, status_id: column.status.id });
    column.count++;

    router.post(
        route('leads.status', drag.card.id),
        { status_id: column.status.id },
        {
            preserveScroll: true,
            preserveState: true,
            onError: () => {
                toast.error('Could not move the lead.');
                sync();
            },
        },
    );
};

const loadMore = async (column) => {
    column.loading = true;
    try {
        const query = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== ''));
        const { data } = await axios.get(route('leads.pipeline.more', column.status.id), { params: { ...query, offset: column.cards.length } });
        column.cards.push(...data.cards);
    } finally {
        column.loading = false;
    }
};

const columnTotal = (column) => column.cards.reduce((sum, c) => sum + Number(c.estimated_value || 0), 0);
</script>

<template>
    <AppLayout title="Pipeline">
        <PageHeader title="Pipeline" :subtitle="can.changeStatus ? 'Drag cards between columns to change status.' : 'Read-only view.'">
            <template #actions>
                <UiButton variant="secondary" icon="list" :href="route('leads.index')">List view</UiButton>
            </template>
        </PageHeader>

        <FilterBar @clear="reset">
            <div class="flex flex-wrap items-center gap-2">
            <SearchInput v-model="filters.search" placeholder="Search leads…" class="w-full sm:w-60" />
            <select v-model="filters.source" class="form-input w-32">
                <option value="">All sources</option>
                <option v-for="s in options.sources" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
            <select v-model="filters.campaign" class="form-input w-40">
                <option value="">All campaigns</option>
                <option v-for="c in options.campaigns" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <select v-if="can.filterByUser" v-model="filters.assignee" class="form-input w-36">
                <option value="">Any owner</option>
                <option value="unassigned">Unassigned</option>
                <option v-for="u in options.users" :key="u.id" :value="u.id">{{ u.name }}</option>
            </select>
            <select v-model="filters.priority" class="form-input w-28">
                <option value="">Any priority</option>
                <option v-for="p in options.priorities" :key="p.value" :value="p.value">{{ p.label }}</option>
            </select>
            <button class="h-10 rounded-lg px-2 text-xs text-slate-500 hover:text-slate-800" @click="reset">Clear</button>
            </div>
        </FilterBar>

        <div class="flex gap-4 overflow-x-auto pb-3">
            <div
                v-for="column in board"
                :key="column.status.id"
                class="flex max-h-[calc(100vh-250px)] min-h-[240px] w-[280px] shrink-0 flex-col rounded-xl border bg-surface-1/60 transition"
                :class="overColumn === column.status.id ? 'border-brand-400 bg-brand-50 ring-2 ring-brand-200' : 'border-slate-200/70'"
                @dragover.prevent="overColumn = column.status.id"
                @dragleave="overColumn = overColumn === column.status.id ? null : overColumn"
                @drop.prevent="onDrop(column)"
            >
                <div class="flex items-center justify-between gap-2 px-4 pb-3 pt-4">
                    <UiBadge :color="column.status.color" dot>{{ column.status.name }}</UiBadge>
                    <span class="flex items-center gap-1.5 text-2xs text-slate-500">
                        <span class="rounded-md bg-slate-100 px-1.5 py-0.5 font-semibold text-slate-700">{{ column.count }}</span>
                        <template v-if="columnTotal(column)">{{ formatCurrency(columnTotal(column)) }}</template>
                    </span>
                </div>
                <div class="flex-1 space-y-2.5 overflow-y-auto px-3 pb-3">
                    <div
                        v-for="card in column.cards"
                        :key="card.id"
                        :draggable="can.changeStatus"
                        class="rounded-xl border border-slate-200/80 bg-slate-50 p-3.5 transition hover:border-slate-300"
                        :class="[can.changeStatus ? 'cursor-grab active:cursor-grabbing' : '', dragging?.card.id === card.id ? 'opacity-40' : '']"
                        @dragstart="onDragStart(card, column.status.id, $event)"
                        @dragend="dragging = null"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <Link :href="route('leads.show', card.id)" class="text-sm font-semibold leading-tight text-slate-900 hover:text-brand-700">{{ card.full_name }}</Link>
                            <PriorityBadge :priority="card.priority" />
                        </div>
                        <p v-if="card.company_name" class="mt-0.5 truncate text-2xs text-slate-500">{{ card.company_name }}</p>
                        <div class="mt-2 flex items-center justify-between text-2xs text-slate-500">
                            <span class="font-mono" :title="`Real ID ${card.id}`">{{ card.id }} · {{ card.lead_number }}</span>
                            <span>{{ card.age_days }}d</span>
                        </div>
                        <div class="mt-2.5 flex items-center justify-between gap-2 border-t border-slate-100 pt-2.5 text-2xs">
                            <span class="flex min-w-0 items-center gap-1.5 truncate text-slate-600">
                                <Avatar v-if="card.assignee" :name="card.assignee.name" size="xs" />{{ card.assignee?.name ?? 'Unassigned' }}
                            </span>
                            <span v-if="card.estimated_value" class="shrink-0 font-semibold text-slate-800">{{ formatCurrency(card.estimated_value) }}</span>
                        </div>
                    </div>
                    <p v-if="!column.cards.length" class="py-4 text-center text-2xs text-slate-400">No leads</p>
                    <UiButton v-if="column.cards.length < column.count" size="sm" variant="ghost" class="w-full" :loading="column.loading" @click="loadMore(column)">
                        Load more ({{ column.count - column.cards.length }})
                    </UiButton>
                </div>
            </div>
        </div>

        <StatusChangeModal :show="lostModal.show" :lead-id="lostModal.leadId" :status="lostModal.status" :lost-reasons="options.lostReasons" @close="lostModal.show = false" />
    </AppLayout>
</template>
