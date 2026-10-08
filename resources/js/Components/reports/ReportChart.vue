<script setup>
import { chartTheme, colorFor, formatValue } from '@/utils/reportFormat';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

// Chart.js is loaded on demand and only the pieces we use are registered,
// so pages without charts never download it. No animation, no 3D.
const props = defineProps({
    chart: { type: String, default: 'bar' }, // line | bar | stacked | donut
    labels: { type: Array, default: () => [] },
    datasets: { type: Array, default: () => [] },
    format: { type: String, default: 'number' },
    currency: { type: String, default: 'INR' },
});

const canvas = ref(null);
const failed = ref(false);
let instance = null;
let ChartClass = null;

async function load() {
    if (ChartClass) return ChartClass;
    const m = await import('chart.js');
    m.Chart.register(m.BarController, m.BarElement, m.LineController, m.LineElement, m.PointElement, m.DoughnutController, m.ArcElement, m.CategoryScale, m.LinearScale, m.Tooltip, m.Legend);
    const colors = chartTheme();
    m.Chart.defaults.color = colors.text;
    m.Chart.defaults.borderColor = colors.grid;
    m.Chart.defaults.font.family = 'Inter, ui-sans-serif, system-ui, sans-serif';
    ChartClass = m.Chart;
    return ChartClass;
}

function config() {
    const colors = chartTheme();
    const donut = props.chart === 'donut';
    const stacked = props.chart === 'stacked';
    const type = donut ? 'doughnut' : props.chart === 'line' ? 'line' : 'bar';
    const fmt = (v) => formatValue(v, props.format, props.currency);

    return {
        type,
        data: {
            labels: props.labels,
            datasets: props.datasets.map((d, i) => ({
                label: d.label,
                data: d.data,
                backgroundColor: donut || d.colors ? props.labels.map((_, j) => colorFor(d.colors?.[j], j)) : colorFor(d.color, i),
                borderColor: donut ? colors.surface : d.colors ? props.labels.map((_, j) => colorFor(d.colors[j], j)) : colorFor(d.color, i),
                borderWidth: donut ? 2 : 2,
                borderRadius: type === 'bar' ? 6 : 0,
                pointRadius: type === 'line' ? 2 : 0,
                tension: 0,
                maxBarThickness: 36,
            })),
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            cutout: donut ? '62%' : undefined,
            plugins: {
                legend: { display: donut || props.datasets.length > 1, position: donut ? 'right' : 'bottom', labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, color: colors.text, font: { size: 11 } } },
                tooltip: {
                    backgroundColor: colors.tooltip,
                    borderColor: colors.border,
                    borderWidth: 1,
                    titleColor: colors.title,
                    bodyColor: colors.text,
                    padding: 10,
                    cornerRadius: 10,
                    callbacks: { label: (ctx) => `${ctx.dataset.label}: ${fmt(ctx.parsed?.y ?? ctx.parsed)}` },
                },
            },
            scales: donut
                ? {}
                : {
                      x: { stacked, grid: { display: false }, border: { color: colors.border }, ticks: { color: colors.muted, font: { size: 11 }, maxRotation: 0, autoSkip: true } },
                      y: { stacked, beginAtZero: true, grid: { color: colors.grid }, border: { display: false }, ticks: { color: colors.muted, font: { size: 11 }, precision: 0, callback: (v) => fmt(v) } },
                  },
        },
    };
}

async function render() {
    try {
        const Chart = await load();
        instance?.destroy();
        if (canvas.value) instance = new Chart(canvas.value, config());
    } catch {
        failed.value = true;
    }
}

const onTheme = () => render();
onMounted(() => {
    render();
    window.addEventListener('crm-theme', onTheme);
});
watch(() => [props.labels, props.datasets, props.chart], render, { deep: true });
onBeforeUnmount(() => {
    window.removeEventListener('crm-theme', onTheme);
    instance?.destroy();
});
</script>

<template>
    <div :class="chart === 'donut' ? 'h-56' : 'h-64'" class="relative">
        <p v-if="failed" class="p-4 text-xs text-slate-500">The chart could not be drawn. The table below shows the same data.</p>
        <canvas v-else ref="canvas" role="img" :aria-label="datasets.map((d) => d.label).join(', ')" />
    </div>
</template>
