<script setup>
import PageHeader from '@/Components/ui/PageHeader.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDateTime } from '@/utils/format';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({ log: Object });

const changedKeys = computed(() => {
    const keys = new Set([...Object.keys(props.log.old_values_json ?? {}), ...Object.keys(props.log.new_values_json ?? {})]);
    return [...keys];
});

const show = (value) => {
    if (value === null || value === undefined) return '—';
    if (typeof value === 'object') return JSON.stringify(value, null, 2);
    if (typeof value === 'boolean') return value ? 'true' : 'false';
    return String(value);
};

const meta = computed(() => [
    ['When', formatDateTime(props.log.created_at)],
    ['User', props.log.user ? `${props.log.user.name} (${props.log.user.email})` : 'System'],
    ['Module', props.log.module],
    ['Entity', props.log.entity_type ? `${props.log.entity_type} #${props.log.entity_id}` : '—'],
    ['Request', props.log.request_method ? `${props.log.request_method} ${props.log.route ?? ''}` : props.log.route ?? '—'],
    ['IP address', props.log.ip_address ?? '—'],
    ['User agent', props.log.user_agent ?? '—'],
]);
</script>

<template>
    <AppLayout :title="`Audit #${log.id}`">
        <PageHeader :title="log.description || log.action">
            <template #breadcrumb><Link :href="route('admin.audit-logs.index')" class="hover:underline">Audit logs</Link> / #{{ log.id }}</template>
            <template #actions><UiBadge color="indigo">{{ log.action }}</UiBadge></template>
        </PageHeader>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="panel">
                <div class="panel-header"><h2 class="panel-title">Context</h2></div>
                <dl class="divide-y divide-slate-100 text-sm">
                    <div v-for="[label, value] in meta" :key="label" class="grid grid-cols-3 gap-2 px-4 py-2">
                        <dt class="text-xs text-slate-500">{{ label }}</dt>
                        <dd class="col-span-2 break-words text-xs text-slate-800">{{ value }}</dd>
                    </div>
                </dl>
            </div>

            <div class="panel lg:col-span-2">
                <div class="panel-header"><h2 class="panel-title">Changes</h2></div>
                <table v-if="changedKeys.length" class="data-table">
                    <thead>
                        <tr><th>Field</th><th>Old value</th><th>New value</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="key in changedKeys" :key="key">
                            <td class="font-mono text-xs">{{ key }}</td>
                            <td><pre class="whitespace-pre-wrap text-xs text-red-700">{{ show(log.old_values_json?.[key]) }}</pre></td>
                            <td><pre class="whitespace-pre-wrap text-xs text-emerald-700">{{ show(log.new_values_json?.[key]) }}</pre></td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="px-4 py-6 text-center text-sm text-slate-500">No field changes recorded for this action.</p>
            </div>
        </div>
    </AppLayout>
</template>
