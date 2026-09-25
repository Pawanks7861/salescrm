<script setup>
import Modal from '@/Components/Modal.vue';
import FormField from '@/Components/ui/FormField.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import { useConfirm } from '@/Composables/useConfirm';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({ roles: Array, totalPermissions: Number, can: Object });

const showCreate = ref(false);
const form = useForm({ name: '', description: '' });
const create = () => form.post(route('admin.roles.store'), { onSuccess: () => (showCreate.value = false) });

const { confirm } = useConfirm();
const destroy = async (role) => {
    if (await confirm({ title: `Delete role "${role.name}"?`, message: 'This cannot be undone.', confirmText: 'Delete', danger: true })) {
        router.delete(route('admin.roles.destroy', role.id), { preserveScroll: true });
    }
};
</script>

<template>
    <AppLayout title="Roles & Permissions">
        <PageHeader title="Roles & Permissions" subtitle="Roles bundle permissions. Individual users can also receive overrides from their profile.">
            <template #actions>
                <UiButton v-if="can.create" icon="plus" @click="showCreate = true">New role</UiButton>
            </template>
        </PageHeader>

        <div class="panel overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Role</th>
                        <th>Description</th>
                        <th>Users</th>
                        <th>Permissions</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="role in roles" :key="role.id">
                        <td>
                            <div class="flex items-center gap-2">
                                <Link :href="route('admin.roles.edit', role.id)" class="link">{{ role.name }}</Link>
                                <UiBadge v-if="role.is_system" color="slate">System</UiBadge>
                            </div>
                            <p class="text-2xs text-slate-400">{{ role.slug }}</p>
                        </td>
                        <td class="max-w-md truncate whitespace-normal text-xs text-slate-500">{{ role.description }}</td>
                        <td>{{ role.users_count }}</td>
                        <td>
                            <UiBadge v-if="role.is_super_admin" color="purple">All (bypass)</UiBadge>
                            <span v-else class="text-xs">{{ role.permissions_count }} / {{ totalPermissions }}</span>
                        </td>
                        <td class="text-right">
                            <div class="flex justify-end gap-1">
                                <UiButton size="sm" variant="ghost" :icon="role.can.update ? 'edit' : 'eye'" :href="route('admin.roles.edit', role.id)">
                                    {{ role.can.update ? 'Edit' : 'View' }}
                                </UiButton>
                                <UiButton v-if="role.can.delete" size="sm" variant="ghost" icon="trash" @click="destroy(role)">Delete</UiButton>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal :show="showCreate" max-width="md" @close="showCreate = false">
            <form @submit.prevent="create">
                <div class="modal-header"><h3 class="text-sm font-semibold">New role</h3></div>
                <div class="space-y-3 p-5">
                    <FormField label="Name" required :error="form.errors.name">
                        <input v-model="form.name" class="form-input" required />
                    </FormField>
                    <FormField label="Description" :error="form.errors.description">
                        <input v-model="form.description" class="form-input" />
                    </FormField>
                </div>
                <div class="modal-footer">
                    <UiButton variant="secondary" @click="showCreate = false">Cancel</UiButton>
                    <UiButton type="submit" :loading="form.processing">Create & set permissions</UiButton>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
