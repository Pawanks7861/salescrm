<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import FormField from '@/Components/ui/FormField.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    canResetPassword: { type: Boolean },
    status: { type: String },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Sign in" />

        <h1 class="text-2xl font-bold tracking-tight text-slate-900">Sign in to your CRM</h1>
        <p class="mb-8 mt-1.5 text-sm text-slate-500">Use the credentials provided by your administrator.</p>

        <div v-if="status" class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{{ status }}</div>

        <form class="space-y-4" @submit.prevent="submit">
            <FormField label="Email" for="email" :error="form.errors.email">
                <input id="email" v-model="form.email" type="email" class="form-input" required autofocus autocomplete="username" />
            </FormField>

            <FormField label="Password" for="password" :error="form.errors.password">
                <input id="password" v-model="form.password" type="password" class="form-input" required autocomplete="current-password" />
            </FormField>

            <div class="flex items-center justify-between">
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <Checkbox v-model:checked="form.remember" name="remember" />
                    Remember me
                </label>
                <Link v-if="canResetPassword" :href="route('password.request')" class="link text-sm">Forgot password?</Link>
            </div>

            <UiButton type="submit" size="lg" class="w-full" :loading="form.processing">Sign in</UiButton>
        </form>
    </GuestLayout>
</template>
