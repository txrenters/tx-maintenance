<script setup>
import { computed, ref } from "vue";
import { router } from "@inertiajs/vue3";
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from "@/Components/ui/popover";
import { RangeCalendar } from "@/Components/ui/range-calendar";
import {
    CalendarDate,
    DateFormatter,
    endOfMonth,
    getLocalTimeZone,
    today,
} from "@internationalized/date";
import { CalendarIcon, XIcon } from "lucide-vue-next";
import AppLayout from "@/Layouts/AppLayout.vue";
import TableData from "./Partials/TableData.vue";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    invoices: Object,
    filter: Object,
    sort: String,
    direction: String,
    canPost: Boolean,
});

const url = ref(route("invoices.index"));
const search = ref(props.filter.search);
const occupancy = ref(props.filter.occupancy ?? "all");

// Merge into the existing query string rather than replacing it, so sorting
// keeps the search and the occupancy filter keeps the sort.
const visitWith = (params) => {
    const query = new URLSearchParams(window.location.search);
    Object.entries(params).forEach(([key, value]) => {
        if (value) {
            query.set(key, value);
        } else {
            query.delete(key);
        }
    });
    // Any change to what is listed returns to the first page.
    query.delete("page");
    router.visit(`${url.value}?${query.toString()}`, {
        method: "get",
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

// Alphabetical and oldest-first on the first click, except for the upload time,
// where accounting wants the most recent invoices.
const ascendingFirst = ["vendor", "address", "posted"];

const applySort = (column) => {
    let direction = ascendingFirst.includes(column) ? "asc" : "desc";
    if (props.sort === column) {
        direction = props.direction === "asc" ? "desc" : "asc";
    }
    visitWith({ sort: column, direction });
};

const applyOccupancy = (value) => {
    occupancy.value = value;
    visitWith({ occupancy: value === "all" ? "" : value });
};

/* Date range ------------------------------------------------------------- */

const df = new DateFormatter("en-US", { dateStyle: "medium" });

// "2026-07-01" -> CalendarDate, so a reloaded page shows the range it filtered by.
const toCalendarDate = (value) => {
    if (!value) return undefined;
    const [year, month, day] = String(value).split("-").map(Number);
    if (!year || !month || !day) return undefined;
    return new CalendarDate(year, month, day);
};

const dateRange = ref({
    start: toCalendarDate(props.filter.start_date),
    end: toCalendarDate(props.filter.end_date),
});

const hasDateRange = computed(
    () => !!(dateRange.value.start || dateRange.value.end)
);

const applyDateRange = () => {
    const { start, end } = dateRange.value;
    visitWith({
        // A start with no end yet is a half-made selection; wait for the end.
        start_date: start && end ? start.toString() : "",
        end_date: start && end ? end.toString() : "",
    });
};

const clearDateRange = () => {
    dateRange.value = { start: undefined, end: undefined };
    visitWith({ start_date: "", end_date: "" });
};

// Whole months are what accounting reconciles, so offer them directly rather
// than making someone click the 1st and the 31st.
const monthPresets = computed(() => {
    const now = today(getLocalTimeZone());
    const presets = [];

    for (let back = 0; back < 6; back++) {
        let year = now.year;
        let month = now.month - back;
        while (month < 1) {
            month += 12;
            year -= 1;
        }

        const start = new CalendarDate(year, month, 1);
        presets.push({
            key: `${year}-${month}`,
            // "This month", "Last month", then the month's own name.
            label:
                back === 0
                    ? "This month"
                    : back === 1
                      ? "Last month"
                      : df
                            .format(start.toDate(getLocalTimeZone()))
                            .replace(/\s\d+,/, ""),
            start,
            end: endOfMonth(start),
        });
    }

    return presets;
});

// Marks the month currently in effect, so an open picker says what it filtered.
const isActivePreset = (preset) => {
    const { start, end } = dateRange.value;
    if (!start || !end) return false;
    return (
        start.compare(preset.start) === 0 && end.compare(preset.end) === 0
    );
};

const applyMonthPreset = (preset) => {
    dateRange.value = { start: preset.start, end: preset.end };
    visitWith({
        start_date: preset.start.toString(),
        end_date: preset.end.toString(),
    });
};

const rangeLabel = computed(() => {
    const { start, end } = dateRange.value;
    if (!start) return "All dates";

    // A whole month reads as its own name rather than as two dates.
    const preset = monthPresets.value.find(isActivePreset);
    if (preset) {
        return df
            .format(preset.start.toDate(getLocalTimeZone()))
            .replace(/\s\d+,/, "");
    }

    const from = df.format(start.toDate(getLocalTimeZone()));
    if (!end) return from;
    return `${from} - ${df.format(end.toDate(getLocalTimeZone()))}`;
});
</script>
<template>
    <Head :title="title" />
    <Card>
        <CardHeader>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <div class="flex-1">
                    <SearchBar :url="url" v-model="search" />
                </div>
                <!-- The trigger reads as a filled field once a range is set,
                     so an active filter is visible without reading it. -->
                <div
                    :class="[
                        'flex items-center rounded-md border transition-colors',
                        hasDateRange
                            ? 'border-primary/40 bg-primary/10'
                            : 'border-input',
                    ]"
                >
                    <Popover>
                        <PopoverTrigger as-child>
                            <button
                                type="button"
                                :class="[
                                    'flex h-10 items-center gap-2 rounded-md px-3 text-left text-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring',
                                    hasDateRange
                                        ? 'font-medium text-foreground'
                                        : 'font-normal text-muted-foreground',
                                ]"
                            >
                                <CalendarIcon
                                    :class="[
                                        'h-4 w-4 shrink-0',
                                        hasDateRange ? 'text-primary' : '',
                                    ]"
                                />
                                <span class="whitespace-nowrap">
                                    {{ rangeLabel }}
                                </span>
                            </button>
                        </PopoverTrigger>
                        <PopoverContent
                            class="w-auto overflow-hidden p-0"
                            align="end"
                        >
                            <div class="flex flex-col sm:flex-row">
                                <!-- Whole months are the common case, so they
                                     lead rather than sit above the calendar. -->
                                <div
                                    class="flex gap-1 overflow-x-auto border-b bg-muted/40 p-2 sm:w-36 sm:flex-col sm:overflow-visible sm:border-b-0 sm:border-r"
                                >
                                    <button
                                        v-for="preset in monthPresets"
                                        :key="preset.key"
                                        type="button"
                                        :class="[
                                            'whitespace-nowrap rounded px-3 py-2 text-left text-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring',
                                            isActivePreset(preset)
                                                ? 'bg-primary text-primary-foreground'
                                                : 'hover:bg-accent hover:text-accent-foreground',
                                        ]"
                                        @click="applyMonthPreset(preset)"
                                    >
                                        {{ preset.label }}
                                    </button>
                                </div>
                                <div class="p-3">
                                    <RangeCalendar
                                        v-model="dateRange"
                                        initial-focus
                                        @update:start-value="
                                            (startDate) =>
                                                (dateRange.start = startDate)
                                        "
                                        @update:model-value="applyDateRange"
                                    />
                                </div>
                            </div>
                        </PopoverContent>
                    </Popover>
                    <button
                        v-if="hasDateRange"
                        type="button"
                        class="mr-1 rounded p-1 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                        title="Clear the date range"
                        aria-label="Clear the date range"
                        @click="clearDateRange"
                    >
                        <XIcon class="h-4 w-4" />
                    </button>
                </div>
                <Select
                    :modelValue="occupancy"
                    @update:modelValue="applyOccupancy"
                >
                    <SelectTrigger class="w-full sm:w-[190px]">
                        <SelectValue placeholder="All properties" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            <SelectItem value="all">All properties</SelectItem>
                            <SelectItem value="vacant">Vacant only</SelectItem>
                            <SelectItem value="occupied">
                                Occupied only
                            </SelectItem>
                        </SelectGroup>
                    </SelectContent>
                </Select>
            </div>
        </CardHeader>
        <CardContent>
            <TableData
                :data="invoices.data"
                :sort="sort"
                :direction="direction"
                :can-post="canPost"
                @sort="applySort"
            />
        </CardContent>
        <CardFooter
            class="border-t px-6 py-4 flex flex-col sm:flex-row justify-between items-center sm:items-start gap-3"
        >
            <PaginationResultRange :data="invoices" />
            <Pagination :pagination="invoices.links" />
        </CardFooter>
    </Card>
</template>