<script setup>
import { ref, computed, watch } from "vue";
import { Head, Deferred } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import { useWorkOrderModal } from "@/composables/useWorkOrderModal";
import { useCityFilter, matchesCityFilter, cityFilterOptions } from "@/composables/useCityFilter";
import { Skeleton } from "@/Components/ui/skeleton";
import { Tabs, TabsList, TabsTrigger } from "@/Components/ui/tabs";
import { ScrollArea, ScrollBar } from "@/Components/ui/scroll-area";
import FilterChip from "@/Components/FilterChip.vue";
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/Components/ui/select";
import { ClipboardList, Search, Tag, CircleCheckBig, MapPin } from "lucide-vue-next";

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
// useCityFilter), so e.g. THMP can keep another vendor's city hidden.
const { cityFilter } = useCityFilter();

const vendorCities = computed(() =>
    [
        ...new Set(
            (props.workOrders ?? [])
                .map((wo) => wo.building?.city)
                .filter(Boolean),
        ),
    ].sort(),
);

const cityOptions = computed(() =>
    cityFilterOptions(vendorCities.value, cityFilter.value),
);

// The workflow tabs, in display order. Statuses with no entry here fall into
// "other"; "all" shows everything. Names must match the service_statuses
// seeder spelling exactly (notably "Followup", not "Follow Up").
const STATUS_TAB_GROUPS = {
    new: ["New"],
    scheduling: [
        "Assigned - Waiting on Scheduling",
        "Owner Approved - Waiting on Scheduling",
    ],
    scheduled: ["Scheduled"],
    payment: [
        "Approved - Waiting on Payment",
        "Completed - Verified - Waiting on Bill",
        "Bill Attached - Waiting on Approval",
    ],
    completed: [
        "Service Completed - Call Tenant for Followup",
        "Completed - Verified - Updating Owner",
        "Owner Completing Work",
        "Paid",
    ],
};

const TAB_LABELS = {
    all: "All",
    new: "New",
    scheduling: "Waiting on Scheduling",
    scheduled: "Scheduled",
    payment: "Waiting on Payment",
    completed: "Completed",
    other: "Other",
};

const tabForStatus = (statusName) => {
    const match = Object.entries(STATUS_TAB_GROUPS).find(([, names]) =>
        names.includes(statusName),
    );

    return match ? match[0] : "other";
};

const activeTab = ref("all");

// Search, category, and city narrow the whole list; the tabs then split what
// remains, so the counts on the tab strip always match the visible grid.
const baseFilteredWorkOrders = computed(() => {
    const term = search.value.trim().toLowerCase();

    return (props.workOrders ?? []).filter((wo) => {
        const matchesCategory =
            selectedCategory.value === "all" ||
            wo.category === selectedCategory.value;

        if (!matchesCategory || !matchesCityFilter(cityFilter.value, wo.building?.city)) {
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

const tabCounts = computed(() => {
    const counts = { all: baseFilteredWorkOrders.value.length, other: 0 };

    for (const key of Object.keys(STATUS_TAB_GROUPS)) {
        counts[key] = 0;
    }

    for (const wo of baseFilteredWorkOrders.value) {
        counts[tabForStatus(wo.service_status?.name)]++;
    }

    return counts;
});

// The Other tab only renders while it has work orders; hop back to All if the
// active tab disappears (or empties out from a filter change).
watch(tabCounts, (counts) => {
    if (activeTab.value === "other" && counts.other === 0) {
        activeTab.value = "all";
    }
});

const filteredWorkOrders = computed(() =>
    activeTab.value === "all"
        ? baseFilteredWorkOrders.value
        : baseFilteredWorkOrders.value.filter(
              (wo) => tabForStatus(wo.service_status?.name) === activeTab.value,
          ),
);

const hasActiveFilters = computed(
    () =>
        !!search.value.trim() ||
        selectedCategory.value !== "all" ||
        cityFilter.value !== "all",
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

                <FilterChip
                    v-if="cityOptions.length > 1"
                    label="City"
                    :icon="MapPin"
                    :model-value="cityFilter"
                    :options="cityOptions"
                    searchable
                    search-placeholder="Search cities…"
                    @update:modelValue="(value) => (cityFilter = value)"
                />

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

            <!-- Status tabs: the tab strip lives inside the Deferred slot so
                 its counts only ever render from the loaded list. -->
            <Tabs v-model:model-value="activeTab" class="mb-4">
                <ScrollArea class="w-full whitespace-nowrap rounded-md border">
                    <TabsList class="w-full justify-start">
                        <TabsTrigger
                            v-for="tab in ['all', 'new', 'scheduling', 'scheduled', 'payment', 'completed', 'other']"
                            v-show="tab !== 'other' || tabCounts.other > 0"
                            :key="tab"
                            :value="tab"
                        >
                            {{ TAB_LABELS[tab] }} ({{ tabCounts[tab] ?? 0 }})
                        </TabsTrigger>
                    </TabsList>
                    <ScrollBar orientation="horizontal" />
                </ScrollArea>
            </Tabs>

            <div
                v-if="filteredWorkOrders.length"
                class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
            >
                <div
                    v-for="wo in filteredWorkOrders"
                    :key="wo.id"
                    @click="open(wo.id)"
                    class="cursor-pointer rounded-lg p-4 text-white shadow-sm transition-all hover:shadow-lg"
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
                            : activeTab !== "all"
                              ? `No work orders in "${TAB_LABELS[activeTab]}".`
                              : "You have no work orders right now."
                    }}
                </p>
            </div>
        </Deferred>
    </div>
</template>
