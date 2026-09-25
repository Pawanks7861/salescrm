<script setup>
import EmptyState from '@/Components/ui/EmptyState.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDateTime } from '@/utils/format';
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    form: Object,
    rows: Array,
    targets: Array,
});

const mappingForm = useForm({ targets: Object.fromEntries(props.rows.map((r) => [r.meta_field, r.target])) });
const save = () => mappingForm.put(route('admin.integrations.facebook.forms.mapping.save', props.form.id), { preserveScroll: true });

const groups = computed(() => {
    const out = {};
    props.targets.forEach((t) => (out[t.group] ??= []).push(t));
    return out;
});
const targetLabel = (value) => props.targets.find((t) => t.value === value)?.label ?? 'Keep in enquiry only';

const samples = {
    FULL_NAME: 'Amit Desai',
    FIRST_NAME: 'Amit',
    LAST_NAME: 'Desai',
    EMAIL: 'amit.desai@example.com',
    WORK_EMAIL: 'amit@company.example',
    PHONE: '+91 98123 45678',
    WORK_PHONE_NUMBER: '+91 20 4000 1234',
    CITY: 'Pune',
    STATE: 'Maharashtra',
    PROVINCE: 'Maharashtra',
    COUNTRY: 'India',
    ZIP: '411001',
    POST_CODE: '411001',
    COMPANY_NAME: 'Desai Traders',
    JOB_TITLE: 'Owner',
};
const sample = (row) => samples[row.type] ?? (row.type === 'CUSTOM' || !row.type ? 'Sample answer' : row.label);

const preview = computed(() =>
    props.rows
        .filter((r) => r.in_form)
        .map((r) => ({ label: r.label, value: sample(r), target: mappingForm.targets[r.meta_field] ?? 'none' })),
);
const duplicateTargets = computed(() => {
    const seen = {};
    Object.values(mappingForm.targets)
        .filter((t) => t && t !== 'none')
        .forEach((t) => (seen[t] = (seen[t] ?? 0) + 1));
    return Object.keys(seen).filter((k) => seen[k] > 1);
});
</script>

<template>
    <AppLayout :title="`Mapping · ${form.form_name}`">
        <PageHeader :title="form.form_name" :subtitle="`Field mapping · ${form.page_name} · form ${form.form_id}`">
            <template #breadcrumb>
                <Link :href="route('admin.integrations.facebook.index')" class="hover:underline">Facebook integration</Link> / Mapping
            </template>
        </PageHeader>

        <div class="grid gap-4 xl:grid-cols-[1fr_360px]">
            <form class="panel min-w-0" @submit.prevent="save">
                <div class="panel-header">
                    <h2 class="panel-title">Questions</h2>
                    <span class="text-2xs text-slate-500">Last synced {{ form.last_synced_at ? formatDateTime(form.last_synced_at) : 'never' }}</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Meta question</th>
                                <th>CRM field</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="r in rows" :key="r.meta_field">
                                <td>
                                    <div class="font-medium text-slate-800">{{ r.label }}</div>
                                    <div class="font-mono text-2xs text-slate-500">{{ r.meta_field }}<span v-if="r.type"> · {{ r.type }}</span></div>
                                    <UiBadge v-if="!r.in_form" color="slate" class="mt-0.5">No longer in form</UiBadge>
                                </td>
                                <td>
                                    <select v-model="mappingForm.targets[r.meta_field]" class="form-input w-56 py-1 text-xs">
                                        <template v-for="(options, group) in groups" :key="group">
                                            <template v-if="!group">
                                                <option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option>
                                            </template>
                                            <optgroup v-else :label="group">
                                                <option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option>
                                            </optgroup>
                                        </template>
                                    </select>
                                    <p v-if="mappingForm.errors[`targets.${r.meta_field}`]" class="mt-0.5 text-2xs text-red-600">{{ mappingForm.errors[`targets.${r.meta_field}`] }}</p>
                                </td>
                                <td>
                                    <UiBadge v-if="r.status === 'invalid'" color="red">Invalid target</UiBadge>
                                    <UiBadge v-else-if="r.status === 'mapped'" :color="r.is_default ? 'blue' : 'green'">{{ r.is_default ? 'Standard' : 'Mapped' }}</UiBadge>
                                    <UiBadge v-else color="slate">Enquiry only</UiBadge>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <EmptyState v-if="!rows.length" icon="document" title="No questions loaded" description="Refresh the Page's forms to load this form's questions." />
                </div>
                <div class="flex items-center justify-between gap-3 bg-slate-50 px-4 py-3">
                    <p class="text-2xs text-slate-500">Every answer is always kept on the enquiry. Mapping only copies answers into lead fields; owner, status and other internal fields can never be set from Meta.</p>
                    <UiButton type="submit" :loading="mappingForm.processing">Save mapping</UiButton>
                </div>
            </form>

            <div class="panel h-fit">
                <div class="panel-header"><h2 class="panel-title">Preview</h2></div>
                <div class="p-4">
                    <p class="mb-2 text-2xs text-slate-500">How a sample submission would be stored with the current selections.</p>
                    <ul class="divide-y divide-slate-100 text-xs">
                        <li v-for="p in preview" :key="p.label" class="flex items-start justify-between gap-2 py-1.5">
                            <div class="min-w-0">
                                <div class="truncate text-slate-500">{{ p.label }}</div>
                                <div class="truncate font-medium text-slate-800">{{ p.value }}</div>
                            </div>
                            <UiBadge :color="p.target === 'none' ? 'slate' : 'indigo'">{{ targetLabel(p.target) }}</UiBadge>
                        </li>
                    </ul>
                    <p v-if="duplicateTargets.length" class="mt-2 rounded-md bg-amber-50 p-2 text-2xs text-amber-800">Several questions map to the same CRM field ({{ duplicateTargets.map(targetLabel).join(', ') }}); the first non-empty answer is used.</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
