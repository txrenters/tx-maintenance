<script setup>
import { ref, computed } from "vue";
import { Head, Deferred } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import { useWorkOrderModal } from "@/composables/useWorkOrderModal";
import { useCityFilter, matchesCityFilter } from "@/composables/useCityFilter";
import { Skeleton } from "@/Components/ui/skeleton";
import { ScrollArea } from "@/Components/ui/scroll-area";
import { Button } from "@/Components/ui/button";
import { Checkbox } from "@/Components/ui/checkbox";
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from "@/Components/ui/popover";
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/Components/ui/select";
import { ClipboardList, Search, Tag, CircleCheckBig, MapPin, ChevronDown } from "lucide-vue-next";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: { type: String, default: "Work Orders" },
    workOrders: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    filter: { type: Object, default: () => ({}) },
});

const { open } = useWorkOrderModal();

// Filtering is client-side: a vendor's list is small, so narrowing by tab,
// category, city, or search is instant and needs no server round-trip. The
// category options are already limited server-side to what the vendor has.
const selectedCategory = ref(props.filter.category ?? "all");
const search = ref(props.filter.search ?? "");

// City show/hide preference — persisted per user in localStorage (see
// useCityFilter), so e.g. THMP can keep another vendor's city hidden. Lives
// behind a compact "Hide cities" popover with a checklist.
const { hiddenCities, isCityHidden, toggleCity, showAllCities } = useCityFilter();

const vendorCities = computed(() =>
    [
        ...new Set(
            (props.workOrders ?? [])
                .map((wo) => wo.building?.city)
                .filter(Boolean),
        ),
    ].sort(),
);

// Search, category, and city narrow the whole list; the kanban columns then
// split what remains, so the column counts always match the visible cards.
const baseFilteredWorkOrders = computed(() => {
    const term = search.value.trim().toLowerCase();

    return (props.workOrders ?? []).filter((wo) => {
        const matchesCategory =
            selectedCategory.value === "all" ||
            wo.category === selectedCategory.value;

        if (!matchesCategory || !matchesCityFilter(hiddenCities.value, wo.building?.city)) {
            return false;
        }

        if (!term) {
            return true;
        }

        return [wo.work_order_no, wo.location, wo.building?.name, wo.category]
            .filter(Boolean)
            .some((field) => String(field).toLowerCase().includes(term));
    });
});

// One kanban column per actual service status, exactly like the staff board:
// table order first, with the billing/payment tail pushed to the far right
// (mirrors mainBoard's reordering). Empty columns disappear.
const TRAILING_STATUSES = [
    "Completed - Verified - Waiting on Bill",
    "Approved - Waiting on Payment",
    "Paid",
];

const columns = computed(() => {
    const byStatus = new Map();

    for (const wo of baseFilteredWorkOrders.value) {
        const key = wo.service_status?.name ?? "No Status";

        if (!byStatus.has(key)) {
            byStatus.set(key, {
                key,
                label: key,
                sortId: wo.service_status?.id ?? Number.MAX_SAFE_INTEGER,
                workOrders: [],
            });
        }

        byStatus.get(key).workOrders.push(wo);
    }

    return [...byStatus.values()].sort((a, b) => {
        const aTrail = TRAILING_STATUSES.indexOf(a.key);
        const bTrail = TRAILING_STATUSES.indexOf(b.key);

        if (aTrail !== bTrail) {
            if (aTrail === -1) return -1;
            if (bTrail === -1) return 1;

            return aTrail - bTrail;
        }

        return a.sortId - b.sortId;
    });
});

const hasActiveFilters = computed(
    () =>
        !!search.value.trim() ||
        selectedCategory.value !== "all" ||
        hiddenCities.value.length > 0,
);

const formatDate = (value) => {
    if (!value) {
        return "";
    }

    return new Date(String(value).replace(" ", "T")).toLocaleDateString(
        "en-US"
    );
};

const countCompletedTask = (tasks) =>
    (tasks ?? []).filter((task) => task.status === "completed").length;

// Mirror the admin board card: a card is painted from its tasks' due dates
// (here the tasks are the vendor's own, scoped server-side) plus the emergency
// and closed overrides.
const checkDueTask = (tasks, scheduledEndDate) => {
    const today = new Date().toISOString().split("T")[0];

    if (scheduledEndDate) {
        if (scheduledEndDate === today) return "blue";
        if (scheduledEndDate < today) return "red";
        if (scheduledEndDate > today) return "green";
    }

    const pending = (tasks ?? []).filter((task) => task.status === "pending");

    if (pending.some((task) => task.due_date < today)) {
        return "red";
    }

    if (pending.some((task) => task.due_date === today)) {
        return "blue";
    }

    return "green";
};

const cardColorClass = (wo) => {
    if (wo.status === "Closed") {
        return "bg-gray-600";
    }

    const color = wo.is_emergency
        ? "red"
        : checkDueTask(wo.tasks, wo.scheduled_end_date);

    if (color === "red") return "bg-destructive";
    if (color === "blue") return "bg-primary";

    return "bg-green-500";
};
</script>

<template>
    <Head :title="title" />

    <div class="p-4 space-y-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <h1 class="text-xl font-bold text-foreground">{{ title }}</h1>

            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground"
                    />
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search work orders"
                        class="h-10 w-full rounded-md border border-input bg-background pl-8 pr-3 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring sm:w-[240px]"
                    />
                </div>

                <Select
                    v-if="categories.length"
                    :modelValue="selectedCategory"
                    @update:modelValue="(value) => (selectedCategory = value)"
                >
                    <SelectTrigger class="w-full sm:w-[200px]">
                        <SelectValue placeholder="Filter by category" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            <SelectItem value="all">All categories</SelectItem>
                            <SelectItem
                                v-for="category in categories"
                                :key="category"
                                :value="category"
                            >
                                {{ category }}
                            </SelectItem>
                        </SelectGroup>
                    </SelectContent>
                </Select>

                <Popover v-if="vendorCities.length > 1">
                    <PopoverTrigger as-child>
                        <Button variant="outline" class="h-10 justify-between gap-2">
                            <span class="flex items-center gap-1.5">
                                <MapPin class="h-4 w-4" />
                                Hide cities
                                <span
                                    v-if="hiddenCities.length"
                                    class="rounded-full bg-primary px-1.5 text-xs font-semibold text-primary-foreground"
                                >
                                    {{ hiddenCities.length }}
                                </span>
                            </span>
                            <ChevronDown class="h-4 w-4 opacity-50" />
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent align="end" class="w-56 p-2">
                        <p class="px-2 pb-2 text-xs text-muted-foreground">
                            Checked cities are hidden from your board.
                        </p>
                        <div class="max-h-64 overflow-y-auto">
                            <div
                                v-for="city in vendorCities"
                                :key="city"
                                class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-accent"
                                @click="toggleCity(city)"
                            >
                                <Checkbox
                                    class="pointer-events-none"
                                    :checked="isCityHidden(city)"
                                />
                                <span :class="{ 'text-muted-foreground line-through': isCityHidden(city) }">
                                    {{ city }}
                                </span>
                            </div>
                        </div>
                        <Button
                            v-if="hiddenCities.length"
                            variant="ghost"
                            size="sm"
                            class="mt-1 w-full justify-center text-xs"
                            @click="showAllCities"
                        >
                            Clear all
                        </Button>
                    </PopoverContent>
                </Popover>
            </div>
        </div>

        <Deferred data="workOrders">
            <template #fallback>
                <div
                    class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
                >
                    <Skeleton
                        v-for="n in 8"
                        :key="n"
                        class="h-40 w-full rounded-lg"
                    />
                </div>
            </template>

            <!-- Kanban: copies the staff board layout — one column per
                 workflow stage, the bordered uppercase header on top and the
                 vendor's cards stacked beneath. Empty columns disappear. -->
            <div
                v-if="columns.length"
                class="flex flex-row flex-nowrap space-x-2 overflow-x-auto scrollbar-hide"
            >
                <div
                    v-for="column in columns"
                    :key="column.key"
                    class="overflow-hidden min-w-[240px] flex-1"
                >
                    <div class="text-center">
                        <div
                            class="h-16 flex items-center justify-center border p-3 text-sm uppercase font-semibold"
                        >
                            <p>{{ column.label }} ({{ column.workOrders.length }})</p>
                        </div>

                        <ScrollArea class="h-[70vh] overflow-y-auto border-t pt-2 mb-5">
                            <div
                                v-for="wo in column.workOrders"
                                :key="wo.id"
                                @click="open(wo.id)"
                                class="mb-2 cursor-pointer rounded-lg p-4 text-white shadow-sm transition-all hover:shadow-lg"
                                :class="cardColorClass(wo)"
                            >
                    <!-- Work order number & created date -->
                    <div
                        class="mb-2 flex items-center justify-between border-b border-white/40 pb-2"
                    >
                        <h2 class="text-lg font-semibold">
                            {{ wo.work_order_no }}
                        </h2>
                        <p class="shrink-0 text-xs text-gray-200">
                            📅 {{ formatDate(wo.created_date) }}
                        </p>
                    </div>

                    <!-- Location -->
                    <p
                        v-if="wo.location"
                        class="text-sm font-semibold text-gray-100"
                    >
                        {{ wo.location }}
                    </p>

                    <!-- Property name -->
                    <p
                        v-if="wo.building?.name"
                        class="text-center text-xs font-medium text-gray-200"
                    >
                        {{ wo.building.name }}
                    </p>

                    <!-- Category -->
                    <p
                        v-if="wo.category"
                        class="flex items-center justify-center gap-1 text-xs text-gray-100"
                    >
                        <Tag class="h-3 w-3" />{{ wo.category }}
                    </p>

                    <!-- Approved -->
                    <p
                        v-if="wo.is_approved"
                        class="flex items-center justify-center gap-1 text-xs text-gray-100"
                    >
                        <CircleCheckBig class="h-3 w-3" />Approved
                    </p>

                    <!-- Tasks + priority -->
                    <div class="mt-2 flex items-center justify-between">
                        <p v-if="wo.tasks?.length > 0" class="text-xs">
                            {{ countCompletedTask(wo.tasks) }}/{{
                                wo.tasks.length
                            }}
                            tasks
                        </p>
                        <span v-else></span>
                        <span
                            v-if="wo.priority"
                            class="rounded border px-1 text-[10px] uppercase"
                            :class="
                                wo.priority === 'High'
                                    ? 'bg-destructive'
                                    : 'bg-primary'
                            "
                        >
                            Priority: {{ wo.priority }}
                        </span>
                    </div>
                            </div>
                        </ScrollArea>
                    </div>
                </div>
            </div>

            <div
                v-else
                class="rounded-lg border bg-card p-10 text-center text-card-foreground shadow-sm"
            >
                <ClipboardList
                    class="mx-auto mb-3 h-10 w-10 text-muted-foreground/40"
                />
                <p class="text-sm text-muted-foreground">
                    {{
                        hasActiveFilters
                            ? "No work orders match your filters."
                            : "You have no work orders right now."
                    }}
                </p>
            </div>
        </Deferred>
    </div>
</template>
