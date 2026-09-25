<script setup>
import BrandMark from '@/Components/ui/BrandMark.vue';
import PoweredBy from '@/Components/ui/PoweredBy.vue';
import { usePage } from '@inertiajs/vue3';
import { computed, onMounted } from 'vue';

const page = usePage();
// A new sign-in in this tab must re-register the browser's push subscription.
onMounted(() => {
    try {
        sessionStorage.removeItem('crm.push.synced');
    } catch {
        /* storage unavailable */
    }
});
const brandName = computed(() => page.props.app?.company || page.props.app?.name || 'Sales CRM');
</script>

<template>
    <div class="relative flex min-h-screen overflow-hidden">
        <div class="relative hidden w-[46%] flex-col justify-between border-r border-slate-200/70 bg-sidebar p-12 lg:flex">
            <div class="pointer-events-none absolute -left-24 top-1/3 h-80 w-80 rounded-full bg-brand-500/10 blur-3xl" aria-hidden="true" />
            <p class="relative text-sm font-semibold uppercase tracking-[0.14em] text-slate-400">{{ page.props.app?.name ?? 'Sales CRM' }}</p>
            <div class="relative">
                <h2 class="text-4xl font-bold leading-tight tracking-tight text-slate-900">Every lead owned.<br />Every follow-up on time.</h2>
                <p class="mt-4 max-w-md text-[15px] leading-relaxed text-slate-500">Facebook leads flow in automatically, get assigned instantly, and every call, note and meeting is tracked.</p>
            </div>
            <p class="relative text-xs text-slate-400">Authorised personnel only. All activity is logged.</p>
        </div>
        <div class="flex flex-1 items-center justify-center px-6 py-12">
            <div class="w-full max-w-sm">
                <div class="mb-8">
                    <BrandMark size="lg" />
                    <p class="mt-4 text-lg font-semibold text-slate-900">{{ brandName }}</p>
                </div>
                <slot />
                <PoweredBy class="mt-10 text-center" />
            </div>
        </div>
    </div>
</template>
