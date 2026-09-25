<script setup>
import NotificationPreferences from '@/Components/notifications/NotificationPreferences.vue';
import FormField from '@/Components/ui/FormField.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({ profile: { type: Object, required: true } });

const profileForm = useForm({ name: props.profile.name, phone: props.profile.phone ?? '' });

const passwordForm = useForm({ current_password: '', password: '', password_confirmation: '' });

const savePassword = () =>
    passwordForm.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
        onError: () => passwordForm.reset('password', 'password_confirmation'),
    });
</script>

<template>
    <AppLayout title="My profile">
        <PageHeader title="My profile" subtitle="Email and role are managed by your administrator." />

        <div class="grid max-w-4xl gap-4 lg:grid-cols-2">
            <form class="panel" @submit.prevent="profileForm.patch(route('profile.update'), { preserveScroll: true })">
                <div class="panel-header"><h2 class="panel-title">Profile</h2></div>
                <div class="space-y-4 p-5">
                    <FormField label="Name" required :error="profileForm.errors.name">
                        <input v-model="profileForm.name" class="form-input" required />
                    </FormField>
                    <FormField label="Phone" :error="profileForm.errors.phone">
                        <input v-model="profileForm.phone" class="form-input" />
                    </FormField>
                    <FormField label="Email"><input :value="profile.email" class="form-input" disabled /></FormField>
                    <div class="grid grid-cols-2 gap-3">
                        <FormField label="Employee code"><input :value="profile.employee_code ?? '—'" class="form-input" disabled /></FormField>
                        <FormField label="Designation"><input :value="profile.designation ?? '—'" class="form-input" disabled /></FormField>
                    </div>
                </div>
                <div class="flex justify-end border-t border-slate-200 px-4 py-2.5">
                    <UiButton type="submit" :loading="profileForm.processing">Save profile</UiButton>
                </div>
            </form>

            <form class="panel" @submit.prevent="savePassword">
                <div class="panel-header"><h2 class="panel-title">Change password</h2></div>
                <div class="space-y-4 p-5">
                    <FormField label="Current password" required :error="passwordForm.errors.current_password">
                        <input v-model="passwordForm.current_password" type="password" class="form-input" autocomplete="current-password" />
                    </FormField>
                    <FormField label="New password" required :error="passwordForm.errors.password" hint="Must contain upper and lower case letters and numbers.">
                        <input v-model="passwordForm.password" type="password" class="form-input" autocomplete="new-password" />
                    </FormField>
                    <FormField label="Confirm new password" required :error="passwordForm.errors.password_confirmation">
                        <input v-model="passwordForm.password_confirmation" type="password" class="form-input" autocomplete="new-password" />
                    </FormField>
                </div>
                <div class="flex justify-end border-t border-slate-200 px-4 py-2.5">
                    <UiButton type="submit" :loading="passwordForm.processing">Update password</UiButton>
                </div>
            </form>

            <NotificationPreferences class="lg:col-span-2" />
        </div>
    </AppLayout>
</template>
