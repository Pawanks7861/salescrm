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
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    types: Array,
    fields: Array,
    colors: Array,
    icons: Array,
});

// --- Types ---
const blank = () => ({ name: '', icon: 'phone', color: 'slate', is_active: true });
const modal = ref({ show: false, item: null });
const typeForm = useForm(blank());

const open = (item = null) => {
    typeForm.defaults(blank());
    typeForm.reset();
    typeForm.clearErrors();
    if (item) Object.assign(typeForm, { name: item.name, icon: item.icon ?? '', color: item.color, is_active: item.is_active });
    modal.value = { show: true, item };
};

const save = () => {
    const opts = { preserveScroll: true, onSuccess: () => (modal.value.show = false) };
    const payload = (d) => ({ ...d, icon: d.icon || null });
    if (modal.value.item) typeForm.transform(payload).put(route('admin.followup-settings.types.update', modal.value.item.id), opts);
    else typeForm.transform(payload).post(route('admin.followup-settings.types.store'), opts);
};

const toggle = (item) => router.put(route('admin.followup-settings.types.update', item.id), { name: item.name, icon: item.icon, color: item.color, is_active: !item.is_active }, { preserveScroll: true });

const { confirm } = useConfirm();
const destroy = async (item) => {
    if (await confirm({ title: `Delete "${item.name}"?`, message: 'Only types never used by a follow-up can be deleted. Deactivate it instead to keep history.', confirmText: 'Delete', danger: true })) {
        router.delete(route('admin.followup-settings.types.destroy', item.id), { preserveScroll: true });
    }
};

const move = (index, delta) => {
    const ids = props.types.map((t) => t.id);
    const target = index + delta;
    if (target < 0 || target >= ids.length) return;
    [ids[index], ids[target]] = [ids[target], ids[index]];
    router.post(route('admin.followup-settings.types.reorder'), { ids }, { preserveScroll: true });
};

// --- Settings ---
const settingsForm = useForm({ settings: Object.fromEntries(props.fields.map((f) => [f.name, f.type === 'integer' && f.options ? String(f.value) : f.value])) });
const saveSettings = () =>
    settingsForm
        .transform((d) => ({ settings: Object.fromEntries(Object.entries(d.settings).map(([k, v]) => [k, props.fields.find((f) => f.name === k)?.type === 'integer' ? Number(v) : v])) }))
        .put(route('admin.followup-settings.settings'), { preserveScroll: true });
</script>

<template>
    <AppLayout title="Follow-up Settings">
        <PageHeader title="Follow-up Settings" subtitle="Follow-up types, reminder defaults and completion rules." />

        <div class="grid gap-4 xl:grid-cols-[1fr_380px]">
            <div class="panel min-w-0">
                <div class="panel-header">
                    <h2 class="panel-title">Follow-up types</h2>
                    <UiButton size="sm" icon="plus" @click="open()">New type</UiButton>
                </div>
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th class="w-16">Order</th>
                                <th>Name</th>
                                <th>Follow-ups</th>
                                <th>Active</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="(t, i) in types" :key="t.id">
                                <td>
                                    <div class="flex gap-0.5">
                                        <button class="rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30" :disabled="i === 0" title="Move up" @click="move(i, -1)"><AppIcon name="chevron-up" class="h-3.5 w-3.5" /></button>
                                        <button class="rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30" :disabled="i === types.length - 1" title="Move down" @click="move(i, 1)"><AppIcon name="chevron" class="h-3.5 w-3.5" /></button>
                                    </div>
                                </td>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <UiBadge :color="t.color"><AppIcon v-if="t.icon" :name="t.icon" class="h-3 w-3" />{{ t.name }}</UiBadge>
                                        <UiBadge v-if="t.is_system" color="slate">System</UiBadge>
                                    </div>
                                </td>
                                <td class="text-xs">{{ t.followups_count }}</td>
                                <td><UiToggle :model-value="t.is_active" @update:model-value="toggle(t)" /></td>
                                <td class="text-right">
                                    <div class="flex justify-end gap-1">
                                        <UiButton size="sm" variant="ghost" icon="edit" @click="open(t)">Edit</UiButton>
                                        <UiButton v-if="!t.is_system && !t.followups_count" size="sm" variant="ghost" icon="trash" @click="destroy(t)">Delete</UiButton>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <EmptyState v-if="!types.length" icon="tag" title="No follow-up types yet" />
                </div>
            </div>

            <form class="panel" @submit.prevent="saveSettings">
                <div class="panel-header"><h2 class="panel-title">Rules & defaults</h2></div>
                <div class="space-y-4 p-5">
                    <template v-for="f in fields" :key="f.key">
                        <label v-if="f.type === 'boolean'" class="flex items-center justify-between gap-3 text-sm text-slate-700">
                            <span>{{ f.label }}</span>
                            <UiToggle v-model="settingsForm.settings[f.name]" />
                        </label>
                        <FormField v-else :label="f.label" :error="settingsForm.errors[`settings.${f.name}`]">
                            <select v-if="f.options" v-model="settingsForm.settings[f.name]" class="form-input">
                                <option v-for="(label, value) in f.options" :key="value" :value="String(value)">{{ label }}</option>
                            </select>
                            <input v-else v-model.number="settingsForm.settings[f.name]" type="number" class="form-input w-32" />
                        </FormField>
                    </template>
                    <p class="text-2xs text-slate-500">Changes to the overdue alert apply to follow-ups scheduled or rescheduled afterwards.</p>
                </div>
                <div class="flex justify-end bg-slate-50 px-4 py-3">
                    <UiButton type="submit" :loading="settingsForm.processing">Save settings</UiButton>
                </div>
            </form>
        </div>

        <Modal :show="modal.show" max-width="md" @close="modal.show = false">
            <form @submit.prevent="save">
                <div class="modal-header">
                    <h3 class="text-sm font-semibold">{{ modal.item ? 'Edit follow-up type' : 'New follow-up type' }}</h3>
                </div>
                <div class="space-y-3 p-5">
                    <FormField label="Name" required :error="typeForm.errors.name">
                        <input v-model="typeForm.name" class="form-input" required maxlength="100" />
                    </FormField>
                    <FormField label="Icon" :error="typeForm.errors.icon">
                        <div class="flex flex-wrap gap-1.5">
                            <button v-for="icon in icons" :key="icon" type="button" class="rounded border p-1.5" :class="typeForm.icon === icon ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-slate-200 text-slate-500'" :title="icon" @click="typeForm.icon = icon">
                                <AppIcon :name="icon" class="h-4 w-4" />
                            </button>
                        </div>
                    </FormField>
                    <FormField label="Colour" :error="typeForm.errors.color">
                        <div class="flex flex-wrap gap-1.5">
                            <button v-for="c in colors" :key="c" type="button" class="rounded ring-offset-1" :class="typeForm.color === c ? 'ring-2 ring-brand-500' : ''" @click="typeForm.color = c">
                                <UiBadge :color="c">{{ c }}</UiBadge>
                            </button>
                        </div>
                    </FormField>
                    <label class="flex items-center gap-2 text-sm"><UiToggle v-model="typeForm.is_active" /> Active</label>
                </div>
                <div class="modal-footer">
                    <UiButton variant="secondary" @click="modal.show = false">Cancel</UiButton>
                    <UiButton type="submit" :loading="typeForm.processing">Save</UiButton>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
