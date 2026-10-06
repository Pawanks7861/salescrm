<script setup>
import Modal from '@/Components/Modal.vue';
import AppIcon from '@/Components/ui/AppIcon.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import FormField from '@/Components/ui/FormField.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import UiToggle from '@/Components/ui/UiToggle.vue';
import { useConfirm } from '@/Composables/useConfirm';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDate } from '@/utils/format';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    type: String,
    items: Array,
    colors: Array,
    sources: Array,
    platforms: Array,
});

const tabs = [
    { key: 'statuses', label: 'Statuses', singular: 'status' },
    { key: 'sources', label: 'Sources', singular: 'source' },
    { key: 'lost-reasons', label: 'Lost reasons', singular: 'lost reason' },
    { key: 'campaigns', label: 'Campaigns', singular: 'campaign' },
];
const current = computed(() => tabs.find((t) => t.key === props.type));
const sortable = computed(() => props.type !== 'campaigns');

const blank = () => ({
    statuses: { name: '', description: '', color: 'slate', probability: 0, is_won: false, is_lost: false, is_active: true, is_default: false },
    sources: { name: '', description: '', color: 'slate', is_active: true, is_default: false },
    'lost-reasons': { name: '', is_active: true },
    campaigns: { name: '', source_id: '', platform: 'manual', external_id: '', description: '', starts_at: '', ends_at: '', is_active: true },
})[props.type];

const modal = ref({ show: false, item: null });
const form = useForm(blank());

const open = (item = null) => {
    form.defaults(blank());
    form.reset();
    form.clearErrors();
    if (item) {
        Object.keys(blank()).forEach((k) => {
            let v = item[k];
            if ((k === 'starts_at' || k === 'ends_at') && v) v = v.substring(0, 10);
            form[k] = v ?? (typeof blank()[k] === 'boolean' ? false : '');
        });
    }
    modal.value = { show: true, item };
};

const save = () => {
    const opts = { preserveScroll: true, onSuccess: () => (modal.value.show = false) };
    const payload = (data) => Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? null : v]));
    if (modal.value.item) {
        form.transform(payload).put(route('admin.lead-settings.update', [props.type, modal.value.item.id]), opts);
    } else {
        form.transform(payload).post(route('admin.lead-settings.store', props.type), opts);
    }
};

const { confirm } = useConfirm();
const destroy = async (item) => {
    if (await confirm({ title: `Delete "${item.name}"?`, message: 'Only records never used by a lead can be deleted. Deactivate it instead to keep history.', confirmText: 'Delete', danger: true })) {
        router.delete(route('admin.lead-settings.destroy', [props.type, item.id]), { preserveScroll: true });
    }
};

const ordered = ref([]);
watch(
    () => props.items,
    (items) => (ordered.value = [...items]),
    { immediate: true },
);

const move = (index, delta) => {
    const target = index + delta;
    if (target < 0 || target >= ordered.value.length) return;
    const next = [...ordered.value];
    [next[index], next[target]] = [next[target], next[index]];
    const previous = ordered.value;
    ordered.value = next;
    router.post(
        route('admin.lead-settings.reorder', props.type),
        { ids: next.map((i) => i.id) },
        {
            preserveScroll: true,
            only: ['items'],
            onError: () => {
                ordered.value = previous;
            },
        },
    );
};
</script>

<template>
    <AppLayout title="Lead Settings">
        <PageHeader title="Lead Settings" subtitle="Reference data used across leads, the pipeline and reports.">
            <template #actions>
                <UiButton icon="plus" @click="open()">New {{ current.singular }}</UiButton>
            </template>
        </PageHeader>

        <div class="panel">
            <div class="flex overflow-x-auto border-b border-slate-200 px-2">
                <Link
                    v-for="t in tabs"
                    :key="t.key"
                    :href="route('admin.lead-settings.index', t.key)"
                    class="-mb-px whitespace-nowrap border-b-2 px-3 py-2.5 text-xs font-medium"
                    :class="type === t.key ? 'border-brand-500 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-800'"
                    preserve-scroll
                >
                    {{ t.label }}
                </Link>
            </div>

            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th v-if="sortable" class="w-16">Order</th>
                            <th>Name</th>
                            <th v-if="type === 'statuses'">Type</th>
                            <th v-if="type === 'statuses'">Probability</th>
                            <th v-if="type === 'campaigns'">Source</th>
                            <th v-if="type === 'campaigns'">Platform / external ID</th>
                            <th v-if="type === 'campaigns'">Dates</th>
                            <th>Leads</th>
                            <th>Status</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="(item, i) in ordered" :key="item.id">
                            <td v-if="sortable">
                                <div class="flex gap-0.5">
                                    <button type="button" class="rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30" :disabled="i === 0" title="Move up" @click="move(i, -1)"><AppIcon name="chevron-up" class="h-3.5 w-3.5" /></button>
                                    <button type="button" class="rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30" :disabled="i === ordered.length - 1" title="Move down" @click="move(i, 1)"><AppIcon name="chevron" class="h-3.5 w-3.5" /></button>
                                </div>
                            </td>
                            <td>
                                <div class="flex items-center gap-2">
                                    <UiBadge v-if="item.color" :color="item.color" dot>{{ item.name }}</UiBadge>
                                    <span v-else class="font-medium text-slate-800">{{ item.name }}</span>
                                    <UiBadge v-if="item.is_default" color="indigo">Default</UiBadge>
                                    <UiBadge v-if="item.is_system" color="slate">System</UiBadge>
                                </div>
                                <p v-if="item.description" class="mt-0.5 text-2xs text-slate-500">{{ item.description }}</p>
                            </td>
                            <td v-if="type === 'statuses'">
                                <UiBadge v-if="item.is_won" color="green">Won</UiBadge>
                                <UiBadge v-else-if="item.is_lost" color="red">Lost</UiBadge>
                                <span v-else class="text-xs text-slate-500">Open</span>
                            </td>
                            <td v-if="type === 'statuses'" class="text-xs">{{ item.probability }}%</td>
                            <td v-if="type === 'campaigns'" class="text-xs">{{ item.source?.name ?? '—' }}</td>
                            <td v-if="type === 'campaigns'" class="text-xs">
                                {{ item.platform }}<span v-if="item.external_id" class="ml-1 font-mono text-2xs text-slate-500">{{ item.external_id }}</span>
                            </td>
                            <td v-if="type === 'campaigns'" class="text-xs text-slate-500">{{ item.starts_at ? formatDate(item.starts_at) : '—' }} → {{ item.ends_at ? formatDate(item.ends_at) : '—' }}</td>
                            <td class="text-xs">{{ item.leads_count }}</td>
                            <td><UiBadge :color="item.is_active ? 'green' : 'slate'" dot>{{ item.is_active ? 'Active' : 'Inactive' }}</UiBadge></td>
                            <td class="text-right">
                                <div class="flex justify-end gap-1">
                                    <UiButton size="sm" variant="ghost" icon="edit" @click="open(item)">Edit</UiButton>
                                    <UiButton v-if="!item.is_system && !item.leads_count" size="sm" variant="ghost" icon="trash" @click="destroy(item)">Delete</UiButton>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-if="!items.length" icon="tag" :title="`No ${current.label.toLowerCase()} yet`" />
            </div>
        </div>

        <Modal :show="modal.show" max-width="md" @close="modal.show = false">
            <form @submit.prevent="save">
                <div class="modal-header">
                    <h3 class="text-sm font-semibold">{{ modal.item ? `Edit ${current.singular}` : `New ${current.singular}` }}</h3>
                </div>
                <div class="space-y-3 p-5">
                    <FormField label="Name" required :error="form.errors.name">
                        <input v-model="form.name" class="form-input" required maxlength="150" />
                    </FormField>

                    <FormField v-if="'description' in form.data()" label="Description" :error="form.errors.description">
                        <input v-model="form.description" class="form-input" maxlength="500" />
                    </FormField>

                    <FormField v-if="'color' in form.data()" label="Colour" :error="form.errors.color">
                        <div class="flex flex-wrap gap-1.5">
                            <button v-for="c in colors" :key="c" type="button" class="rounded ring-offset-1" :class="form.color === c ? 'ring-2 ring-brand-500' : ''" @click="form.color = c">
                                <UiBadge :color="c">{{ c }}</UiBadge>
                            </button>
                        </div>
                    </FormField>

                    <template v-if="type === 'statuses'">
                        <FormField label="Win probability (%)" :error="form.errors.probability">
                            <input v-model.number="form.probability" type="number" min="0" max="100" class="form-input w-28" />
                        </FormField>
                        <div class="flex flex-wrap gap-4 text-sm">
                            <label class="flex items-center gap-2"><input v-model="form.is_won" type="checkbox" class="rounded border-slate-300 text-brand-600" :disabled="form.is_lost" /> Won status</label>
                            <label class="flex items-center gap-2"><input v-model="form.is_lost" type="checkbox" class="rounded border-slate-300 text-brand-600" :disabled="form.is_won" /> Lost status (requires reason)</label>
                        </div>
                        <p v-if="form.errors.is_won" class="form-error">{{ form.errors.is_won }}</p>
                    </template>

                    <template v-if="type === 'campaigns'">
                        <div class="grid grid-cols-2 gap-3">
                            <FormField label="Source" :error="form.errors.source_id">
                                <select v-model="form.source_id" class="form-input">
                                    <option value="">—</option>
                                    <option v-for="s in sources" :key="s.id" :value="s.id">{{ s.name }}</option>
                                </select>
                            </FormField>
                            <FormField label="Platform" required :error="form.errors.platform">
                                <select v-model="form.platform" class="form-input">
                                    <option v-for="p in platforms" :key="p" :value="p">{{ p }}</option>
                                </select>
                            </FormField>
                        </div>
                        <FormField label="External campaign ID" hint="Platform campaign ID used to match inbound leads (e.g. Facebook)." :error="form.errors.external_id">
                            <input v-model="form.external_id" class="form-input font-mono" maxlength="100" />
                        </FormField>
                        <div class="grid grid-cols-2 gap-3">
                            <FormField label="Starts" :error="form.errors.starts_at"><input v-model="form.starts_at" type="date" class="form-input" /></FormField>
                            <FormField label="Ends" :error="form.errors.ends_at"><input v-model="form.ends_at" type="date" class="form-input" /></FormField>
                        </div>
                    </template>

                    <div class="flex flex-wrap items-center gap-5 border-t border-slate-100 pt-3">
                        <label class="flex items-center gap-2 text-sm"><UiToggle v-model="form.is_active" /> Active</label>
                        <label v-if="'is_default' in form.data()" class="flex items-center gap-2 text-sm"><UiToggle v-model="form.is_default" /> Default</label>
                    </div>
                    <p v-if="form.errors.is_active" class="form-error">{{ form.errors.is_active }}</p>
                    <p v-if="form.errors.is_default" class="form-error">{{ form.errors.is_default }}</p>
                </div>
                <div class="modal-footer">
                    <UiButton variant="secondary" @click="modal.show = false">Cancel</UiButton>
                    <UiButton type="submit" :loading="form.processing">Save</UiButton>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
