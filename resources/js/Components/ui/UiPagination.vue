<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    paginator: { type: Object, required: true },
});
</script>

<template>
    <div v-if="paginator.total > 0" class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 px-5 py-3 text-xs text-slate-500">
        <div>
            Showing <span class="font-medium text-slate-700">{{ paginator.from }}</span>–<span class="font-medium text-slate-700">{{ paginator.to }}</span>
            of <span class="font-medium text-slate-700">{{ paginator.total.toLocaleString() }}</span>
        </div>
        <nav v-if="paginator.last_page > 1" class="flex items-center gap-0.5">
            <template v-for="(link, i) in paginator.links" :key="i">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    preserve-state
                    class="flex h-8 min-w-[2rem] items-center justify-center rounded-lg px-2 text-center transition"
                    :class="link.active ? 'bg-brand-600 font-semibold text-white' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'"
                    v-html="link.label"
                />
                <span v-else class="flex h-8 min-w-[2rem] items-center justify-center px-2 text-center text-slate-300" v-html="link.label" />
            </template>
        </nav>
    </div>
</template>
