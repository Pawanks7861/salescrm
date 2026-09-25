<script>
// Shared across layout instances so a flash is toasted once per visit.
const lastFlash = { key: '', at: 0 };
</script>

<script setup>
import Dropdown from '@/Components/Dropdown.vue';
import AppIcon from '@/Components/ui/AppIcon.vue';
import BrandMark from '@/Components/ui/BrandMark.vue';
import ConfirmDialog from '@/Components/ui/ConfirmDialog.vue';
import PoweredBy from '@/Components/ui/PoweredBy.vue';
import SoftphoneWidget from '@/Components/calls/SoftphoneWidget.vue';
import ToastContainer from '@/Components/ui/ToastContainer.vue';
import GlobalLeadSearch from '@/Components/leads/GlobalLeadSearch.vue';
import NotificationBell from '@/Components/NotificationBell.vue';
import { usePermissions } from '@/Composables/usePermissions';
import { useToast } from '@/Composables/useToast';
import { startNotifier } from '@/notifications/notifier';
import { initials } from '@/utils/format';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';

defineProps({ title: { type: String, default: '' } });

const page = usePage();
const toast = useToast();
const { can } = usePermissions();
const sidebarOpen = ref(false);

// Desktop rail mode (icons only). Tablets always get the rail; phones get a drawer.
const collapsed = ref(typeof localStorage !== 'undefined' && localStorage.getItem('crm.sidebar.collapsed') === '1');
const toggleCollapsed = () => {
    collapsed.value = !collapsed.value;
    try {
        localStorage.setItem('crm.sidebar.collapsed', collapsed.value ? '1' : '0');
    } catch {
        /* storage unavailable */
    }
};
const labelClass = computed(() => (collapsed.value ? 'md:hidden' : 'md:hidden lg:inline'));
const blockLabelClass = computed(() => (collapsed.value ? 'md:hidden' : 'md:hidden lg:block'));

const user = computed(() => page.props.auth.user);
const navigation = computed(() => page.props.navigation ?? []);
const unread = computed(() => page.props.notifications?.unread ?? 0);
const brandName = computed(() => page.props.app?.company || page.props.app?.name || 'Sales CRM');

const isActive = (pattern) => {
    try {
        if (pattern && typeof pattern === 'object' && !Array.isArray(pattern)) {
            return route().current(pattern.name, pattern.params);
        }
        return Array.isArray(pattern) ? pattern.some((p) => route().current(p)) : route().current(pattern);
    } catch {
        return false;
    }
};

const hasRoute = (name) => {
    try {
        return route().has(name);
    } catch {
        return false;
    }
};

// Errors raised by services that are not tied to a form field.
const GENERAL_ERROR_KEYS = ['user', 'role', 'permissions', 'rule', 'general', 'record', 'duplicate', 'assigned_to', 'lost_reason_id', 'status_id', 'file', 'status', 'disposition_id', 'next_action', 'notes'];

const showFlash = (props) => {
    const key = JSON.stringify(props.flash ?? {});
    if (key === lastFlash.key && Date.now() - lastFlash.at < 1500) return;
    lastFlash.key = key;
    lastFlash.at = Date.now();

    if (props.flash?.success) toast.success(props.flash.success);
    if (props.flash?.error) toast.error(props.flash.error);
};

const listeners = [];
onMounted(() => {
    showFlash(page.props);
    startNotifier(() => page.props);
    listeners.push(
        router.on('success', (event) => showFlash(event.detail.page.props)),
        router.on('error', (event) => {
            const errors = event.detail.errors ?? {};
            const general = GENERAL_ERROR_KEYS.map((k) => errors[k]).find(Boolean);
            toast.error(general ?? 'Please correct the highlighted fields.');
        }),
        router.on('navigate', () => (sidebarOpen.value = false)),
    );
});
onUnmounted(() => listeners.splice(0).forEach((off) => off()));
</script>

<template>
    <Head :title="title" />
    <div class="min-h-screen">
        <!-- Mobile overlay -->
        <Transition enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0" leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0">
            <div v-if="sidebarOpen" class="fixed inset-0 z-[41] bg-[var(--overlay)] md:hidden" @click="sidebarOpen = false" />
        </Transition>

        <!-- Sidebar: drawer on phones, icon rail on tablets, full (collapsible) on desktop -->
        <aside
            class="fixed inset-y-0 left-0 z-[42] flex w-sidebar flex-col border-r border-slate-200/70 bg-sidebar transition-[transform,width] duration-200 md:w-[76px] md:translate-x-0"
            :class="[sidebarOpen ? 'translate-x-0' : '-translate-x-full', collapsed ? '' : 'lg:w-sidebar']"
            aria-label="Main navigation"
        >
            <div class="flex h-16 shrink-0 items-center gap-3 px-5 md:justify-center md:px-0" :class="collapsed ? '' : 'lg:justify-start lg:px-5'">
                <BrandMark :class="collapsed ? 'md:!max-w-[2.75rem]' : 'md:!max-w-[2.75rem] lg:!max-w-[7.5rem]'" />
                <span class="truncate text-[15px] font-semibold tracking-tight text-slate-900" :class="blockLabelClass">{{ brandName }}</span>
                <button class="icon-btn ml-auto md:hidden" aria-label="Close menu" @click="sidebarOpen = false"><AppIcon name="close" class="h-5 w-5" /></button>
            </div>

            <nav class="flex-1 overflow-y-auto px-3 pb-4 pt-2">
                <div v-for="(section, index) in navigation" :key="section.title || index" class="mb-4">
                    <p v-if="section.title" class="mb-1.5 px-3 text-2xs font-semibold uppercase tracking-[0.1em] text-slate-400" :class="blockLabelClass">{{ section.title }}</p>
                    <div v-else-if="index > 0" class="mx-3 mb-2 border-t border-slate-100" />
                    <Link
                        v-for="item in section.items"
                        :key="item.href"
                        :href="item.href"
                        :title="item.label"
                        :aria-label="item.label"
                        class="group relative mb-0.5 flex h-[46px] items-center gap-3 rounded-xl px-3 text-sm font-medium transition-colors md:justify-center"
                        :class="[
                            isActive(item.active) ? 'bg-brand-50 text-slate-900' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800',
                            collapsed ? '' : 'lg:justify-start',
                        ]"
                        :aria-current="isActive(item.active) ? 'page' : undefined"
                    >
                        <span v-if="isActive(item.active)" class="absolute inset-y-2.5 left-0 w-[3px] rounded-r-full bg-brand-500" aria-hidden="true" />
                        <AppIcon :name="item.icon" class="h-5 w-5 shrink-0 transition-colors" :class="isActive(item.active) ? 'text-brand-500' : 'text-slate-400 group-hover:text-slate-600'" />
                        <span class="truncate" :class="labelClass">{{ item.label }}</span>
                    </Link>
                </div>
            </nav>

            <button
                type="button"
                class="mx-3 mb-2 hidden h-9 items-center gap-2 rounded-lg px-3 text-xs text-slate-400 transition hover:bg-slate-50 hover:text-slate-700 lg:flex"
                :class="collapsed ? 'justify-center' : ''"
                :title="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                @click="toggleCollapsed"
            >
                <AppIcon :name="collapsed ? 'expand' : 'collapse'" class="h-4 w-4" />
                <span v-if="!collapsed">Collapse</span>
            </button>

            <div class="border-t border-slate-100 p-3">
                <Dropdown align="left" width="full" direction="up">
                    <template #trigger>
                        <button type="button" class="flex w-full items-center gap-3 rounded-xl p-2 text-left transition hover:bg-slate-50 md:justify-center" :class="collapsed ? '' : 'lg:justify-start'" :title="user.name">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-600 ring-1 ring-brand-500/30">{{ initials(user.name) }}</span>
                            <span class="min-w-0 flex-1" :class="blockLabelClass">
                                <span class="block truncate text-sm font-semibold leading-tight text-slate-900">{{ user.name }}</span>
                                <span class="block truncate text-2xs leading-tight text-slate-500">{{ user.designation || user.role?.name || 'No role' }}</span>
                            </span>
                            <AppIcon name="chevron-up" class="h-4 w-4 shrink-0 text-slate-400" :class="blockLabelClass" />
                        </button>
                    </template>
                    <template #content>
                        <div class="border-b border-slate-100 px-4 py-2.5">
                            <p class="truncate text-xs font-medium text-slate-800">{{ user.email }}</p>
                            <p class="text-2xs text-slate-500">{{ user.role?.name ?? 'No role' }}</p>
                        </div>
                        <Link :href="route('profile.edit')" class="flex items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-100">
                            <AppIcon name="user-circle" class="h-4 w-4 text-slate-400" />My profile
                        </Link>
                        <Link :href="route('logout')" method="post" as="button" class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-slate-700 hover:bg-slate-100">
                            <AppIcon name="logout" class="h-4 w-4 text-slate-400" />Log out
                        </Link>
                    </template>
                </Dropdown>
                <PoweredBy class="mt-2 px-2" :class="blockLabelClass" />
            </div>
        </aside>

        <!-- Main -->
        <div class="transition-[padding] duration-200 md:pl-[76px]" :class="collapsed ? '' : 'lg:pl-sidebar'">
            <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-100 bg-app/80 px-4 backdrop-blur-md sm:px-6 lg:px-8">
                <button class="icon-btn md:hidden" aria-label="Open menu" @click="sidebarOpen = true">
                    <AppIcon name="menu" class="h-5 w-5" />
                </button>

                <div class="min-w-0 flex-1">
                    <slot name="search">
                        <GlobalLeadSearch v-if="hasRoute('search.leads') && can('lead.view', 'lead.view_all')" />
                    </slot>
                </div>

                <NotificationBell />
            </header>

            <main class="mx-auto w-full max-w-[1680px] px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                <slot />
            </main>
        </div>

        <ToastContainer />
        <ConfirmDialog />
        <SoftphoneWidget v-if="hasRoute('telephony.config') && can('call.make', 'call.receive')" />
    </div>
</template>
