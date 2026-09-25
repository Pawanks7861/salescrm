import { router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';

/**
 * Keeps list filters in the query string; filtering always happens server-side.
 */
export function useFilters(initial, url, { debounce = 350 } = {}) {
    const filters = reactive({ ...initial });
    let timer = null;

    const apply = () => {
        const query = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '' && v !== null && v !== undefined));
        router.get(url, query, { preserveState: true, preserveScroll: true, replace: true });
    };

    watch(filters, () => {
        clearTimeout(timer);
        timer = setTimeout(apply, debounce);
    });

    const reset = () => {
        Object.keys(filters).forEach((key) => (filters[key] = ''));
    };

    return { filters, apply, reset };
}
