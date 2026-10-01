<script setup>
import CustomFieldInput from '@/Components/leads/CustomFieldInput.vue';
import AppIcon from '@/Components/ui/AppIcon.vue';
import FormField from '@/Components/ui/FormField.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDate } from '@/utils/format';
import { Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    lead: { type: Object, default: null },
    customFields: Array,
    options: Object,
    can: Object,
});

const isEdit = computed(() => !!props.lead);

const form = useForm({
    first_name: props.lead?.first_name ?? '',
    last_name: props.lead?.last_name ?? '',
    email: props.lead?.email ?? '',
    phone: props.lead?.phone ?? '',
    alternate_phone: props.lead?.alternate_phone ?? '',
    company_name: props.lead?.company_name ?? '',
    designation: props.lead?.designation ?? '',
    city: props.lead?.city ?? '',
    state: props.lead?.state ?? '',
    country: props.lead?.country ?? props.options.defaultCountry ?? '',
    pincode: props.lead?.pincode ?? '',
    ...(props.can.leadValue ? { estimated_value: props.lead?.estimated_value ?? '' } : {}),
    priority: props.lead?.priority ?? 'medium',
    source_id: props.lead?.source_id ?? props.options.sources[0]?.id ?? '',
    campaign_id: props.lead?.campaign_id ?? '',
    status_id: '',
    assigned_to: '',
    confirm_duplicate: false,
    custom_fields: Object.fromEntries(props.customFields.map((f) => [f.slug, f.value ?? (f.type === 'multiselect' ? [] : f.type === 'checkbox' ? false : null)])),
});

const campaigns = computed(() => props.options.campaigns.filter((c) => !c.source_id || !form.source_id || c.source_id === Number(form.source_id)));

// Live duplicate warning (only leads the user can already see are ever returned).
const matches = ref([]);
let dupTimer = null;
const checkDuplicates = () => {
    clearTimeout(dupTimer);
    if (isEdit.value) return;
    const hasInput = form.phone.replace(/\D/g, '').length >= 6 || form.email.includes('@');
    if (!hasInput) {
        matches.value = [];
        return;
    }
    dupTimer = setTimeout(async () => {
        try {
            const { data } = await axios.post(route('leads.duplicate-check'), {
                phone: form.phone || null,
                alternate_phone: form.alternate_phone || null,
                email: form.email || null,
            });
            matches.value = data.matches;
            if (!data.matches.length) form.confirm_duplicate = false;
        } catch {
            matches.value = [];
        }
    }, 450);
};
watch(() => [form.phone, form.alternate_phone, form.email], checkDuplicates);

const submit = () => {
    const payload = (data) => ({
        ...data,
        campaign_id: data.campaign_id || null,
        status_id: data.status_id || null,
        assigned_to: data.assigned_to || null,
        ...(props.can.leadValue ? { estimated_value: data.estimated_value === '' ? null : data.estimated_value } : {}),
    });

    if (isEdit.value) {
        const { status_id, assigned_to, confirm_duplicate, ...rest } = form.data();
        form.transform(() => payload(rest)).put(route('leads.update', props.lead.id), { preserveScroll: true });
    } else {
        form.transform(payload).post(route('leads.store'), { preserveScroll: true });
    }
};
</script>

<template>
    <AppLayout :title="isEdit ? `Edit ID ${lead.id}` : 'New lead'">
        <PageHeader :title="isEdit ? `Edit lead ${lead.id} · ${lead.lead_number}` : 'New lead'">
            <template #breadcrumb>
                <Link :href="route('leads.index')" class="hover:text-slate-700">Leads</Link>
                <template v-if="isEdit"> / <Link :href="route('leads.show', lead.id)" class="hover:text-slate-700">{{ lead.id }} · {{ lead.lead_number }}</Link></template>
            </template>
        </PageHeader>

        <form class="grid gap-4 xl:grid-cols-3" @submit.prevent="submit">
            <div class="space-y-4 xl:col-span-2">
                <!-- Duplicate warning -->
                <div v-if="matches.length || form.errors.duplicate" class="rounded-md border border-amber-300 bg-amber-50 p-3">
                    <div class="flex items-start gap-2">
                        <AppIcon name="warning" class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-amber-900">Possible duplicate{{ matches.length > 1 ? 's' : '' }} found</p>
                            <p v-if="form.errors.duplicate" class="text-xs text-amber-800">{{ form.errors.duplicate }}</p>
                            <ul class="mt-2 divide-y divide-amber-200 text-xs">
                                <li v-for="m in matches" :key="m.id" class="flex flex-wrap items-center gap-2 py-1.5">
                                    <Link :href="route('leads.show', m.id)" class="link font-mono" target="_blank">{{ m.id }} · {{ m.lead_number }}</Link>
                                    <span class="font-medium text-slate-800">{{ m.full_name }}</span>
                                    <UiBadge v-if="m.status" :color="m.status.color">{{ m.status.name }}</UiBadge>
                                    <span class="text-slate-500">Owner: {{ m.assignee ?? 'Unassigned' }} · matched on {{ m.matched_on.replace('_', ' ') }} · {{ formatDate(m.created_at) }}</span>
                                </li>
                            </ul>
                            <label class="mt-2 flex items-center gap-2 text-xs font-medium text-amber-900">
                                <input v-model="form.confirm_duplicate" type="checkbox" class="rounded border-amber-400 text-amber-600" />
                                I have reviewed the matches and still want to create this lead
                            </label>
                        </div>
                    </div>
                </div>

                <section class="panel">
                    <div class="panel-header"><h2 class="panel-title">Contact</h2></div>
                    <div class="grid gap-3 p-4 sm:grid-cols-2">
                        <FormField label="First name" required :error="form.errors.first_name">
                            <input v-model="form.first_name" class="form-input" required maxlength="100" autofocus />
                        </FormField>
                        <FormField label="Last name" :error="form.errors.last_name">
                            <input v-model="form.last_name" class="form-input" maxlength="100" />
                        </FormField>
                        <FormField label="Phone" :error="form.errors.phone" hint="Phone or email is required.">
                            <input v-model="form.phone" class="form-input" maxlength="30" inputmode="tel" placeholder="+91 98765 43210" />
                        </FormField>
                        <FormField label="Alternate phone" :error="form.errors.alternate_phone">
                            <input v-model="form.alternate_phone" class="form-input" maxlength="30" inputmode="tel" />
                        </FormField>
                        <FormField label="Email" :error="form.errors.email" class="sm:col-span-2">
                            <input v-model="form.email" type="email" class="form-input" maxlength="191" />
                        </FormField>
                    </div>
                </section>

                <section class="panel">
                    <div class="panel-header"><h2 class="panel-title">Company & location</h2></div>
                    <div class="grid gap-3 p-4 sm:grid-cols-2">
                        <FormField label="Company" :error="form.errors.company_name">
                            <input v-model="form.company_name" class="form-input" maxlength="150" />
                        </FormField>
                        <FormField label="Designation" :error="form.errors.designation">
                            <input v-model="form.designation" class="form-input" maxlength="100" />
                        </FormField>
                        <FormField label="City" :error="form.errors.city">
                            <input v-model="form.city" class="form-input" maxlength="100" />
                        </FormField>
                        <FormField label="State" :error="form.errors.state">
                            <input v-model="form.state" class="form-input" maxlength="100" />
                        </FormField>
                        <FormField label="Country" :error="form.errors.country">
                            <input v-model="form.country" class="form-input" maxlength="100" />
                        </FormField>
                        <FormField label="Pincode" :error="form.errors.pincode">
                            <input v-model="form.pincode" class="form-input" maxlength="20" />
                        </FormField>
                    </div>
                </section>

                <section v-if="customFields.length" class="panel">
                    <div class="panel-header"><h2 class="panel-title">Additional details</h2></div>
                    <div class="grid gap-3 p-4 sm:grid-cols-2">
                        <CustomFieldInput
                            v-for="field in customFields"
                            :key="field.id"
                            v-model="form.custom_fields[field.slug]"
                            :field="field"
                            :error="form.errors[`custom_fields.${field.slug}`]"
                        />
                    </div>
                </section>
            </div>

            <div class="space-y-4">
                <section class="panel">
                    <div class="panel-header"><h2 class="panel-title">Lead details</h2></div>
                    <div class="space-y-4 p-5">
                        <FormField label="Source" required :error="form.errors.source_id">
                            <select v-model="form.source_id" class="form-input" :disabled="!can.editSource" required>
                                <option v-for="s in options.sources" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </select>
                        </FormField>
                        <FormField label="Campaign" :error="form.errors.campaign_id">
                            <select v-model="form.campaign_id" class="form-input" :disabled="!can.editSource">
                                <option value="">No campaign</option>
                                <option v-for="c in campaigns" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                        </FormField>
                        <FormField v-if="!isEdit" label="Initial status" :error="form.errors.status_id">
                            <select v-model="form.status_id" class="form-input">
                                <option value="">Default</option>
                                <option v-for="s in options.statuses" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </select>
                        </FormField>
                        <FormField label="Priority" required :error="form.errors.priority">
                            <select v-model="form.priority" class="form-input" required>
                                <option v-for="p in options.priorities" :key="p.value" :value="p.value">{{ p.label }}</option>
                            </select>
                        </FormField>
                        <FormField v-if="can.leadValue" label="Estimated value (₹)" :error="form.errors.estimated_value">
                            <input v-model="form.estimated_value" type="number" min="0" step="1" class="form-input" />
                        </FormField>
                    </div>
                </section>

                <section v-if="can.assign" class="panel">
                    <div class="panel-header"><h2 class="panel-title">Assignment</h2></div>
                    <div class="space-y-4 p-5">
                        <FormField label="Owner" hint="Leave empty to apply assignment rules (you become owner if no rule matches)." :error="form.errors.assigned_to">
                            <select v-model="form.assigned_to" class="form-input">
                                <option value="">Automatic</option>
                                <option v-for="u in options.assignees" :key="u.id" :value="u.id">{{ u.name }}</option>
                            </select>
                        </FormField>
                    </div>
                </section>
                <p v-else-if="!isEdit" class="px-1 text-2xs text-slate-500">This lead will be assigned to you.</p>

                <div class="flex justify-end gap-2">
                    <UiButton variant="secondary" :href="isEdit ? route('leads.show', lead.id) : route('leads.index')">Cancel</UiButton>
                    <UiButton type="submit" :loading="form.processing" :disabled="!isEdit && matches.length > 0 && !form.confirm_duplicate">
                        {{ isEdit ? 'Save changes' : 'Create lead' }}
                    </UiButton>
                </div>
            </div>
        </form>
    </AppLayout>
</template>
