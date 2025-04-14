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
} from "lucide-vue-next";

defineOptions({ layout: AppLayout });

import { DonutChart } from "@/Components/ui/chart-donut";
import BarChart from "@/chart/BarChart.vue";
import { string } from "zod";

const props = defineProps({
    title: String,
    workOrders: Object,
    tasks: Object,
    invoices: Object,
    serviceStatus: Object,
    vendors: Object,
    workOrderChart: Object,
    filter: Object,
});

const activeVendors = computed(() => {
    return props.vendors.filter((vendor) => vendor.is_active === 1);
});

const inactiveVendors = computed(() => {
    return props.vendors.filter((vendor) => vendor.is_active === 0);
});

const completedWorkOrders = computed(() => {
    return props.workOrders.filter((order) => order.status === "Closed");
});

const pendingWorkOrders = computed(() => {
    return props.workOrders.filter((order) => order.status === "Open" && order.service_status_id === 1);
});

const processWorkOrders = computed(() => {
    return props.workOrders.filter((order) => order.status === "Open" && order.service_status_id !== 1);
});

const completedTasks = computed(() => {
    return props.tasks.filter((task) => task.status === "completed");
});


const invoiceTotalApproved = computed(() => {
    return props.invoices
        .filter((invoice) => invoice.status === "approved")
        .reduce((total, invoice) => total + parseFloat(invoice.amount || 0), 0);
});

const pendingInvoices = computed(() => {
    return props.invoices.filter((task) => task.status === "pending");
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
const startTimer = () => {
    setTimeout(() => {
        show.value = false;
    }, 4000); // 4 seconds
};

usePoll(5000);
</script>

<template>
    <Head :title="title" />
    <div
        class="flex justify-start flex-col gap-3 sm:justify-between sm:flex-row items-center my-2"
    >
        <transition name="fade-slide" appear @after-enter="startTimer">
            <div
                class="bg-gradient-to-r from-blue-500 to-green-500 text-white rounded shadow p-4 sm:p-6 md:p-8 w-full"
            >
                <h2 class="text-xl sm:text-2xl md:text-3xl font-bold">
                    Welcome back, {{ $page.props.auth.user.name }}! 👋
                </h2>
                <p class="text-sm sm:text-base mt-2">
                    We’re glad to see you again. Let’s get some work done today!
                </p>
            </div>
        </transition>
    </div>
    <div class="grid gap-4 md:grid-cols-2 md:gap-8 lg:grid-cols-4">
        <Card>
            <CardHeader
                class="flex flex-row items-center justify-between space-y-0 pb-2"
            >
                <CardTitle class="text-sm font-medium">Total Work Orders </CardTitle>
                <Wrench class="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
                <div class="text-2xl font-bold">+{{ workOrders.length }}</div>
            </CardContent>
        </Card>
        <Card v-if="$page.props.auth.user.roles.includes('tenant') || $page.props.auth.user.roles.includes('owner')">
            <CardHeader
                class="flex flex-row items-center justify-between space-y-0 pb-2"
            >
                <CardTitle class="text-sm font-medium"> Completed </CardTitle>
                <Wrench class="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
                <div class="text-2xl font-bold">+{{ completedWorkOrders.length }}</div>
                <p class="text-xs text-muted-foreground">
                    {{
                        getCompletionPercentage(
                            completedWorkOrders,
                            workOrders
                        )
                    }}% completed
                </p>
            </CardContent>
        </Card>
        <Card v-if="$page.props.auth.user.roles.includes('tenant') || $page.props.auth.user.roles.includes('owner')">
            <CardHeader
                class="flex flex-row items-center justify-between space-y-0 pb-2"
            >
                <CardTitle class="text-sm font-medium">Pending </CardTitle>
                <Wrench class="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
                <div class="text-2xl font-bold">+{{ pendingWorkOrders.length }}</div>
                <p class="text-xs text-muted-foreground">
                    {{
                        getCompletionPercentage(
                            pendingWorkOrders,
                            workOrders
                        )
                    }}% incomplete
                </p>
            </CardContent>
        </Card>
        <Card v-if="$page.props.auth.user.roles.includes('tenant') || $page.props.auth.user.roles.includes('owner')">
            <CardHeader
                class="flex flex-row items-center justify-between space-y-0 pb-2"
            >
                <CardTitle class="text-sm font-medium">In Progress </CardTitle>
                <Wrench class="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
                <div class="text-2xl font-bold">+{{ processWorkOrders.length }}</div>
                <p class="text-xs text-muted-foreground">
                    {{
                        getCompletionPercentage(
                            processWorkOrders,
                            workOrders
                        )
                    }}% in progress
                </p>
            </CardContent>
        </Card>
        <Card v-if="!$page.props.auth.user.roles.includes('tenant') && !$page.props.auth.user.roles.includes('owner')">
            <CardHeader
                class="flex flex-row items-center justify-between space-y-0 pb-2"
            >
                <CardTitle class="text-sm font-medium"> Tasks </CardTitle>
                <ListChecks class="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
                <div class="text-2xl font-bold">+{{ tasks.length }}</div>
                <p class="text-xs text-muted-foreground">
                    {{ getCompletionPercentage(completedTasks, tasks) }}% ({{
                        completedTasks.length
                    }}) completed
                </p>
            </CardContent>
        </Card>
        <Card v-if="!$page.props.auth.user.roles.includes('tenant') && !$page.props.auth.user.roles.includes('owner')">
            <CardHeader
                class="flex flex-row items-center justify-between space-y-0 pb-2"
            >
                <CardTitle class="text-sm font-medium"> Invoices </CardTitle>
                <DollarSign class="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
                <div class="text-2xl font-bold">
                    +{{ invoiceTotalApproved.toFixed(2) }}
                </div>
                <p class="text-xs text-muted-foreground">
                    {{ pendingInvoices.length }} pending invoices
                </p>
            </CardContent>
        </Card v-if="!$page.props.auth.user.roles.includes('tenant') && !$page.props.auth.user.roles.includes('owner')">
        <Card v-if="!$page.props.auth.user.roles.includes('tenant') && !$page.props.auth.user.roles.includes('owner')">
            <CardHeader
                class="flex flex-row items-center justify-between space-y-0 pb-2"
            >
                <CardTitle class="text-sm font-medium"> Vendors </CardTitle>
                <Truck class="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
                <div class="text-2xl font-bold">
                    +{{ activeVendors.length }}
                </div>
                <p class="text-xs text-muted-foreground">
                    {{ inactiveVendors.length }} inactive vendors
                </p>
            </CardContent>
        </Card>
    </div>
    <div class="grid gap-4 md:gap-8 lg:grid-cols-2 xl:grid-cols-3">
        <Card class="xl:col-span-2">
            <CardHeader class="flex flex-row items-center justify-between">
                <div class="grid gap-2">
                    <CardTitle>Work Orders by Month</CardTitle>
                    <CardDescription>
                        See how many work orders were created, completed, or
                        updated in each month.
                    </CardDescription>
                </div>
                <!-- <Button as-child size="sm" class="ml-auto gap-1">
                    <Link :href="route('work_orders.index')">
                        View All
                        <ArrowUpRight class="h-4 w-4" />
                    </Link>
                </Button> -->
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
        <Card>
            <CardHeader>
                <CardTitle>Work Orders by Service Status</CardTitle>
                <CardDescription>
                    See the distribution of work orders across various service
                    status.
                </CardDescription>
            </CardHeader>
            <CardContent class="flex gap-1 items-center mt-4 sm:mt-20">
                <DonutChart
                    class="h-64"
                    index="name"
                    :category="'total'"
                    :data="serviceStatus"
                />
            </CardContent>
        </Card>
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
