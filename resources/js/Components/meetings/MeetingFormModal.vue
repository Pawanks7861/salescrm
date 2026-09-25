<script setup>
import LeadPicker from '@/Components/followups/LeadPicker.vue';
import ConflictNotice from '@/Components/meetings/ConflictNotice.vue';
import ParticipantPicker from '@/Components/meetings/ParticipantPicker.vue';
import Modal from '@/Components/Modal.vue';
import AppIcon from '@/Components/ui/AppIcon.vue';
import FormField from '@/Components/ui/FormField.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import { addMinutes, durationLabel, minutesBetween, nextSlot } from '@/utils/format';
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

/**
 * Schedule a meeting (fixed lead from Lead 360, picked lead, or no lead when
 * permitted) or edit an upcoming one. An existing meeting's time changes only
 * through Reschedule and its participants on the detail page, so edit mode
 * hides those sections. Host / participants are re-validated on the server.
 */
const props = defineProps({
    show: Boolean,
    options: { type: Object, required: true },
    lead: { type: Object, default: null },
    meeting: { type: Object, default: null },
    preset: { type: Object, default: null }, // { date, start_time, end_time } from a calendar slot
    defaultHostId: { type: Number, default: null },
});
const emit = defineEmits(['close']);

const editing = computed(() => !!props.meeting);
const pickedLead = ref(null);
const participants = ref([]);
const endTouched = ref(false);

const form = useForm({
    lead_id: null,
    title: '',
    meeting_type_id: '',
    host_user_id: '',
    scheduled_date: '',
    start_time: '',
    end_time: '',
    end_date: '',
    timezone: '',
    location_type: 'office',
    location: '',
    address: '',
    meeting_url: '',
    agenda: '',
    description: '',
    priority: 'medium',
    reminders: [],
    participant_user_ids: [],
    include_lead: false,
    external_participants: [],
    override_conflict: false,
});

const typeById = (id) => props.options.types.find((t) => t.id === Number(id));

watch(
    () => props.show,
    (open) => {
        if (!open) return;
        form.clearErrors();
        const m = props.meeting;
        const type = m ? typeById(m.type_id) : props.options.types[0];
        const slot = nextSlot(1);
        const date = props.preset?.date ?? slot.date;
        const start = props.preset?.start_time ?? slot.time;
        const duration = type?.default_duration_minutes ?? props.options.default_duration;
        const end = props.preset?.end_time ? { date, time: props.preset.end_time } : addMinutes(date, start, duration);

        pickedLead.value = props.lead ?? null;
        participants.value = [];
        endTouched.value = !!props.preset?.end_time;
        Object.assign(form, {
            lead_id: props.lead?.id ?? null,
            title: m?.title ?? '',
            meeting_type_id: m?.type_id ?? type?.id ?? '',
            host_user_id: m?.host_user_id ?? props.defaultHostId ?? '',
            scheduled_date: date,
            start_time: start,
            end_time: end.time,
            end_date: end.date !== date ? end.date : '',
            timezone: props.options.crm_timezone,
            location_type: m?.location_type ?? type?.default_location_type ?? 'office',
            location: m?.location ?? '',
            address: m?.address ?? '',
            meeting_url: m?.meeting_url ?? '',
            agenda: m?.agenda ?? '',
            description: m?.description ?? '',
            priority: m?.priority ?? 'medium',
            reminders: m ? [...(m.reminders ?? [])] : [...props.options.default_reminders],
            participant_user_ids: [],
            include_lead: !!props.lead,
            external_participants: [],
            override_conflict: false,
        });
    },
);

watch(pickedLead, (lead) => {
    form.lead_id = lead?.id ?? null;
    if (!lead) form.include_lead = false;
});

watch(
    () => form.meeting_type_id,
    (id, old) => {
        if (!old || editing.value) return;
        const type = typeById(id);
        if (!type) return;
        form.location_type = type.default_location_type;
        if (!endTouched.value) {
            const end = addMinutes(form.scheduled_date, form.start_time, type.default_duration_minutes);
            form.end_time = end.time;
            form.end_date = end.date !== form.scheduled_date ? end.date : '';
        }
    },
);

// Keep the duration when the start moves (until the user edits the end).
watch(
    () => [form.scheduled_date, form.start_time],
    ([date, time], [oldDate, oldTime] = []) => {
        if (editing.value || !oldDate || !oldTime || !date || !time) return;
        const duration = minutesBetween(oldDate, oldTime, form.end_date || oldDate, form.end_time);
        if (duration > 0) {
            const end = addMinutes(date, time, duration);
            form.end_time = end.time;
            form.end_date = end.date !== date ? end.date : '';
        }
    },
);

const duration = computed(() => minutesBetween(form.scheduled_date, form.start_time, form.end_date || form.scheduled_date, form.end_time));
const currentLead = computed(() => props.lead ?? pickedLead.value ?? props.meeting?.lead ?? null);
const leadRequired = computed(() => !props.options.can_create_without_lead);
const canPickHost = computed(() => props.options.hosts?.length > 0);
const isOnline = computed(() => form.location_type === 'online');
const isPhysical = computed(() => ['office', 'client_location', 'site', 'other'].includes(form.location_type));

const toggleReminder = (value) => {
    form.reminders = form.reminders.includes(value) ? form.reminders.filter((v) => v !== value) : [...form.reminders, value];
};
const addExternal = () => form.external_participants.push({ name: '', email: '', phone: '' });
const removeExternal = (i) => form.external_participants.splice(i, 1);

const firstParticipantError = computed(() => Object.entries(form.errors).find(([k]) => k.startsWith('participant_user_ids'))?.[1]);

const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => emit('close') };
    const payload = (data) => ({
        ...data,
        host_user_id: data.host_user_id || null,
        end_date: data.end_date || null,
        participant_user_ids: participants.value.map((u) => u.id),
        external_participants: data.external_participants.filter((p) => p.name.trim() !== ''),
    });

    if (editing.value) {
        form.transform((data) => {
            const { lead_id, scheduled_date, start_time, end_time, end_date, timezone, participant_user_ids, include_lead, external_participants, ...rest } = payload(data);
            return rest;
        }).put(route('meetings.update', props.meeting.id), opts);
    } else {
        form.transform(payload).post(route('meetings.store'), opts);
    }
};
</script>

<template>
    <Modal :show="show" max-width="2xl" @close="emit('close')">
        <form @submit.prevent="submit">
            <div class="modal-header">
                <h3 class="text-sm font-semibold">{{ editing ? `Edit ${meeting.meeting_number}` : 'Schedule meeting' }}</h3>
                <p v-if="currentLead" class="text-2xs text-slate-500">{{ currentLead.full_name }} · {{ currentLead.lead_number }}</p>
            </div>

            <div class="max-h-[70vh] space-y-5 overflow-y-auto p-5">
                <!-- Details -->
                <section class="grid gap-3 sm:grid-cols-2">
                    <h4 class="text-2xs font-semibold uppercase tracking-wide text-slate-500 sm:col-span-2">Meeting details</h4>
                    <FormField v-if="!editing && !lead" label="Lead" :required="leadRequired" :error="form.errors.lead_id" class="sm:col-span-2" :hint="leadRequired ? '' : 'Optional — leave empty for an internal meeting.'">
                        <LeadPicker v-model="pickedLead" />
                    </FormField>
                    <FormField label="Title" :error="form.errors.title" class="sm:col-span-2" hint="Defaults to the meeting type.">
                        <input v-model="form.title" type="text" class="form-input" maxlength="191" placeholder="e.g. Product demo – home loan plans" />
                    </FormField>
                    <FormField label="Meeting type" required :error="form.errors.meeting_type_id">
                        <select v-model="form.meeting_type_id" class="form-input" required>
                            <option v-for="t in options.types" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                    </FormField>
                    <FormField label="Priority" :error="form.errors.priority">
                        <select v-model="form.priority" class="form-input">
                            <option v-for="p in options.priorities" :key="p.value" :value="p.value">{{ p.label }}</option>
                        </select>
                    </FormField>
                    <FormField v-if="canPickHost" label="Host" :error="form.errors.host_user_id">
                        <select v-model="form.host_user_id" class="form-input">
                            <option value="">Me</option>
                            <option v-for="u in options.hosts" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </select>
                    </FormField>
                </section>

                <!-- Date & time -->
                <section v-if="!editing" class="grid gap-3 sm:grid-cols-4">
                    <h4 class="text-2xs font-semibold uppercase tracking-wide text-slate-500 sm:col-span-4">Date &amp; time</h4>
                    <FormField label="Date" required :error="form.errors.scheduled_date">
                        <input v-model="form.scheduled_date" type="date" class="form-input" required />
                    </FormField>
                    <FormField label="Start" required :error="form.errors.start_time">
                        <input v-model="form.start_time" type="time" class="form-input" required />
                    </FormField>
                    <FormField label="End" required :error="form.errors.end_time" :hint="duration > 0 ? durationLabel(duration) : ''">
                        <input v-model="form.end_time" type="time" class="form-input" required @input="endTouched = true" />
                    </FormField>
                    <FormField label="Timezone" :error="form.errors.timezone">
                        <select v-model="form.timezone" class="form-input">
                            <option v-for="tz in options.timezones" :key="tz" :value="tz">{{ tz }}</option>
                        </select>
                    </FormField>
                    <FormField v-if="form.end_date || duration <= 0" label="End date" :error="form.errors.end_date" hint="Only for meetings ending on a later day.">
                        <input v-model="form.end_date" type="date" class="form-input" @input="endTouched = true" />
                    </FormField>
                </section>

                <!-- Location -->
                <section class="grid gap-3 sm:grid-cols-2">
                    <h4 class="text-2xs font-semibold uppercase tracking-wide text-slate-500 sm:col-span-2">Location</h4>
                    <FormField label="Location type" :error="form.errors.location_type">
                        <select v-model="form.location_type" class="form-input">
                            <option v-for="l in options.location_types" :key="l.value" :value="l.value">{{ l.label }}</option>
                        </select>
                    </FormField>
                    <FormField v-if="isOnline" label="Online meeting URL" :error="form.errors.meeting_url" hint="Paste the Meet / Zoom / Teams link.">
                        <input v-model="form.meeting_url" type="url" class="form-input" maxlength="500" placeholder="https://" />
                    </FormField>
                    <FormField v-if="isPhysical" label="Location name" :error="form.errors.location">
                        <input v-model="form.location" type="text" class="form-input" maxlength="191" placeholder="e.g. Client Office" />
                    </FormField>
                    <FormField v-if="isPhysical" label="Address" :error="form.errors.address" class="sm:col-span-2">
                        <input v-model="form.address" type="text" class="form-input" maxlength="500" placeholder="e.g. Prahlad Nagar, Ahmedabad" />
                    </FormField>
                </section>

                <!-- Participants -->
                <section v-if="!editing" class="space-y-3">
                    <h4 class="text-2xs font-semibold uppercase tracking-wide text-slate-500">Participants</h4>
                    <FormField label="Internal participants" :error="firstParticipantError">
                        <ParticipantPicker v-model="participants" :lead-id="currentLead?.id ?? null" :exclude="form.host_user_id ? [Number(form.host_user_id)] : []" />
                    </FormField>
                    <label v-if="currentLead" class="flex items-center gap-2 text-sm text-slate-700">
                        <input v-model="form.include_lead" type="checkbox" class="rounded border-slate-300 text-brand-600" />
                        Add lead ({{ currentLead.full_name }}) as participant
                    </label>
                    <div>
                        <div v-for="(p, i) in form.external_participants" :key="i" class="mb-2 grid gap-2 sm:grid-cols-[1fr_1fr_140px_auto]">
                            <input v-model="p.name" type="text" class="form-input" maxlength="191" placeholder="External name" />
                            <input v-model="p.email" type="email" class="form-input" maxlength="191" placeholder="Email (optional)" />
                            <input v-model="p.phone" type="tel" class="form-input" maxlength="30" placeholder="Phone" />
                            <button type="button" class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" title="Remove" @click="removeExternal(i)"><AppIcon name="close" class="h-4 w-4" /></button>
                            <p v-if="form.errors[`external_participants.${i}.name`] || form.errors[`external_participants.${i}.email`]" class="form-error sm:col-span-4">
                                {{ form.errors[`external_participants.${i}.name`] || form.errors[`external_participants.${i}.email`] }}
                            </p>
                        </div>
                        <button type="button" class="text-xs font-medium text-brand-700 hover:underline" @click="addExternal">+ Add external contact</button>
                        <p class="mt-1 text-2xs text-slate-500">External contacts are stored on the meeting only — no user or lead is created and nothing is sent to them.</p>
                    </div>
                </section>

                <!-- Reminders -->
                <section>
                    <h4 class="mb-2 text-2xs font-semibold uppercase tracking-wide text-slate-500">Reminders</h4>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="r in options.reminders"
                            :key="r.value"
                            type="button"
                            class="rounded-full border px-2.5 py-1 text-xs"
                            :class="form.reminders.includes(r.value) ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-slate-300 text-slate-600 hover:bg-slate-50'"
                            @click="toggleReminder(r.value)"
                        >
                            {{ r.label }}
                        </button>
                    </div>
                    <p class="mt-1 text-2xs text-slate-500">{{ form.reminders.length ? 'In-app reminders go to the host and internal participants.' : 'No reminder.' }}</p>
                </section>

                <!-- Agenda -->
                <section class="grid gap-3">
                    <h4 class="text-2xs font-semibold uppercase tracking-wide text-slate-500">Agenda &amp; notes</h4>
                    <FormField label="Agenda" :error="form.errors.agenda">
                        <textarea v-model="form.agenda" rows="2" class="form-input" maxlength="5000" />
                    </FormField>
                    <FormField label="Description" :error="form.errors.description">
                        <textarea v-model="form.description" rows="2" class="form-input" maxlength="5000" />
                    </FormField>
                </section>

                <ConflictNotice v-model:override="form.override_conflict" :errors="form.errors" :can-override="options.can_override_conflict" />
                <p v-if="form.errors.status" class="form-error">{{ form.errors.status }}</p>
            </div>

            <div class="modal-footer">
                <UiButton variant="secondary" @click="emit('close')">Cancel</UiButton>
                <UiButton type="submit" :loading="form.processing" :disabled="!editing && leadRequired && !form.lead_id">
                    {{ editing ? 'Save' : 'Schedule' }}
                </UiButton>
            </div>
        </form>
    </Modal>
</template>
