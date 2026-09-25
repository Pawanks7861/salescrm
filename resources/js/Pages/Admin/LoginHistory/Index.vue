<script setup>
import EmptyState from '@/Components/ui/EmptyState.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import SearchInput from '@/Components/ui/SearchInput.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import UiPagination from '@/Components/ui/UiPagination.vue';
import { useFilters } from '@/Composables/useFilters';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDateTime } from '@/utils/format';

const props = defineProps({ histories: Object, filters: Object, users: Array });

const { filters, reset } = useFilters(
    {
        search: props.filters.search ?? '',
        user_id: props.filters.user_id ?? '',
        event: props.filters.event ?? '',
        date_from: props.filters.date_from ?? '',
        date_to: props.filters.date_to ?? '',
    },
    route('admin.login-history.index'),
);

const eventBadge = { login: ['green', 'Login'], logout: ['slate', 'Logout'], failed: ['red', 'Failed'], lockout: ['red', 'Locked out'] };

const duration = (h) => {
    if (!h.logged_in_at || !h.logged_out_at) return null;
    const minutes = Math.round((new Date(h.logged_out_at) - new Date(h.logged_in_at)) / 60000);
    return minutes < 60 ? `${minutes}m` : `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
};
</script>

<template>
    <AppLayout title="Login history">
        <PageHeader title="Login history" subtitle="Successful and failed sign-ins, with device details." />

        <div class="panel">
            <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-4">
                <SearchInput v-model="filters.search" placeholder="Email or IP…" class="w-52" />
                <select v-model="filters.user_id" class="form-input w-40">
                    <option value="">All users</option>
                    <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
                <select v-model="filters.event" class="form-input w-32">
                    <option value="">All events</option>
                    <option value="login">Login</option>
                    <option value="logout">Logout</option>
                    <option value="failed">Failed</option>
                    <option value="lockout">Lockout</option>
                </select>
                <input v-model="filters.date_from" type="date" class="form-input w-36" />
                <input v-model="filters.date_to" type="date" class="form-input w-36" />
                <button class="text-xs text-slate-500 hover:text-slate-700" @click="reset">Clear</button>
            </div>

            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>User / email</th>
                            <th>Event</th>
                            <th>Logged out</th>
                            <th>IP</th>
                            <th>Browser</th>
                            <th>OS</th>
                            <th>Device</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="h in histories.data" :key="h.id">
                            <td class="text-xs text-slate-500">{{ formatDateTime(h.created_at) }}</td>
                            <td>
                                <p class="text-xs font-medium text-slate-800">{{ h.user?.name ?? 'Unknown user' }}</p>
                                <p class="text-2xs text-slate-500">{{ h.email }}</p>
                            </td>
                            <td><UiBadge :color="eventBadge[h.event][0]" dot>{{ eventBadge[h.event][1] }}</UiBadge></td>
                            <td class="text-xs text-slate-500">
                                <template v-if="h.logged_out_at">{{ formatDateTime(h.logged_out_at) }} <span class="text-slate-400">({{ duration(h) }})</span></template>
                                <template v-else>—</template>
                            </td>
                            <td class="font-mono text-2xs">{{ h.ip_address }}</td>
                            <td class="text-xs">{{ h.browser }}</td>
                            <td class="text-xs">{{ h.platform }}</td>
                            <td class="text-xs capitalize">{{ h.device }}</td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-if="!histories.data.length" icon="key" title="No sign-in records match these filters" />
            </div>
            <UiPagination :paginator="histories" />
        </div>
    </AppLayout>
</template>
