/**
 * Compact batch label for table cells: the first `visible` names plus a "+N"
 * counter whose tooltip lists the rest, so many batches never widen a row.
 */
export function batchTags(batches = [], visible = 1) {
    const list = Array.isArray(batches) ? batches.filter((b) => b && b.name) : [];
    const shown = list.slice(0, Math.max(0, visible));
    const hidden = list.slice(shown.length);

    return {
        shown,
        more: hidden.length,
        title: hidden.map((b) => b.name).join(', '),
    };
}

export const batchStatusColor = { active: 'green', inactive: 'amber', archived: 'slate' };

export function batchStatusLabel(status) {
    const value = typeof status === 'object' && status ? status.value : status;
    return value ? value.charAt(0).toUpperCase() + value.slice(1) : '';
}

/** Initial date-input values for the batch form ("" for a missing date). */
export function batchDateFields(batch) {
    const day = (value) => (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}/.test(value) ? value.slice(0, 10) : '');
    return { start_date: day(batch?.start_date), end_date: day(batch?.end_date) };
}

/** Edit-form payload: never sends lead_ids, and an archived batch keeps its status. */
export function batchUpdatePayload({ lead_ids, status, trainers, ...data }, archived = false) {
    return archived ? data : { ...data, status };
}

/**
 * `trainer_ids` from the selected trainer objects. Omitted entirely when the
 * user cannot manage trainers, so saving the form never touches assignments.
 */
export function trainerPayload(trainers, canManage) {
    return canManage ? { trainer_ids: (trainers ?? []).map((t) => t.id) } : {};
}

/** Create-form payload (batch page and lead list): trainer objects become trainer_ids. */
export function batchCreatePayload({ trainers, ...data }, canManageTrainers = false) {
    return { ...data, ...trainerPayload(trainers, canManageTrainers) };
}

/**
 * Compact trainer cell: the first name plus "+N", with every name in the
 * tooltip. Deactivated trainers are marked so history stays readable.
 */
export function trainerSummary(trainers = []) {
    const list = Array.isArray(trainers) ? trainers.filter((t) => t && t.name) : [];
    const label = (t) => (t.active === false ? `${t.name} (inactive)` : t.name);

    return {
        first: list[0] ? label(list[0]) : null,
        more: Math.max(0, list.length - 1),
        title: list.map(label).join('\n'),
    };
}

/**
 * Display-only schedule label derived from the dates and today's CRM date
 * (all "YYYY-MM-DD", compared as strings). Never replaces the batch status.
 */
export function batchPhase(startDate, endDate, today) {
    if (endDate && endDate < today) return { key: 'ended', label: 'Ended', color: 'slate' };
    if (startDate && startDate > today) return { key: 'upcoming', label: 'Upcoming', color: 'blue' };
    if (startDate) return { key: 'ongoing', label: 'Ongoing', color: 'green' };
    return null;
}
