<script setup>
defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
});
</script>

<template>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-x-4 gap-y-3">
        <div class="flex min-w-0 items-center gap-4">
            <slot name="leading" />
            <div class="min-w-0">
                <div v-if="$slots.breadcrumb" class="mb-1.5 text-xs text-slate-500"><slot name="breadcrumb" /></div>
                <h1 class="truncate text-2xl font-bold tracking-tight text-slate-900 lg:text-[30px] lg:leading-9">{{ title }}</h1>
                <p v-if="subtitle" class="mt-1 text-sm text-slate-500 lg:text-[15px]">{{ subtitle }}</p>
            </div>
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2"><slot name="actions" /></div>
    </div>
</template>
