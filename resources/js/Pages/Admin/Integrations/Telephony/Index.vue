<script setup>
import Modal from '@/Components/Modal.vue';
import AppIcon from '@/Components/ui/AppIcon.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import FormField from '@/Components/ui/FormField.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import UiToggle from '@/Components/ui/UiToggle.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDateTime, timeAgo } from '@/utils/format';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * Admin → Integrations → Telephony. Nothing here calls the provider on load;
 * credentials are shown only as "configured / missing".
 */
const props = defineProps({
    provider: Object,
    integration: Object,
    health: Object,
    numbers: Array,
    agents: Array,
    dispositions: Array,
    fields: Array,
    permissions: Object,
    options: Object,
});

// --- Connection & calling mode ---
const integrationForm = useForm({ ...props.integration });
const saveIntegration = () => integrationForm.put(route('admin.integrations.telephony.integration'), { preserveScroll: true });

// --- Recording & call rules ---
const settingsForm = useForm({ settings: Object.fromEntries(props.fields.map((f) => [f.name, f.type === 'integer' && f.options ? String(f.value) : f.value])) });
const saveSettings = () =>
    settingsForm
        .transform((d) => ({ settings: Object.fromEntries(Object.entries(d.settings).map(([k, v]) => [k, props.fields.find((f) => f.name === k)?.type === 'integer' ? Number(v) : v])) }))
        .put(route('admin.integrations.telephony.settings'), { preserveScroll: true });
const recordingFields = computed(() => props.fields.filter((f) => f.name.startsWith('recording_') || f.name === 'event_retention_days'));
const ruleFields = computed(() => props.fields.filter((f) => !recordingFields.value.includes(f)));

// --- Numbers ---
const blankNumber = () => ({ phone_number: '', display_name: '', number_type: 'virtual', provider_number_id: '', supports_inbound: true, supports_outbound: true, supports_webrtc: false, is_active: true, is_default: false });
const numberModal = ref({ show: false, item: null });
const numberForm = useForm(blankNumber());
const openNumber = (item = null) => {
    numberForm.defaults(blankNumber());
    numberForm.reset();
    numberForm.clearErrors();
    if (item) Object.assign(numberForm, { ...blankNumber(), ...item, provider_number_id: item.provider_number_id ?? '' });
    numberModal.value = { show: true, item };
};
const saveNumber = () => {
    const opts = { preserveScroll: true, onSuccess: () => (numberModal.value.show = false) };
    const payload = (d) => ({ ...d, provider_number_id: d.provider_number_id || null });
    if (numberModal.value.item) numberForm.transform(payload).put(route('admin.integrations.telephony.numbers.update', numberModal.value.item.id), opts);
    else numberForm.transform(payload).post(route('admin.integrations.telephony.numbers.store'), opts);
};

// --- Agents ---
const blankAgent = () => ({ user_id: '', provider_user_id: '', provider_agent_id: '', provider_sip_username: '', registered_phone: '', calling_mode: 'pstn', is_enabled: true });
const agentModal = ref({ show: false, item: null });
const agentForm = useForm(blankAgent());
const openAgent = (item = null) => {
    agentForm.defaults(blankAgent());
    agentForm.reset();
    agentForm.clearErrors();
    if (item) Object.assign(agentForm, Object.fromEntries(Object.keys(blankAgent()).map((k) => [k, item[k] ?? blankAgent()[k]])));
    agentModal.value = { show: true, item };
};
const saveAgent = () => {
    const opts = { preserveScroll: true, onSuccess: () => (agentModal.value.show = false) };
    const payload = (d) => Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v === '' ? null : v]));
    if (agentModal.value.item) agentForm.transform(payload).put(route('admin.integrations.telephony.agents.update', agentModal.value.item.id), opts);
    else agentForm.transform(payload).post(route('admin.integrations.telephony.agents.store'), opts);
};
const unmappedUsers = computed(() => props.options.users.filter((u) => !props.agents.some((a) => a.user_id === u.id) || agentModal.value.item?.user_id === u.id));

// --- Dispositions ---
const blankDisposition = () => ({ name: '', color: 'slate', is_contact: true, requires_note: false, requires_next_action: false, is_active: true });
const dispositionModal = ref({ show: false, item: null });
const dispositionForm = useForm(blankDisposition());
const openDisposition = (item = null) => {
    dispositionForm.defaults(blankDisposition());
    dispositionForm.reset();
    dispositionForm.clearErrors();
    if (item) Object.assign(dispositionForm, Object.fromEntries(Object.keys(blankDisposition()).map((k) => [k, item[k]])));
    dispositionModal.value = { show: true, item };
};
const saveDisposition = () => {
    const opts = { preserveScroll: true, onSuccess: () => (dispositionModal.value.show = false) };
    if (dispositionModal.value.item) dispositionForm.put(route('admin.integrations.telephony.dispositions.update', dispositionModal.value.item.id), opts);
    else dispositionForm.post(route('admin.integrations.telephony.dispositions.store'), opts);
};

// --- Explicit provider actions ---
const busy = ref(null);
const run = (name, routeName) => router.post(route(routeName), {}, { preserveScroll: true, onStart: () => (busy.value = name), onFinish: () => (busy.value = null) });

const modeLabel = (m) => props.options.modes.find((o) => o.value === m)?.label ?? m;
const permissionLabel = (key) => key.replace('call.', '').replace('recording.', 'recording ').replace(/_/g, ' ');
const healthColor = computed(() => ({ connected: 'green', auth_failed: 'red', error: 'red', not_configured: 'amber' })[props.health.last_health_status] ?? 'slate');
</script>

<template>
    <AppLayout title="Telephony">
        <PageHeader title="Telephony" subtitle="Browser calling, click-to-call, call recording and call outcomes.">
            <template #actions>
                <UiButton variant="secondary" icon="refresh" :loading="busy === 'health'" @click="run('health', 'admin.integrations.telephony.health')">Test connection</UiButton>
            </template>
        </PageHeader>

        <div v-if="provider.is_fake" class="mb-4 flex items-center gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900">
            <AppIcon name="warning" class="h-4 w-4" /> The local fake provider is active (TELEPHONY_DRIVER=fake). No real calls are placed. It cannot run in production.
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
            <!-- Provider -->
            <div class="panel">
                <div class="panel-header">
                    <h2 class="panel-title">Provider</h2>
                    <UiBadge :color="provider.is_fake ? 'amber' : 'indigo'">{{ provider.driver }}</UiBadge>
                </div>
                <div class="space-y-4 p-5 text-sm">
                    <ul class="grid gap-1 sm:grid-cols-2">
                        <li v-for="c in provider.credentials" :key="c.label" class="flex items-center gap-1.5 text-xs">
                            <AppIcon :name="c.configured ? 'check' : 'warning'" class="h-3.5 w-3.5" :class="c.configured ? 'text-emerald-600' : 'text-amber-600'" />
                            {{ c.label }} <span class="text-slate-400">{{ c.configured ? 'configured' : 'missing' }}</span>
                        </li>
                    </ul>
                    <p class="text-2xs text-slate-500">Credentials are read from the server environment (.env) only. They are never stored in the database or shown here.</p>
                    <div class="space-y-1 rounded-md bg-slate-50 p-2.5 text-2xs text-slate-600">
                        <p class="font-semibold text-slate-700">Callback URLs (configure in the provider dashboard)</p>
                        <p>Status callback: <code class="break-all">{{ provider.status_callback_url }}?token=…</code></p>
                        <p>Passthru applet: <code class="break-all">{{ provider.passthru_url }}?token=…</code></p>
                        <p>Append <code>?token=</code> followed by the callback secret from the environment. Secret {{ provider.webhook_secret_configured ? 'configured' : 'MISSING — callbacks will be rejected' }}; IP allowlist {{ provider.ip_allowlist ? 'on' : 'off' }}.</p>
                    </div>
                </div>
            </div>

            <!-- Health -->
            <div class="panel">
                <div class="panel-header">
                    <h2 class="panel-title">Health</h2>
                    <UiBadge :color="healthColor" dot>{{ health.last_health_status ?? 'not checked' }}</UiBadge>
                </div>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-3 p-5 text-xs">
                    <div><dt class="text-slate-500">Last check</dt><dd>{{ health.last_health_check_at ? timeAgo(health.last_health_check_at) : 'never' }}</dd></div>
                    <div><dt class="text-slate-500">Last callback</dt><dd>{{ health.last_callback_at ? timeAgo(health.last_callback_at) : 'never' }}</dd></div>
                    <div><dt class="text-slate-500">Default number</dt><dd>{{ health.default_number ?? 'not set' }}</dd></div>
                    <div><dt class="text-slate-500">Enabled agents</dt><dd>{{ health.agents_enabled }}</dd></div>
                    <div><dt class="text-slate-500">Failed callbacks (24h)</dt><dd :class="health.failed_events_24h ? 'text-red-600 font-semibold' : ''">{{ health.failed_events_24h }}</dd></div>
                    <div><dt class="text-slate-500">Queue "{{ health.queue }}"</dt><dd>{{ health.queued_jobs ?? 'n/a' }} waiting</dd></div>
                    <div v-if="health.last_error" class="col-span-2">
                        <dt class="text-slate-500">Last error</dt>
                        <dd class="text-red-600">{{ health.last_error }} <span class="text-slate-400">· {{ formatDateTime(health.last_error_at) }}</span></dd>
                    </div>
                    <div v-if="health.recent_errors.length" class="col-span-2">
                        <dt class="text-slate-500">Recent callback errors</dt>
                        <dd v-for="e in health.recent_errors" :key="e.id" class="truncate text-slate-600">{{ formatDateTime(e.at) }} · {{ e.type }} · {{ e.error }}</dd>
                    </div>
                </dl>
            </div>

            <!-- Connection & calling mode -->
            <form class="panel" @submit.prevent="saveIntegration">
                <div class="panel-header"><h2 class="panel-title">Connection &amp; calling mode</h2></div>
                <div class="space-y-4 p-5 text-sm">
                    <FormField label="Name" :error="integrationForm.errors.name">
                        <input v-model="integrationForm.name" class="form-input" maxlength="100" />
                    </FormField>
                    <label class="flex items-center justify-between gap-3"><span>Calling enabled</span><UiToggle v-model="integrationForm.is_active" /></label>
                    <label class="flex items-center justify-between gap-3"><span>Browser calling (WebRTC)</span><UiToggle v-model="integrationForm.browser_calling_enabled" /></label>
                    <label class="flex items-center justify-between gap-3"><span>Phone click-to-call (PSTN)</span><UiToggle v-model="integrationForm.pstn_calling_enabled" /></label>
                    <label class="flex items-center justify-between gap-3"><span>Record calls</span><UiToggle v-model="integrationForm.recording_enabled" /></label>
                    <FormField label="Default calling mode" :error="integrationForm.errors.default_calling_mode">
                        <select v-model="integrationForm.default_calling_mode" class="form-input">
                            <option v-for="m in options.modes" :key="m.value" :value="m.value">{{ m.label }}</option>
                        </select>
                    </FormField>
                    <p class="text-2xs text-slate-500">If browser calling is unavailable (no microphone, unsupported browser), agents with a registered phone fall back to click-to-call.</p>
                </div>
                <div class="flex justify-end bg-slate-50 px-4 py-3"><UiButton type="submit" :loading="integrationForm.processing">Save</UiButton></div>
            </form>

            <!-- Recording & rules -->
            <form class="panel" @submit.prevent="saveSettings">
                <div class="panel-header"><h2 class="panel-title">Recording &amp; call rules</h2></div>
                <div class="space-y-4 p-5 text-sm">
                    <template v-for="group in [recordingFields, ruleFields]" :key="group.map((f) => f.name).join()">
                        <template v-for="f in group" :key="f.key">
                            <label v-if="f.type === 'boolean'" class="flex items-center justify-between gap-3 text-slate-700">
                                <span>{{ f.label }}</span>
                                <UiToggle v-model="settingsForm.settings[f.name]" />
                            </label>
                            <FormField v-else :label="f.label" :error="settingsForm.errors[`settings.${f.name}`]">
                                <select v-if="f.options" v-model="settingsForm.settings[f.name]" class="form-input">
                                    <option v-for="(label, value) in f.options" :key="value" :value="String(value)">{{ label }}</option>
                                </select>
                                <input v-else-if="f.type === 'string'" v-model="settingsForm.settings[f.name]" type="text" class="form-input" maxlength="255" />
                                <input v-else v-model.number="settingsForm.settings[f.name]" type="number" class="form-input w-32" />
                            </FormField>
                        </template>
                        <hr class="border-slate-100" />
                    </template>
                    <p class="rounded-md bg-amber-50 p-2.5 text-2xs text-amber-900">
                        Recording laws differ by country and state. Keep the recording notice enabled and have your compliance team review consent requirements before enabling recording. Expired recordings are removed; the call record is kept.
                    </p>
                </div>
                <div class="flex justify-end bg-slate-50 px-4 py-3"><UiButton type="submit" :loading="settingsForm.processing">Save settings</UiButton></div>
            </form>
        </div>

        <!-- Numbers -->
        <div class="panel mt-4">
            <div class="panel-header">
                <h2 class="panel-title">Numbers</h2>
                <div class="flex gap-2">
                    <UiButton size="sm" variant="secondary" icon="refresh" :loading="busy === 'sync'" @click="run('sync', 'admin.integrations.telephony.numbers.sync')">Sync from provider</UiButton>
                    <UiButton size="sm" icon="plus" @click="openNumber()">Add number</UiButton>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead><tr><th>Number</th><th>Name</th><th>Type</th><th>Capabilities</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="n in numbers" :key="n.id">
                            <td class="font-mono text-xs">{{ n.phone_number }}</td>
                            <td class="text-sm">{{ n.display_name }} <UiBadge v-if="n.is_default" color="indigo">Default</UiBadge></td>
                            <td class="text-xs">{{ n.number_type }}</td>
                            <td class="text-2xs text-slate-600">{{ [n.supports_inbound && 'Inbound', n.supports_outbound && 'Outbound', n.supports_webrtc && 'WebRTC'].filter(Boolean).join(' · ') }}</td>
                            <td><UiBadge :color="n.is_active ? 'green' : 'slate'">{{ n.is_active ? 'Active' : 'Inactive' }}</UiBadge></td>
                            <td class="text-right"><UiButton size="sm" variant="ghost" icon="edit" @click="openNumber(n)">Edit</UiButton></td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-if="!numbers.length" icon="phone" title="No numbers yet" description="Add your provider virtual number (ExoPhone) or sync it from the provider." />
            </div>
        </div>

        <!-- Agents -->
        <div class="panel mt-4">
            <div class="panel-header">
                <h2 class="panel-title">Agents</h2>
                <UiButton size="sm" icon="plus" @click="openAgent()">Add agent</UiButton>
            </div>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead><tr><th>User</th><th>Provider user ID</th><th>SIP username</th><th>Registered phone</th><th>Mode</th><th>Last registered</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="a in agents" :key="a.id">
                            <td>
                                <p class="text-sm font-medium text-slate-800">{{ a.name }}</p>
                                <p class="text-2xs text-slate-500">{{ a.email }}<template v-if="!a.user_active"> · <span class="text-red-600">user inactive</span></template></p>
                            </td>
                            <td class="font-mono text-xs">{{ a.provider_user_id ?? '—' }}</td>
                            <td class="font-mono text-xs">{{ a.provider_sip_username ?? '—' }}</td>
                            <td class="font-mono text-xs">{{ a.registered_phone ?? '—' }}</td>
                            <td class="text-xs">{{ modeLabel(a.calling_mode) }}</td>
                            <td class="text-xs">{{ a.last_registered_at ? timeAgo(a.last_registered_at) : 'never' }}</td>
                            <td><UiBadge :color="a.is_enabled && a.user_active ? 'green' : 'slate'">{{ a.is_enabled ? 'Enabled' : 'Disabled' }}</UiBadge></td>
                            <td class="text-right"><UiButton size="sm" variant="ghost" icon="edit" @click="openAgent(a)">Edit</UiButton></td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-if="!agents.length" icon="user" title="No calling accounts yet" description="Map each salesperson to their provider user (browser calling) and/or their own phone (click-to-call)." />
            </div>
            <p class="border-t border-slate-100 px-4 py-2 text-2xs text-slate-500">SIP passwords are never stored in the CRM. Browser calling uses a per-agent provider identity, not a shared SIP account.</p>
        </div>

        <!-- Dispositions -->
        <div class="panel mt-4">
            <div class="panel-header">
                <h2 class="panel-title">Call dispositions</h2>
                <UiButton size="sm" icon="plus" @click="openDisposition()">Add disposition</UiButton>
            </div>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead><tr><th>Name</th><th>Counts as contact</th><th>Note required</th><th>Next action required</th><th>Calls</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="d in dispositions" :key="d.id">
                            <td><UiBadge :color="d.color">{{ d.name }}</UiBadge> <UiBadge v-if="d.is_system" color="slate">System</UiBadge></td>
                            <td class="text-xs">{{ d.is_contact ? 'Yes' : 'No' }}</td>
                            <td class="text-xs">{{ d.requires_note ? 'Yes' : 'No' }}</td>
                            <td class="text-xs">{{ d.requires_next_action ? 'Yes' : 'No' }}</td>
                            <td class="text-xs">{{ d.calls_count }}</td>
                            <td><UiBadge :color="d.is_active ? 'green' : 'slate'">{{ d.is_active ? 'Active' : 'Inactive' }}</UiBadge></td>
                            <td class="text-right"><UiButton size="sm" variant="ghost" icon="edit" @click="openDisposition(d)">Edit</UiButton></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Permissions -->
        <div class="panel mt-4">
            <div class="panel-header">
                <h2 class="panel-title">Permissions</h2>
                <UiButton size="sm" variant="secondary" :href="route('admin.roles.index')">Manage roles</UiButton>
            </div>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Role</th>
                            <th v-for="k in permissions.keys" :key="k" class="text-center capitalize">{{ permissionLabel(k) }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="r in permissions.roles" :key="r.name">
                            <td class="text-sm font-medium">{{ r.name }}</td>
                            <td v-for="k in permissions.keys" :key="k" class="text-center">
                                <AppIcon v-if="r.bypass || r.granted.includes(k)" name="check" class="mx-auto h-4 w-4 text-emerald-600" />
                                <span v-else class="text-slate-300">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Number modal -->
        <Modal :show="numberModal.show" max-width="md" @close="numberModal.show = false">
            <form @submit.prevent="saveNumber">
                <div class="modal-header"><h3 class="text-sm font-semibold">{{ numberModal.item ? 'Edit number' : 'Add number' }}</h3></div>
                <div class="grid gap-3 p-5 sm:grid-cols-2">
                    <FormField label="Phone number" required :error="numberForm.errors.phone_number"><input v-model="numberForm.phone_number" class="form-input" maxlength="30" /></FormField>
                    <FormField label="Display name" required :error="numberForm.errors.display_name"><input v-model="numberForm.display_name" class="form-input" maxlength="100" /></FormField>
                    <FormField label="Type" :error="numberForm.errors.number_type">
                        <select v-model="numberForm.number_type" class="form-input">
                            <option value="virtual">Virtual</option><option value="toll_free">Toll free</option><option value="mobile">Mobile</option><option value="landline">Landline</option>
                        </select>
                    </FormField>
                    <FormField label="Provider number ID" :error="numberForm.errors.provider_number_id" class="sm:col-span-2"><input v-model="numberForm.provider_number_id" class="form-input" maxlength="100" /></FormField>
                    <label class="flex items-center gap-2 text-sm"><UiToggle v-model="numberForm.supports_inbound" /> Inbound</label>
                    <label class="flex items-center gap-2 text-sm"><UiToggle v-model="numberForm.supports_outbound" /> Outbound</label>
                    <label class="flex items-center gap-2 text-sm"><UiToggle v-model="numberForm.supports_webrtc" /> WebRTC</label>
                    <label class="flex items-center gap-2 text-sm"><UiToggle v-model="numberForm.is_active" /> Active</label>
                    <label class="flex items-center gap-2 text-sm"><UiToggle v-model="numberForm.is_default" /> Default caller ID</label>
                </div>
                <div class="modal-footer">
                    <UiButton variant="secondary" @click="numberModal.show = false">Cancel</UiButton>
                    <UiButton type="submit" :loading="numberForm.processing">Save</UiButton>
                </div>
            </form>
        </Modal>

        <!-- Agent modal -->
        <Modal :show="agentModal.show" max-width="md" @close="agentModal.show = false">
            <form @submit.prevent="saveAgent">
                <div class="modal-header"><h3 class="text-sm font-semibold">{{ agentModal.item ? 'Edit calling account' : 'Add calling account' }}</h3></div>
                <div class="grid gap-3 p-5 sm:grid-cols-2">
                    <FormField label="User" required :error="agentForm.errors.user_id" class="sm:col-span-2">
                        <select v-model="agentForm.user_id" class="form-input" :disabled="!!agentModal.item">
                            <option value="" disabled>Select a user…</option>
                            <option v-for="u in unmappedUsers" :key="u.id" :value="u.id">{{ u.name }} ({{ u.email }})</option>
                        </select>
                    </FormField>
                    <FormField label="Calling mode" required :error="agentForm.errors.calling_mode">
                        <select v-model="agentForm.calling_mode" class="form-input">
                            <option v-for="m in options.modes" :key="m.value" :value="m.value">{{ m.label }}</option>
                        </select>
                    </FormField>
                    <FormField label="Registered phone" :error="agentForm.errors.registered_phone" hint="Rings first for click-to-call.">
                        <input v-model="agentForm.registered_phone" class="form-input" maxlength="30" inputmode="tel" />
                    </FormField>
                    <FormField label="Provider user ID" :error="agentForm.errors.provider_user_id" hint="Required for browser calling."><input v-model="agentForm.provider_user_id" class="form-input" maxlength="100" /></FormField>
                    <FormField label="Provider agent ID" :error="agentForm.errors.provider_agent_id"><input v-model="agentForm.provider_agent_id" class="form-input" maxlength="100" /></FormField>
                    <FormField label="SIP username" :error="agentForm.errors.provider_sip_username" class="sm:col-span-2" hint="Used to route incoming calls to this agent. No password is stored.">
                        <input v-model="agentForm.provider_sip_username" class="form-input" maxlength="150" />
                    </FormField>
                    <label class="flex items-center gap-2 text-sm"><UiToggle v-model="agentForm.is_enabled" /> Enabled</label>
                </div>
                <div class="modal-footer">
                    <UiButton variant="secondary" @click="agentModal.show = false">Cancel</UiButton>
                    <UiButton type="submit" :loading="agentForm.processing">Save</UiButton>
                </div>
            </form>
        </Modal>

        <!-- Disposition modal -->
        <Modal :show="dispositionModal.show" max-width="md" @close="dispositionModal.show = false">
            <form @submit.prevent="saveDisposition">
                <div class="modal-header"><h3 class="text-sm font-semibold">{{ dispositionModal.item ? 'Edit disposition' : 'Add disposition' }}</h3></div>
                <div class="space-y-3 p-5">
                    <FormField label="Name" required :error="dispositionForm.errors.name"><input v-model="dispositionForm.name" class="form-input" maxlength="100" /></FormField>
                    <FormField label="Colour" :error="dispositionForm.errors.color">
                        <div class="flex flex-wrap gap-1.5">
                            <button v-for="c in options.colors" :key="c" type="button" class="rounded ring-offset-1" :class="dispositionForm.color === c ? 'ring-2 ring-brand-500' : ''" @click="dispositionForm.color = c">
                                <UiBadge :color="c">{{ c }}</UiBadge>
                            </button>
                        </div>
                    </FormField>
                    <label class="flex items-center gap-2 text-sm"><UiToggle v-model="dispositionForm.is_contact" /> Counts as a real conversation</label>
                    <label class="flex items-center gap-2 text-sm"><UiToggle v-model="dispositionForm.requires_note" /> Note required</label>
                    <label class="flex items-center gap-2 text-sm"><UiToggle v-model="dispositionForm.requires_next_action" /> Follow-up or meeting required</label>
                    <label class="flex items-center gap-2 text-sm"><UiToggle v-model="dispositionForm.is_active" /> Active</label>
                </div>
                <div class="modal-footer">
                    <UiButton variant="secondary" @click="dispositionModal.show = false">Cancel</UiButton>
                    <UiButton type="submit" :loading="dispositionForm.processing">Save</UiButton>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
