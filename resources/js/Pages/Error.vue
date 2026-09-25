<script setup>
import UiButton from '@/Components/ui/UiButton.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({ status: { type: Number, required: true }, context: { type: String, default: null } });

const messages = {
    403: ['Access denied', 'You do not have permission to view this page or perform this action. This attempt has been logged.'],
    404: ['Page not found', 'The page or record you are looking for does not exist or is not available to you.'],
    419: ['Session expired', 'Your session expired for security reasons. Please reload the page and try again.'],
    429: ['Too many requests', 'You are doing that too often. Please wait a moment and try again.'],
    500: ['Something went wrong', 'An unexpected error occurred. Please try again. If it keeps happening, contact your administrator.'],
    503: ['Down for maintenance', 'The CRM is being updated. Please check back in a few minutes.'],
};

const content = computed(() =>
    props.status === 403 && props.context === 'lead'
        ? ['No access to this lead', 'You no longer have access to this lead. It may have been reassigned to another user.']
        : (messages[props.status] ?? messages[500]),
);
const loggedIn = computed(() => !!usePage().props.auth?.user);

const goBack = () => window.history.back();
</script>

<template>
    <Head :title="content[0]" />
    <div class="flex min-h-screen items-center justify-center bg-slate-50 px-4">
        <div class="max-w-md text-center">
            <p class="text-5xl font-bold text-brand-600">{{ status }}</p>
            <h1 class="mt-3 text-xl font-semibold text-slate-900">{{ content[0] }}</h1>
            <p class="mt-2 text-sm text-slate-600">{{ content[1] }}</p>
            <div class="mt-6 flex justify-center gap-2">
                <UiButton variant="secondary" @click="goBack">Go back</UiButton>
                <UiButton :href="loggedIn ? route('dashboard') : route('login')">{{ loggedIn ? 'Dashboard' : 'Sign in' }}</UiButton>
            </div>
        </div>
    </div>
</template>
