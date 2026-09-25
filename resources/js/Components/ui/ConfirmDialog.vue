<script setup>
import Modal from '@/Components/Modal.vue';
import { useConfirm } from '@/Composables/useConfirm';
import AppIcon from './AppIcon.vue';
import UiButton from './UiButton.vue';

const { state, settle } = useConfirm();
</script>

<template>
    <Modal :show="state.open" max-width="md" @close="settle(false)">
        <div class="flex gap-4 p-6">
            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl" :class="state.danger ? 'bg-red-100 text-red-600' : 'bg-brand-100 text-brand-600'">
                <AppIcon :name="state.danger ? 'warning' : 'info'" class="h-5 w-5" />
            </div>
            <div>
                <h3 class="text-base font-semibold text-slate-900">{{ state.title }}</h3>
                <p v-if="state.message" class="mt-1 text-sm text-slate-600">{{ state.message }}</p>
            </div>
        </div>
        <div class="flex justify-end gap-2 border-t border-slate-100 bg-slate-50/60 px-6 py-4">
            <UiButton variant="secondary" @click="settle(false)">Cancel</UiButton>
            <UiButton :variant="state.danger ? 'danger' : 'primary'" @click="settle(true)">{{ state.confirmText }}</UiButton>
        </div>
    </Modal>
</template>
