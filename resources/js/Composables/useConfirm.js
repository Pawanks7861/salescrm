import { reactive } from 'vue';

const state = reactive({
    open: false,
    title: '',
    message: '',
    confirmText: 'Confirm',
    danger: false,
    resolve: null,
});

/**
 * Promise-based confirmation dialog rendered once by AppLayout.
 * Usage: if (await confirm({ title, message, danger: true })) { ... }
 */
export function useConfirm() {
    const confirm = ({ title = 'Are you sure?', message = '', confirmText = 'Confirm', danger = false } = {}) =>
        new Promise((resolve) => {
            Object.assign(state, { open: true, title, message, confirmText, danger, resolve });
        });

    const settle = (result) => {
        state.resolve?.(result);
        Object.assign(state, { open: false, resolve: null });
    };

    return { confirm, state, settle };
}
