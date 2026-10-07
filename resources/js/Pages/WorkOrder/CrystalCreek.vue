<script setup>
import AppLayout from "@/Layouts/AppLayout.vue";
import LawnCare from "./LawnCare.vue";
import CrystalCreekCreateDialog from "@/Components/WorkOrder/CrystalCreekCreateDialog.vue";

defineOptions({ layout: AppLayout });

// Crystal Creek Air: work orders for outside customers, people whose home is
// not a Texas Renters property. They are worked by the THMP crew through
// Jobber and never touch PropertyWare. This page is the only board they show
// on; the Create Work Order button is how the office enters a caller.
const props = defineProps({
    title: String,
    service_status: Object,
    vendors: Object,
    categories: Object,
    types: Array,
    catalog: Object,
    users: Object,
    filter: Object,
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
        list-route-name="work_orders.crystal_creek"
        :crystal-creek="true"
    >
        <template #board-actions>
            <CrystalCreekCreateDialog :catalog="catalog ?? { categories: [], scopes: {} }" />
        </template>
    </LawnCare>
</template>
