<script setup>
import { ref } from "vue";
import { router } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import { useToast } from "@/Components/ui/toast/use-toast";
import SearchBar from "@/Components/SearchBar.vue";
import { DateTime } from "luxon";
import { Tag, Truck, User } from "lucide-vue-next";
import Navigation from "./partials/Navigation.vue";

const { toast } = useToast();

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    jobsByStatus: Object,
    statistics: Object,
    filter: Object,
});

const url = route("inspections.index");
const search = ref(props.filter.search ?? "");

const formatStatus = (status) => {
    return status.replace(/_/g, " ").replace(/\b\w/g, (l) => l.toUpperCase());
};

const formatDate = (date) => {
    if (!date) return "------";

    let parsedDate;

    if (typeof date === "string") {
        if (date.includes("T")) {
            // Handle ISO format (2025-03-06T17:41:20.000000Z)
            parsedDate = DateTime.fromISO(date, { zone: "utc" });
        } else {
            // Handle non-ISO format (2025-03-06 23:10:06)
            parsedDate = DateTime.fromFormat(date, "yyyy-MM-dd HH:mm:ss", {
                zone: "utc",
            });
        }
    } else if (date instanceof Date) {
        parsedDate = DateTime.fromJSDate(date);
    } else {
        return "Invalid Date";
    }

    return parsedDate.isValid
        ? parsedDate.toFormat("MM/dd/yyyy")
        : "Invalid Date";
};

const formatUSD = (value) => {
    if (typeof value !== "number") return value;
    return new Intl.NumberFormat("en-US", {
        style: "currency",
        currency: "USD",
    }).format(value);
};
</script>
<template>
    <Head :title="title" />
    <div class="flex gap-3 flex-col sm:flex-row items-center justify-between">
        <SearchBar :url="url" v-model="search" class="w-full" />
        <Navigation />
    </div>
    <ScrollArea
        class="w-[90vw] sm:w-[85vw] md:w-[75vw] lg:w-[70vw] xl:w-[75vw]"
    >
        <div
            class="flex flex-row flex-nowrap space-x-2 overflow-x-auto scrollbar-hide"
        >
            <div
                v-for="(collection, status) in jobsByStatus"
                :key="status"
                class="overflow-hidden min-w-[250px] max-w-[250px]"
            >
                <div class="text-center font-semibol">
                    <div
                        class="h-16 flex items-center justify-center border p-3 text-sm uppercase font-semibold"
                    >
                        <p>
                            {{ formatStatus(status) }} ({{ collection.length }})
                        </p>
                    </div>
                </div>
                <ScrollArea class="h-[70vh] overflow-y-auto border-t pt-2 mb-5">
                    <div
                        v-motion-slide-visible-once-right
                        v-for="item in collection"
                        :key="item.id"
                        class="mb-2 rounded-lg p-4 text-white cursor-pointer hover:shadow-lg transition-all"
                        :class="{
                            'bg-destructive':
                                item.job_status === 'late' ||
                                item.job_status === 'ending_within_30_days',
                            'bg-primary': item.job_status === 'unscheduled',
                            'bg-orange-400':
                                item.job_status === 'requires_invoicing' ||
                                item.job_status === 'action_required' ||
                                item.job_status === 'requires_invoicing' ||
                                item.job_status === 'on_hold',
                            'bg-green-500':
                                item.job_status === 'upcoming' ||
                                item.job_status === 'active',
                        }"
                    >
                        <!-- Work Order Number & Date -->
                        <div
                            class="flex justify-between items-center border-b pb-2 mb-2"
                        >
                            <h1 class="text-lg font-semibold">
                                {{ item.job_number }}
                            </h1>
                            <p class="text-lg font-semibold">
                                {{ formatUSD(item.total) }}
                            </p>
                        </div>

                        <!-- Location -->
                        <p
                            class="text-sm text-gray-100 text-center font-semibold text-wrap"
                        >
                            {{ item.title }}
                        </p>
                        <div class="flex gap-2 justify-center">
                            <p
                                class="text-xs text-gray-100 flex items-center gap-1 justify-center"
                            >
                                <Tag class="w-3 h-3" />{{
                                    item.job_type === "ONE_OFF"
                                        ? "One-off Job"
                                        : "Recurring Job"
                                }}
                            </p>
                        </div>
                        <div
                            v-if="item.client_name"
                            class="flex justify-start items-start mb-1 mt-2"
                        >
                            <User class="w-4 h-4" />
                            <p class="text-gray-100 text-sm text-wrap">
                                {{ item.client_name }}
                            </p>
                        </div>
                        <div class="flex justify-between items-center mt-2">
                            <p class="text-xs text-gray-200 flex gap-1">
                                <Truck class="w-4 h-4" />
                                Visits: {{ item.visits_count }}
                            </p>
                            <p class="text-xs text-gray-200">
                                📅 {{ formatDate(item.start_at) }}
                            </p>
                        </div>
                    </div>

                    <ScrollBar orientation="vertical" />
                </ScrollArea>
            </div>
        </div>
        <ScrollBar orientation="horizontal" />
    </ScrollArea>

    <div class="">
        <span class="text-gray-600">Drag/swipe the scrollbar →</span>
    </div>
</template>
