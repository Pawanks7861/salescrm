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
import { computed, ref } from 'vue';

const props = defineProps({ fields: Array, types: Array });

const typeLabel = (value) => props.types.find((t) => t.value === value)?.label ?? value;

const modal = ref({ show: false, field: null });
const form = useForm({ name: '', field_type: 'text', optionsText: '', help_text: '', is_required: false, is_active: true, min: '', max: '' });

const selectedType = computed(() => props.types.find((t) => t.value === (modal.value.field?.field_type ?? form.field_type)));

const open = (field = null) => {
    form.reset();
    form.clearErrors();
    if (field) {
        form.name = field.name;
        form.field_type = field.field_type;
        form.optionsText = (field.options ?? []).join('\n');
        form.help_text = field.help_text ?? '';
        form.is_required = field.is_required;
        form.is_active = field.is_active;
        form.min = field.min ?? '';
        form.max = field.max ?? '';
    }
    modal.value = { show: true, field };
};

const save = () => {
    const transform = (data) => {
        const payload = {
            name: data.name,
            help_text: data.help_text || null,
            is_required: data.is_required,
            is_active: data.is_active,
            min: data.min === '' ? null : data.min,
            max: data.max === '' ? null : data.max,
            options: data.optionsText.split('\n').map((o) => o.trim()).filter(Boolean),
        };
        if (!modal.value.field) payload.field_type = data.field_type;
        return payload;
    };
    const opts = { preserveScroll: true, onSuccess: () => (modal.value.show = false) };
    modal.value.field
        ? form.transform(transform).put(route('admin.custom-fields.update', modal.value.field.id), opts)
        : form.transform(transform).post(route('admin.custom-fields.store'), opts);
};

const { confirm } = useConfirm();
const destroy = async (field) => {
    if (await confirm({ title: `Delete "${field.name}"?`, message: 'Fields that already hold lead data cannot be deleted — deactivate them instead.', confirmText: 'Delete', danger: true })) {
        router.delete(route('admin.custom-fields.destroy', field.id), { preserveScroll: true });
    }
};

const move = (index, delta) => {
    const ids = props.fields.map((f) => f.id);
    const target = index + delta;
    if (target < 0 || target >= ids.length) return;
    [ids[index], ids[target]] = [ids[target], ids[index]];
    router.post(route('admin.custom-fields.reorder'), { ids }, { preserveScroll: true });
};
</script>

<template>
    <AppLayout title="Custom Fields">
        <PageHeader title="Custom Fields" subtitle="Extra lead fields shown on the lead form and 360° page. Validated on the server.">
            <template #actions>
                <UiButton icon="plus" @click="open()">New field</UiButton>
            </template>
        </PageHeader>

        <div class="panel overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="w-16">Order</th>
                        <th>Field</th>
                        <th>Type</th>
                        <th>Options / rules</th>
                        <th>Required</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="(field, i) in fields" :key="field.id">
                        <td>
                            <div class="flex gap-0.5">
                                <button class="rounded p-0.5 text-slate-400 hover:bg-slate-100 disabled:opacity-30" :disabled="i === 0" @click="move(i, -1)"><AppIcon name="chevron-up" class="h-3.5 w-3.5" /></button>
                                <button class="rounded p-0.5 text-slate-400 hover:bg-slate-100 disabled:opacity-30" :disabled="i === fields.length - 1" @click="move(i, 1)"><AppIcon name="chevron" class="h-3.5 w-3.5" /></button>
                            </div>
                        </td>
                        <td>
                            <p class="font-medium text-slate-800">{{ field.name }}</p>
                            <p class="font-mono text-2xs text-slate-400">{{ field.slug }}</p>
                        </td>
                        <td><UiBadge color="indigo">{{ typeLabel(field.field_type) }}</UiBadge></td>
                        <td class="max-w-xs truncate text-xs text-slate-500">
                            <template v-if="field.options.length">{{ field.options.join(', ') }}</template>
                            <template v-else-if="field.min !== null || field.max !== null">min {{ field.min ?? '—' }} · max {{ field.max ?? '—' }}</template>
                            <template v-else>—</template>
                        </td>
                        <td class="text-xs">{{ field.is_required ? 'Yes' : 'No' }}</td>
                        <td><UiBadge :color="field.is_active ? 'green' : 'slate'" dot>{{ field.is_active ? 'Active' : 'Inactive' }}</UiBadge></td>
                        <td class="text-right">
                            <div class="flex justify-end gap-1">
                                <UiButton size="sm" variant="ghost" icon="edit" @click="open(field)">Edit</UiButton>
                                <UiButton size="sm" variant="ghost" icon="trash" @click="destroy(field)">Delete</UiButton>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!fields.length" icon="adjustments" title="No custom fields yet" description="Add fields such as budget range, property type or preferred contact time." />
        </div>

        <Modal :show="modal.show" max-width="md" @close="modal.show = false">
            <form @submit.prevent="save">
                <div class="modal-header"><h3 class="text-sm font-semibold">{{ modal.field ? 'Edit field' : 'New field' }}</h3></div>
                <div class="space-y-3 p-5">
                    <FormField label="Label" required :error="form.errors.name">
                        <input v-model="form.name" class="form-input" required maxlength="80" />
                    </FormField>
                    <FormField label="Type" required :error="form.errors.field_type" :hint="modal.field ? 'The type cannot change once created.' : ''">
                        <select v-model="form.field_type" class="form-input" :disabled="!!modal.field">
                            <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
                        </select>
                    </FormField>
                    <FormField v-if="selectedType?.has_options" label="Options (one per line)" required :error="form.errors.options">
                        <textarea v-model="form.optionsText" rows="4" class="form-input" />
                    </FormField>
                    <div v-if="['number', 'text', 'textarea'].includes(selectedType?.value)" class="grid grid-cols-2 gap-3">
                        <FormField :label="selectedType.value === 'number' ? 'Minimum' : 'Min length'" :error="form.errors.min"><input v-model="form.min" type="number" class="form-input" /></FormField>
                        <FormField :label="selectedType.value === 'number' ? 'Maximum' : 'Max length'" :error="form.errors.max"><input v-model="form.max" type="number" class="form-input" /></FormField>
                    </div>
                    <FormField label="Help text" :error="form.errors.help_text">
                        <input v-model="form.help_text" class="form-input" maxlength="255" />
                    </FormField>
                    <div class="flex gap-5 border-t border-slate-100 pt-3 text-sm">
                        <label class="flex items-center gap-2"><UiToggle v-model="form.is_required" /> Required</label>
                        <label class="flex items-center gap-2"><UiToggle v-model="form.is_active" /> Active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <UiButton variant="secondary" @click="modal.show = false">Cancel</UiButton>
                    <UiButton type="submit" :loading="form.processing">Save</UiButton>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
