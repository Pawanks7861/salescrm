<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import AppIcon from '@/Components/ui/AppIcon.vue';
import FormField from '@/Components/ui/FormField.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({
    canResetPassword: { type: Boolean },
    status: { type: String },
    officeBlocked: { type: Boolean, default: false },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});
const showPassword = ref(false);

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
        <div v-if="officeBlocked" class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">The CRM can only be used on the Medawk office WiFi.</div>

        <form class="space-y-4" @submit.prevent="submit">
            <FormField label="Email" for="email" :error="form.errors.email">
                <input id="email" v-model="form.email" type="email" class="form-input" required autofocus autocomplete="username" />
            </FormField>

            <FormField label="Password" for="password" :error="form.errors.password">
                <div class="relative">
                    <input id="password" v-model="form.password" :type="showPassword ? 'text' : 'password'" class="form-input pr-10" required autocomplete="current-password" />
                    <button type="button" class="absolute inset-y-0 right-0 flex items-center px-3 text-slate-400 hover:text-slate-600" :aria-label="showPassword ? 'Hide password' : 'Show password'" :aria-pressed="showPassword" @click="showPassword = !showPassword">
                        <AppIcon :name="showPassword ? 'eye-off' : 'eye'" class="h-4 w-4" />
                    </button>
                </div>
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
