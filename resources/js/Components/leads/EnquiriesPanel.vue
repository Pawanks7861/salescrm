<script setup>
import UiBadge from '@/Components/ui/UiBadge.vue';
import { formatDateTime } from '@/utils/format';
import { ref } from 'vue';

defineProps({ enquiries: { type: Array, required: true } });

const expanded = ref(null);
const humanize = (key) => key.replace(/[_?]+/g, ' ').trim().replace(/\b\w/g, (c) => c.toUpperCase());
const label = (e, key) => e.labels?.[key] || humanize(key);
</script>

<template>
    <div>
        <p v-if="!enquiries.length" class="py-6 text-center text-xs text-slate-500">No enquiries recorded.</p>
        <ul class="divide-y divide-slate-100">
            <li v-for="(e, i) in enquiries" :key="e.id" class="py-2.5">
                <button type="button" class="flex w-full flex-wrap items-center gap-2 text-left" @click="expanded = expanded === e.id ? null : e.id">
                    <span class="text-xs font-medium text-slate-800">{{ formatDateTime(e.received_at) }}</span>
                    <UiBadge color="indigo">{{ e.source ?? 'Unknown source' }}</UiBadge>
                    <span v-if="e.meta?.form_name" class="text-2xs text-slate-600">{{ e.meta.form_name }}</span>
                    <span v-if="e.campaign" class="text-2xs text-slate-500">{{ e.campaign }}</span>
                    <UiBadge v-if="e.is_duplicate" color="amber">Repeat enquiry</UiBadge>
                    <UiBadge v-if="i === enquiries.length - 1" color="slate">Original</UiBadge>
                    <span class="ml-auto text-2xs text-slate-400">{{ expanded === e.id ? 'Hide' : 'Details' }}</span>
                </button>
                <div v-if="expanded === e.id" class="mt-2 space-y-2">
                    <dl v-if="e.meta" class="grid gap-x-4 gap-y-1 rounded border border-slate-100 p-3 text-xs sm:grid-cols-2">
                        <dt class="text-slate-500">Platform</dt>
                        <dd class="text-slate-800">{{ e.meta.platform === 'ig' ? 'Instagram' : 'Facebook' }}<span v-if="e.meta.is_organic" class="text-slate-500"> (organic)</span></dd>
                        <template v-if="e.meta.page_name"><dt class="text-slate-500">Page</dt><dd class="text-slate-800">{{ e.meta.page_name }}</dd></template>
                        <template v-if="e.meta.form_name"><dt class="text-slate-500">Form</dt><dd class="text-slate-800">{{ e.meta.form_name }}</dd></template>
                        <template v-if="e.meta.campaign_name"><dt class="text-slate-500">Campaign</dt><dd class="text-slate-800">{{ e.meta.campaign_name }}</dd></template>
                        <template v-if="e.meta.adset_name"><dt class="text-slate-500">Ad set</dt><dd class="text-slate-800">{{ e.meta.adset_name }}</dd></template>
                        <template v-if="e.meta.ad_name"><dt class="text-slate-500">Ad</dt><dd class="text-slate-800">{{ e.meta.ad_name }}</dd></template>
                    </dl>
                    <dl class="grid gap-x-4 gap-y-1 rounded bg-slate-50 p-3 text-xs sm:grid-cols-2">
                        <template v-for="(value, key) in e.data ?? {}" :key="key">
                            <dt class="text-slate-500">{{ label(e, key) }}</dt>
                            <dd class="whitespace-pre-line break-words text-slate-800">{{ Array.isArray(value) ? value.join(', ') : value }}</dd>
                        </template>
                        <template v-if="e.external_id">
                            <dt class="text-slate-500">{{ e.channel === 'facebook' ? 'Meta lead ID' : 'External ID' }}</dt>
                            <dd class="font-mono text-slate-800">{{ e.external_id }}</dd>
                        </template>
                    </dl>
                </div>
            </li>
        </ul>
    </div>
</template>
