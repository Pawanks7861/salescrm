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
    rules: Array,
    conditions: Array,
    assignmentTypes: Array,
    users: Array,
    sources: Array,
    campaigns: Array,
    facebookForms: { type: Array, default: () => [] },
});

const conditionLabel = (rule) => {
    const v = rule.condition_value;
    switch (rule.condition_type) {
        case 'any': return 'Any lead';
        case 'source': return `Source is ${props.sources.find((s) => String(s.id) === v)?.name ?? v}`;
        case 'campaign': return `Campaign is ${props.campaigns.find((c) => String(c.id) === v)?.name ?? v}`;
        case 'team': return rule.condition_label;
        case 'facebook_form': return `Facebook form is ${props.facebookForms.find((f) => f.value === v)?.label ?? v}`;
        default: return `${rule.condition_type.charAt(0).toUpperCase() + rule.condition_type.slice(1)} is "${v}"`;
    }
};
const actionLabel = (rule) => {
    switch (rule.assignment_type) {
        case 'user': return `Assign to ${rule.assigned_user ?? '—'}`;
        case 'round_robin': return `Rotate across ${rule.user_pool.map((id) => props.users.find((u) => u.id === id)?.name ?? `#${id}`).join(', ')}`;
        default: return rule.assignment_label ?? rule.assignment_type;
    }
};

const modal = ref({ show: false, rule: null });
const form = useForm({ name: '', condition_type: 'source', condition_value: '', assignment_type: 'user', assigned_user_id: '', user_pool: [], priority: 100, is_active: true });

const open = (rule = null) => {
    form.reset();
    form.clearErrors();
    if (rule) {
        const supportedCondition = props.conditions.some((c) => c.value === rule.condition_type);
        const supportedAssignment = props.assignmentTypes.some((a) => a.value === rule.assignment_type);
        Object.assign(form, {
            name: rule.name,
            condition_type: supportedCondition ? rule.condition_type : 'any',
            condition_value: supportedCondition ? (rule.condition_value ?? '') : '',
            assignment_type: supportedAssignment ? rule.assignment_type : 'user',
            assigned_user_id: rule.assigned_user_id ?? '',
            user_pool: [...rule.user_pool],
            priority: rule.priority,
            is_active: rule.is_active,
        });
    } else {
        form.priority = (props.rules.at(-1)?.priority ?? 0) + 10;
    }
    modal.value = { show: true, rule };
};

const togglePool = (id) => {
    const i = form.user_pool.indexOf(id);
    i === -1 ? form.user_pool.push(id) : form.user_pool.splice(i, 1);
};

const save = () => {
    const opts = { preserveScroll: true, onSuccess: () => (modal.value.show = false) };
    const transform = (d) => ({ ...d, condition_value: d.condition_value === '' ? null : String(d.condition_value), assigned_user_id: d.assigned_user_id || null });
    modal.value.rule
        ? form.transform(transform).put(route('admin.assignment-rules.update', modal.value.rule.id), opts)
        : form.transform(transform).post(route('admin.assignment-rules.store'), opts);
};

const toggle = (rule) => router.post(route('admin.assignment-rules.toggle', rule.id), {}, { preserveScroll: true });

const { confirm } = useConfirm();
const destroy = async (rule) => {
    if (await confirm({ title: `Delete rule "${rule.name}"?`, message: 'Past assignments made by this rule keep their history.', confirmText: 'Delete', danger: true })) {
        router.delete(route('admin.assignment-rules.destroy', rule.id), { preserveScroll: true });
    }
};

const move = (index, delta) => {
    const ids = props.rules.map((r) => r.id);
    const target = index + delta;
    if (target < 0 || target >= ids.length) return;
    [ids[index], ids[target]] = [ids[target], ids[index]];
    router.post(route('admin.assignment-rules.reorder'), { ids }, { preserveScroll: true });
};
</script>

<template>
    <AppLayout title="Assignment Rules">
        <PageHeader title="Assignment Rules" subtitle="Evaluated top to bottom for new unassigned leads. The first matching rule wins.">
            <template #actions>
                <UiButton icon="plus" @click="open()">New rule</UiButton>
            </template>
        </PageHeader>

        <div class="panel overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="w-16">Order</th>
                        <th>Rule</th>
                        <th>When</th>
                        <th>Then</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="(rule, i) in rules" :key="rule.id" :class="{ 'opacity-60': !rule.is_active }">
                        <td>
                            <div class="flex gap-0.5">
                                <button class="rounded p-0.5 text-slate-400 hover:bg-slate-100 disabled:opacity-30" :disabled="i === 0" @click="move(i, -1)"><AppIcon name="chevron-up" class="h-3.5 w-3.5" /></button>
                                <button class="rounded p-0.5 text-slate-400 hover:bg-slate-100 disabled:opacity-30" :disabled="i === rules.length - 1" @click="move(i, 1)"><AppIcon name="chevron" class="h-3.5 w-3.5" /></button>
                            </div>
                        </td>
                        <td class="font-medium text-slate-800">
                            {{ rule.name }}
                            <UiBadge v-if="rule.deprecated" color="amber" class="ml-1" title="Team-based rules no longer run. Edit the rule to assign a user or a user rotation.">Deprecated — not running</UiBadge>
                        </td>
                        <td class="text-xs">{{ conditionLabel(rule) }}</td>
                        <td class="text-xs">{{ actionLabel(rule) }}</td>
                        <td class="text-xs">{{ rule.priority }}</td>
                        <td><UiBadge :color="rule.is_active && !rule.deprecated ? 'green' : 'slate'" dot>{{ rule.deprecated ? 'Inactive' : rule.is_active ? 'Enabled' : 'Disabled' }}</UiBadge></td>
                        <td class="text-right">
                            <div class="flex justify-end gap-1">
                                <UiButton v-if="!rule.deprecated || rule.is_active" size="sm" variant="ghost" :icon="rule.is_active ? 'ban' : 'check'" @click="toggle(rule)">{{ rule.is_active ? 'Disable' : 'Enable' }}</UiButton>
                                <UiButton size="sm" variant="ghost" icon="edit" @click="open(rule)">Edit</UiButton>
                                <UiButton size="sm" variant="ghost" icon="trash" @click="destroy(rule)">Delete</UiButton>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rules.length" icon="switch" title="No assignment rules" description="Without rules, inbound leads stay unassigned and manually created leads go to their creator." />
        </div>

        <Modal :show="modal.show" max-width="lg" @close="modal.show = false">
            <form @submit.prevent="save">
                <div class="modal-header"><h3 class="text-sm font-semibold">{{ modal.rule ? 'Edit rule' : 'New rule' }}</h3></div>
                <div class="space-y-3 p-5">
                    <FormField label="Rule name" required :error="form.errors.name">
                        <input v-model="form.name" class="form-input" required maxlength="120" />
                    </FormField>

                    <div class="grid grid-cols-2 gap-3">
                        <FormField label="When" required :error="form.errors.condition_type">
                            <select v-model="form.condition_type" class="form-input" @change="form.condition_value = ''">
                                <option v-for="c in conditions" :key="c.value" :value="c.value">{{ c.label }}</option>
                            </select>
                        </FormField>
                        <FormField v-if="form.condition_type !== 'any'" label="Equals" required :error="form.errors.condition_value">
                            <select v-if="form.condition_type === 'source'" v-model="form.condition_value" class="form-input">
                                <option value="" disabled>Select…</option>
                                <option v-for="s in sources" :key="s.id" :value="String(s.id)">{{ s.name }}</option>
                            </select>
                            <select v-else-if="form.condition_type === 'campaign'" v-model="form.condition_value" class="form-input">
                                <option value="" disabled>Select…</option>
                                <option v-for="c in campaigns" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
                            </select>
                            <select v-else-if="form.condition_type === 'facebook_form'" v-model="form.condition_value" class="form-input">
                                <option value="" disabled>{{ facebookForms.length ? 'Select…' : 'No synced forms yet' }}</option>
                                <option v-for="f in facebookForms" :key="f.value" :value="f.value">{{ f.label }}</option>
                            </select>
                            <input v-else v-model="form.condition_value" class="form-input" maxlength="150" :placeholder="form.condition_type === 'city' ? 'e.g. Ahmedabad' : 'e.g. Gujarat'" />
                        </FormField>
                    </div>

                    <FormField label="Then" required :error="form.errors.assignment_type">
                        <select v-model="form.assignment_type" class="form-input">
                            <option v-for="a in assignmentTypes" :key="a.value" :value="a.value">{{ a.label }}</option>
                        </select>
                    </FormField>

                    <FormField v-if="form.assignment_type === 'user'" label="User" required :error="form.errors.assigned_user_id">
                        <select v-model="form.assigned_user_id" class="form-input">
                            <option value="" disabled>Select…</option>
                            <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}{{ u.designation ? ` — ${u.designation}` : '' }}</option>
                        </select>
                    </FormField>
                    <FormField v-if="form.assignment_type === 'round_robin'" label="Users in rotation" required :error="form.errors.user_pool">
                        <div class="flex max-h-40 flex-wrap gap-1.5 overflow-y-auto">
                            <button
                                v-for="u in users"
                                :key="u.id"
                                type="button"
                                class="rounded-full border px-2.5 py-0.5 text-xs"
                                :class="form.user_pool.includes(u.id) ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-slate-300 text-slate-600 hover:bg-slate-50'"
                                @click="togglePool(u.id)"
                            >
                                {{ u.name }}
                            </button>
                        </div>
                    </FormField>

                    <div class="flex items-end gap-5 border-t border-slate-100 pt-3">
                        <FormField label="Priority" hint="Lower runs first." :error="form.errors.priority">
                            <input v-model.number="form.priority" type="number" min="1" max="65000" class="form-input w-28" />
                        </FormField>
                        <label class="mb-6 flex items-center gap-2 text-sm"><UiToggle v-model="form.is_active" /> Enabled</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <UiButton variant="secondary" @click="modal.show = false">Cancel</UiButton>
                    <UiButton type="submit" :loading="form.processing">Save rule</UiButton>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
