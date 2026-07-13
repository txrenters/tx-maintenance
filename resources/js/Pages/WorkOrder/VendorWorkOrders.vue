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
import { ClipboardList, Search, Tag, MapPin } from "lucide-vue-next";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: { type: String, default: "My Work Orders" },
    workOrders: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    filter: { type: Object, default: () => ({}) },
});

const { open } = useWorkOrderModal();

// Filtering is client-side: a vendor's list is small, so narrowing by status or
// search is instant and needs no server round-trip.
const selectedStatus = ref(props.filter.status ?? "all");
const search = ref(props.filter.search ?? "");

const filteredWorkOrders = computed(() => {
    const term = search.value.trim().toLowerCase();

    return (props.workOrders ?? []).filter((wo) => {
        const matchesStatus =
            selectedStatus.value === "all" ||
            wo.service_status?.name === selectedStatus.value;

        if (!matchesStatus) {
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
                    :modelValue="selectedStatus"
                    @update:modelValue="(value) => (selectedStatus = value)"
                >
                    <SelectTrigger class="w-full sm:w-[240px]">
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
                        class="h-32 w-full rounded-lg"
                    />
                </div>
            </template>

            <div
                v-if="filteredWorkOrders.length"
                class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
            >
                <button
                    v-for="wo in filteredWorkOrders"
                    :key="wo.id"
                    type="button"
                    @click="open(wo.id)"
                    class="block rounded-lg border border-l-4 border-l-primary bg-card p-4 text-left text-card-foreground shadow-sm transition-all hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-ring"
                >
                    <div
                        class="mb-2 flex items-center justify-between border-b border-border pb-2"
                    >
                        <h2 class="text-lg font-semibold">
                            {{ wo.work_order_no }}
                        </h2>
                        <p class="shrink-0 text-xs text-muted-foreground">
                            📅 {{ formatDate(wo.created_date) }}
                        </p>
                    </div>

                    <p
                        v-if="wo.service_status?.name"
                        class="mb-2 inline-block rounded border px-2 py-0.5 text-[11px] font-medium uppercase text-muted-foreground"
                    >
                        {{ wo.service_status.name }}
                    </p>

                    <p
                        v-if="wo.location"
                        class="flex items-start gap-1 text-sm font-semibold text-foreground"
                    >
                        <MapPin class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                        {{ wo.location }}
                    </p>

                    <p
                        v-if="wo.building?.name"
                        class="text-xs font-medium text-muted-foreground"
                    >
                        {{ wo.building.name }}
                    </p>

                    <p
                        v-if="wo.category"
                        class="mt-1 flex items-center gap-1 text-xs text-muted-foreground"
                    >
                        <Tag class="h-3 w-3" />{{ wo.category }}
                    </p>
                </button>
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
                        search || selectedStatus !== "all"
                            ? "No work orders match your filters."
                            : "You have no work orders right now."
                    }}
                </p>
            </div>
        </Deferred>
    </div>
</template>
