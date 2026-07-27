<script setup>
import AppLayout from "@/Layouts/AppLayout.vue";
import LawnCare from "./LawnCare.vue";
import HoaUploadDialog from "@/Components/WorkOrder/HoaUploadDialog.vue";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    service_status: Object,
    vendors: Object,
    categories: Object,
    types: Array,
    users: Object,
    filter: Object,
    buildings: Array,
});
</script>

<template>
    <LawnCare
        :title="title"
        :service_status="service_status"
        :vendors="vendors"
        :categories="categories"
        :types="types"
        :users="users"
        :filter="filter"
        list-route-name="work_orders.hoa"
        :hoa="true"
    >
        <template #board-actions>
            <HoaUploadDialog :buildings="buildings ?? []" />
        </template>
    </LawnCare>
</template>
