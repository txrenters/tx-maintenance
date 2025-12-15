<script setup>
import { ref, computed } from "vue";
import { usePoll } from "@inertiajs/vue3";
import { Qalendar } from "qalendar";
import { useColorMode } from "@vueuse/core";
import AppLayout from "@/Layouts/AppLayout.vue";
import "qalendar/dist/style.css";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    service_schedules: Object,
});

console.log(props.service_schedules);

const events = ref(props.service_schedules);
const mode = useColorMode();

// Computed class based on color mode
const calendarClass = computed(() => {
    return mode.value === "dark" ? "is-dark-mode" : "is-light-mode";
});

const config = ref({
    week: {
        startsOn: "sunday",
        nDays: 7,
        scrollToHour: 5,
    },
    month: {
        showTrailingAndLeadingDates: false,
    },
    style: {
        fontFamily: "Nunito, sans-serif",
    },
    defaultMode: "week",
    isSilent: true,
    showCurrentTime: true,
});

usePoll(10000);
</script>

<template>
    <Head :title="title" />

    <div :class="calendarClass">
        <Qalendar :events="events" :config="config" />
    </div>
</template>

<style>
/* Dark mode styles for Qalendar event popup/modal */
.is-dark-mode .calendar-month__event,
.is-dark-mode .calendar-week__event {
    color: #fff;
}

.is-dark-mode .event-flyout,
.is-dark-mode .date-picker,
.is-dark-mode .mode-is-day .calendar-header,
.is-dark-mode .calendar-header__mode-picker {
    background-color: hsl(var(--background)) !important;
    color: hsl(var(--foreground)) !important;
    border-color: hsl(var(--border)) !important;
}

.is-dark-mode .event-flyout__header,
.is-dark-mode .event-flyout__body {
    background-color: hsl(var(--background)) !important;
    color: hsl(var(--foreground)) !important;
}

.is-dark-mode .event-flyout__title,
.is-dark-mode .event-flyout__description {
    color: hsl(var(--foreground)) !important;
}

.is-dark-mode .event-flyout__time-value,
.is-dark-mode .event-flyout__location-value,
.is-dark-mode .event-flyout__with-value {
    color: hsl(var(--muted-foreground)) !important;
}

.is-dark-mode .calendar-month__weekday,
.is-dark-mode .calendar-header__mode-value,
.is-dark-mode .calendar-header__chevron-btn {
    color: hsl(var(--foreground)) !important;
}

.is-dark-mode .calendar-month__day,
.is-dark-mode .calendar-week__day {
    background-color: hsl(var(--background)) !important;
    border-color: hsl(var(--border)) !important;
}

.is-dark-mode .calendar-month__day:hover,
.is-dark-mode .calendar-week__day:hover {
    background-color: hsl(var(--accent)) !important;
}

.is-dark-mode .is-today {
    background-color: hsl(var(--accent)) !important;
}

.is-dark-mode .time-line__hour-text {
    color: hsl(var(--muted-foreground)) !important;
}
</style>
