<script setup>
import { ref, computed } from "vue";
import { Head, Deferred } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import { useWorkOrderModal } from "@/composables/useWorkOrderModal";
import { Skeleton } from "@/Components/ui/skeleton";
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/Components/ui/select";
import { ClipboardList, Search, Tag, CircleCheckBig } from "lucide-vue-next";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: { type: String, default: "Work Orders" },
    workOrders: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    filter: { type: Object, default: () => ({}) },
});

const { open } = useWorkOrderModal();

// Filtering is client-side: a vendor's list is small, so narrowing by status,
// category, or search is instant and needs no server round-trip. The dropdown
// options themselves are already limited server-side to what the vendor has.
const selectedStatus = ref(props.filter.status ?? "all");
const selectedCategory = ref(props.filter.category ?? "all");
const search = ref(props.filter.search ?? "");

const filteredWorkOrders = computed(() => {
    const term = search.value.trim().toLowerCase();

    return (props.workOrders ?? []).filter((wo) => {
        const matchesStatus =
            selectedStatus.value === "all" ||
            wo.service_status?.name === selectedStatus.value;

        const matchesCategory =
            selectedCategory.value === "all" ||
            wo.category === selectedCategory.value;

        if (!matchesStatus || !matchesCategory) {
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
                    v-if="statuses.length"
                    :modelValue="selectedStatus"
                    @update:modelValue="(value) => (selectedStatus = value)"
                >
                    <SelectTrigger class="w-full sm:w-[200px]">
                        <SelectValue placeholder="Filter by status" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            <SelectItem value="all">All statuses</SelectItem>
                            <SelectItem
                                v-for="status in statuses"
                                :key="status.id"
                                :value="status.name"
                            >
                                {{ status.name }}
                            </SelectItem>
                        </SelectGroup>
                    </SelectContent>
                </Select>

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
                        search ||
                        selectedStatus !== "all" ||
                        selectedCategory !== "all"
                            ? "No work orders match your filters."
                            : "You have no work orders right now."
                    }}
                </p>
            </div>
        </Deferred>
    </div>
</template>
