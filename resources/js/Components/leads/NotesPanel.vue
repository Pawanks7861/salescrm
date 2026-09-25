<script setup>
import Modal from '@/Components/Modal.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { formatDateTime, initials, timeAgo } from '@/utils/format';
import { router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { ref } from 'vue';

const props = defineProps({
    leadId: { type: Number, required: true },
    notes: { type: Array, required: true },
    visibilities: { type: Array, required: true },
    canAdd: { type: Boolean, default: false },
});

const visibilityMeta = {
    team: { label: 'Shared', color: 'slate', hint: 'Everyone who can see this lead' },
    private: { label: 'Private', color: 'purple', hint: 'Only you (and authorised admins)' },
    management: { label: 'Management', color: 'amber', hint: 'You and users allowed to see management notes' },
};

const form = useForm({ note: '', visibility: 'team' });
const add = () => form.post(route('leads.notes.store', props.leadId), { preserveScroll: true, onSuccess: () => form.reset('note') });

const editing = ref(null);
const editForm = useForm({ note: '', visibility: 'team' });
const startEdit = (note) => {
    editing.value = note.id;
    editForm.note = note.note;
    editForm.visibility = note.visibility;
    editForm.clearErrors();
};
const saveEdit = (note) =>
    editForm.put(route('leads.notes.update', [props.leadId, note.id]), { preserveScroll: true, onSuccess: () => (editing.value = null) });

const { confirm } = useConfirm();
const destroy = async (note) => {
    if (await confirm({ title: 'Delete this note?', message: 'The note will be removed from the lead. Its edit history is kept for audit.', confirmText: 'Delete', danger: true })) {
        router.delete(route('leads.notes.destroy', [props.leadId, note.id]), { preserveScroll: true });
    }
};

const history = ref({ show: false, items: [], loading: false });
const showHistory = async (note) => {
    history.value = { show: true, items: [], loading: true };
    try {
        const { data } = await axios.get(route('leads.notes.history', [props.leadId, note.id]));
        history.value.items = data.data;
    } finally {
        history.value.loading = false;
    }
};
</script>

<template>
    <div class="space-y-4">
        <form v-if="canAdd" class="rounded-md border border-slate-200 p-3" @submit.prevent="add">
            <textarea v-model="form.note" rows="3" class="form-input" placeholder="Add a note…" maxlength="10000" required />
            <p v-if="form.errors.note" class="form-error">{{ form.errors.note }}</p>
            <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <select v-model="form.visibility" class="form-input w-36">
                        <option v-for="v in visibilities" :key="v" :value="v">{{ visibilityMeta[v].label }}</option>
                    </select>
                    <span class="text-2xs text-slate-500">{{ visibilityMeta[form.visibility]?.hint }}</span>
                </div>
                <UiButton type="submit" size="sm" :loading="form.processing" :disabled="!form.note.trim()">Add note</UiButton>
            </div>
        </form>

        <p v-if="!notes.length" class="py-6 text-center text-xs text-slate-500">No notes yet.</p>

        <div v-for="note in notes" :key="note.id" class="flex gap-3">
            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-slate-100 text-2xs font-semibold text-slate-600">{{ initials(note.author?.name ?? '?') }}</span>
            <div class="min-w-0 flex-1 rounded-md border border-slate-200 bg-white p-3">
                <div class="mb-1 flex flex-wrap items-center gap-2 text-2xs text-slate-500">
                    <span class="font-semibold text-slate-700">{{ note.author?.name ?? 'Unknown' }}</span>
                    <span :title="formatDateTime(note.created_at)">{{ timeAgo(note.created_at) }}</span>
                    <UiBadge :color="visibilityMeta[note.visibility]?.color">{{ visibilityMeta[note.visibility]?.label }}</UiBadge>
                    <button v-if="note.edited" class="italic hover:text-slate-700" @click="showHistory(note)">edited{{ note.editor ? ` by ${note.editor.name}` : '' }}</button>
                    <span class="ml-auto flex gap-1">
                        <button v-if="note.can.update && editing !== note.id" class="text-slate-500 hover:text-brand-700" @click="startEdit(note)">Edit</button>
                        <button v-if="note.can.delete" class="text-slate-500 hover:text-red-600" @click="destroy(note)">Delete</button>
                    </span>
                </div>
                <form v-if="editing === note.id" @submit.prevent="saveEdit(note)">
                    <textarea v-model="editForm.note" rows="3" class="form-input" maxlength="10000" required />
                    <p v-if="editForm.errors.note" class="form-error">{{ editForm.errors.note }}</p>
                    <div class="mt-2 flex items-center justify-end gap-2">
                        <select v-model="editForm.visibility" class="form-input w-32">
                            <option v-for="v in visibilities" :key="v" :value="v">{{ visibilityMeta[v].label }}</option>
                        </select>
                        <UiButton size="sm" variant="secondary" @click="editing = null">Cancel</UiButton>
                        <UiButton size="sm" type="submit" :loading="editForm.processing">Save</UiButton>
                    </div>
                </form>
                <p v-else class="whitespace-pre-line break-words text-sm text-slate-800">{{ note.note }}</p>
            </div>
        </div>

        <Modal :show="history.show" max-width="lg" @close="history.show = false">
            <div class="modal-header"><h3 class="text-sm font-semibold">Note edit history</h3></div>
            <div class="max-h-[60vh] space-y-3 overflow-y-auto p-5">
                <p v-if="history.loading" class="text-xs text-slate-500">Loading…</p>
                <div v-for="h in history.items" :key="h.id" class="rounded border border-slate-200 p-3 text-xs">
                    <p class="mb-2 text-2xs text-slate-500">{{ h.editor }} · {{ formatDateTime(h.created_at) }}<template v-if="h.old_visibility !== h.new_visibility"> · visibility {{ h.old_visibility }} → {{ h.new_visibility }}</template></p>
                    <p class="whitespace-pre-line rounded bg-red-50 p-2 text-red-900 line-through decoration-red-300">{{ h.old_content }}</p>
                    <p class="mt-1 whitespace-pre-line rounded bg-emerald-50 p-2 text-emerald-900">{{ h.new_content }}</p>
                </div>
            </div>
            <div class="flex justify-end bg-slate-50 px-5 py-3"><UiButton variant="secondary" @click="history.show = false">Close</UiButton></div>
        </Modal>
    </div>
</template>
