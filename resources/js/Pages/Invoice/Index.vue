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

const rangeLabel = computed(() => {
    const { start, end } = dateRange.value;
    if (!start) return "Any date";
    const from = df.format(start.toDate(getLocalTimeZone()));
    if (!end) return from;
    return `${from} - ${df.format(end.toDate(getLocalTimeZone()))}`;
});

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

const applyMonthPreset = (preset) => {
    dateRange.value = { start: preset.start, end: preset.end };
    visitWith({
        start_date: preset.start.toString(),
        end_date: preset.end.toString(),
    });
};
</script>
<template>
    <Head :title="title" />
    <Card>
        <CardHeader>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <div class="flex-1">
                    <SearchBar :url="url" v-model="search" />
                </div>
                <Popover>
                    <PopoverTrigger as-child>
                        <Button
                            variant="outline"
                            :class="[
                                'w-full justify-start text-left text-xs font-normal sm:w-[240px]',
                                !hasDateRange ? 'text-muted-foreground' : '',
                            ]"
                        >
                            <CalendarIcon class="mr-2 h-4 w-4 shrink-0" />
                            {{ rangeLabel }}
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent class="w-auto p-0" align="end">
                        <div
                            class="flex flex-col gap-1 border-b p-3 sm:flex-row sm:flex-wrap"
                        >
                            <Button
                                v-for="preset in monthPresets"
                                :key="preset.key"
                                variant="ghost"
                                size="sm"
                                class="justify-start text-xs"
                                @click="applyMonthPreset(preset)"
                            >
                                {{ preset.label }}
                            </Button>
                        </div>
                        <RangeCalendar
                            v-model="dateRange"
                            initial-focus
                            :number-of-months="2"
                            @update:start-value="
                                (startDate) => (dateRange.start = startDate)
                            "
                            @update:model-value="applyDateRange"
                        />
                    </PopoverContent>
                </Popover>
                <Button
                    v-if="hasDateRange"
                    variant="ghost"
                    size="icon"
                    title="Clear the date range"
                    aria-label="Clear the date range"
                    @click="clearDateRange"
                >
                    <XIcon class="h-4 w-4" />
                </Button>
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