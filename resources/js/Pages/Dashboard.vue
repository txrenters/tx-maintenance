<script setup>
import { computed, ref, watch } from "vue";
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

import { DonutChart } from "@/Components/ui/chart-donut";
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
    workOrders: Object,
    tasks: Object,
    inspections: Object,
    serviceStatus: Object,
    inspectionVisits: Object,
    workOrderChart: Object,
    filter: Object,
});

const upcomingInspections = computed(() => {
    return props.inspectionVisits.filter(
        (visit) => !visit.is_complete && new Date(visit.start_at) >= new Date()
    );
});

const completedInspections = computed(() => {
    if (!props.inspectionVisits) return [];
    return props.inspectionVisits.filter((visit) => {
        return (
            visit.is_complete === true ||
            visit.is_complete === 1 ||
            visit.visit_status === "completed" ||
            visit.status === "completed"
        );
    });
});

const overdueInspections = computed(() => {
    return props.inspectionVisits.filter(
        (visit) => !visit.is_complete && new Date(visit.start_at) < new Date()
    );
});

const completedWorkOrders = computed(() => {
    return props.workOrders.filter((order) => order.status === "Closed");
});

const pendingWorkOrders = computed(() => {
    return props.workOrders.filter(
        (order) => order.status === "Open" && order.service_status_id === 1
    );
});

const processWorkOrders = computed(() => {
    return props.workOrders.filter(
        (order) => order.status === "Open" && order.service_status_id !== 1
    );
});

const completedTasks = computed(() => {
    return props.tasks.filter((task) => task.status === "completed");
});

const totalInspectionJobs = computed(() => {
    return props.inspections ? props.inspections.length : 0;
});

const activeInspectionJobs = computed(() => {
    if (!props.inspections) return [];
    return props.inspections.filter((job) => {
        const status = job.job_status?.toLowerCase();
        // Only count as inactive if explicitly closed, archived, completed, or cancelled
        return ![
            "archived",
            "closed",
            "completed",
            "cancelled",
            "done",
        ].includes(status);
    });
});

const completedInspectionJobs = computed(() => {
    if (!props.inspections) return [];
    return props.inspections.filter((job) => {
        const status = job.job_status?.toLowerCase();
        return ["completed", "done", "closed"].includes(status);
    });
});

const thisWeekInspections = computed(() => {
    const startOfWeek = new Date();
    startOfWeek.setDate(startOfWeek.getDate() - startOfWeek.getDay());
    const endOfWeek = new Date();
    endOfWeek.setDate(endOfWeek.getDate() + (6 - endOfWeek.getDay()));

    return props.inspectionVisits.filter((visit) => {
        const visitDate = new Date(visit.start_at);
        return visitDate >= startOfWeek && visitDate <= endOfWeek;
    });
});

function getCompletionPercentage(completed, total) {
    if (
        !Array.isArray(completed) ||
        !Array.isArray(total) ||
        total.length === 0
    ) {
        return "0.00";
    }
    return ((completed.length / total.length) * 100).toFixed(2);
}

const currentYear = new Date().getFullYear();
const years = Array.from(
    { length: currentYear - 2023 },
    (_, i) => currentYear - i
);

const selectedYear = ref(props.filter.year ?? currentYear);

watch(selectedYear, (newYear) => {
    router.visit(route("dashboard", { year: newYear }), {
        preserveState: true,
        preserveScroll: true,
    });
});
const show = ref(true);
usePoll(5000);

// Analytics computations
const workOrdersThisMonth = computed(() => {
    const currentMonth = new Date().getMonth();
    const currentYear = new Date().getFullYear();
    return props.workOrders.filter((order) => {
        // Use created_date instead of created_at to match the controller and graph
        const orderDate = new Date(order.created_date || order.created_at);
        return (
            orderDate.getMonth() === currentMonth &&
            orderDate.getFullYear() === currentYear
        );
    });
});

const workOrdersLastMonth = computed(() => {
    const lastMonth = new Date().getMonth() - 1;
    const year =
        lastMonth < 0 ? new Date().getFullYear() - 1 : new Date().getFullYear();
    const month = lastMonth < 0 ? 11 : lastMonth;
    return props.workOrders.filter((order) => {
        // Use created_date to match the controller
        const orderDate = new Date(order.created_date || order.created_at);
        return (
            orderDate.getMonth() === month && orderDate.getFullYear() === year
        );
    });
});

const upcomingInspectionsCount = computed(() => {
    const today = new Date();
    const nextWeek = new Date();
    nextWeek.setDate(today.getDate() + 7);

    return props.inspectionVisits.filter((visit) => {
        if (visit.is_complete) return false;
        const visitDate = new Date(visit.start_at);
        return visitDate >= today && visitDate <= nextWeek;
    }).length;
});

// Inspection status distribution for pie chart
const inspectionStatusDistribution = computed(() => {
    const statusCounts = {};
    props.inspections.forEach((job) => {
        const status = job.job_status || "unknown";
        statusCounts[status] = (statusCounts[status] || 0) + 1;
    });

    return Object.entries(statusCounts).map(([status, count]) => ({
        name: status.charAt(0).toUpperCase() + status.slice(1),
        value: count,
        total: count,
    }));
});

// Inspections by day of week
const inspectionsByDayOfWeek = computed(() => {
    const days = [
        "Sunday",
        "Monday",
        "Tuesday",
        "Wednesday",
        "Thursday",
        "Friday",
        "Saturday",
    ];
    const dayCounts = new Array(7).fill(0);

    props.inspectionVisits.forEach((visit) => {
        const date = new Date(visit.start_at);
        dayCounts[date.getDay()]++;
    });

    return days.map((day, index) => ({
        name: day.slice(0, 3),
        inspections: dayCounts[index],
    }));
});

// Monthly inspection trend
const monthlyInspectionTrend = computed(() => {
    const monthData = {};
    const currentYear = new Date().getFullYear();

    props.inspectionVisits.forEach((visit) => {
        const date = new Date(visit.start_at);
        if (date.getFullYear() === currentYear) {
            const monthKey = date.toLocaleString("en-US", { month: "short" });
            if (!monthData[monthKey]) {
                monthData[monthKey] = {
                    scheduled: 0,
                    completed: 0,
                };
            }
            monthData[monthKey].scheduled++;
            if (visit.is_complete) {
                monthData[monthKey].completed++;
            }
        }
    });

    const months = [
        "Jan",
        "Feb",
        "Mar",
        "Apr",
        "May",
        "Jun",
        "Jul",
        "Aug",
        "Sep",
        "Oct",
        "Nov",
        "Dec",
    ];
    return months.map((month) => ({
        name: month,
        Scheduled: monthData[month]?.scheduled || 0,
        Completed: monthData[month]?.completed || 0,
    }));
});

// Inspection completion by job type
const inspectionsByType = computed(() => {
    const typeData = {};

    props.inspections.forEach((job) => {
        const type = job.job_type || "Other";
        if (!typeData[type]) {
            typeData[type] = {
                name: type,
                total: 0,
                completed: 0,
            };
        }
        typeData[type].total++;

        const completedVisits = props.inspectionVisits.filter(
            (visit) => visit.job_id === job.id && visit.is_complete
        ).length;
        const totalVisits = props.inspectionVisits.filter(
            (visit) => visit.job_id === job.id
        ).length;

        if (totalVisits > 0 && completedVisits === totalVisits) {
            typeData[type].completed++;
        }
    });

    return Object.values(typeData).map((type) => ({
        name: type.name,
        Total: type.total,
        Completed: type.completed,
        completionRate:
            type.total > 0
                ? Math.round((type.completed / type.total) * 100)
                : 0,
    }));
});

const inspectionCompletionRate = computed(() => {
    if (!props.inspectionVisits || props.inspectionVisits.length === 0)
        return 0;

    const completedVisits = props.inspectionVisits.filter((visit) => {
        // Check multiple possible fields for completion status
        return (
            visit.is_complete === true ||
            visit.is_complete === 1 ||
            visit.visit_status === "completed" ||
            visit.status === "completed"
        );
    });

    const rate = (completedVisits.length / props.inspectionVisits.length) * 100;
    return Math.round(rate);
});

// Add back monthlyGrowthRate for work orders
const monthlyGrowthRate = computed(() => {
    if (workOrdersLastMonth.value.length === 0) return 100;
    return (
        ((workOrdersThisMonth.value.length - workOrdersLastMonth.value.length) /
            workOrdersLastMonth.value.length) *
        100
    );
});

// Add averageCompletionTime for the hero section
const averageCompletionTime = computed(() => {
    const completedOrders = props.workOrders.filter(
        (order) =>
            order.status === "Closed" &&
            (order.completed_at || order.completed_date || order.closed_date)
    );

    if (completedOrders.length === 0) return 0;

    const totalDays = completedOrders.reduce((sum, order) => {
        // Use created_date and check multiple possible completion date fields
        const created = new Date(order.created_date || order.created_at);
        const completed = new Date(
            order.completed_at || order.completed_date || order.closed_date
        );

        // Calculate difference in days
        const diffTime = completed - created;
        const diffDays = diffTime / (1000 * 60 * 60 * 24);

        // Only count valid positive differences
        return diffDays >= 0 ? sum + diffDays : sum;
    }, 0);

    const avg =
        completedOrders.length > 0 ? totalDays / completedOrders.length : 0;
    return Math.round(avg);
});

const urgentWorkOrders = computed(() => {
    return props.workOrders.filter(
        (order) => order.priority === "urgent" || order.priority === "high"
    );
});

const workOrderStatusDistribution = computed(() => {
    const statuses = props.workOrders.reduce((acc, order) => {
        acc[order.status] = (acc[order.status] || 0) + 1;
        return acc;
    }, {});
    return Object.entries(statuses).map(([status, count]) => ({
        status,
        count,
    }));
});

const formattedCount = (number) => {
    return number.toLocaleString();
};
</script>

<template>
    <Head :title="title" />
    <!-- Modern Hero Section -->
    <div class="mb-8">
        <div
            v-motion-fade-visible
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
                            <TrendingUp class="h-4 w-4" />
                            <span class="text-sm font-medium"
                                >{{ workOrdersThisMonth.length }} this
                                month</span
                            >
                        </div>
                        <div
                            class="flex items-center gap-2 bg-white/10 backdrop-blur rounded-lg px-3 py-2"
                        >
                            <Clock class="h-4 w-4" />
                            <span class="text-sm font-medium"
                                >{{ averageCompletionTime }} days avg</span
                            >
                        </div>
                        <div
                            v-if="urgentWorkOrders.length > 0"
                            class="flex items-center gap-2 bg-red-500/20 backdrop-blur rounded-lg px-3 py-2"
                        >
                            <AlertTriangle class="h-4 w-4" />
                            <span class="text-sm font-medium"
                                >{{ urgentWorkOrders.length }} urgent</span
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
                    {{ formattedCount(workOrders.length) }}
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
                    {{ formattedCount(completedWorkOrders.length) }}
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
                                    completedWorkOrders,
                                    workOrders
                                )
                            }}%
                        </span>
                    </div>
                    <Progress
                        :model-value="
                            parseFloat(
                                getCompletionPercentage(
                                    completedWorkOrders,
                                    workOrders
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
                $page.props.auth.user.roles.includes('owner')
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
                    {{ formattedCount(pendingWorkOrders.length) }}
                </div>
                <div class="mt-2 flex items-center justify-between">
                    <Badge
                        :variant="
                            urgentWorkOrders.length > 0
                                ? 'destructive'
                                : 'secondary'
                        "
                        class="text-xs"
                    >
                        {{ urgentWorkOrders.length }} urgent
                    </Badge>
                    <span class="text-xs text-muted-foreground"
                        >{{
                            getCompletionPercentage(
                                pendingWorkOrders,
                                workOrders
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
                $page.props.auth.user.roles.includes('owner')
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
                    {{ formattedCount(processWorkOrders.length) }}
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
                                    processWorkOrders,
                                    workOrders
                                )
                            }}%
                        </span>
                    </div>
                    <Progress
                        :model-value="
                            parseFloat(
                                getCompletionPercentage(
                                    processWorkOrders,
                                    workOrders
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
                    {{ tasks.length }}
                </div>
                <div class="mt-3">
                    <div class="flex justify-between items-center mb-1">
                        <span class="text-xs text-muted-foreground"
                            >Completed</span
                        >
                        <span
                            class="text-xs font-medium text-teal-700 dark:text-teal-300"
                        >
                            {{ completedTasks.length.toLocaleString() }}/{{
                                tasks.length.toLocaleString()
                            }}
                        </span>
                    </div>
                    <Progress
                        :model-value="
                            parseFloat(
                                getCompletionPercentage(completedTasks, tasks)
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
                !$page.props.auth.user.roles.includes('tenant') &&
                !$page.props.auth.user.roles.includes('owner')
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
                    {{ totalInspectionJobs }}
                </div>
                <div class="mt-2 flex items-center justify-between">
                    <div class="flex gap-2">
                        <Badge variant="secondary" class="text-xs">
                            {{ formattedCount(activeInspectionJobs.length) }}
                            active
                        </Badge>
                        <Badge variant="outline" class="text-xs">
                            {{ formattedCount(completedInspectionJobs.length) }}
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
                !$page.props.auth.user.roles.includes('tenant') &&
                !$page.props.auth.user.roles.includes('owner')
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
                    {{ formattedCount(thisWeekInspections.length) }}
                </div>
                <div class="mt-2 flex items-center justify-between">
                    <Badge
                        :variant="
                            overdueInspections.length > 0
                                ? 'destructive'
                                : 'outline'
                        "
                        class="text-xs"
                    >
                        {{ formattedCount(overdueInspections.length) }} overdue
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
                <BarChart :data="workOrderChart" />
            </CardContent>
        </Card>

        <!-- Additional Analytics Cards -->
        <div class="space-y-6">
            <!-- Inspection Completion Rate -->
            <Card
                class="border-0 shadow-lg bg-gradient-to-br from-indigo-50 to-purple-100 dark:from-indigo-950 dark:to-purple-900"
            >
                <CardHeader class="pb-2">
                    <div class="flex items-center justify-between">
                        <CardTitle
                            class="text-sm font-medium text-indigo-700 dark:text-indigo-300"
                            >Completion Rate</CardTitle
                        >
                        <div class="p-2 bg-indigo-500/10 rounded-lg">
                            <CheckCircle2
                                class="h-4 w-4 text-indigo-600 dark:text-indigo-400"
                            />
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    <div
                        class="text-2xl font-bold text-indigo-900 dark:text-indigo-100"
                    >
                        {{ inspectionCompletionRate }}%
                    </div>
                    <p class="text-xs text-muted-foreground mt-1">
                        inspections completed
                    </p>
                </CardContent>
            </Card>

            <!-- Upcoming Inspections -->
            <Card
                class="border-0 shadow-lg bg-gradient-to-br from-pink-50 to-rose-100 dark:from-pink-950 dark:to-rose-900"
            >
                <CardHeader class="pb-2">
                    <div class="flex items-center justify-between">
                        <CardTitle
                            class="text-sm font-medium text-pink-700 dark:text-pink-300"
                            >Next 7 Days</CardTitle
                        >
                        <div class="p-2 bg-pink-500/10 rounded-lg">
                            <Clock
                                class="h-4 w-4 text-pink-600 dark:text-pink-400"
                            />
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    <div
                        class="text-2xl font-bold text-pink-900 dark:text-pink-100"
                    >
                        {{ formattedCount(upcomingInspectionsCount) }}
                    </div>
                    <p class="text-xs text-muted-foreground mt-1">
                        upcoming inspections
                    </p>
                </CardContent>
            </Card>

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
                    <DonutChart
                        class="h-48 w-full"
                        index="name"
                        :category="'total'"
                        :data="serviceStatus"
                    />
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
