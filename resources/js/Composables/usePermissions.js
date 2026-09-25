import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * UI-only permission helper. The backend enforces every permission independently;
 * this only decides what to render.
 */
export function usePermissions() {
    const page = usePage();
    const permissions = computed(() => new Set(page.props.auth?.permissions ?? []));
    const isSuperAdmin = computed(() => !!page.props.auth?.user?.is_super_admin);

    const can = (...names) => isSuperAdmin.value || names.some((name) => permissions.value.has(name));

    return { can, isSuperAdmin };
}
