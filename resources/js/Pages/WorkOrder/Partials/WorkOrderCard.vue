<script setup>
import { Truck, Tag, UserRoundPen, CircleCheckBig, MapPin } from "lucide-vue-next";
import { DateTime } from "luxon";
import { usePage } from "@inertiajs/vue3";
import { nextTick, onMounted, ref, watch } from "vue";

const emit = defineEmits(["showWorkOrder"]);

const props = defineProps({
    work_order: Object,
    service_status: Object,
    // Signal to scroll to and flash a work order's card — set by the parent
    // when the modal closes so staff are taken straight to the card they were
    // just handling, wherever it landed (e.g. it moved to the New column after
    // a status change). Shape: { id, token }; token changes so re-focusing the
    // same work order re-triggers.
    focusSignal: {
        type: Object,
        default: null,
    },
});

// Remembers each column's scroll offset for the whole SPA session, keyed by
// service status id. Module-scoped on purpose: updating a work order from the
// modal re-fetches the deferred `service_status` prop, which tears this board
// down to its skeleton and remounts it with every column back at the top —
// component state would be lost, module state survives.
const columnScrollPositions = new Map();

const boardRoot = ref(null);

// Scroll events don't bubble, so a capture-phase listener on the board root
// hears every column viewport without wiring a listener per column.
const rememberColumnScroll = (event) => {
    if (event.target === boardRoot.value) {
        columnScrollPositions.set("__board", event.target.scrollLeft);

        return;
    }

    const column = event.target?.closest?.("[data-scroll-column]");

    if (column) {
        columnScrollPositions.set(column.dataset.scrollColumn, event.target.scrollTop);
    }
};

const restoreColumnScroll = async () => {
    await nextTick();

    if (!boardRoot.value) return;

    for (const [columnId, scrollTop] of columnScrollPositions) {
        if (columnId === "__board") {
            boardRoot.value.scrollLeft = scrollTop;
            continue;
        }

        const column = boardRoot.value.querySelector(
            `[data-scroll-column="${columnId}"]`,
        );

        if (!column || !scrollTop) continue;

        // The scrollable element is the reka-ui viewport; setting scrollTop on
        // the non-scrolling wrapper as well is a harmless no-op either way.
        const viewport = column.querySelector("[data-reka-scroll-area-viewport]");
        [column, viewport].filter(Boolean).forEach((el) => {
            el.scrollTop = scrollTop;
        });
    }
};

onMounted(restoreColumnScroll);
watch(() => props.service_status, restoreColumnScroll, { flush: "post" });

// Scroll to and briefly flash the card the user was just working on, wherever
// it now lives on the board.
const flashId = ref(null);
let flashTimer = null;

const focusWorkOrder = async (id) => {
    if (!id) return;

    await nextTick();

    const card = boardRoot.value?.querySelector(`[data-work-order-id="${id}"]`);

    if (!card) return;

    // "nearest" on both axes: if the card is already visible, nothing scrolls
    // (the preserved column positions stay put and we only flash); if it moved
    // off-screen — e.g. to the New column — scroll the minimum needed to reveal
    // it, never yanking the column to a mid-card position.
    card.scrollIntoView({ block: "nearest", inline: "nearest", behavior: "smooth" });

    flashId.value = id;

    if (flashTimer) clearTimeout(flashTimer);
    flashTimer = setTimeout(() => {
        if (flashId.value === id) flashId.value = null;
    }, 2000);
};

watch(
    () => props.focusSignal,
    (signal) => {
        if (signal?.id) focusWorkOrder(signal.id);
    },
    { flush: "post" },
);

const page = usePage();
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
    const completedTasks = (tasks ?? []).filter(
        (task) => task.status === "completed"
    );
    return completedTasks.length;
};

const checkDueTask = (tasks, scheduled_end_date) => {
    const today = new Date().toISOString().split("T")[0];

    let bgColor = "green"; // Default color if all tasks are upcoming

    // Check scheduled_end_date first (highest priority)
    if (scheduled_end_date) {
        if (scheduled_end_date === today) return "blue"; // Due today
        if (scheduled_end_date < today) return "red"; // Overdue
        if (scheduled_end_date > today) return "green"; // Upcoming
    }

    const pendingTasks = (tasks ?? []).filter(
        (task) => task.status === "pending"
    );

    // Check pending tasks
    if (pendingTasks.some((task) => task.due_date < today)) {
        bgColor = "red"; // Any overdue task
    } else if (pendingTasks.some((task) => task.due_date === today)) {
        bgColor = "blue"; // Any task due today
    }

    return bgColor;
};
</script>

<template>
    <div
        ref="boardRoot"
        class="flex flex-row flex-nowrap space-x-2 overflow-x-auto scrollbar-hide"
        @scroll.capture="rememberColumnScroll"
    >
        <template v-for="status in service_status" :key="status.id">
            <div
                v-if="status.work_orders.length !== 0"
                class="overflow-hidden min-w-[240px]"
            >
                <div class="text-center font-semibol">
                    <!-- Status Name -->
                    <div
                        class="h-16 flex items-center justify-center border p-3 text-sm uppercase font-semibold"
                    >
                        <p>
                            {{ status.name }} ({{ status.work_orders.length }})
                        </p>
                    </div>

                    <!-- Work Orders List -->
                    <ScrollArea
                        :data-scroll-column="status.id"
                        class="h-[70vh] overflow-y-auto border-t pt-2 mb-5"
                    >
                        <div
                            @click="handleWorkOrder(work_order)"
                            v-motion-slide-visible-once-right
                            v-for="work_order in status.work_orders"
                            :key="work_order.id"
                            :data-work-order-id="work_order.id"
                            class="mb-2 rounded-lg p-4 text-white cursor-pointer hover:shadow-lg transition-all"
                            :class="{
                                'wo-flash': work_order.id === flashId,
                                'bg-destructive':
                                    checkDueTask(
                                        work_order.tasks,
                                        work_order.scheduled_end_date
                                    ) == 'red' || work_order.is_emergency,
                                'bg-primary':
                                    checkDueTask(
                                        work_order.tasks,
                                        work_order.scheduled_end_date
                                    ) == 'blue',
                                'bg-green-500':
                                    checkDueTask(
                                        work_order.tasks,
                                        work_order.scheduled_end_date
                                    ) == 'green',
                                'bg-gray-600': work_order.status === 'Closed',
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

                            <!-- Property name -->
                              <span class="text-center text-xs text-gray-200">
                                    <span
                                        v-if="work_order.building?.name"
                                        class="font-medium"
                                        >{{ work_order.building.name }}</span
                                    >
                                </span>
                                <p
                                    class="text-xs text-gray-100 flex items-center gap-1 justify-center"
                                >
                                    <Tag class="w-3 h-3" />{{
                                        work_order.category
                                    }}
                                </p>
                                <p
                                    v-if="work_order.is_approved"
                                    class="text-xs text-gray-100 flex items-center gap-1 justify-center"
                                >
                                    <CircleCheckBig class="w-3 h-3" />Approved
                                </p>

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
                            <div v-else-if="work_order.owners?.length" class="flex text-left mb-1 mt-2">
                                <UserRoundPen class="w-4 h-4" />
                                <p class="text-xs text-gray-100 uppercase">
                                    {{ work_order.owners[0]?.first_name }}
                                    {{ work_order.owners[0]?.last_name }}
                                </p>
                            </div>
                            <p
                                class="text-xs text-gray-100"
                                v-for="vendor in displayVendors(work_order)"
                                :key="vendor.id"
                            >
                                <span class="flex gap-1 text-left uppercase">
                                    <Truck class="w-4 h-4" />{{
                                        vendor.name
                                    }}</span
                                >
                            </p>

                            <!-- Requested Info -->
                            <div class="flex justify-between items-center mt-1">
                                <div class="flex gap-1 items-center">
                                    <p
                                        class="text-xs"
                                        v-if="work_order.tasks?.length > 0"
                                    >
                                        {{
                                            countCompletedTask(
                                                work_order.tasks
                                            )
                                        }}/{{ work_order.tasks.length }}
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
                                        >Priority:
                                        {{ work_order.priority }}</span
                                    >
                                </div>
                            </div>
                        </div>

                        <ScrollBar orientation="vertical" />
                    </ScrollArea>
                </div>
            </div>
        </template>
    </div>
</template>

<style scoped>
/* Brief ring that pulses then fades on the card the user was just working on. */
@keyframes wo-flash {
    0% {
        box-shadow: 0 0 0 0 rgba(250, 204, 21, 0);
    }
    15% {
        box-shadow: 0 0 0 4px rgba(250, 204, 21, 0.95);
    }
    100% {
        box-shadow: 0 0 0 4px rgba(250, 204, 21, 0);
    }
}

.wo-flash {
    animation: wo-flash 1.9s ease-out;
}
</style>
