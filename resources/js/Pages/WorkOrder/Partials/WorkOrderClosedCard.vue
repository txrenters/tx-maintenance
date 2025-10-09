<script setup>
import { Truck, Tag, UserRoundPen, CircleCheckBig } from "lucide-vue-next";
import { DateTime } from "luxon";
const emit = defineEmits(["showWorkOrder"]);

const props = defineProps({
    work_order: Object,
    service_status: Object,
});

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
    <div class="flex flex-row flex-wrap gap-2">
        <template v-for="status in service_status" :key="status.id">
            <div
                @click="handleWorkOrder(work_order)"
                v-motion-slide-visible-once-right
                v-for="work_order in status.work_orders"
                :key="work_order.id"
                class="rounded-lg p-4 min-w-[240px] text-white cursor-pointer hover:shadow-lg transition-all"
                :class="{
                    'bg-destructive': checkDueTask(work_order.tasks) === 'red',
                    'bg-primary': checkDueTask(work_order.tasks) === 'blue',
                    'bg-green-500': checkDueTask(work_order.tasks) === 'green',
                    'bg-secondary': work_order.status === 'Closed',
                }"
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
                        {{ work_order.owners[0].first_name }}
                        {{ work_order.owners[0].last_name }}
                    </p>
                </div>
                <p
                    class="text-xs text-gray-100"
                    v-for="vendor in work_order.vendors"
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
