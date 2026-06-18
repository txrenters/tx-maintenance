<script setup>
import { computed } from "vue";
import { Head } from "@inertiajs/vue3";
import {
    MapPin,
    AlertTriangle,
    ChevronRight,
    ClipboardList,
    MessageSquare,
} from "lucide-vue-next";

const props = defineProps({
    vendorName: String,
    workOrders: { type: Array, default: () => [] },
});

const priorityClass = (priority) => {
    const p = (priority || "").toLowerCase();
    if (p.includes("high") || p.includes("emergency"))
        return "bg-destructive/10 text-destructive";
    if (p.includes("medium")) return "bg-amber-100 text-amber-700";
    return "bg-muted text-muted-foreground";
};

const hasJobs = computed(() => props.workOrders.length > 0);
</script>

<template>
    <Head title="My Work Orders" />

    <div class="min-h-screen bg-muted">
        <div class="mx-auto w-full max-w-md lg:max-w-5xl px-4 py-5 space-y-4">
            <div class="px-1">
                <p class="text-xs font-semibold text-muted-foreground">
                    Hi {{ vendorName }}
                </p>
                <h1 class="text-xl font-bold text-foreground">My Work Orders</h1>
            </div>

            <div
                v-if="hasJobs"
                class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
            >
            <a
                v-for="wo in workOrders"
                :key="wo.work_order_no"
                :href="wo.url"
                class="block rounded-lg border bg-card text-card-foreground shadow-sm p-4 active:bg-accent"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="font-bold text-foreground"
                                >#{{ wo.work_order_no }}</span
                            >
                            <span
                                v-if="wo.status"
                                class="text-xs font-medium rounded-full bg-primary/10 text-primary px-2.5 py-0.5"
                            >
                                {{ wo.status }}
                            </span>
                            <span
                                v-if="wo.is_emergency"
                                class="inline-flex items-center gap-1 text-xs font-medium rounded-full bg-destructive/10 text-destructive px-2.5 py-0.5"
                            >
                                <AlertTriangle class="w-3 h-3" /> Emergency
                            </span>
                            <span
                                v-if="wo.unread > 0"
                                class="inline-flex items-center gap-1 text-xs font-medium rounded-full bg-destructive text-destructive-foreground px-2.5 py-0.5"
                            >
                                <MessageSquare class="w-3 h-3" />
                                {{ wo.unread }} new
                            </span>
                        </div>

                        <p
                            v-if="wo.description"
                            class="mt-1 text-sm text-foreground line-clamp-2"
                        >
                            {{ wo.description }}
                        </p>

                        <div
                            v-if="wo.location"
                            class="mt-2 flex items-start gap-1.5 text-xs text-muted-foreground"
                        >
                            <MapPin class="w-3.5 h-3.5 mt-0.5 shrink-0" />
                            <span class="truncate">{{ wo.location }}</span>
                        </div>

                        <span
                            v-if="wo.priority"
                            class="mt-2 inline-block text-xs font-medium rounded-full px-2.5 py-0.5"
                            :class="priorityClass(wo.priority)"
                        >
                            {{ wo.priority }}
                        </span>
                    </div>
                    <ChevronRight class="w-5 h-5 text-muted-foreground/40 shrink-0 mt-1" />
                </div>
            </a>
            </div>

            <div
                v-if="!hasJobs"
                class="rounded-lg border bg-card text-card-foreground shadow-sm p-8 text-center"
            >
                <ClipboardList class="w-10 h-10 text-muted-foreground/40 mx-auto mb-3" />
                <p class="text-muted-foreground text-sm">
                    You have no active work orders right now.
                </p>
            </div>

            <p class="text-center text-xs text-muted-foreground pt-2 pb-6">
                TX Maintenance · Vendor Portal
            </p>
        </div>
    </div>
</template>
