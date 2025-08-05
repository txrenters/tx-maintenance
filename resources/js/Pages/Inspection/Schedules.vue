<script setup>
import { ref } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import { useToast } from "@/Components/ui/toast/use-toast";
import SearchBar from "@/Components/SearchBar.vue";
import Navigation from "./partials/Navigation.vue";
import "@schedule-x/theme-shadcn/dist/index.css";
import { ScheduleXCalendar } from "@schedule-x/vue";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/Components/ui/dialog";
import { Button } from "@/Components/ui/button";
import { Badge } from "@/Components/ui/badge";
import { MapPin, Calendar, User, Edit, Eye } from "lucide-vue-next";
import {
    createCalendar,
    createViewDay,
    createViewMonthAgenda,
    createViewMonthGrid,
    createViewWeek,
    viewMonthGrid,
    createViewList,
} from "@schedule-x/calendar";
import { useEchoPublic } from "@laravel/echo-vue";

const { toast } = useToast();

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    events: Array,
    filters: Object,
});
console.log("events:", props.events);

// Helper function to get date status and add CSS class
const getEventDateClass = (eventStart, eventEnd) => {
    const today = new Date();
    const startDate = new Date(eventStart);
    const endDate = eventEnd ? new Date(eventEnd) : startDate;

    // Reset time to compare only dates
    today.setHours(0, 0, 0, 0);
    startDate.setHours(0, 0, 0, 0);
    endDate.setHours(0, 0, 0, 0);

    // For multi-day events, determine class based on relationship to today
    if (endDate < today) {
        return "event-past";
    } else if (startDate <= today && today <= endDate) {
        return "event-today"; // Event is currently happening (spans today)
    } else if (startDate > today) {
        return "event-future";
    } else {
        return "event-future"; // Default fallback
    }
};

// Process events to add date-based styling and ensure proper multi-day format
const processedEvents =
    props.events?.map((event) => {
        const startDate = event.start || event.date;
        const endDate = event.end;

        // Get date class for color coding
        const dateClass = getEventDateClass(startDate, endDate);

        return {
            ...event,
            // Ensure required properties are present
            id: event.id || `event-${Math.random()}`,
            start: startDate,
            end: endDate || startDate, // Ensure end date exists, default to start date
            title: event.title || event.summary || "Untitled Event",
            // Use Schedule-X _options to add CSS classes properly
            _options: {
                ...event._options,
                additionalClasses: [
                    dateClass,
                    ...(event._options?.additionalClasses || []),
                ],
            },
        };
    }) || [];

// Debug log to see the processed events
console.log("Processed events for Schedule-X:", processedEvents);

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
    views: [
        createViewDay(),
        createViewMonthGrid(),
        createViewMonthAgenda(),
        createViewList(),
    ],
    events: processedEvents,
    callbacks: {
        onEventClick(calendarEvent) {
            openEventModal(calendarEvent);
        },
    },
});

const url = route("visits.index");
const search = ref(props.filters.search ?? "");

// Modal state management
const isModalOpen = ref(false);
const selectedEvent = ref(null);

// Function to open modal with event details
const openEventModal = (event) => {
    selectedEvent.value = event;
    isModalOpen.value = true;
};

// Function to close modal
const closeEventModal = () => {
    isModalOpen.value = false;
    selectedEvent.value = null;
};

useEchoPublic("visits", "VisitUpdated", (e) => {
    const updatedVisit = e.visit;

    const existingIndex = props.events.findIndex(
        (event) => event.id === updatedVisit.id
    );

    const formattedEvent = {
        ...updatedVisit,
        id: updatedVisit.id,
        start: updatedVisit.start || updatedVisit.date,
        end: updatedVisit.end || updatedVisit.date,
        title: updatedVisit.title || updatedVisit.summary || "Untitled Event",
        _options: {
            ...updatedVisit._options,
            additionalClasses: [
                getEventDateClass(updatedVisit.start, updatedVisit.end),
                ...(updatedVisit._options?.additionalClasses || []),
            ],
        },
    };

    if (existingIndex !== -1) {
        props.events.splice(existingIndex, 1, formattedEvent); // update
    } else {
        props.events.push(formattedEvent); // insert new
    }
});

useEchoPublic("visits", "VisitDeleted", (e) => {
    events.value = events.value.filter((event) => event.id !== e.visitId);
});
</script>

<template>
    <Head :title="title" />
    <div class="flex gap-3 flex-col sm:flex-row items-center justify-between">
        <SearchBar :url="url" v-model="search" class="w-full" />
        <Navigation />
    </div>
    <div class="is-light-mode calendar-theme-override">
        <ScheduleXCalendar :calendar-app="calendarApp"> </ScheduleXCalendar>
    </div>

    <!-- Event Details Modal -->
    <Dialog :open="isModalOpen" @update:open="isModalOpen = $event">
        <DialogContent class="overflow-y-auto">
            <DialogHeader>
                <DialogTitle
                    class="flex items-center gap-2 text-xl font-semibold"
                >
                    {{ selectedEvent?.title || "Event Details" }}
                </DialogTitle>
                <DialogDescription
                    v-if="selectedEvent?.jobNumber"
                    class="text-sm text-muted-foreground"
                >
                    Job #{{ selectedEvent.jobNumber }}
                </DialogDescription>
            </DialogHeader>

            <div v-if="selectedEvent" class="space-y-6">
                <!-- Status Badge -->
                <div class="flex items-center gap-2">
                    <Badge
                        :variant="
                            selectedEvent.is_complete ? 'default' : 'secondary'
                        "
                        :class="[
                            'px-3 py-1 font-medium',
                            selectedEvent.is_complete
                                ? 'bg-green-100 text-green-800 border-green-200 hover:bg-green-200'
                                : 'bg-yellow-100 text-yellow-800 border-yellow-200 hover:bg-yellow-200',
                        ]"
                    >
                        <div class="flex items-center gap-1.5">
                            <div
                                :class="[
                                    'w-2 h-2 rounded-full',
                                    selectedEvent.is_complete
                                        ? 'bg-green-500'
                                        : 'bg-yellow-500',
                                ]"
                            ></div>
                            {{
                                selectedEvent.is_complete
                                    ? "Completed"
                                    : "In Progress"
                            }}
                        </div>
                    </Badge>
                </div>

                <!-- Work Description -->
                <div v-if="selectedEvent.description" class="space-y-2">
                    <h3 class="font-semibold">Details</h3>
                    <div class="bg-muted/50 p-4 rounded-lg">
                        <a
                            :href="selectedEvent.job.jobber_web_uri"
                            target="_blank"
                            class="text-sm leading-relaxed text-primary font-semibold"
                        >
                            {{ selectedEvent.job.title }} - Job #{{
                                selectedEvent.job.job_number
                            }}
                        </a>
                        <p
                            class="text-sm leading-relaxed"
                            v-html="selectedEvent.description"
                        ></p>
                    </div>
                </div>

                <div
                    v-if="selectedEvent.location || selectedEvent.address"
                    class="space-y-2"
                >
                    <div class="flex items-center gap-2">
                        <MapPin class="h-4 w-4 text-muted-foreground" />
                        <span class="font-medium">Location</span>
                    </div>
                    <p class="text-sm pl-6">
                        {{ selectedEvent.location || selectedEvent.address }}
                    </p>
                </div>

                <div
                    v-if="selectedEvent.teamMember || selectedEvent.assignedTo"
                    class="space-y-2"
                >
                    <div class="flex items-center gap-2">
                        <User class="h-4 w-4 text-muted-foreground" />
                        <span class="font-medium">Team Member</span>
                    </div>
                    <p class="text-sm pl-6">
                        {{
                            selectedEvent.teamMember || selectedEvent.assignedTo
                        }}
                    </p>
                </div>

                <!-- Details Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Start Date -->
                    <div v-if="selectedEvent.start">
                        <div class="flex items-center gap-2">
                            <Calendar class="h-4 w-4 text-muted-foreground" />
                            <span class="font-medium">Start Date</span>
                        </div>
                        <p class="text-sm pl-6">
                            {{
                                new Date(
                                    selectedEvent.start
                                ).toLocaleDateString("en-US", {
                                    weekday: "long",
                                    year: "numeric",
                                    month: "long",
                                    day: "numeric",
                                })
                            }}
                        </p>
                    </div>

                    <div
                        v-if="
                            selectedEvent.end &&
                            selectedEvent.end !== selectedEvent.start
                        "
                    >
                        <div class="flex items-center gap-2">
                            <Calendar class="h-4 w-4 text-muted-foreground" />
                            <span class="font-medium">End Date</span>
                        </div>
                        <p class="text-sm pl-6">
                            {{
                                new Date(selectedEvent.end).toLocaleDateString(
                                    "en-US",
                                    {
                                        weekday: "long",
                                        year: "numeric",
                                        month: "long",
                                        day: "numeric",
                                    }
                                )
                            }}
                        </p>
                    </div>
                </div>

                <!-- Additional Event Properties -->
                <div
                    v-if="
                        selectedEvent.notes ||
                        selectedEvent.priority ||
                        selectedEvent.category
                    "
                    class="space-y-4"
                >
                    <div v-if="selectedEvent.notes" class="space-y-2">
                        <span class="font-medium">Notes</span>
                        <p class="text-sm text-muted-foreground">
                            {{ selectedEvent.notes }}
                        </p>
                    </div>

                    <div class="flex gap-4">
                        <div v-if="selectedEvent.priority" class="space-y-1">
                            <span class="font-medium text-xs">Priority</span>
                            <Badge variant="outline" class="text-xs">{{
                                selectedEvent.priority
                            }}</Badge>
                        </div>

                        <div v-if="selectedEvent.category" class="space-y-1">
                            <span class="font-medium text-xs">Category</span>
                            <Badge variant="outline" class="text-xs">{{
                                selectedEvent.category
                            }}</Badge>
                        </div>
                    </div>
                </div>
            </div>

            <DialogFooter class="flex gap-2 justify-end mt-5">
                <Button variant="outline" @click="closeEventModal">
                    Close
                </Button>
                <Button
                    v-if="
                        selectedEvent?.job.jobber_web_uri || selectedEvent?.id
                    "
                    as-child
                >
                    <a :href="selectedEvent.job.jobber_web_uri" target="_blank">
                        <Eye class="h-4 w-4" /> View Details</a
                    >
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
<style scoped>
.sx-vue-calendar-wrapper {
    height: 1300px;
}

.sx__event .sx__month-grid-event .sx__month-grid-cell {
    height: fit-content;
}

/* Shadcn Theme Color Integration - Override Schedule-X dark theme */
.calendar-theme-override {
    --sx-color-surface: hsl(var(--background));
    --sx-color-on-surface: hsl(var(--foreground));
    --sx-color-background: hsl(var(--background));
    --sx-color-on-background: hsl(var(--foreground));
    --sx-color-primary: hsl(var(--primary));
    --sx-color-on-primary: hsl(var(--primary-foreground));
    --sx-color-secondary: hsl(var(--secondary));
    --sx-color-on-secondary: hsl(var(--secondary-foreground));
    --sx-color-outline: hsl(var(--border));
    --sx-color-outline-variant: hsl(var(--border));
    --sx-internal-color-text: hsl(var(--foreground));
}

/* Calendar background and text colors */
:deep(.sx__calendar-wrapper) {
    background-color: hsl(var(--background)) !important;
    color: hsl(var(--foreground)) !important;
}

/* Month grid styling */
:deep(.sx__month-grid) {
    background-color: hsl(var(--background)) !important;
    color: hsl(var(--foreground)) !important;
}

/* Calendar cells */
:deep(.sx__month-grid-cell) {
    background-color: hsl(var(--background)) !important;
    border-color: hsl(var(--border)) !important;
}

/* Day headers */
:deep(.sx__week-grid__day-name) {
    color: hsl(var(--muted-foreground)) !important;
    background-color: hsl(var(--muted)) !important;
}

/* Date numbers */
:deep(.sx__week-grid__date-number) {
    color: hsl(var(--foreground)) !important;
}

/* Date-based Event Color Coding */
/* Past events - Red */
:deep(.sx__month-grid-event.event-past),
:deep(.event-past) {
    background-color: #fee2e2 !important; /* red-100 */
    color: #991b1b !important; /* red-800 */
    border: 1px solid #fca5a5 !important; /* red-300 */
}

:deep(.sx__month-grid-event.event-past:hover),
:deep(.event-past:hover) {
    background-color: #fecaca !important; /* red-200 */
    color: #7f1d1d !important; /* red-900 */
}

/* Today's events - Blue */
:deep(.sx__month-grid-event.event-today),
:deep(.event-today) {
    background-color: #dbeafe !important; /* blue-100 */
    color: #1e40af !important; /* blue-800 */
    border: 1px solid #93c5fd !important; /* blue-300 */
}

:deep(.sx__month-grid-event.event-today:hover),
:deep(.event-today:hover) {
    background-color: #bfdbfe !important; /* blue-200 */
    color: #1e3a8a !important; /* blue-900 */
}

/* Future events - Green */
:deep(.sx__month-grid-event.event-future),
:deep(.event-future) {
    background-color: #dcfce7 !important; /* green-100 */
    color: #166534 !important; /* green-800 */
    border: 1px solid #86efac !important; /* green-300 */
}

:deep(.sx__month-grid-event.event-future:hover),
:deep(.event-future:hover) {
    background-color: #bbf7d0 !important; /* green-200 */
    color: #14532d !important; /* green-900 */
}

/* Default fallback (if date cannot be determined) */
:deep(
        .sx__month-grid-event:not(.event-past):not(.event-today):not(
                .event-future
            )
    ) {
    background-color: hsl(var(--muted)) !important;
    color: hsl(var(--foreground)) !important;
    border: 1px solid hsl(var(--border)) !important;
}

:deep(
        .sx__month-grid-event:not(.event-past):not(.event-today):not(
                .event-future
            ):hover
    ) {
    background-color: hsl(var(--accent)) !important;
    color: hsl(var(--accent-foreground)) !important;
}

/* Calendar header */
:deep(.sx__calendar-header) {
    background-color: hsl(var(--background)) !important;
    color: hsl(var(--foreground)) !important;
    border-bottom: 1px solid hsl(var(--border)) !important;
}

/* Navigation buttons */
:deep(.sx__calendar-header button) {
    color: hsl(var(--foreground)) !important;
    background-color: transparent !important;
}

:deep(.sx__calendar-header button:hover) {
    background-color: hsl(var(--accent)) !important;
    color: hsl(var(--accent-foreground)) !important;
}

/* Today's date highlighting */
:deep(.sx__month-grid-day.is-today) {
    background-color: hsl(var(--accent)) !important;
}

:deep(.sx__month-grid-day.is-today .sx__week-grid__date-number) {
    color: hsl(var(--accent-foreground)) !important;
    font-weight: 600;
}

/* Week view and day view date-based coloring */
:deep(.sx__time-grid-event.event-past) {
    background-color: #fee2e2 !important;
    color: #991b1b !important;
    border: 1px solid #fca5a5 !important;
}

:deep(.sx__time-grid-event.event-today) {
    background-color: #dbeafe !important;
    color: #1e40af !important;
    border: 1px solid #93c5fd !important;
}

:deep(.sx__time-grid-event.event-future) {
    background-color: #dcfce7 !important;
    color: #166534 !important;
    border: 1px solid #86efac !important;
}

:deep(
        .sx__time-grid-event:not(.event-past):not(.event-today):not(
                .event-future
            )
    ) {
    background-color: hsl(var(--muted)) !important;
    color: hsl(var(--foreground)) !important;
    border: 1px solid hsl(var(--border)) !important;
}

/* Agenda view date-based coloring */
:deep(.sx__month-agenda-event.event-past) {
    background-color: #fee2e2 !important;
    color: #991b1b !important;
    border: 1px solid #fca5a5 !important;
}

:deep(.sx__month-agenda-event.event-today) {
    background-color: #dbeafe !important;
    color: #1e40af !important;
    border: 1px solid #93c5fd !important;
}

:deep(.sx__month-agenda-event.event-future) {
    background-color: #dcfce7 !important;
    color: #166534 !important;
    border: 1px solid #86efac !important;
}

:deep(
        .sx__month-agenda-event:not(.event-past):not(.event-today):not(
                .event-future
            )
    ) {
    background-color: hsl(var(--muted)) !important;
    color: hsl(var(--foreground)) !important;
    border: 1px solid hsl(var(--border)) !important;
}
</style>
