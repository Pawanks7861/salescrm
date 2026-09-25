<script setup>
import EmptyState from '@/Components/ui/EmptyState.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import SearchInput from '@/Components/ui/SearchInput.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import UiPagination from '@/Components/ui/UiPagination.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { useFilters } from '@/Composables/useFilters';
import AppLayout from '@/Layouts/AppLayout.vue';
import { initials, timeAgo, formatDateTime } from '@/utils/format';
import { Link, router } from '@inertiajs/vue3';

const props = defineProps({
    users: Object,
    filters: Object,
    roles: Array,
    can: Object,
});

const { filters, reset } = useFilters(
    { search: props.filters.search ?? '', role_id: props.filters.role_id ?? '', status: props.filters.status ?? '' },
    route('admin.users.index'),
);

const { confirm } = useConfirm();

const toggleActive = async (user) => {
    const ok = await confirm({
        title: user.is_active ? `Deactivate ${user.name}?` : `Activate ${user.name}?`,
        message: user.is_active
            ? 'They will be signed out immediately and cannot log in. Their leads and history are kept.'
            : 'They will be able to sign in again with their existing password.',
        confirmText: user.is_active ? 'Deactivate' : 'Activate',
        danger: user.is_active,
    });
    if (ok) router.post(route('admin.users.toggle-active', user.id), {}, { preserveScroll: true });
};
</script>

<template>
    <AppLayout title="Users">
        <PageHeader title="Users" :subtitle="`${users.total} user${users.total === 1 ? '' : 's'}`">
            <template #actions>
                <UiButton v-if="can.create" :href="route('admin.users.create')" icon="plus">New user</UiButton>
            </template>
        </PageHeader>

        <div class="panel">
            <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-4">
                <SearchInput v-model="filters.search" placeholder="Name, email, code, phone…" class="w-64" />
                <select v-model="filters.role_id" class="form-input w-40">
                    <option value="">All roles</option>
                    <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option>
                </select>
                <select v-model="filters.status" class="form-input w-32">
                    <option value="">Any status</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
                <button class="text-xs text-slate-500 hover:text-slate-700" @click="reset">Clear</button>
            </div>

            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Code</th>
                            <th>Role</th>
                            <th>Designation</th>
                            <th>Status</th>
                            <th>Last login</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="user in users.data" :key="user.id">
                            <td>
                                <div class="flex items-center gap-2">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-full bg-slate-100 text-2xs font-semibold text-slate-600">{{ initials(user.name) }}</span>
                                    <div>
                                        <p class="font-medium text-slate-900">{{ user.name }}</p>
                                        <p class="text-2xs text-slate-500">{{ user.email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="text-xs">{{ user.employee_code ?? '—' }}</td>
                            <td><UiBadge :color="user.role ? 'indigo' : 'amber'">{{ user.role?.name ?? 'No role' }}</UiBadge></td>
                            <td class="text-xs">{{ user.designation ?? '—' }}</td>
                            <td>
                                <UiBadge :color="user.is_active ? 'green' : 'slate'" dot>{{ user.is_active ? 'Active' : 'Inactive' }}</UiBadge>
                            </td>
                            <td class="text-xs text-slate-500" :title="formatDateTime(user.last_login_at)">{{ user.last_login_at ? timeAgo(user.last_login_at) : 'Never' }}</td>
                            <td class="text-right">
                                <div class="flex justify-end gap-1">
                                    <UiButton v-if="user.can.update" size="sm" variant="ghost" icon="edit" :href="route('admin.users.edit', user.id)">Edit</UiButton>
                                    <UiButton v-if="user.can.toggleActive" size="sm" variant="ghost" :icon="user.is_active ? 'ban' : 'check'" @click="toggleActive(user)">
                                        {{ user.is_active ? 'Deactivate' : 'Activate' }}
                                    </UiButton>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-if="!users.data.length" icon="user" title="No users match these filters" />
            </div>
            <UiPagination :paginator="users" />
        </div>
    </AppLayout>
</template>
