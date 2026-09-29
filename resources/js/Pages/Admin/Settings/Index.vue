<script setup>
import BrandingAsset from '@/Components/admin/BrandingAsset.vue';
import FormField from '@/Components/ui/FormField.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import UiToggle from '@/Components/ui/UiToggle.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    groups: Object,
    group: String,
    fields: Array,
    timezones: Array,
    branding: { type: Object, default: null },
    can: Object,
    version: { type: String, default: null },
    network: { type: Object, default: null },
});

// Keys contain dots, so values are nested as settings[group][name].
const form = useForm({
    settings: { [props.group]: Object.fromEntries(props.fields.map((f) => [f.name, f.value])) },
});

const errorFor = (field) => form.errors[`settings.${field.key}`];
const submit = () => form.put(route('admin.settings.update', props.group), { preserveScroll: true });
</script>

<template>
    <AppLayout title="System settings">
        <PageHeader title="System settings" subtitle="Every change is recorded in the audit log with its previous value." />

        <div class="flex flex-col gap-4 lg:flex-row">
            <nav class="panel h-fit w-full shrink-0 p-1.5 lg:w-52">
                <Link
                    v-for="(label, key) in groups"
                    :key="key"
                    :href="route('admin.settings.index', key)"
                    class="block rounded-md px-3 py-1.5 text-sm"
                    :class="key === group ? 'bg-brand-50 font-medium text-brand-700' : 'text-slate-600 hover:bg-slate-50'"
                >
                    {{ label }}
                </Link>
                <p v-if="version" class="mt-1 border-t border-slate-200 px-3 pb-1 pt-2 text-[11px] text-slate-400">Version {{ version }}</p>
            </nav>

            <form class="panel flex-1" @submit.prevent="submit">
                <div class="panel-header"><h2 class="panel-title">{{ groups[group] }}</h2></div>
                <div v-if="network" class="border-b border-slate-100 px-4 py-3 text-xs text-slate-600">
                    You are connecting from <span class="font-mono text-slate-900">{{ network.client_ip }}</span>.
                    <template v-if="network.restricted"> This address {{ network.allowed_here ? 'is on' : 'is not on' }} the Medawk WiFi list.</template>
                    <template v-else> Copy it into the IP list while you are on the Medawk WiFi, then turn the restriction on.</template>
                </div>
                <div class="grid gap-4 p-4 md:grid-cols-2">
                    <template v-for="field in fields" :key="field.key">
                        <div v-if="field.type === 'boolean'" class="md:col-span-2">
                            <UiToggle v-model="form.settings[group][field.name]" :label="field.label" :disabled="!can.manage" />
                            <p v-if="field.help" class="mt-1 text-2xs text-slate-500">{{ field.help }}</p>
                        </div>
                        <FormField v-else :label="field.label" :error="errorFor(field)" :hint="field.help">
                            <select v-if="field.key === 'general.timezone'" v-model="form.settings[group][field.name]" class="form-input" :disabled="!can.manage">
                                <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
                            </select>
                            <select v-else-if="field.options" v-model="form.settings[group][field.name]" class="form-input" :disabled="!can.manage">
                                <option v-for="(label, value) in field.options" :key="value" :value="field.type === 'integer' ? Number(value) : value">{{ label }}</option>
                            </select>
                            <textarea
                                v-else-if="field.key === 'security.office_wifi_ips'"
                                v-model="form.settings[group][field.name]"
                                class="form-input min-h-20 font-mono"
                                :disabled="!can.manage"
                            />
                            <input
                                v-else
                                v-model="form.settings[group][field.name]"
                                :type="field.type === 'integer' ? 'number' : field.type === 'encrypted' ? 'password' : 'text'"
                                class="form-input"
                                :placeholder="field.type === 'encrypted' ? 'Leave blank to keep current value' : ''"
                                :disabled="!can.manage"
                            />
                        </FormField>
                    </template>
                </div>
                <div v-if="can.manage" class="flex justify-end border-t border-slate-200 px-4 py-2.5">
                    <UiButton type="submit" :loading="form.processing">Save {{ groups[group].toLowerCase() }} settings</UiButton>
                </div>
            </form>
        </div>

        <section v-if="branding" class="panel mt-4 lg:ml-56" aria-labelledby="branding-title">
            <div class="panel-header">
                <h2 id="branding-title" class="panel-title">Branding</h2>
                <span class="text-xs text-slate-500">Shown on the login screen, sidebar, browser tab and notifications.</span>
            </div>
            <div class="grid gap-6 p-5 xl:grid-cols-2">
                <BrandingAsset
                    type="logo"
                    label="Company logo"
                    :hint="`${branding.limits.logo}. Horizontal or square; never cropped.`"
                    :current-url="branding.logo_url"
                    accept="image/png,image/jpeg,image/webp"
                    :can-manage="can.manage"
                />
                <BrandingAsset
                    type="favicon"
                    label="Favicon"
                    :hint="branding.limits.favicon"
                    :current-url="branding.favicon_url"
                    :fallback-url="branding.default_favicon_url"
                    accept="image/png,image/x-icon,image/vnd.microsoft.icon,.ico,image/webp"
                    :can-manage="can.manage"
                />
            </div>
            <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500">The company name above is used next to the logo; when it is empty the CRM name is shown.</p>
        </section>
    </AppLayout>
</template>
