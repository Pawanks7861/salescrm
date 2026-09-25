<script setup>
import Modal from '@/Components/Modal.vue';
import AppIcon from '@/Components/ui/AppIcon.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import FormField from '@/Components/ui/FormField.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import UiToggle from '@/Components/ui/UiToggle.vue';
import { useConfirm } from '@/Composables/useConfirm';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDateTime, timeAgo } from '@/utils/format';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    health: Object,
    setup: Object,
    pages: Array,
    forms: Array,
    sources: Array,
    settings: Array,
    recentFailures: Array,
});

const { confirm } = useConfirm();
const busy = ref(false);
const post = (name, params = {}, data = {}) => {
    busy.value = true;
    router.post(route(name, params), data, { preserveScroll: true, onFinish: () => (busy.value = false) });
};

const tokenState = computed(() => {
    const h = props.health;
    if (!h.connected && h.status === 'disconnected') return { label: 'Disconnected', color: 'slate' };
    if (h.status === 'needs_reauthorization') return { label: 'Needs Reauthorization', color: 'amber' };
    if (h.status === 'permission_missing') return { label: 'Permission Missing', color: 'amber' };
    if (h.status === 'error') return { label: 'Error', color: 'red' };
    if (h.token_expiring) return { label: 'Token Expiring', color: 'amber' };
    return { label: 'Connected', color: 'green' };
});

const connect = (rerequest = false) => post('admin.integrations.facebook.connect', {}, { rerequest });
const disconnect = async () => {
    if (await confirm({ title: 'Disconnect Meta?', message: 'New Facebook leads will stop arriving. Stored tokens are deleted. Existing leads, enquiries and history are kept.', confirmText: 'Disconnect', danger: true })) {
        post('admin.integrations.facebook.disconnect');
    }
};

const togglePage = (page) => {
    busy.value = true;
    router.put(route('admin.integrations.facebook.pages.update', page.id), { is_selected: !page.is_selected }, { preserveScroll: true, onFinish: () => (busy.value = false) });
};
const toggleForm = (form) => router.put(route('admin.integrations.facebook.forms.update', form.id), { is_enabled: !form.is_enabled }, { preserveScroll: true });
const setFormSource = (form, value) => router.put(route('admin.integrations.facebook.forms.update', form.id), { lead_source_id: value ? Number(value) : null }, { preserveScroll: true });

const syncModal = ref({ show: false, form: null });
const syncForm = useForm({ days: 7 });
const openSync = (form) => {
    syncForm.reset();
    syncModal.value = { show: true, form };
};
const submitSync = () => syncForm.post(route('admin.integrations.facebook.forms.sync-leads', syncModal.value.form.id), { preserveScroll: true, onSuccess: () => (syncModal.value.show = false) });

const tokenModal = ref(false);
const tokenForm = useForm({ access_token: '' });
const submitToken = () => tokenForm.post(route('admin.integrations.facebook.manual-token'), { preserveScroll: true, onFinish: () => tokenForm.reset(), onSuccess: () => (tokenModal.value = false) });

const settingsForm = useForm({ settings: Object.fromEntries(props.settings.map((s) => [s.name, s.value])) });
const saveSettings = () => settingsForm.put(route('admin.integrations.facebook.settings'), { preserveScroll: true });

const copied = ref('');
const copy = async (text, key) => {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = key;
        setTimeout(() => (copied.value = ''), 1500);
    } catch {
        /* clipboard unavailable */
    }
};
</script>

<template>
    <AppLayout title="Facebook Integration">
        <PageHeader title="Facebook Lead Ads" subtitle="Receive Meta Instant Form leads (Facebook and Instagram) directly into the CRM.">
            <template #actions>
                <UiButton size="sm" variant="secondary" icon="list" :href="route('admin.integrations.facebook.events.index')">Webhook events</UiButton>
            </template>
        </PageHeader>

        <div v-if="!setup.app_configured || !setup.verify_token_configured" class="mb-4 flex gap-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
            <AppIcon name="warning" class="h-4 w-4 shrink-0" />
            <div>
                The Meta app is not fully configured on the server. Set
                <code v-if="!setup.app_configured">META_APP_ID / META_APP_SECRET</code>
                <code v-if="!setup.verify_token_configured">META_WEBHOOK_VERIFY_TOKEN</code>
                in the environment (never in the database or this screen).
            </div>
        </div>

        <!-- Health overview -->
        <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="panel p-4">
                <div class="text-2xs font-medium uppercase tracking-wide text-slate-500">Connection</div>
                <div class="mt-1 flex items-center gap-2"><UiBadge :color="tokenState.color" dot>{{ tokenState.label }}</UiBadge></div>
                <p class="mt-1 truncate text-xs text-slate-500">{{ health.account_name || 'No account connected' }}</p>
            </div>
            <div class="panel p-4">
                <div class="text-2xs font-medium uppercase tracking-wide text-slate-500">Pages / forms</div>
                <div class="mt-1 text-lg font-semibold text-slate-900">{{ health.pages_connected }} <span class="text-sm font-normal text-slate-500">pages</span> · {{ health.forms_enabled }} <span class="text-sm font-normal text-slate-500">forms</span></div>
                <p v-if="health.pages_unsubscribed" class="text-xs text-amber-700">{{ health.pages_unsubscribed }} page(s) not subscribed to leads</p>
            </div>
            <div class="panel p-4">
                <div class="text-2xs font-medium uppercase tracking-wide text-slate-500">Webhook</div>
                <div class="mt-1"><UiBadge :color="health.webhook_healthy ? 'green' : 'amber'" dot>{{ health.webhook_healthy ? 'Healthy' : 'Attention needed' }}</UiBadge></div>
                <p class="mt-1 text-xs text-slate-500">Last delivery: {{ health.last_webhook_at ? timeAgo(health.last_webhook_at) : 'never' }}</p>
            </div>
            <div class="panel p-4">
                <div class="text-2xs font-medium uppercase tracking-wide text-slate-500">Leads & failures</div>
                <p class="mt-1 text-xs text-slate-600">Last lead: {{ health.last_lead_at ? timeAgo(health.last_lead_at) : 'never' }}</p>
                <Link :href="route('admin.integrations.facebook.events.index', { status: 'failed' })" class="text-xs" :class="health.failed_events ? 'font-medium text-red-600' : 'text-slate-500'">
                    {{ health.failed_events }} failed event(s){{ health.failed_events_recent ? ` · ${health.failed_events_recent} in 24h` : '' }}
                </Link>
            </div>
        </div>

        <div class="grid gap-4 xl:grid-cols-[1fr_380px]">
            <div class="min-w-0 space-y-4">
                <!-- Pages -->
                <div class="panel">
                    <div class="panel-header">
                        <h2 class="panel-title">Pages</h2>
                        <UiButton size="sm" variant="secondary" icon="refresh" :disabled="!health.connected || busy" @click="post('admin.integrations.facebook.pages.refresh')">Refresh Pages</UiButton>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Page</th>
                                    <th>Subscription</th>
                                    <th>Forms</th>
                                    <th>Receive leads</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="p in pages" :key="p.id" :class="!p.is_active ? 'opacity-60' : ''">
                                    <td>
                                        <div class="flex items-center gap-2">
                                            <img v-if="p.picture_url" :src="p.picture_url" alt="" class="h-7 w-7 rounded" referrerpolicy="no-referrer" />
                                            <div class="min-w-0">
                                                <div class="truncate font-medium text-slate-800">{{ p.page_name }}</div>
                                                <div class="text-2xs text-slate-500">{{ p.category || 'Page' }} · {{ p.page_id }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <UiBadge v-if="!p.is_active" color="slate">No longer accessible</UiBadge>
                                        <UiBadge v-else-if="p.is_subscribed" color="green" dot>Subscribed</UiBadge>
                                        <UiBadge v-else-if="p.is_selected" color="amber" dot>Not subscribed</UiBadge>
                                        <span v-else class="text-xs text-slate-400">—</span>
                                        <p v-if="p.subscription_error" class="mt-0.5 max-w-xs text-2xs text-red-600">{{ p.subscription_error }}</p>
                                    </td>
                                    <td class="text-xs">{{ p.forms_count }}</td>
                                    <td><UiToggle :model-value="p.is_selected" :disabled="busy || !health.connected || !p.is_active" @update:model-value="togglePage(p)" /></td>
                                    <td class="text-right">
                                        <UiButton size="sm" variant="ghost" icon="refresh" :disabled="busy || !p.is_active || !health.connected" @click="post('admin.integrations.facebook.forms.refresh', p.id)">Load forms</UiButton>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <EmptyState v-if="!pages.length" icon="facebook" title="No Pages yet" :description="health.connected ? 'Click “Refresh Pages” to load the Pages you manage.' : 'Connect a Meta account to list your Pages.'" />
                    </div>
                </div>

                <!-- Forms -->
                <div class="panel">
                    <div class="panel-header"><h2 class="panel-title">Lead forms</h2></div>
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Form</th>
                                    <th>Lead source</th>
                                    <th>Mapping</th>
                                    <th>Leads</th>
                                    <th>Enabled</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="f in forms" :key="f.id">
                                    <td>
                                        <div class="font-medium text-slate-800">{{ f.form_name }}</div>
                                        <div class="text-2xs text-slate-500">
                                            {{ f.page_name }} · {{ f.form_id }}
                                            <UiBadge v-if="f.status && f.status !== 'ACTIVE'" color="slate" class="ml-1">{{ f.status.toLowerCase() }}</UiBadge>
                                        </div>
                                    </td>
                                    <td>
                                        <select class="form-input w-40 py-1 text-xs" :value="f.lead_source_id ?? ''" @change="setFormSource(f, $event.target.value)">
                                            <option value="">Automatic (Facebook / Instagram)</option>
                                            <option v-for="s in sources" :key="s.id" :value="s.id">{{ s.name }}</option>
                                        </select>
                                    </td>
                                    <td class="text-xs">
                                        <span class="text-emerald-700">{{ f.mapping.mapped }} mapped</span>
                                        <span v-if="f.mapping.unmapped" class="text-slate-500"> · {{ f.mapping.unmapped }} enquiry-only</span>
                                        <span v-if="f.mapping.invalid" class="text-red-600"> · {{ f.mapping.invalid }} invalid</span>
                                    </td>
                                    <td class="text-xs">
                                        {{ f.leads_count }}
                                        <div v-if="f.last_lead_at" class="text-2xs text-slate-500">{{ timeAgo(f.last_lead_at) }}</div>
                                    </td>
                                    <td><UiToggle :model-value="f.is_enabled" @update:model-value="toggleForm(f)" /></td>
                                    <td class="text-right">
                                        <div class="flex justify-end gap-1">
                                            <UiButton size="sm" variant="ghost" icon="adjustments" :href="route('admin.integrations.facebook.forms.mapping', f.id)">Mapping</UiButton>
                                            <UiButton size="sm" variant="ghost" icon="inbox" :disabled="!f.is_enabled || !f.page_receiving || !health.connected" @click="openSync(f)">Sync leads</UiButton>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <EmptyState v-if="!forms.length" icon="document" title="No forms yet" description="Enable a Page and click “Load forms”. New forms are also discovered automatically when a lead arrives." />
                    </div>
                </div>

                <!-- Recent failures -->
                <div v-if="recentFailures.length" class="panel">
                    <div class="panel-header">
                        <h2 class="panel-title">Recent failures</h2>
                        <Link :href="route('admin.integrations.facebook.events.index', { status: 'failed' })" class="text-xs text-brand-600 hover:underline">View all</Link>
                    </div>
                    <ul class="divide-y divide-slate-100">
                        <li v-for="e in recentFailures" :key="e.id" class="px-4 py-2.5 text-xs">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-mono text-slate-700">{{ e.leadgen_id }}</span>
                                <span class="text-slate-500">{{ formatDateTime(e.failed_at) }}</span>
                            </div>
                            <p class="mt-0.5 text-red-600"><UiBadge color="red">{{ e.category }}</UiBadge> {{ e.message }}</p>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="space-y-4">
                <!-- Connection -->
                <div class="panel">
                    <div class="panel-header"><h2 class="panel-title">Connection</h2></div>
                    <div class="space-y-4 p-5 text-xs text-slate-600">
                        <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5">
                            <dt class="text-slate-500">Status</dt>
                            <dd><UiBadge :color="tokenState.color">{{ tokenState.label }}</UiBadge></dd>
                            <dt class="text-slate-500">Account</dt>
                            <dd class="truncate">{{ health.account_name || '—' }}</dd>
                            <dt class="text-slate-500">Graph API</dt>
                            <dd>{{ health.graph_version }}</dd>
                            <dt class="text-slate-500">Token</dt>
                            <dd>{{ health.token_type ? health.token_type.replace('_', ' ') : '—' }}<span v-if="health.token_expires_at"> · expires {{ formatDateTime(health.token_expires_at) }}</span></dd>
                            <dt class="text-slate-500">Last check</dt>
                            <dd>{{ health.last_verified_at ? timeAgo(health.last_verified_at) : '—' }}</dd>
                        </dl>
                        <div v-if="health.missing_scopes.length" class="rounded-md bg-amber-50 p-2 text-amber-800">Missing permissions: {{ health.missing_scopes.join(', ') }}</div>
                        <div v-if="health.last_error" class="rounded-md bg-red-50 p-2 text-red-700">{{ health.last_error }}<span v-if="health.last_error_at" class="block text-2xs opacity-75">{{ formatDateTime(health.last_error_at) }}</span></div>
                        <div class="flex flex-wrap gap-2 pt-1">
                            <UiButton v-if="!health.connected" size="sm" icon="link" :disabled="!setup.app_configured || busy" @click="connect()">Connect with Facebook</UiButton>
                            <template v-else>
                                <UiButton size="sm" variant="secondary" icon="check" :disabled="busy" @click="post('admin.integrations.facebook.test')">Test connection</UiButton>
                                <UiButton size="sm" variant="secondary" icon="refresh" :disabled="busy" @click="connect(true)">Reconnect</UiButton>
                                <UiButton size="sm" variant="ghost" icon="ban" :disabled="busy" @click="disconnect">Disconnect</UiButton>
                            </template>
                            <UiButton v-if="health.status === 'disconnected' && health.account_name" size="sm" variant="secondary" icon="refresh" :disabled="!setup.app_configured || busy" @click="connect(true)">Reconnect</UiButton>
                        </div>
                    </div>
                </div>

                <!-- Webhook setup -->
                <div class="panel">
                    <div class="panel-header"><h2 class="panel-title">Meta app setup</h2></div>
                    <div class="space-y-2.5 p-4 text-xs text-slate-600">
                        <div>
                            <div class="mb-0.5 text-slate-500">Webhook callback URL (object: Page, field: leadgen)</div>
                            <div class="flex items-center gap-1">
                                <code class="min-w-0 flex-1 truncate rounded bg-slate-100 px-1.5 py-1">{{ setup.webhook_url }}</code>
                                <button class="icon-btn" title="Copy" @click="copy(setup.webhook_url, 'hook')"><AppIcon :name="copied === 'hook' ? 'check' : 'duplicate'" class="h-4 w-4" /></button>
                            </div>
                        </div>
                        <div>
                            <div class="mb-0.5 text-slate-500">OAuth redirect URI</div>
                            <div class="flex items-center gap-1">
                                <code class="min-w-0 flex-1 truncate rounded bg-slate-100 px-1.5 py-1">{{ setup.redirect_uri }}</code>
                                <button class="icon-btn" title="Copy" @click="copy(setup.redirect_uri, 'oauth')"><AppIcon :name="copied === 'oauth' ? 'check' : 'duplicate'" class="h-4 w-4" /></button>
                            </div>
                        </div>
                        <p>Verify token: <UiBadge :color="setup.verify_token_configured ? 'green' : 'amber'">{{ setup.verify_token_configured ? 'configured in environment' : 'not configured' }}</UiBadge></p>
                        <p class="text-2xs text-slate-500">Leads are processed on the <code>{{ setup.queue }}</code> queue — the worker must run <code>queue:work --queue=default,{{ setup.queue }}</code>.</p>
                        <div v-if="setup.manual_token_allowed" class="border-t border-dashed border-slate-200 pt-2.5">
                            <div class="mb-1 text-2xs font-semibold uppercase tracking-wide text-slate-500">Development / Advanced</div>
                            <UiButton size="sm" variant="ghost" icon="key" @click="tokenModal = true">Use a system-user token</UiButton>
                        </div>
                    </div>
                </div>

                <!-- Settings -->
                <form class="panel" @submit.prevent="saveSettings">
                    <div class="panel-header"><h2 class="panel-title">Lead handling</h2></div>
                    <div class="space-y-4 p-5">
                        <template v-for="s in settings" :key="s.key">
                            <label v-if="s.type === 'boolean'" class="flex items-center justify-between gap-3 text-sm text-slate-700">
                                <span>{{ s.label }}</span>
                                <UiToggle v-model="settingsForm.settings[s.name]" />
                            </label>
                            <FormField v-else :label="s.label" :error="settingsForm.errors[`settings.${s.name}`]">
                                <input v-if="s.type === 'string'" v-model="settingsForm.settings[s.name]" type="text" class="form-input" maxlength="100" />
                                <input v-else v-model.number="settingsForm.settings[s.name]" type="number" class="form-input w-32" />
                            </FormField>
                        </template>
                        <p class="rounded-md bg-slate-50 p-2.5 text-2xs text-slate-600">Duplicate handling (merge / flag / allow) and assignment follow the global lead settings and assignment rules — use the “Facebook form” rule condition to route specific forms.</p>
                    </div>
                    <div class="flex justify-end bg-slate-50 px-4 py-3">
                        <UiButton type="submit" :loading="settingsForm.processing">Save</UiButton>
                    </div>
                </form>
            </div>
        </div>

        <Modal :show="syncModal.show" max-width="sm" @close="syncModal.show = false">
            <form @submit.prevent="submitSync">
                <div class="modal-header"><h3 class="text-sm font-semibold">Sync recent leads</h3></div>
                <div class="space-y-3 p-5 text-sm">
                    <p class="text-xs text-slate-600">Fetches leads from “{{ syncModal.form?.form_name }}” in the background. Leads already in the CRM are skipped; Meta keeps leads for 90 days.</p>
                    <FormField label="Days to look back" :error="syncForm.errors.days">
                        <input v-model.number="syncForm.days" type="number" min="1" max="90" class="form-input w-28" />
                    </FormField>
                </div>
                <div class="modal-footer">
                    <UiButton variant="secondary" @click="syncModal.show = false">Cancel</UiButton>
                    <UiButton type="submit" :loading="syncForm.processing">Queue sync</UiButton>
                </div>
            </form>
        </Modal>

        <Modal :show="tokenModal" max-width="md" @close="tokenModal = false">
            <form autocomplete="off" @submit.prevent="submitToken">
                <div class="modal-header"><h3 class="text-sm font-semibold">Connect with a system-user token</h3></div>
                <div class="space-y-3 p-5">
                    <p class="text-xs text-slate-600">Super Admin only. The token is verified with Meta, stored encrypted and never shown again.</p>
                    <FormField label="Access token" :error="tokenForm.errors.access_token">
                        <input v-model="tokenForm.access_token" type="password" class="form-input font-mono" autocomplete="off" spellcheck="false" />
                    </FormField>
                </div>
                <div class="modal-footer">
                    <UiButton variant="secondary" @click="tokenModal = false">Cancel</UiButton>
                    <UiButton type="submit" :loading="tokenForm.processing">Verify & connect</UiButton>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
