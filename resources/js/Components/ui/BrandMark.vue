<script setup>
import { initials } from '@/utils/format';
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    size: { type: String, default: 'md' }, // md (sidebar) | lg (login)
});

const page = usePage();
const name = computed(() => page.props.app?.company || page.props.app?.name || 'Sales CRM');
const logo = computed(() => page.props.app?.logo_url || null);
const failed = ref(false);
watch(logo, () => (failed.value = false));

const box = computed(() => (props.size === 'lg' ? 'h-14 max-w-[15rem]' : 'h-9 max-w-[7.5rem]'));
const fallback = computed(() => (props.size === 'lg' ? 'h-14 w-14 rounded-2xl text-lg' : 'h-9 w-9 rounded-xl text-sm'));
</script>

<template>
    <img v-if="logo && !failed" :src="logo" :alt="`${name} logo`" class="w-auto shrink-0 object-contain" :class="box" @error="failed = true" />
    <div
        v-else
        class="flex shrink-0 items-center justify-center bg-gradient-to-br from-brand-500 to-brand-700 font-bold text-white shadow-glow"
        :class="fallback"
        aria-hidden="true"
    >
        {{ initials(name) }}
    </div>
</template>
