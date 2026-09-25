<script setup>
import FormField from '@/Components/ui/FormField.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({ role: Object, modules: Array, can: Object });

const readOnly = computed(() => !props.can.update);

const details = useForm({ name: props.role.name, description: props.role.description ?? '' });
const perms = useForm({ permissions: [...props.role.permissions] });

const selected = computed(() => new Set(perms.permissions));

const toggleModule = (module, on) => {
    const names = module.permissions.map((p) => p.name);
    perms.permissions = on
        ? [...new Set([...perms.permissions, ...names])]
        : perms.permissions.filter((p) => !names.includes(p));
};

const moduleState = (module) => {
    const count = module.permissions.filter((p) => selected.value.has(p.name)).length;
    return count === 0 ? 'none' : count === module.permissions.length ? 'all' : 'some';
};
</script>

<template>
    <AppLayout :title="`Role: ${role.name}`">
        <PageHeader :title="role.name" :subtitle="`${role.users_count} user${role.users_count === 1 ? '' : 's'} · ${perms.permissions.length} permissions`">
            <template #breadcrumb><Link :href="route('admin.roles.index')" class="hover:underline">Roles & Permissions</Link> /</template>
            <template #actions>
                <UiBadge v-if="role.is_super_admin" color="purple">Super Admin bypasses all permission checks</UiBadge>
                <UiBadge v-else-if="readOnly" color="amber">Read only</UiBadge>
            </template>
        </PageHeader>

        <form v-if="!role.is_super_admin" class="panel mb-4 max-w-4xl" @submit.prevent="details.put(route('admin.roles.update', role.id), { preserveScroll: true })">
            <div class="grid gap-4 p-4 md:grid-cols-[1fr_2fr_auto] md:items-end">
                <FormField label="Name" :error="details.errors.name">
                    <input v-model="details.name" class="form-input" :disabled="readOnly" />
                </FormField>
                <FormField label="Description" :error="details.errors.description">
                    <input v-model="details.description" class="form-input" :disabled="readOnly" />
                </FormField>
                <UiButton v-if="!readOnly" type="submit" variant="secondary" :loading="details.processing">Save details</UiButton>
            </div>
        </form>

        <form class="panel" @submit.prevent="perms.put(route('admin.roles.permissions', role.id), { preserveScroll: true })">
            <div class="panel-header">
                <div>
                    <h2 class="panel-title">Permission matrix</h2>
                    <p class="text-2xs text-slate-500">Changes apply immediately to every user with this role and are recorded in the audit log.</p>
                </div>
                <UiButton v-if="!readOnly && !role.is_super_admin" type="submit" :loading="perms.processing">Save permissions</UiButton>
            </div>
            <p v-if="perms.errors.permissions" class="form-error px-4 pt-2">{{ perms.errors.permissions }}</p>
            <div class="grid gap-3 p-4 md:grid-cols-2 xl:grid-cols-3">
                <div v-for="module in modules" :key="module.module" class="rounded-md border border-slate-200">
                    <label class="flex items-center justify-between border-b border-slate-100 bg-slate-50 px-3 py-1.5">
                        <span class="text-xs font-semibold text-slate-700">{{ module.module }}</span>
                        <input
                            type="checkbox"
                            class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            :checked="moduleState(module) === 'all'"
                            :indeterminate="moduleState(module) === 'some'"
                            :disabled="readOnly || role.is_super_admin"
                            @change="toggleModule(module, $event.target.checked)"
                        />
                    </label>
                    <div class="space-y-1 px-3 py-2">
                        <label v-for="p in module.permissions" :key="p.name" class="flex items-center justify-between gap-2 text-xs text-slate-700">
                            <span>
                                {{ p.label }}
                                <span class="block font-mono text-2xs text-slate-400">{{ p.name }}</span>
                            </span>
                            <input
                                v-model="perms.permissions"
                                type="checkbox"
                                :value="p.name"
                                class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                                :disabled="readOnly || role.is_super_admin"
                            />
                        </label>
                    </div>
                </div>
            </div>
        </form>
    </AppLayout>
</template>
