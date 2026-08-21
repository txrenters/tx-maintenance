<script setup>
import AppLayout from "@/Layouts/AppLayout.vue";
import { Head } from "@inertiajs/vue3";
import { CalendarClock } from "lucide-vue-next";

defineOptions({ layout: AppLayout });

defineProps({
    title: String,
});
</script>

<template>
    <Head :title="title" />

    <div class="bg-background border rounded-lg p-4">
        <h2 class="text-lg font-semibold">Scheduler</h2>
    </div>

    <div
        class="bg-background border rounded-lg flex flex-col items-center justify-center gap-3 py-24 text-muted-foreground"
    >
        <CalendarClock class="h-10 w-10" />
        <p class="text-sm">Nothing here yet.</p>
    </div>
</template>
