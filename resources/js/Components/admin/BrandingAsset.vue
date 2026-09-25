<script setup>
import UiButton from '@/Components/ui/UiButton.vue';
import { router, useForm } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';

const props = defineProps({
    type: { type: String, required: true },
    label: { type: String, required: true },
    hint: { type: String, default: '' },
    currentUrl: { type: String, default: null },
    fallbackUrl: { type: String, default: null },
    accept: { type: String, required: true },
    canManage: { type: Boolean, default: false },
});

const input = ref(null);
const preview = ref(null);
const form = useForm({ file: null });

const clearPreview = () => {
    if (preview.value) URL.revokeObjectURL(preview.value);
    preview.value = null;
};

const pick = (e) => {
    const file = e.target.files?.[0];
    clearPreview();
    form.clearErrors();
    form.file = file ?? null;
    if (file) preview.value = URL.createObjectURL(file);
};

const cancel = () => {
    clearPreview();
    form.reset();
    if (input.value) input.value.value = '';
};

const upload = () =>
    form.post(route('admin.branding.store', props.type), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: cancel,
    });

const removing = ref(false);
const remove = () =>
    router.delete(route('admin.branding.destroy', props.type), {
        preserveScroll: true,
        onStart: () => (removing.value = true),
        onFinish: () => (removing.value = false),
    });

onBeforeUnmount(clearPreview);
</script>

<template>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
        <div
            class="flex shrink-0 items-center justify-center overflow-hidden rounded-xl border border-dashed border-slate-200 bg-slate-50"
            :class="type === 'logo' ? 'h-24 w-full sm:w-56' : 'h-24 w-24'"
        >
            <img v-if="preview || currentUrl" :src="preview || currentUrl" :alt="`${label} preview`" class="max-h-full max-w-full object-contain p-3" />
            <img v-else-if="fallbackUrl" :src="fallbackUrl" :alt="`Default ${label.toLowerCase()}`" class="h-10 w-10 object-contain opacity-80" />
            <span v-else class="px-3 text-center text-2xs text-slate-400">No {{ label.toLowerCase() }} uploaded</span>
        </div>
        <div class="min-w-0 flex-1">
            <p class="text-sm font-medium text-slate-900">{{ label }}</p>
            <p class="mt-0.5 text-xs text-slate-500">{{ hint }}</p>
            <p v-if="preview" class="mt-1 text-xs text-amber-600">Preview — not saved yet.</p>
            <p v-else-if="!currentUrl" class="mt-1 text-xs text-slate-500">Using the default CRM branding.</p>
            <p v-if="form.errors.file" class="form-error mt-1">{{ form.errors.file }}</p>
            <div v-if="canManage" class="mt-3 flex flex-wrap gap-2">
                <input ref="input" type="file" class="sr-only" :accept="accept" :aria-label="`Choose ${label.toLowerCase()} file`" @change="pick" />
                <template v-if="preview">
                    <UiButton size="sm" :loading="form.processing" @click="upload">Save {{ label.toLowerCase() }}</UiButton>
                    <UiButton size="sm" variant="secondary" :disabled="form.processing" @click="cancel">Cancel</UiButton>
                </template>
                <template v-else>
                    <UiButton size="sm" variant="secondary" icon="upload" @click="input?.click()">{{ currentUrl ? 'Replace' : 'Upload' }}</UiButton>
                    <UiButton v-if="currentUrl" size="sm" variant="ghost" :loading="removing" @click="remove">Remove</UiButton>
                </template>
            </div>
        </div>
    </div>
</template>
