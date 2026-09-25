<script setup>
import AppIcon from '@/Components/ui/AppIcon.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { formatBytes, formatDateTime } from '@/utils/format';
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    leadId: { type: Number, required: true },
    attachments: { type: Array, required: true },
    canUpload: Boolean,
    canDownload: Boolean,
    maxKb: Number,
    extensions: Array,
});

const input = ref(null);
const form = useForm({ file: null });

const upload = (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    form.file = file;
    form.post(route('leads.attachments.store', props.leadId), {
        preserveScroll: true,
        forceFormData: true,
        onFinish: () => {
            form.reset();
            if (input.value) input.value.value = '';
        },
    });
};

const { confirm } = useConfirm();
const destroy = async (a) => {
    if (await confirm({ title: `Delete ${a.name}?`, message: 'The file will no longer be available on this lead.', confirmText: 'Delete', danger: true })) {
        router.delete(route('leads.attachments.destroy', [props.leadId, a.id]), { preserveScroll: true });
    }
};
</script>

<template>
    <div class="space-y-3">
        <div v-if="canUpload" class="flex flex-wrap items-center gap-3 rounded-md border border-dashed border-slate-300 p-3">
            <input ref="input" type="file" class="hidden" :accept="extensions.map((e) => '.' + e).join(',')" @change="upload" />
            <UiButton size="sm" variant="secondary" icon="paperclip" :loading="form.processing" @click="input.click()">Upload file</UiButton>
            <span class="text-2xs text-slate-500">Max {{ Math.round(maxKb / 1024) }} MB · {{ extensions.join(', ') }}</span>
            <p v-if="form.errors.file" class="form-error w-full">{{ form.errors.file }}</p>
            <div v-if="form.progress" class="h-1 w-full overflow-hidden rounded bg-slate-100">
                <div class="h-full bg-brand-500" :style="{ width: form.progress.percentage + '%' }" />
            </div>
        </div>

        <p v-if="!attachments.length" class="py-6 text-center text-xs text-slate-500">No attachments.</p>

        <table v-else class="data-table">
            <thead>
                <tr>
                    <th>File</th>
                    <th>Size</th>
                    <th>Uploaded by</th>
                    <th>Date</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="a in attachments" :key="a.id">
                    <td class="max-w-xs">
                        <span class="flex items-center gap-1.5 truncate text-sm"><AppIcon name="document" class="h-4 w-4 shrink-0 text-slate-400" />{{ a.name }}</span>
                    </td>
                    <td class="text-xs">{{ formatBytes(a.size) }}</td>
                    <td class="text-xs">{{ a.uploader?.name ?? '—' }}</td>
                    <td class="text-xs text-slate-500">{{ formatDateTime(a.created_at) }}</td>
                    <td class="text-right">
                        <div class="flex justify-end gap-1">
                            <a v-if="canDownload" :href="route('leads.attachments.download', [leadId, a.id])" class="inline-flex items-center gap-1 rounded-md px-2.5 py-1 text-xs font-medium text-slate-600 hover:bg-slate-100">
                                <AppIcon name="download" class="h-4 w-4" /> Download
                            </a>
                            <UiButton v-if="a.can_delete" size="sm" variant="ghost" icon="trash" @click="destroy(a)">Delete</UiButton>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
        <p v-if="attachments.length && !canDownload" class="text-2xs text-slate-500">Downloading files requires additional permission.</p>
    </div>
</template>
