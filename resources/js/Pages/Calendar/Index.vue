<script setup>
import { ref, computed } from "vue";
import { router, usePoll, Head } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import SearchBar from "@/Components/SearchBar.vue";
import { Button } from "@/Components/ui/button";
import { Card, CardContent } from "@/Components/ui/card";
import { Badge } from "@/Components/ui/badge";
import {
    ChevronLeft,
    ChevronRight,
    CalendarDays,
    MapPin,
    User,
    Wrench,
    CheckCircle2,
    AlertCircle,
    Clock,
} from "lucide-vue-next";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    events: Array,
    weekStart: String, // "YYYY-MM-DD" — Sunday of the visible week (Chicago)
    filters: Object,
});

const url = route("scheduled_service");
const search = ref(props.filters?.search ?? "");

// Always reason about calendar days in Chicago time to avoid timezone drift.
const chicagoFormatter = new Intl.DateTimeFormat("en-CA", {
    timeZone: "America/Chicago",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
});

// Parse "YYYY-MM-DD" into a UTC-noon Date so formatting in Chicago stays stable.
const parseYMD = (ymd) => {
    if (!ymd) return null;
    const [year, month, day] = ymd.split("-").map((s) => parseInt(s, 10));
    if (!year || !month || !day) return null;
    return new Date(Date.UTC(year, month - 1, day, 12, 0, 0, 0));
};

const currentWeekStart = ref(parseYMD(props.weekStart) ?? new Date());

// Seven consecutive calendar days starting at the week's Sunday.
const weekDates = computed(() => {
    const base = chicagoFormatter.format(currentWeekStart.value);
    const [year, month, day] = base.split("-").map(Number);
    const dates = [];
    for (let i = 0; i < 7; i++) {
        dates.push(new Date(Date.UTC(year, month - 1, day + i, 12, 0, 0)));
    }
    return dates;
});

const weekRangeText = computed(() => {
    const start = weekDates.value[0];
    const end = weekDates.value[6];
    const startMonth = start.toLocaleDateString("en-US", {
        month: "short",
        timeZone: "America/Chicago",
    });
    const endMonth = end.toLocaleDateString("en-US", {
        month: "short",
        timeZone: "America/Chicago",
    });
    const startDay = start.getUTCDate();
    const endDay = end.getUTCDate();
    const year = end.getUTCFullYear();

    return startMonth === endMonth
        ? `${startMonth} ${startDay}–${endDay}, ${year}`
        : `${startMonth} ${startDay} – ${endMonth} ${endDay}, ${year}`;
});

const todayChicago = () => chicagoFormatter.format(new Date());

const isToday = (date) => chicagoFormatter.format(date) === todayChicago();

const isCurrentWeek = computed(() => {
    const today = todayChicago();
    const first = chicagoFormatter.format(weekDates.value[0]);
    const last = chicagoFormatter.format(weekDates.value[6]);
    return today >= first && today <= last;
});

// Group events onto their scheduled day (Chicago).
const eventsByDate = computed(() => {
    const grouped = {};
    weekDates.value.forEach((date) => {
        grouped[chicagoFormatter.format(date)] = [];
    });

    (props.events || []).forEach((event) => {
        const key = chicagoFormatter.format(new Date(event.start));
        if (grouped[key]) {
            grouped[key].push(event);
        }
    });

    return grouped;
});

const weekCount = computed(() => (props.events || []).length);

const dayEvents = (date) => eventsByDate.value[chicagoFormatter.format(date)] ?? [];

const isTransitioning = ref(false);

// Navigate weeks, preserving the active search term.
const navigateWeek = (direction) => {
    if (isTransitioning.value) return;
    isTransitioning.value = true;

    const base = chicagoFormatter.format(currentWeekStart.value);
    const [year, month, day] = base.split("-").map(Number);
    const next = new Date(year, month - 1, day);
    next.setDate(next.getDate() + direction * 7);
    const weekKey = chicagoFormatter.format(next);

    router.visit(url, {
        method: "get",
        data: { week_start: weekKey, search: search.value || undefined },
        preserveState: false,
        preserveScroll: false,
        onFinish: () => (isTransitioning.value = false),
        onError: () => (isTransitioning.value = false),
    });
};

const goToToday = () => {
    if (isTransitioning.value) return;
    isTransitioning.value = true;
    router.visit(url, {
        method: "get",
        data: { search: search.value || undefined },
        preserveState: false,
        preserveScroll: false,
        onFinish: () => (isTransitioning.value = false),
        onError: () => (isTransitioning.value = false),
    });
};

// Swipe navigation on touch devices.
const touchStartX = ref(null);
const touchEndX = ref(null);
const handleTouchStart = (e) => (touchStartX.value = e.touches[0].clientX);
const handleTouchMove = (e) => (touchEndX.value = e.touches[0].clientX);
const handleTouchEnd = () => {
    if (touchStartX.value == null || touchEndX.value == null) return;
    const distance = touchStartX.value - touchEndX.value;
    if (distance > 50) navigateWeek(1);
    if (distance < -50) navigateWeek(-1);
    touchStartX.value = null;
    touchEndX.value = null;
};

// Card status derives from the schedule's status, then its date vs today.
const eventStatus = (event) => {
    if ((event.status || "").toLowerCase() === "completed") {
        return "complete";
    }
    const eventDay = chicagoFormatter.format(new Date(event.start));
    const today = todayChicago();
    if (eventDay < today) return "overdue";
    if (eventDay === today) return "today";
    return "upcoming";
};

const statusMeta = {
    complete: { icon: CheckCircle2, card: "border-l-4 border-l-green-500", iconColor: "text-green-500" },
    overdue: { icon: AlertCircle, card: "border-l-4 border-l-red-500", iconColor: "text-red-500" },
    today: { icon: Clock, card: "border-l-4 border-l-blue-500", iconColor: "text-blue-500" },
    upcoming: { icon: CalendarDays, card: "border-l-4 border-l-muted", iconColor: "text-muted-foreground" },
};

const meta = (event) => statusMeta[eventStatus(event)];

const openWorkOrder = (event) => {
    if (!event.work_order_id) return;
    window.open(route("work_orders.details", event.work_order_id), "_blank", "noopener,noreferrer");
};

// Live refresh: re-pull just the events for the current week/search every 15s.
usePoll(15000, { only: ["events"] });
</script>

<template>
    <Head :title="title" />

    <!-- Heading + Search -->
    <div class="flex flex-col sm:flex-row items-center justify-between gap-3 mb-3">
        <div class="flex items-center gap-2 w-full sm:w-auto">
            <h1 class="text-xl font-semibold">Schedules</h1>
            <Badge variant="secondary">{{ weekCount }} this week</Badge>
        </div>
        <SearchBar :url="url" v-model="search" class="w-full sm:w-80" />
    </div>

    <!-- Week Navigation Header -->
    <div class="bg-background border rounded-lg p-4">
        <div class="flex items-center justify-between">
            <Button
                variant="ghost"
                size="icon"
                @click="navigateWeek(-1)"
                :disabled="isTransitioning"
                class="hover:bg-accent"
            >
                <ChevronLeft class="h-5 w-5" />
            </Button>

            <div class="flex items-center gap-3">
                <h2 class="text-lg font-semibold">{{ weekRangeText }}</h2>
                <Button
                    v-if="!isCurrentWeek"
                    size="sm"
                    @click="goToToday"
                    class="text-xs"
                >
                    Today
                </Button>
            </div>

            <Button
                variant="ghost"
                size="icon"
                @click="navigateWeek(1)"
                :disabled="isTransitioning"
                class="hover:bg-accent"
            >
                <ChevronRight class="h-5 w-5" />
            </Button>
        </div>
    </div>

    <!-- Week View -->
    <div
        class="bg-background border rounded-lg overflow-hidden mt-3"
        @touchstart="handleTouchStart"
        @touchmove="handleTouchMove"
        @touchend="handleTouchEnd"
    >
        <!--
            One responsive grid: a vertical, day-by-day list on mobile and a
            7-column week grid on desktop. Each day carries its own header so
            there is no separate header row to fall out of alignment.
        -->
        <div class="grid grid-cols-1 md:grid-cols-7 md:min-h-[500px]">
            <div
                v-for="date in weekDates"
                :key="date.toISOString()"
                :class="[
                    'flex flex-col border-b last:border-b-0 md:border-b-0 md:border-r md:last:border-r-0',
                    isToday(date) ? 'bg-primary/5' : '',
                    // On mobile, hide days with nothing scheduled to avoid a long empty list.
                    !dayEvents(date).length ? 'hidden md:flex' : '',
                ]"
            >
                <!-- Day header -->
                <div
                    :class="[
                        'flex items-baseline gap-2 md:block md:text-center p-3 border-b bg-muted/30',
                        isToday(date) ? 'md:bg-primary/10' : '',
                    ]"
                >
                    <span class="font-medium text-sm md:block">
                        {{ date.toLocaleDateString("en-US", { weekday: "short", timeZone: "America/Chicago" }) }}
                    </span>
                    <span :class="['text-lg md:block', isToday(date) ? 'font-bold text-primary' : '']">
                        {{ date.getUTCDate() }}
                    </span>
                    <span class="text-xs text-muted-foreground md:block">
                        {{ date.toLocaleDateString("en-US", { month: "short", timeZone: "America/Chicago" }) }}
                    </span>
                </div>

                <!-- Cards -->
                <div class="p-2 space-y-2 flex-1 md:overflow-y-auto md:max-h-[600px]">
                    <Card
                        v-for="event in dayEvents(date)"
                        :key="event.id"
                        :class="['cursor-pointer hover:shadow-md transition-shadow', meta(event).card]"
                        @click="openWorkOrder(event)"
                    >
                        <CardContent class="p-3">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-xs font-semibold">
                                    WO #{{ event.work_order_no ?? "—" }}
                                </span>
                                <component :is="meta(event).icon" :class="['h-4 w-4', meta(event).iconColor]" />
                            </div>

                            <div class="font-medium text-sm line-clamp-2 mb-2">
                                {{ event.title || "Scheduled service" }}
                            </div>

                            <div v-if="event.vendor" class="flex items-start gap-1 mb-1">
                                <Wrench class="h-3 w-3 mt-0.5 text-muted-foreground" />
                                <span class="text-xs line-clamp-1">{{ event.vendor }}</span>
                            </div>
                            <div v-if="event.tenant" class="flex items-start gap-1 mb-1">
                                <User class="h-3 w-3 mt-0.5 text-muted-foreground" />
                                <span class="text-xs line-clamp-1">{{ event.tenant }}</span>
                            </div>
                            <div v-if="event.location" class="flex items-start gap-1">
                                <MapPin class="h-3 w-3 mt-0.5 text-muted-foreground" />
                                <span class="text-xs line-clamp-2">{{ event.location }}</span>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Desktop-only empty state (empty days are hidden on mobile). -->
                    <div
                        v-if="!dayEvents(date).length"
                        class="hidden md:block text-center py-8 text-muted-foreground"
                    >
                        <CalendarDays class="h-8 w-8 mx-auto mb-2 opacity-20" />
                        <p class="text-xs">No schedules</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mobile: the whole week is empty. -->
        <div v-if="!weekCount" class="md:hidden text-center py-10 text-muted-foreground">
            <CalendarDays class="h-8 w-8 mx-auto mb-2 opacity-20" />
            <p class="text-sm">No schedules this week</p>
        </div>
    </div>
</template>
