<script setup>
import MeetingFormModal from '@/Components/meetings/MeetingFormModal.vue';
import MeetingRescheduleModal from '@/Components/meetings/MeetingRescheduleModal.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { addMinutes, crmParts } from '@/utils/format';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin from '@fullcalendar/interaction';
import listPlugin from '@fullcalendar/list';
import timeGridPlugin from '@fullcalendar/timegrid';
import FullCalendar from '@fullcalendar/vue3';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';

/**
 * Meeting calendar. Events are range-loaded from calendar.events (visible
 * meetings only, filtered server-side). The calendar runs in UTC-coercion
 * mode: the feed sends CRM-timezone wall-clock times without an offset, so
 * rendering, "now" and drag results are all in the CRM timezone.
 */
const props = defineProps({
    options: Object,
    can: Object,
});

// Event fills tuned for the dark theme (see resources/css/theme.css).
const COLORS = {
    slate: '#4a5573',
    gray: '#4a5573',
    blue: '#2f7fc4',
    sky: '#2f8fcf',
    indigo: '#5457e6',
    purple: '#7c4fd6',
    violet: '#7c4fd6',
    pink: '#c2477f',
    red: '#c9384f',
    orange: '#c86a2e',
    amber: '#b8862a',
    yellow: '#b8862a',
    green: '#239a74',
    emerald: '#239a74',
    teal: '#1f9687',
    cyan: '#2a93b0',
};

const filters = reactive({ scope: '', host: '', type: '', hide_cancelled: true });
const loading = ref(false);
const loadError = ref('');
const calendar = ref(null);
const isMobile = typeof window !== 'undefined' && window.innerWidth < 768;

const canCreate = computed(() => props.can.create);

// "YYYY-MM-DDTHH:mm:ss" of a coerced (UTC) calendar Date = CRM wall-clock time.
const wall = (d) => d.toISOString().slice(0, 19);
const parts = (d) => ({ date: wall(d).slice(0, 10), time: wall(d).slice(11, 16) });

const fetchEvents = (info, success, failure) => {
    loading.value = true;
    loadError.value = '';
    const params = { start: wall(info.start), end: wall(info.end), hide_cancelled: filters.hide_cancelled ? 1 : 0 };
    ['scope', 'host', 'type'].forEach((k) => filters[k] && (params[k] = filters[k]));
    axios
        .get(route('calendar.events'), { params })
        .then(({ data }) =>
            success(
                data.data.map((e) => {
                    const color = COLORS[e.extendedProps.color] || COLORS.slate;
                    const cancelled = ['cancelled', 'no_show'].includes(e.extendedProps.status);
                    return {
                        ...e,
                        backgroundColor: cancelled ? 'transparent' : color,
                        borderColor: color,
                        textColor: cancelled ? '#a5aec4' : '#ffffff',
                        classNames: cancelled ? ['crm-event-muted'] : [],
                    };
                }),
            ),
        )
        .catch((error) => {
            loadError.value = error.response?.data?.message || 'Could not load meetings.';
            failure(error);
        })
        .finally(() => (loading.value = false));
};

const refetch = () => calendar.value?.getApi().refetchEvents();
watch(filters, refetch);

// Create from an empty slot / day.
const creating = ref(false);
const preset = ref(null);
const openCreate = (start, end, allDay) => {
    if (!canCreate.value) return;
    const s = parts(start);
    if (allDay) {
        const slot = { date: s.date, time: '10:00' };
        const e = addMinutes(slot.date, slot.time, props.options.default_duration || 30);
        preset.value = { date: slot.date, start_time: slot.time, end_time: e.time };
    } else {
        const e = end ? parts(end) : addMinutes(s.date, s.time, props.options.default_duration || 30);
        preset.value = { date: s.date, start_time: s.time, end_time: e.time };
    }
    creating.value = true;
};

// Drag / resize → reschedule workflow (confirmed in the modal, reverted on cancel).
const rescheduling = ref(null);
let pendingRevert = null;
const onMove = (info) => {
    const ev = info.event;
    const start = parts(ev.start);
    const end = ev.end ? parts(ev.end) : addMinutes(start.date, start.time, ev.extendedProps.duration_minutes || 30);
    pendingRevert = info.revert;
    rescheduling.value = {
        meeting: {
            id: ev.id,
            title: ev.title,
            meeting_number: ev.extendedProps.meeting_number,
            start_at: ev.extendedProps.start_at,
            end_at: ev.extendedProps.end_at,
            duration_minutes: ev.extendedProps.duration_minutes,
        },
        preset: { date: start.date, start_time: start.time, end_time: end.time, end_date: end.date !== start.date ? end.date : '' },
    };
};
const closeReschedule = () => {
    pendingRevert?.();
    pendingRevert = null;
    rescheduling.value = null;
};
const rescheduled = () => {
    pendingRevert = null;
    rescheduling.value = null;
    refetch();
};

const calendarOptions = computed(() => ({
    plugins: [dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin],
    initialView: isMobile ? 'listWeek' : 'timeGridWeek',
    timeZone: 'UTC',
    now: () => {
        const p = crmParts(new Date());
        return `${p.date}T${p.time}:00`;
    },
    headerToolbar: isMobile
        ? { left: 'prev,next', center: 'title', right: 'listWeek,timeGridDay' }
        : { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek' },
    buttonText: { today: 'Today', month: 'Month', week: 'Week', day: 'Day', list: 'Agenda' },
    height: 'auto',
    nowIndicator: true,
    firstDay: 1,
    slotMinTime: '07:00:00',
    slotMaxTime: '22:00:00',
    scrollTime: '08:00:00',
    slotDuration: '00:30:00',
    allDaySlot: false,
    dayMaxEvents: 4,
    eventTimeFormat: { hour: 'numeric', minute: '2-digit', meridiem: 'short' },
    events: fetchEvents,
    selectable: canCreate.value,
    selectMirror: true,
    select: (info) => {
        openCreate(info.start, info.view.type.startsWith('dayGrid') ? null : info.end, info.view.type.startsWith('dayGrid'));
        info.view.calendar.unselect();
    },
    editable: true,
    eventStartEditable: true,
    eventDurationEditable: true,
    eventDrop: onMove,
    eventResize: onMove,
    eventClick: (info) => {
        info.jsEvent.preventDefault();
        router.visit(info.event.extendedProps.url);
    },
    noEventsContent: 'No meetings in this period',
}));
</script>

<template>
    <AppLayout title="Calendar">
        <PageHeader title="Calendar" subtitle="Meetings you can see · times in the CRM timezone">
            <template #actions>
                <UiButton :href="route('meetings.index')" variant="secondary" icon="list">List</UiButton>
                <UiButton v-if="canCreate" icon="plus" @click="(preset = null), (creating = true)">Schedule meeting</UiButton>
            </template>
        </PageHeader>

        <div class="panel">
            <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-4">
                <select v-if="can.filterByUser" v-model="filters.scope" class="form-input w-32">
                    <option value="">All visible</option>
                    <option value="mine">Mine only</option>
                </select>
                <select v-if="options.users?.length" v-model="filters.host" class="form-input w-36">
                    <option value="">Any host</option>
                    <option v-for="u in options.users" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
                <select v-model="filters.type" class="form-input w-40">
                    <option value="">Any type</option>
                    <option v-for="t in options.types" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>
                <label class="flex items-center gap-1.5 text-xs text-slate-600">
                    <input v-model="filters.hide_cancelled" type="checkbox" class="rounded border-slate-300" />
                    Hide cancelled
                </label>
                <span v-if="loading" class="text-2xs text-slate-400">Loading…</span>
                <span v-if="loadError" class="form-error">{{ loadError }}</span>
                <span class="ml-auto hidden text-2xs text-slate-400 md:inline">
                    <template v-if="canCreate">Click an empty slot to schedule · </template>drag a meeting to reschedule
                </span>
            </div>
            <div class="crm-calendar p-3">
                <FullCalendar ref="calendar" :options="calendarOptions">
                    <template #eventContent="arg">
                        <div class="overflow-hidden px-1 py-0.5 text-2xs leading-tight">
                            <p class="truncate font-semibold">
                                <span v-if="arg.timeText">{{ arg.timeText }} · </span>{{ arg.event.title }}
                            </p>
                            <p v-if="arg.event.extendedProps.lead" class="truncate opacity-90">{{ arg.event.extendedProps.lead }}<template v-if="arg.event.extendedProps.lead_id"> · ID {{ arg.event.extendedProps.lead_id }}</template></p>
                            <p class="truncate opacity-75">
                                {{ arg.event.extendedProps.type }}<template v-if="arg.event.extendedProps.host"> · {{ arg.event.extendedProps.host }}</template>
                                <template v-if="!['scheduled', 'confirmed'].includes(arg.event.extendedProps.status)"> · {{ arg.event.extendedProps.status_label }}</template>
                            </p>
                        </div>
                    </template>
                </FullCalendar>
            </div>
        </div>

        <MeetingFormModal :show="creating" :options="options" :preset="preset" @close="(creating = false), refetch()" />
        <MeetingRescheduleModal
            :show="!!rescheduling"
            :meeting="rescheduling?.meeting"
            :preset="rescheduling?.preset"
            :can-override="options.can_override_conflict"
            stay
            @done="rescheduled"
            @close="closeReschedule"
        />
    </AppLayout>
</template>