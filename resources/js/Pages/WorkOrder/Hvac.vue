<script setup>
import AppLayout from "@/Layouts/AppLayout.vue";
import LawnCare from "./LawnCare.vue";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    service_status: Object,
    vendors: Object,
    categories: Object,
    types: Array,
    users: Object,
    filter: Object,
    // Whether this user is on the allow-list for the "new activity" counters.
    // The server decides; false renders the board exactly as every other board.
    shows_new_activity: { type: Boolean, default: false },
    board_seen_at: { type: String, default: null },
});
</script>

<template>
    <LawnCare
        v-bind="props"
        list-route-name="work_orders.hvac"
        :new-activity="shows_new_activity"
        :board-seen-at="board_seen_at"
    />
</template>
