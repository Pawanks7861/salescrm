<script setup>
import Modal from '@/Components/Modal.vue';
import FormField from '@/Components/ui/FormField.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import UiToggle from '@/Components/ui/UiToggle.vue';
import { useConfirm } from '@/Composables/useConfirm';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDateTime } from '@/utils/format';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    user: { type: Object, default: null },
    roles: Array,
    overrides: { type: Object, default: () => ({ grant: [], deny: [] }) },
    permissionCatalogue: { type: Object, default: null },
    can: { type: Object, default: () => ({}) },
});

const editing = computed(() => !!props.user);

const form = useForm({
    name: props.user?.name ?? '',
    employee_code: props.user?.employee_code ?? '',
    email: props.user?.email ?? '',
    phone: props.user?.phone ?? '',
    designation: props.user?.designation ?? '',
    role_id: props.user?.role_id ?? '',
    is_active: props.user?.is_active ?? true,
    ...(props.user ? {} : { password: '', password_confirmation: '' }),
});

const submit = () => {
    if (editing.value) {
        form.put(route('admin.users.update', props.user.id));
    } else {
        form.post(route('admin.users.store'));
    }
};

// Reset password
const showReset = ref(false);
const resetForm = useForm({ password: '', password_confirmation: '' });
const submitReset = () =>
    resetForm.post(route('admin.users.reset-password', props.user.id), {
        preserveScroll: true,
        onSuccess: () => {
            showReset.value = false;
            resetForm.reset();
        },
    });

// Activation
const { confirm } = useConfirm();
const toggleActive = async () => {
    const ok = await confirm({
        title: props.user.is_active ? `Deactivate ${props.user.name}?` : `Activate ${props.user.name}?`,
        message: props.user.is_active ? 'They will be signed out immediately. Their records are preserved.' : 'They will be able to sign in again.',
        confirmText: props.user.is_active ? 'Deactivate' : 'Activate',
        danger: props.user.is_active,
    });
    if (ok) router.post(route('admin.users.toggle-active', props.user.id), {}, { preserveScroll: true });
};

// Per-user permission overrides
const overrideState = ref(
    Object.fromEntries([
        ...props.overrides.grant.map((p) => [p, 'grant']),
        ...props.overrides.deny.map((p) => [p, 'deny']),
    ]),
);
const savingOverrides = ref(false);
const saveOverrides = () => {
    savingOverrides.value = true;
    const entries = Object.entries(overrideState.value);
    router.put(
        route('admin.users.permissions', props.user.id),
        {
            grant: entries.filter(([, t]) => t === 'grant').map(([p]) => p),
            deny: entries.filter(([, t]) => t === 'deny').map(([p]) => p),
        },
        { preserveScroll: true, onFinish: () => (savingOverrides.value = false) },
    );
};
const setOverride = (name, value) => {
    if (value === 'inherit') delete overrideState.value[name];
    else overrideState.value[name] = value;
};
</script>

<template>
    <AppLayout :title="editing ? `Edit ${user.name}` : 'New user'">
        <PageHeader :title="editing ? user.name : 'New user'" :subtitle="editing && user.last_login_at ? `Last login ${formatDateTime(user.last_login_at)}` : ''">
            <template #breadcrumb><Link :href="route('admin.users.index')" class="hover:underline">Users</Link> /</template>
            <template v-if="editing" #actions>
                <UiBadge v-if="user.is_super_admin" color="purple">Super Admin</UiBadge>
                <UiButton v-if="can.resetPassword" variant="secondary" icon="key" @click="showReset = true">Reset password</UiButton>
                <UiButton v-if="can.toggleActive" :variant="user.is_active ? 'danger' : 'secondary'" :icon="user.is_active ? 'ban' : 'check'" @click="toggleActive">
                    {{ user.is_active ? 'Deactivate' : 'Activate' }}
                </UiButton>
            </template>
        </PageHeader>

        <form class="panel max-w-4xl" @submit.prevent="submit">
            <div class="panel-header"><h2 class="panel-title">Account</h2></div>
            <div class="grid gap-4 p-4 md:grid-cols-2">
                <FormField label="Full name" required :error="form.errors.name">
                    <input v-model="form.name" class="form-input" required />
                </FormField>
                <FormField label="Employee code" :error="form.errors.employee_code">
                    <input v-model="form.employee_code" class="form-input" />
                </FormField>
                <FormField label="Email" required :error="form.errors.email">
                    <input v-model="form.email" type="email" class="form-input" required />
                </FormField>
                <FormField label="Phone" :error="form.errors.phone">
                    <input v-model="form.phone" class="form-input" />
                </FormField>
                <FormField label="Designation" :error="form.errors.designation">
                    <input v-model="form.designation" class="form-input" />
                </FormField>
                <FormField label="Role" required :error="form.errors.role_id">
                    <select v-model="form.role_id" class="form-input" required>
                        <option value="" disabled>Select role</option>
                        <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option>
                    </select>
                </FormField>
            </div>

            <template v-if="!editing">
                <div class="panel-header border-t"><h2 class="panel-title">Initial password</h2></div>
                <div class="grid gap-4 p-4 md:grid-cols-2">
                    <FormField label="Password" required :error="form.errors.password" hint="Upper & lower case letters and numbers.">
                        <input v-model="form.password" type="password" class="form-input" autocomplete="new-password" required />
                    </FormField>
                    <FormField label="Confirm password" required>
                        <input v-model="form.password_confirmation" type="password" class="form-input" autocomplete="new-password" required />
                    </FormField>
                    <UiToggle v-model="form.is_active" label="Account active" />
                </div>
            </template>

            <div class="flex justify-end gap-2 border-t border-slate-200 px-4 py-2.5">
                <UiButton variant="secondary" :href="route('admin.users.index')">Cancel</UiButton>
                <UiButton type="submit" :loading="form.processing">{{ editing ? 'Save changes' : 'Create user' }}</UiButton>
            </div>
        </form>

        <!-- Permission overrides -->
        <div v-if="editing && permissionCatalogue" class="panel mt-4 max-w-4xl">
            <div class="panel-header">
                <div>
                    <h2 class="panel-title">Permission overrides</h2>
                    <p class="text-2xs text-slate-500">Grant or deny individual permissions on top of the user's role. "Inherit" follows the role.</p>
                </div>
                <UiButton size="sm" :loading="savingOverrides" @click="saveOverrides">Save overrides</UiButton>
            </div>
            <div class="grid gap-x-6 gap-y-4 p-4 md:grid-cols-2">
                <div v-for="(perms, module) in permissionCatalogue" :key="module">
                    <p class="mb-1 text-2xs font-semibold uppercase tracking-wide text-slate-500">{{ module }}</p>
                    <div v-for="p in perms" :key="p.name" class="flex items-center justify-between gap-2 py-0.5">
                        <span class="text-xs text-slate-700" :title="p.name">{{ p.label }}</span>
                        <select
                            :value="overrideState[p.name] ?? 'inherit'"
                            class="form-input w-24 py-0.5 text-xs"
                            :class="{ 'border-emerald-400 bg-emerald-50': overrideState[p.name] === 'grant', 'border-red-400 bg-red-50': overrideState[p.name] === 'deny' }"
                            @change="setOverride(p.name, $event.target.value)"
                        >
                            <option value="inherit">Inherit</option>
                            <option value="grant">Grant</option>
                            <option value="deny">Deny</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <Modal :show="showReset" max-width="md" @close="showReset = false">
            <form @submit.prevent="submitReset">
                <div class="modal-header">
                    <h3 class="text-sm font-semibold">Reset password for {{ user?.name }}</h3>
                    <p class="text-xs text-slate-500">All of their active sessions will be ended.</p>
                </div>
                <div class="space-y-3 p-5">
                    <FormField label="New password" required :error="resetForm.errors.password">
                        <input v-model="resetForm.password" type="password" class="form-input" autocomplete="new-password" />
                    </FormField>
                    <FormField label="Confirm password" required>
                        <input v-model="resetForm.password_confirmation" type="password" class="form-input" autocomplete="new-password" />
                    </FormField>
                </div>
                <div class="modal-footer">
                    <UiButton variant="secondary" @click="showReset = false">Cancel</UiButton>
                    <UiButton type="submit" :loading="resetForm.processing">Reset password</UiButton>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
