<script setup>
import { Truck, Tag, UserRoundPen, CircleCheckBig } from "lucide-vue-next";
import { DateTime } from "luxon";
import { usePage } from "@inertiajs/vue3";
import { computed } from "vue";

const emit = defineEmits(["showWorkOrder"]);

const props = defineProps({
    work_order: Object,
    service_status: Object,
    // Client-side filters applied to the already-loaded board (instant, no
    // server round-trip). Each is a no-op when empty/"all".
    searchTerm: { type: String, default: "" },
    vendorFilter: { type: [String, Number], default: "" },
    categoryFilter: { type: String, default: "" },
    dateRange: { type: Object, default: null },
});

const page = usePage();

const matchesSearch = (work_order) => {
    const term = (props.searchTerm || "").trim().toLowerCase();
    if (!term) return true;

    return [work_order.work_order_no, work_order.location, work_order.building?.name]
        .filter((value) => value != null)
        .some((value) => String(value).toLowerCase().includes(term));
};

// Vendors whose work orders the selected vendor's filter must not show: THMP
// is tagged onto Jimmie Gendke SFA's work orders only so the Jobber job gets
// created, so THMP's filter leaves them out. The map comes from the controller
// (vendor_filter_exclusions, THMP id => hidden vendor ids; [] when empty).
const hiddenVendorIds = computed(
    () =>
        new Set(
            ((page.props.vendor_filter_exclusions ?? {})[String(props.vendorFilter)] ?? []).map(String)
        )
);

const matchesVendor = (work_order) => {
    if (!props.vendorFilter || props.vendorFilter === "all") return true;

    const vendors = work_order.vendors || [];

    if (vendors.some((vendor) => hiddenVendorIds.value.has(String(vendor.id)))) return false;

    return vendors.some((vendor) => String(vendor.id) === String(props.vendorFilter));
};

const matchesCategory = (work_order) => {
    if (!props.categoryFilter || props.categoryFilter === "all") return true;

    return work_order.category === props.categoryFilter;
};

const matchesDate = (work_order) => {
    const start = props.dateRange?.start ? props.dateRange.start.toString() : null;
    const end = props.dateRange?.end ? props.dateRange.end.toString() : null;
    if (!start && !end) return true;

    const created = work_order.created_date
        ? String(work_order.created_date).slice(0, 10)
        : null;
    if (!created) return false;

    if (start && created < start) return false;
    if (end && created > end) return false;

    return true;
};

const visibleWorkOrders = (status) =>
    (status.work_orders || []).filter(
        (work_order) =>
            matchesSearch(work_order) &&
            matchesVendor(work_order) &&
            matchesCategory(work_order) &&
            matchesDate(work_order)
    );

const authUser = page.props.auth?.user;
const isVendor = (authUser?.roles ?? []).includes("vendor");
const myVendorId = authUser?.vendor?.id;

// A vendor must only see their own tag on a shared work order, never other vendors'.
const displayVendors = (workOrder) => {
    const vendors = workOrder?.vendors ?? [];
    if (isVendor && myVendorId) {
        return vendors.filter((v) => v.id === myVendorId);
    }
    return vendors;
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

const handleWorkOrder = (work_order) => {
    emit("showWorkOrder", work_order); // Emit event to parent
};

const countCompletedTask = (tasks) => {
    const completedTasks = tasks.filter((task) => task.status === "completed");
    return completedTasks.length;
};

const checkDueTask = (tasks) => {
    const today = new Date().toISOString().split("T")[0];

    let bgColor = "green"; // Default color if all tasks are upcoming

    const pendingTasks = tasks.filter((task) => task.status === "pending");

    // Check for past due tasks first (highest priority)
    if (pendingTasks.some((task) => task.due_date < today)) {
        bgColor = "red";
    }
    // Check for tasks due today (next priority)
    else if (pendingTasks.some((task) => task.due_date === today)) {
        bgColor = "blue";
    }

    return bgColor;
};
</script>

<template>
    <div
        class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 gap-4"
    >
        <template v-for="status in service_status" :key="status.id">
            <div
                @click="handleWorkOrder(work_order)"
                v-for="work_order in visibleWorkOrders(status)"
                :key="work_order.id"
                class="rounded-lg bg-gray-700 p-4 min-w-[240px] text-white cursor-pointer hover:shadow-lg transition-all"
            >
                <!-- Work Order Number & Date -->
                <div
                    class="flex justify-between items-center border-b pb-2 mb-2"
                >
                    <h1 class="text-lg font-semibold">
                        {{ work_order.work_order_no }}
                    </h1>
                    <p class="text-xs text-gray-200">
                        📅 {{ formatDate(work_order.created_date) }}
                    </p>
                </div>

                <!-- Location -->
                <p class="text-sm text-gray-100 font-semibold">
                    {{ work_order.location }}
                </p>
                <div class="flex gap-2 justify-center">
                    <p
                        class="text-xs text-gray-100 flex items-center gap-1 justify-center"
                    >
                        <Tag class="w-3 h-3" />{{ work_order.category }}
                    </p>
                    <p
                        v-if="work_order.is_approved"
                        class="text-xs text-gray-100 flex items-center gap-1 justify-center"
                    >
                        <CircleCheckBig class="w-3 h-3" />Approved
                    </p>
                </div>

                <div
                    v-if="work_order.requested_by"
                    class="flex text-left gap-1 mb-1 mt-2"
                >
                    <UserRoundPen class="w-4 h-4" />
                    <p class="text-xs text-gray-100 uppercase">
                        {{ work_order.requested_by?.first_name }}
                        {{ work_order.requested_by?.last_name }}
                    </p>
                </div>
                <div v-else class="flex text-left mb-1 mt-2">
                    <UserRoundPen class="w-4 h-4" />
                    <p class="text-xs text-gray-100 uppercase">
                        {{ work_order.owners?.[0]?.first_name ?? "N/A" }}
                        {{ work_order.owners?.[0]?.last_name ?? "" }}
                    </p>
                </div>
                <p
                    class="text-xs text-gray-100"
                    v-for="vendor in displayVendors(work_order)"
                    :key="vendor.id"
                >
                    <span class="flex gap-1 text-left uppercase">
                        <Truck class="w-4 h-4" />{{ vendor.name }}</span
                    >
                </p>

                <!-- Requested Info -->
                <div class="flex justify-between items-center mt-1">
                    <div class="flex gap-1 items-center">
                        <p class="text-xs" v-if="work_order.tasks.length > 0">
                            {{ countCompletedTask(work_order.tasks) }}/{{
                                work_order.tasks.length
                            }}
                            tasks
                        </p>
                    </div>
                    <div>
                        <span
                            class="text-[10px] px-1 uppercase rounded border"
                            :class="
                                work_order.priority === 'High'
                                    ? 'bg-destructive'
                                    : 'bg-primary'
                            "
                            >Priority: {{ work_order.priority }}</span
                        >
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
