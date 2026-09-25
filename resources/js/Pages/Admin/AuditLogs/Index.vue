<script setup>
import EmptyState from '@/Components/ui/EmptyState.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import SearchInput from '@/Components/ui/SearchInput.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import UiPagination from '@/Components/ui/UiPagination.vue';
import { useFilters } from '@/Composables/useFilters';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDateTime } from '@/utils/format';
import { Link } from '@inertiajs/vue3';

const props = defineProps({ logs: Object, filters: Object, actions: Array, modules: Array, users: Array });

const { filters, reset } = useFilters(
    {
        search: props.filters.search ?? '',
        user_id: props.filters.user_id ?? '',
        action: props.filters.action ?? '',
        module: props.filters.module ?? '',
        entity_type: props.filters.entity_type ?? '',
        entity_id: props.filters.entity_id ?? '',
        date_from: props.filters.date_from ?? '',
        date_to: props.filters.date_to ?? '',
    },
    route('admin.audit-logs.index'),
);

const actionColor = (action) => {
    if (/FAILED|DENIED|ATTEMPTED|LOCKOUT|DELETED|DISABLED/.test(action)) return 'red';
    if (/CREATED|ENABLED|LOGIN$/.test(action)) return 'green';
    if (/PERMISSION|SETTING|PASSWORD/.test(action)) return 'amber';
    return 'indigo';
};
</script>

<template>
    <AppLayout title="Audit logs">
        <PageHeader title="Audit logs" subtitle="Immutable record of security and administrative actions." />

        <div class="panel">
            <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-4">
                <SearchInput v-model="filters.search" placeholder="Search description…" class="w-56" />
                <select v-model="filters.user_id" class="form-input w-40">
                    <option value="">All users</option>
                    <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
                <select v-model="filters.action" class="form-input w-48">
                    <option value="">All actions</option>
                    <option v-for="a in actions" :key="a" :value="a">{{ a }}</option>
                </select>
                <select v-model="filters.module" class="form-input w-32">
                    <option value="">All modules</option>
                    <option v-for="m in modules" :key="m" :value="m">{{ m }}</option>
                </select>
                <input v-model="filters.date_from" type="date" class="form-input w-36" title="From" />
                <input v-model="filters.date_to" type="date" class="form-input w-36" title="To" />
                <button class="text-xs text-slate-500 hover:text-slate-700" @click="reset">Clear</button>
            </div>

            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Module</th>
                            <th>Entity</th>
                            <th>Description</th>
                            <th>IP</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="log in logs.data" :key="log.id">
                            <td class="text-xs text-slate-500">{{ formatDateTime(log.created_at) }}</td>
                            <td class="text-xs">{{ log.user?.name ?? 'System' }}</td>
                            <td><UiBadge :color="actionColor(log.action)">{{ log.action }}</UiBadge></td>
                            <td class="text-xs">{{ log.module }}</td>
                            <td class="text-xs text-slate-500">{{ log.entity_type ? `${log.entity_type} #${log.entity_id}` : '—' }}</td>
                            <td class="max-w-md truncate text-xs" :title="log.description">{{ log.description }}</td>
                            <td class="font-mono text-2xs text-slate-500">{{ log.ip_address ?? '—' }}</td>
                            <td class="text-right">
                                <Link :href="route('admin.audit-logs.show', log.id)" class="link text-xs">{{ log.has_changes ? 'Changes' : 'Details' }}</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-if="!logs.data.length" icon="document" title="No audit entries match these filters" />
            </div>
            <UiPagination :paginator="logs" />
        </div>
    </AppLayout>
</template>
