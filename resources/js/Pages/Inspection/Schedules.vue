<script setup>
import { ref } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import { useToast } from "@/Components/ui/toast/use-toast";
import SearchBar from "@/Components/SearchBar.vue";
import Navigation from "./partials/Navigation.vue";
import "@schedule-x/theme-shadcn/dist/index.css";
import { ScheduleXCalendar } from "@schedule-x/vue";
import {
    createCalendar,
    createViewDay,
    createViewMonthAgenda,
    createViewMonthGrid,
    createViewWeek,
    viewMonthGrid,
    createViewList,
} from "@schedule-x/calendar";

const { toast } = useToast();

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    events: Array,
    filters: Object,
});
console.log("events:", props.events);

const calendarApp = createCalendar({
    selectedDate: new Date().now,
    theme: "shadcn",
    month: {
        showTrailingAndLeadingDates: false,
    },
    monthGridOptions: {
        nEventsPerDay: 20,
    },
    defaultView: viewMonthGrid.name,
    firstDayOfWeek: 0,
    // isDark: true,
    views: [
        createViewDay(),
        createViewWeek(),
        createViewMonthGrid(),
        createViewMonthAgenda(),
        createViewList(),
    ],
    events: props.events,
    callbacks: {
        onEventClick(calendarEvent) {
            alert(calendarEvent.title);
        },
    },
});

const url = route("visits.index");
const search = ref(props.filters.search ?? "");
</script>

<template>
    <Head :title="title" />
    <div class="flex gap-3 flex-col sm:flex-row items-center justify-between">
        <SearchBar :url="url" v-model="search" class="w-full" />
        <Navigation />
    </div>
    <div class="is-light-mode">
        <ScheduleXCalendar :calendar-app="calendarApp"> </ScheduleXCalendar>
    </div>
</template>
<style scoped>
.sx-vue-calendar-wrapper {
    height: 1300px;
}

.sx__event .sx__month-grid-event .sx__month-grid-cell {
    height: fit-content;
}
</style>
