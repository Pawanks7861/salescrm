import { reactive } from 'vue';

const state = reactive({ items: [] });
let nextId = 1;

function push(type, message, timeout = 4000) {
    if (!message) return;
    const id = nextId++;
    state.items.push({ id, type, message });
    if (timeout) setTimeout(() => dismiss(id), timeout);
}

function dismiss(id) {
    const index = state.items.findIndex((t) => t.id === id);
    if (index !== -1) state.items.splice(index, 1);
}

export function useToast() {
    return {
        toasts: state.items,
        success: (message) => push('success', message),
        error: (message) => push('error', message, 6000),
        info: (message) => push('info', message),
        dismiss,
    };
}
