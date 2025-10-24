<script setup>
import { computed, ref, watch, onMounted } from "vue";
import { Head } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import { usePoll, router } from "@inertiajs/vue3";
import {
    ArrowUpRight,
    DollarSign,
    ListChecks,
    Truck,
    Wrench,
    TrendingUp,
    TrendingDown,
    Clock,
    CheckCircle,
    CheckCircle2,
    AlertTriangle,
    Users,
    Calendar,
    BarChart3,
    Hammer,
    HammerIcon,
} from "lucide-vue-next";

defineOptions({ layout: AppLayout });

import BarChart from "@/chart/BarChart.vue";
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from "@/Components/ui/card";
import { Badge } from "@/Components/ui/badge";
import { Progress } from "@/Components/ui/progress";
import { Skeleton } from "@/Components/ui/skeleton";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
    SelectGroup,
} from "@/Components/ui/select";
import { string } from "zod";

const props = defineProps({
    title: String,
    stats: Object, // Essential stats loaded immediately
    workOrderChart: Object, // Lazy loaded
    serviceStatus: Object, // Lazy loaded
    inspectionAnalytics: Object, // Lazy loaded
    filter: Object,
});

// Use pre-calculated stats for better performance
const upcomingInspections = computed(
    () => props.stats?.upcoming_inspections || 0
);
const overdueInspections = computed(
    () => props.stats?.overdue_inspections || 0
);

// All data is loaded immediately, no need for loading states
const isChartLoading = computed(() => false);
const isServiceStatusLoading = computed(() => false);
const isInspectionAnalyticsLoading = computed(() => false);

// Get inspection completion rate from analytics or stats
const inspectionCompletionRate = computed(() => {
    return props.inspectionAnalytics?.completionRate || 0;
});

function getCompletionPercentage(completed, total) {
    if (total === 0) return "0.00";
    return ((completed / total) * 100).toFixed(2);
}

const currentYear = new Date().getFullYear();
const years = Array.from(
    { length: currentYear - 2023 },
    (_, i) => currentYear - i
);

const selectedYear = ref(props.filter.year ?? currentYear);

watch(selectedYear, (newYear) => {
    router.visit(`/dashboard?year=${newYear}`, {
        preserveState: true,
        preserveScroll: true,
    });
});
// Optimized polling - only essential stats, longer interval
const isVisible = ref(true);

// Only poll when page is visible to reduce server load
if (typeof document !== "undefined") {
    document.addEventListener("visibilitychange", () => {
        isVisible.value = !document.hidden;
    });
}

// More conservative polling - only refresh critical stats
usePoll(30000, {
    preserveState: true,
    preserveScroll: true,
    only: ["stats"], // Only refresh essential statistics
});

// Use pre-calculated values from server
const monthlyGrowthRate = computed(() => props.stats?.monthly_growth_rate || 0);
const averageCompletionTime = computed(
    () => props.stats?.average_completion_time || 0
);

const formattedCount = (number) => {
    return number.toLocaleString();
};

// Service status computed property
const serviceStatus = computed(() => props.serviceStatus || []);

// Helper functions for service status progress bars
const getStatusPercentage = (total) => {
    if (!serviceStatus.value || serviceStatus.value.length === 0) return 0;

    const maxTotal = Math.max(...serviceStatus.value.map((s) => s.total || 0));
    if (maxTotal === 0) return 0;

    // Always show at least a small bar (5%) if there's any value, max 100%
    return total === 0
        ? 0
        : Math.max(5, Math.min(100, (total / maxTotal) * 100));
};

const getStatusColor = (index) => {
    const colors = [
        "from-blue-500 to-blue-600", // Blue
        "from-green-500 to-green-600", // Green
        "from-yellow-500 to-yellow-600", // Yellow
        "from-purple-500 to-purple-600", // Purple
        "from-red-500 to-red-600", // Red
        "from-indigo-500 to-indigo-600", // Indigo
        "from-pink-500 to-pink-600", // Pink
        "from-teal-500 to-teal-600", // Teal
    ];

    return colors[index % colors.length];
};
</script>

<template>
    <Head :title="title" />
    <!-- Modern Hero Section -->
    <div class="mb-8">
        <div
            class="relative overflow-hidden bg-gradient-to-br from-blue-500 via-indigo-700 to-blue-700 text-white rounded-xl shadow-2xl p-6 md:p-8"
        >
            <!-- Content -->
            <div class="relative z-10 flex justify-between items-center">
                <div class="flex-1">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="p-2 bg-white/20 rounded-lg backdrop-blur">
                            <Wrench class="h-6 w-6" />
                        </div>
                        <h1 class="text-2xl md:text-3xl font-bold">
                            Maintenance Dashboard
                        </h1>
                    </div>
                    <p class="text-lg opacity-90 mb-4">
                        Welcome back, {{ $page.props.auth.user.name }}! Here's
                        your maintenance overview.
                    </p>

                    <!-- Quick Stats Row -->
                    <div class="flex flex-wrap gap-4">
                        <div
                            class="flex items-center gap-2 bg-white/10 backdrop-blur rounded-lg px-3 py-2"
                        >
                            <Wrench class="h-4 w-4" />
                            <span class="text-sm font-medium"
                                >{{
                                    formattedCount(
                                        stats?.monthly_work_orders || 0
                                    )
                                }}
                                work orders this month</span
                            >
                        </div>
                        <div
                            class="flex items-center gap-2 bg-white/10 backdrop-blur rounded-lg px-3 py-2"
                        >
                            <Clock class="h-4 w-4" />
                            <span class="text-sm font-medium"
                                >{{ averageCompletionTime }} days avg completion
                                time</span
                            >
                        </div>
                        <div
                            v-if="stats?.urgent_work_orders > 0"
                            class="flex items-center gap-2 bg-red-500/20 backdrop-blur rounded-lg px-3 py-2"
                        >
                            <AlertTriangle class="h-4 w-4" />
                            <span class="text-sm font-medium"
                                >{{ stats.urgent_work_orders }} urgent work
                                {{
                                    stats.urgent_work_orders === 1
                                        ? "order"
                                        : "orders"
                                }}</span
                            >
                        </div>
                    </div>
                </div>

                <!-- Decorative Icon -->
                <div class="hidden md:block">
                    <div class="relative">
                        <div
                            class="absolute inset-0 bg-white/10 rounded-full blur-xl"
                        ></div>
                        <div
                            class="relative p-6 bg-white/10 backdrop-blur rounded-full"
                        >
                            <BarChart3 class="h-12 w-12" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Enhanced Analytics Cards -->
    <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-4 mb-8">
        <!-- Total Work Orders Card -->
        <Card
            class="relative overflow-hidden border-0 shadow-lg bg-gradient-to-br from-blue-50 to-blue-100 dark:from-blue-950 dark:to-blue-900"
        >
            <CardHeader
                class="flex flex-row items-center justify-between space-y-0 pb-2"
            >
                <CardTitle
                    class="text-sm font-medium text-blue-700 dark:text-blue-300"
                    >Total Work Orders</CardTitle
                >
                <div class="p-2 bg-blue-500/10 rounded-lg">
                    <Wrench class="h-4 w-4 text-blue-600 dark:text-blue-400" />
                </div>
            </CardHeader>
            <CardContent>
                <div
                    class="text-3xl font-bold text-blue-900 dark:text-blue-100"
                >
                    {{ formattedCount(stats?.total_work_orders || 0) }}
                </div>
                <div class="flex items-center gap-2 mt-2">
                    <div
                        :class="
                            monthlyGrowthRate >= 0
                                ? 'text-green-600'
                                : 'text-red-600'
                        "
                        class="flex items-center text-xs font-medium"
                    >
                        <component
                            :is="
                                monthlyGrowthRate >= 0
                                    ? TrendingUp
                                    : TrendingDown
                            "
                            class="h-3 w-3 mr-1"
                        />
                        {{ Math.abs(monthlyGrowthRate).toFixed(1) }}%
                    </div>
                    <span class="text-xs text-muted-foreground"
                        >vs last month</span
                    >
                </div>
            </CardContent>
            <div class="absolute top-0 right-0 w-20 h-20 opacity-5">
                <Wrench class="w-full h-full" />
            </div>
        </Card>
        <!-- Completed Work Orders Card -->
        <Card
            v-if="
                $page.props.auth.user.roles.includes('tenant') ||
                $page.props.auth.user.roles.includes('owner')
            "
            class="relative overflow-hidden border-0 shadow-lg bg-gradient-to-br from-green-50 to-emerald-100 dark:from-green-950 dark:to-emerald-900"
        >
            <CardHeader
                class="flex flex-row items-center justify-between space-y-0 pb-2"
            >
                <CardTitle
                    class="text-sm font-medium text-green-700 dark:text-green-300"
                    >Completed</CardTitle
                >
                <div class="p-2 bg-green-500/10 rounded-lg">
                    <CheckCircle
                        class="h-4 w-4 text-green-600 dark:text-green-400"
                    />
                </div>
            </CardHeader>
            <CardContent>
                <div
                    class="text-3xl font-bold text-green-900 dark:text-green-100"
                >
                    {{ formattedCount(stats?.completed_work_orders || 0) }}
                </div>
                <div class="mt-3">
                    <div class="flex justify-between items-center mb-1">
                        <span class="text-xs text-muted-foreground"
                            >Completion Rate</span
                        >
                        <span
                            class="text-xs font-medium text-green-700 dark:text-green-300"
                        >
                            {{
                                getCompletionPercentage(
                                    stats?.completed_work_orders || 0,
                                    stats?.total_work_orders || 1
                                )
                            }}%
                        </span>
                    </div>
                    <Progress
                        :model-value="
                            parseFloat(
                                getCompletionPercentage(
                                    stats?.completed_work_orders || 0,
                                    stats?.total_work_orders || 1
                                )
                            )
                        "
                        class="h-2 bg-green-200 dark:bg-green-800"
                    />
                </div>
            </CardContent>
            <div class="absolute top-0 right-0 w-20 h-20 opacity-5">
                <CheckCircle class="w-full h-full" />
            </div>
        </Card>
        <!-- Pending Work Orders Card -->
        <Card
            v-if="
                $page.props.auth.user.roles.includes('tenant') ||
                $page.props.auth.user.roles.includes('owner') ||
                $page.props.auth.user.roles.includes('vendor')
            "
            class="relative overflow-hidden border-0 shadow-lg bg-gradient-to-br from-amber-50 to-orange-100 dark:from-amber-950 dark:to-orange-900"
        >
            <CardHeader
                class="flex flex-row items-center justify-between space-y-0 pb-2"
            >
                <CardTitle
                    class="text-sm font-medium text-amber-700 dark:text-amber-300"
                    >Pending</CardTitle
                >
                <div class="p-2 bg-amber-500/10 rounded-lg">
                    <Clock class="h-4 w-4 text-amber-600 dark:text-amber-400" />
                </div>
            </CardHeader>
            <CardContent>
                <div
                    class="text-3xl font-bold text-amber-900 dark:text-amber-100"
                >
                    {{ formattedCount(stats?.pending_work_orders || 0) }}
                </div>
                <div class="mt-2 flex items-center justify-between">
                    <Badge
                        :variant="
                            (stats?.urgent_work_orders || 0) > 0
                                ? 'destructive'
                                : 'secondary'
                        "
                        class="text-xs"
                    >
                        {{ stats?.urgent_work_orders || 0 }} urgent
                    </Badge>
                    <span class="text-xs text-muted-foreground"
                        >{{
                            getCompletionPercentage(
                                stats?.pending_work_orders || 0,
                                stats?.total_work_orders || 1
                            )
                        }}% of total</span
                    >
                </div>
            </CardContent>
            <div class="absolute top-0 right-0 w-20 h-20 opacity-5">
                <Clock class="w-full h-full" />
            </div>
        </Card>
        <!-- In Progress Work Orders Card -->
        <Card
            v-if="
                $page.props.auth.user.roles.includes('tenant') ||
                $page.props.auth.user.roles.includes('owner') ||
                $page.props.auth.user.roles.includes('vendor')
            "
            class="relative overflow-hidden border-0 shadow-lg bg-gradient-to-br from-purple-50 to-indigo-100 dark:from-purple-950 dark:to-indigo-900"
        >
            <CardHeader
                class="flex flex-row items-center justify-between space-y-0 pb-2"
            >
                <CardTitle
                    class="text-sm font-medium text-purple-700 dark:text-purple-300"
                    >In Progress</CardTitle
                >
                <div class="p-2 bg-purple-500/10 rounded-lg">
                    <TrendingUp
                        class="h-4 w-4 text-purple-600 dark:text-purple-400"
                    />
                </div>
            </CardHeader>
            <CardContent>
                <div
                    class="text-3xl font-bold text-purple-900 dark:text-purple-100"
                >
                    {{ formattedCount(stats?.process_work_orders || 0) }}
                </div>
                <div class="mt-3">
                    <div class="flex justify-between items-center mb-1">
                        <span class="text-xs text-muted-foreground"
                            >Active Work</span
                        >
                        <span
                            class="text-xs font-medium text-purple-700 dark:text-purple-300"
                        >
                            {{
                                getCompletionPercentage(
                                    stats?.process_work_orders || 0,
                                    stats?.total_work_orders || 1
                                )
                            }}%
                        </span>
                    </div>
                    <Progress
                        :model-value="
                            parseFloat(
                                getCompletionPercentage(
                                    stats?.process_work_orders || 0,
                                    stats?.total_work_orders || 1
                                )
                            )
                        "
                        class="h-2 bg-purple-200 dark:bg-purple-800"
                    />
                </div>
            </CardContent>
            <div class="absolute top-0 right-0 w-20 h-20 opacity-5">
                <TrendingUp class="w-full h-full" />
            </div>
        </Card>
        <!-- Tasks Card -->
        <Card
            v-if="
                !$page.props.auth.user.roles.includes('tenant') &&
                !$page.props.auth.user.roles.includes('owner')
            "
            class="relative overflow-hidden border-0 shadow-lg bg-gradient-to-br from-teal-50 to-cyan-100 dark:from-teal-950 dark:to-cyan-900"
        >
            <CardHeader
                class="flex flex-row items-center justify-between space-y-0 pb-2"
            >
                <CardTitle
                    class="text-sm font-medium text-teal-700 dark:text-teal-300"
                    >Tasks</CardTitle
                >
                <div class="p-2 bg-teal-500/10 rounded-lg">
                    <ListChecks
                        class="h-4 w-4 text-teal-600 dark:text-teal-400"
                    />
                </div>
            </CardHeader>
            <CardContent>
                <div
                    class="text-3xl font-bold text-teal-900 dark:text-teal-100"
                >
                    {{ formattedCount(stats?.total_tasks || 0) }}
                </div>
                <div class="mt-3">
                    <div class="flex justify-between items-center mb-1">
                        <span class="text-xs text-muted-foreground"
                            >Completed</span
                        >
                        <span
                            class="text-xs font-medium text-teal-700 dark:text-teal-300"
                        >
                            {{ formattedCount(stats?.completed_tasks || 0) }}/{{
                                formattedCount(stats?.total_tasks || 0)
                            }}
                        </span>
                    </div>
                    <Progress
                        :model-value="
                            parseFloat(
                                getCompletionPercentage(
                                    stats?.completed_tasks || 0,
                                    stats?.total_tasks || 1
                                )
                            )
                        "
                        class="h-2 bg-teal-200 dark:bg-teal-800"
                    />
                </div>
            </CardContent>
            <div class="absolute top-0 right-0 w-20 h-20 opacity-5">
                <ListChecks class="w-full h-full" />
            </div>
        </Card>
        <!-- Inspection Jobs Card -->
        <Card
            v-if="
                $page.props.auth.user.roles.includes('admin') &&
                $page.props.auth.user.roles.includes('woc')
            "
            class="relative overflow-hidden border-0 shadow-lg bg-gradient-to-br from-emerald-50 to-green-100 dark:from-emerald-950 dark:to-green-900"
        >
            <CardHeader
                class="flex flex-row items-center justify-between space-y-0 pb-2"
            >
                <CardTitle
                    class="text-sm font-medium text-emerald-700 dark:text-emerald-300"
                    >Inspection Jobs</CardTitle
                >
                <div class="p-2 bg-emerald-500/10 rounded-lg">
                    <HammerIcon
                        class="h-4 w-4 text-emerald-600 dark:text-emerald-400"
                    />
                </div>
            </CardHeader>
            <CardContent>
                <div
                    class="text-3xl font-bold text-emerald-900 dark:text-emerald-100"
                >
                    {{ formattedCount(stats?.total_inspections || 0) }}
                </div>
                <div class="mt-2 flex items-center justify-between">
                    <div class="flex gap-2">
                        <Badge variant="secondary" class="text-xs">
                            {{ formattedCount(stats?.active_inspections || 0) }}
                            active
                        </Badge>
                        <Badge variant="outline" class="text-xs">
                            {{
                                formattedCount(
                                    Math.max(
                                        0,
                                        (stats?.total_inspections || 0) -
                                            (stats?.active_inspections || 0)
                                    )
                                )
                            }}
                            completed
                        </Badge>
                    </div>
                    <span class="text-xs text-muted-foreground"
                        >total jobs</span
                    >
                </div>
            </CardContent>
            <div class="absolute top-0 right-0 w-20 h-20 opacity-5">
                <HammerIcon class="w-full h-full" />
            </div>
        </Card>
        <!-- Inspection Visits Card -->
        <Card
            v-if="
                $page.props.auth.user.roles.includes('admin') &&
                $page.props.auth.user.roles.includes('woc')
            "
            class="relative overflow-hidden border-0 shadow-lg bg-gradient-to-br from-orange-50 to-red-100 dark:from-orange-950 dark:to-red-900"
        >
            <CardHeader
                class="flex flex-row items-center justify-between space-y-0 pb-2"
            >
                <CardTitle
                    class="text-sm font-medium text-orange-700 dark:text-orange-300"
                    >This Week</CardTitle
                >
                <div class="p-2 bg-orange-500/10 rounded-lg">
                    <Calendar
                        class="h-4 w-4 text-orange-600 dark:text-orange-400"
                    />
                </div>
            </CardHeader>
            <CardContent>
                <div
                    class="text-3xl font-bold text-orange-900 dark:text-orange-100"
                >
                    {{ formattedCount(upcomingInspections) }}
                </div>
                <div class="mt-2 flex items-center justify-between">
                    <Badge
                        :variant="
                            overdueInspections > 0 ? 'destructive' : 'outline'
                        "
                        class="text-xs"
                    >
                        {{ formattedCount(overdueInspections) }} overdue
                    </Badge>
                    <span class="text-xs text-muted-foreground"
                        >inspections</span
                    >
                </div>
            </CardContent>
            <div class="absolute top-0 right-0 w-20 h-20 opacity-5">
                <Calendar class="w-full h-full" />
            </div>
        </Card>
    </div>
    <!-- Enhanced Charts Section -->
    <div class="grid gap-6 lg:grid-cols-3 xl:grid-cols-4">
        <!-- Main Chart -->
        <Card class="lg:col-span-2 xl:col-span-3 border-0 shadow-lg">
            <CardHeader class="flex flex-row items-center justify-between">
                <div class="grid gap-2">
                    <div class="flex items-center gap-2">
                        <div class="p-2 bg-blue-500/10 rounded-lg">
                            <BarChart3 class="h-5 w-5 text-blue-600" />
                        </div>
                        <CardTitle>Work Orders Trend</CardTitle>
                    </div>
                    <CardDescription>
                        Monthly work order statistics showing creation,
                        completion, and update patterns.
                    </CardDescription>
                </div>
                <Select
                    :modelValue="String(selectedYear)"
                    @update:modelValue="(value) => (selectedYear = value)"
                >
                    <SelectTrigger class="w-full sm:w-[100px]">
                        <SelectValue placeholder="Select a year" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            <SelectItem
                                v-for="year in years"
                                :key="year"
                                :value="year.toString()"
                            >
                                {{ year }}
                            </SelectItem>
                        </SelectGroup>
                    </SelectContent>
                </Select>
            </CardHeader>
            <CardContent>
                <div v-if="isChartLoading" class="space-y-4">
                    <!-- Chart legend skeleton -->
                    <div class="flex gap-6 justify-center">
                        <div class="flex items-center gap-2">
                            <Skeleton class="h-3 w-3 rounded-full" />
                            <Skeleton class="h-3 w-12" />
                        </div>
                        <div class="flex items-center gap-2">
                            <Skeleton class="h-3 w-3 rounded-full" />
                            <Skeleton class="h-3 w-16" />
                        </div>
                    </div>
                    <!-- Chart bars skeleton -->
                    <div class="h-64 flex items-end justify-between gap-2 px-4">
                        <div class="flex-1 space-y-1">
                            <Skeleton class="h-32 w-full" />
                            <Skeleton class="h-3 w-8 mx-auto" />
                        </div>
                        <div class="flex-1 space-y-1">
                            <Skeleton class="h-24 w-full" />
                            <Skeleton class="h-3 w-8 mx-auto" />
                        </div>
                        <div class="flex-1 space-y-1">
                            <Skeleton class="h-40 w-full" />
                            <Skeleton class="h-3 w-8 mx-auto" />
                        </div>
                        <div class="flex-1 space-y-1">
                            <Skeleton class="h-28 w-full" />
                            <Skeleton class="h-3 w-8 mx-auto" />
                        </div>
                        <div class="flex-1 space-y-1">
                            <Skeleton class="h-36 w-full" />
                            <Skeleton class="h-3 w-8 mx-auto" />
                        </div>
                        <div class="flex-1 space-y-1">
                            <Skeleton class="h-20 w-full" />
                            <Skeleton class="h-3 w-8 mx-auto" />
                        </div>
                        <div class="flex-1 space-y-1">
                            <Skeleton class="h-44 w-full" />
                            <Skeleton class="h-3 w-8 mx-auto" />
                        </div>
                        <div class="flex-1 space-y-1">
                            <Skeleton class="h-16 w-full" />
                            <Skeleton class="h-3 w-8 mx-auto" />
                        </div>
                        <div class="flex-1 space-y-1">
                            <Skeleton class="h-32 w-full" />
                            <Skeleton class="h-3 w-8 mx-auto" />
                        </div>
                        <div class="flex-1 space-y-1">
                            <Skeleton class="h-24 w-full" />
                            <Skeleton class="h-3 w-8 mx-auto" />
                        </div>
                        <div class="flex-1 space-y-1">
                            <Skeleton class="h-28 w-full" />
                            <Skeleton class="h-3 w-8 mx-auto" />
                        </div>
                        <div class="flex-1 space-y-1">
                            <Skeleton class="h-20 w-full" />
                            <Skeleton class="h-3 w-8 mx-auto" />
                        </div>
                    </div>
                </div>
                <BarChart v-else :data="workOrderChart" />
            </CardContent>
        </Card>

        <!-- Additional Analytics Cards -->
        <div class="space-y-6">
            <!-- Service Status Donut Chart -->
            <Card class="border-0 shadow-lg">
                <CardHeader class="pb-2">
                    <div class="flex items-center gap-2">
                        <div class="p-2 bg-purple-500/10 rounded-lg">
                            <div
                                class="w-4 h-4 bg-gradient-to-r from-purple-500 to-pink-500 rounded-full"
                            ></div>
                        </div>
                        <div>
                            <CardTitle class="text-sm font-medium"
                                >Service Status</CardTitle
                            >
                            <CardDescription class="text-xs">
                                Work order status breakdown
                            </CardDescription>
                        </div>
                    </div>
                </CardHeader>
                <CardContent class="pt-2">
                    <div v-if="isServiceStatusLoading" class="space-y-4">
                        <!-- Skeleton for progress bars -->
                        <div v-for="i in 4" :key="i" class="space-y-2">
                            <div class="flex justify-between items-center">
                                <Skeleton class="h-3 w-20" />
                                <Skeleton class="h-3 w-8" />
                            </div>
                            <Skeleton class="h-2 w-full rounded-full" />
                        </div>
                    </div>
                    <div v-else class="space-y-4">
                        <!-- Progress bars for each service status -->
                        <div
                            v-for="(status, index) in serviceStatus"
                            :key="status.name"
                            class="space-y-2"
                        >
                            <div class="flex justify-between items-center">
                                <span
                                    class="text-sm font-medium text-gray-700 dark:text-gray-300"
                                >
                                    {{ status.name }}
                                </span>
                                <span
                                    class="text-sm font-semibold text-gray-900 dark:text-gray-100"
                                >
                                    {{ formattedCount(status.total || 0) }}
                                </span>
                            </div>
                            <div
                                class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2"
                            >
                                <div
                                    class="h-2 rounded-full transition-all duration-500 ease-in-out"
                                    :class="`bg-gradient-to-r ${getStatusColor(
                                        index
                                    )}`"
                                    :style="{
                                        width: `${getStatusPercentage(
                                            status.total || 0
                                        )}%`,
                                    }"
                                ></div>
                            </div>
                        </div>
                        <!-- Show message if no service status data -->
                        <div
                            v-if="!serviceStatus || serviceStatus.length === 0"
                            class="text-center py-6"
                        >
                            <p class="text-sm text-muted-foreground">
                                No service status data available
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
<style scoped>
.fade-slide-enter-active,
.fade-slide-leave-active {
    transition: all 0.7s ease;
}
.fade-slide-enter-from {
    opacity: 0;
    transform: translateY(-10px);
}
.fade-slide-enter-to {
    opacity: 1;
    transform: translateY(0);
}
.fade-slide-leave-from {
    opacity: 1;
}
.fade-slide-leave-to {
    opacity: 0;
}
</style>
