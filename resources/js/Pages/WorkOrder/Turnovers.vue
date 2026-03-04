<script setup>
import AppLayout from "@/Layouts/AppLayout.vue";
import LawnCare from "./LawnCare.vue";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    service_status: Object,
    vendors: Object,
    categories: Object,
    users: Object,
    filter: Object,
});
</script>

<template>
    <LawnCare v-bind="props" list-route-name="work_orders.turnovers" />
</template>
