<script setup>
import { ref, computed, onMounted } from "vue";
import { Head } from "@inertiajs/vue3";
import {
    Tag,
    ClipboardList,
    MessageSquare,
    LogIn,
    Sun,
    Moon,
} from "lucide-vue-next";

const props = defineProps({
    vendorName: String,
    loginUrl: String,
    workOrders: { type: Array, default: () => [] },
});

// Default to dark (the in-app dashboard look); remember the vendor's choice.
const isDark = ref(true);

onMounted(() => {
    const saved = localStorage.getItem("vendorPortalTheme");
    if (saved) {
        isDark.value = saved === "dark";
    }
});

const toggleTheme = () => {
    isDark.value = !isDark.value;
    localStorage.setItem("vendorPortalTheme", isDark.value ? "dark" : "light");
};

// Group the vendor's work orders into status columns, ordered like the
// dashboard (by service-status id).
const columns = computed(() => {
    const map = new Map();
    for (const wo of props.workOrders) {
        const key = wo.status || "Other";
        if (!map.has(key)) {
            map.set(key, {
                name: key,
                order: wo.service_status_id ?? 9999,
                work_orders: [],
            });
        }
        map.get(key).work_orders.push(wo);
    }
    return [...map.values()].sort((a, b) => a.order - b.order);
});

const hasJobs = computed(() => props.workOrders.length > 0);

// Left accent (light mode) + full colored background (dark mode), mirroring the
// in-app WorkOrderCard: red = emergency/overdue, blue = due today, green = upcoming.
const accentClasses = (accent) => {
    if (accent === "red") return "border-l-destructive dark:bg-destructive";
    if (accent === "blue") return "border-l-primary dark:bg-primary";
    return "border-l-green-500 dark:bg-green-500";
};
</script>

<template>
    <div :class="isDark ? 'dark' : ''">
        <Head title="My Work Orders" />

        <div class="min-h-screen bg-muted dark:bg-neutral-950">
            <div class="mx-auto w-full max-w-md lg:max-w-6xl px-4 py-5 space-y-4">
                <div class="flex items-start justify-between gap-3 px-1">
                    <div class="min-w-0">
                        <p
                            class="text-xs font-semibold text-muted-foreground dark:text-neutral-400"
                        >
                            Hi {{ vendorName }}
                        </p>
                        <h1
                            class="text-xl font-bold text-foreground dark:text-white"
                        >
                            My Work Orders
                        </h1>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <button
                            type="button"
                            @click="toggleTheme"
                            :title="
                                isDark
                                    ? 'Switch to light mode'
                                    : 'Switch to dark mode'
                            "
                            class="inline-flex items-center justify-center rounded-md border border-input bg-background p-2 text-foreground shadow-sm transition-colors hover:bg-accent dark:border-neutral-700 dark:bg-neutral-800 dark:text-white dark:hover:bg-neutral-700"
                        >
                            <Sun v-if="isDark" class="w-4 h-4" />
                            <Moon v-else class="w-4 h-4" />
                        </button>
                        <a
                            v-if="loginUrl"
                            :href="loginUrl"
                            class="inline-flex items-center gap-1.5 rounded-md border border-input bg-background px-3 py-1.5 text-sm font-medium text-foreground shadow-sm transition-colors hover:bg-accent dark:border-neutral-700 dark:bg-neutral-800 dark:text-white dark:hover:bg-neutral-700"
                        >
                            <LogIn class="w-4 h-4" />
                            Login
                        </a>
                    </div>
                </div>

                <!-- Status columns -->
                <div
                    v-if="hasJobs"
                    class="flex flex-row flex-nowrap gap-2 overflow-x-auto pb-3"
                >
                    <div
                        v-for="col in columns"
                        :key="col.name"
                        class="min-w-[260px] flex-shrink-0"
                    >
                        <!-- Column header -->
                        <div
                            class="h-16 flex items-center justify-center border p-3 text-center text-sm uppercase font-semibold text-foreground dark:text-white dark:border-neutral-800"
                        >
                            <p>{{ col.name }} ({{ col.work_orders.length }})</p>
                        </div>

                        <!-- Cards -->
                        <div
                            class="mt-2 space-y-2 max-h-[70vh] overflow-y-auto pr-1"
                        >
                            <a
                                v-for="wo in col.work_orders"
                                :key="wo.work_order_no"
                                :href="wo.url"
                                class="block rounded-lg border border-l-4 p-4 shadow-sm transition-all hover:shadow-lg bg-card text-card-foreground dark:border-0 dark:text-white"
                                :class="accentClasses(wo.accent)"
                            >
                                <!-- Number & date -->
                                <div
                                    class="flex justify-between items-center border-b border-border dark:border-white/30 pb-2 mb-2"
                                >
                                    <h2 class="text-lg font-semibold">
                                        {{ wo.work_order_no }}
                                    </h2>
                                    <p
                                        class="text-xs text-muted-foreground dark:text-gray-200 shrink-0"
                                    >
                                        📅 {{
                                            new Date(
                                                String(wo.created_date).replace(
                                                    " ",
                                                    "T"
                                                )
                                            ).toLocaleDateString("en-US")
                                        }}
                                    </p>
                                </div>

                                <!-- Location -->
                                <p
                                    v-if="wo.location"
                                    class="text-sm font-semibold text-foreground dark:text-gray-100"
                                >
                                    {{ wo.location }}
                                </p>

                                <!-- Property name -->
                                <p
                                    v-if="wo.building"
                                    class="text-center text-xs font-medium text-muted-foreground dark:text-gray-200"
                                >
                                    {{ wo.building }}
                                </p>

                                <!-- Category -->
                                <p
                                    v-if="wo.category"
                                    class="text-xs flex items-center gap-1 justify-center text-muted-foreground dark:text-gray-100"
                                >
                                    <Tag class="w-3 h-3" />{{ wo.category }}
                                </p>

                                <!-- Unread messages (portal-only) -->
                                <p
                                    v-if="wo.unread > 0"
                                    class="mt-1 text-xs flex items-center gap-1 justify-center text-destructive dark:text-white font-medium"
                                >
                                    <MessageSquare class="w-3 h-3" />{{
                                        wo.unread
                                    }}
                                    new message<span v-if="wo.unread > 1"
                                        >s</span
                                    >
                                </p>

                                <!-- Tasks + priority -->
                                <div class="flex justify-between items-center mt-2">
                                    <p
                                        v-if="wo.total_tasks > 0"
                                        class="text-xs"
                                    >
                                        {{ wo.completed_tasks }}/{{
                                            wo.total_tasks
                                        }}
                                        tasks
                                    </p>
                                    <span v-else></span>
                                    <span
                                        v-if="wo.priority"
                                        class="text-[10px] px-1 uppercase rounded border text-white"
                                        :class="
                                            wo.priority === 'High'
                                                ? 'bg-destructive'
                                                : 'bg-primary'
                                        "
                                    >
                                        Priority: {{ wo.priority }}
                                    </span>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>

                <div
                    v-if="!hasJobs"
                    class="rounded-lg border bg-card text-card-foreground shadow-sm p-8 text-center dark:border-neutral-800 dark:bg-neutral-900"
                >
                    <ClipboardList
                        class="w-10 h-10 text-muted-foreground/40 dark:text-neutral-600 mx-auto mb-3"
                    />
                    <p class="text-muted-foreground dark:text-neutral-400 text-sm">
                        You have no active work orders right now.
                    </p>
                </div>

                <p
                    class="text-center text-xs text-muted-foreground dark:text-neutral-500 pt-2 pb-6"
                >
                    TX Maintenance · Vendor Portal
                </p>
            </div>
        </div>
    </div>
</template>
