import { usePage } from '@inertiajs/vue3';

function timezone() {
    try {
        return usePage().props.app?.timezone || undefined;
    } catch {
        return undefined;
    }
}

export function formatDateTime(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat('en-IN', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: timezone(),
    }).format(new Date(value));
}

export function formatDate(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat('en-IN', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        timeZone: timezone(),
    }).format(new Date(value));
}

/** { date: 'YYYY-MM-DD', time: 'HH:MM' } of an instant in the CRM timezone. */
export function crmParts(value = new Date()) {
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat('en-CA', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
            timeZone: timezone(),
        })
            .formatToParts(new Date(value))
            .map((p) => [p.type, p.value]),
    );
    return { date: `${parts.year}-${parts.month}-${parts.day}`, time: `${parts.hour}:${parts.minute}` };
}

/** Default slot for a new follow-up: the next full hour (CRM timezone). */
export function nextSlot(hoursAhead = 1) {
    const d = new Date(Date.now() + hoursAhead * 3600000);
    d.setMinutes(0, 0, 0);
    return crmParts(d);
}

/** "Today, 3:00 pm" / "Tomorrow, 11:00 am" / "26 Sep, 11:00 am" in the CRM timezone. */
export function formatDue(value) {
    if (!value) return '—';
    const tz = timezone();
    const target = crmParts(value).date;
    const today = crmParts().date;
    const tomorrow = crmParts(Date.now() + 86400000).date;
    const yesterday = crmParts(Date.now() - 86400000).date;
    const time = new Intl.DateTimeFormat('en-IN', { hour: 'numeric', minute: '2-digit', timeZone: tz }).format(new Date(value));
    const label =
        target === today
            ? 'Today'
            : target === tomorrow
              ? 'Tomorrow'
              : target === yesterday
                ? 'Yesterday'
                : new Intl.DateTimeFormat('en-IN', { day: 'numeric', month: 'short', timeZone: tz }).format(new Date(value));
    return `${label}, ${time}`;
}

export function formatTime(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat('en-IN', { hour: 'numeric', minute: '2-digit', timeZone: timezone() }).format(new Date(value));
}

/** "Today, 3:00 pm – 4:00 pm" (end date repeated only when it differs). */
export function formatRange(start, end) {
    if (!start) return '—';
    if (!end) return formatDue(start);
    return crmParts(start).date === crmParts(end).date ? `${formatDue(start)} – ${formatTime(end)}` : `${formatDue(start)} – ${formatDue(end)}`;
}

/** Wall-clock arithmetic on a local date + time (no timezone involved). */
export function addMinutes(date, time, minutes) {
    const d = new Date(`${date}T${time}:00Z`);
    if (Number.isNaN(d.getTime())) return { date, time };
    d.setUTCMinutes(d.getUTCMinutes() + Number(minutes || 0));
    const iso = d.toISOString();
    return { date: iso.slice(0, 10), time: iso.slice(11, 16) };
}

/** Minutes between two local date + time pairs. */
export function minutesBetween(startDate, startTime, endDate, endTime) {
    return Math.round((new Date(`${endDate}T${endTime}:00Z`) - new Date(`${startDate}T${startTime}:00Z`)) / 60000);
}

export function durationLabel(minutes) {
    if (!minutes || minutes < 0) return '—';
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return [h ? `${h} h` : '', m ? `${m} min` : ''].filter(Boolean).join(' ');
}

export function timeAgo(value) {
    if (!value) return '—';
    const seconds = Math.round((Date.now() - new Date(value).getTime()) / 1000);
    const units = [
        ['year', 31536000],
        ['month', 2592000],
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];
    const rtf = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });
    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) return rtf.format(-Math.round(seconds / size), unit);
    }
    return 'just now';
}

export function formatCurrency(value) {
    if (value === null || value === undefined || value === '') return '—';
    return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 }).format(Number(value));
}

export function formatBytes(bytes) {
    if (!bytes) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
    return `${(bytes / 1024 ** i).toFixed(i ? 1 : 0)} ${units[i]}`;
}

export function initials(name = '') {
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join('');
}
