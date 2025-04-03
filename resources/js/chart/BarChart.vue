<template>
    <Bar id="my-chart-id" :options="options" :data="barChartData" />
</template>

<script setup>
import { Bar } from "vue-chartjs";
import {
    Chart as ChartJS,
    Title,
    Tooltip,
    Legend,
    BarElement,
    CategoryScale,
    LinearScale,
} from "chart.js";

ChartJS.register(
    Title,
    Tooltip,
    Legend,
    BarElement,
    CategoryScale,
    LinearScale
);

const props = defineProps({
    data: Object,
});

import { computed } from "vue";

const barChartData = computed(() => {
    return {
        labels: props.data.map((work_order) => work_order.name),
        datasets: [
            {
                label: "Created",
                backgroundColor: "#2563EA",
                data: props.data.map((work_order) => work_order.Created),
            },
            {
                label: "Completed",
                backgroundColor: "#43C79B",
                data: props.data.map((work_order) => work_order.Completed),
            },
        ],
    };
});

const options = {
    responsive: true,
    animations: {
        tension: {
            duration: 1000,
            easing: "easeInCubic",
            from: 1,
            to: 0,
            loop: true,
        },
    },
};
</script>
