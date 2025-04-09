<script setup>
import { computed } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import { usePoll } from "@inertiajs/vue3";
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

const props = defineProps({
    title: String,
    workOrders: Object,
    tasks: Object,
    invoices: Object,
    serviceStatus: Object,
    vendors: Object,
    workOrderChart: Object,
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

usePoll(3000);
</script>

<template>
    <Head :title="title" />
    <div class="grid gap-4 md:grid-cols-2 md:gap-8 lg:grid-cols-4">
        <Card>
            <CardHeader
                class="flex flex-row items-center justify-between space-y-0 pb-2"
            >
                <CardTitle class="text-sm font-medium"> Work Orders </CardTitle>
                <Wrench class="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
                <div class="text-2xl font-bold">+{{ workOrders.length }}</div>
                <p class="text-xs text-muted-foreground">
                    {{
                        getCompletionPercentage(
                            completedWorkOrders,
                            workOrders
                        )
                    }}% ({{ completedWorkOrders.length }}) completed
                </p>
            </CardContent>
        </Card>
        <Card>
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
        <Card>
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
        </Card>
        <Card>
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
            <CardHeader class="flex flex-row items-center">
                <div class="grid gap-2">
                    <CardTitle>Work Orders by Month</CardTitle>
                    <CardDescription>
                        See how many work orders were created, completed, or
                        updated in each month.
                    </CardDescription>
                </div>
                <Button as-child size="sm" class="ml-auto gap-1">
                    <Link :href="route('work_orders.index')">
                        View All
                        <ArrowUpRight class="h-4 w-4" />
                    </Link>
                </Button>
            </CardHeader>
            <CardContent>
                <!-- <BarChart
                    :data="workOrderChart"
                    index="name"
                    :categories="['Created', 'Completed']"
                    :colors="['#2563EB', '#13B982']"
                    :filterOpacity="1"
                /> -->
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
