<script setup>
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
      parsedDate = DateTime.fromFormat(date, "yyyy-MM-dd HH:mm:ss", { zone: "utc" });
    }
  } else if (date instanceof Date) {
    parsedDate = DateTime.fromJSDate(date);
  } else {
    return "Invalid Date";
  }

  return parsedDate.isValid ? parsedDate.toFormat("MM/dd/yyyy") : "Invalid Date";
};

const handleWorkOrder = (work_order) => {
  emit("showWorkOrder", work_order); // Emit event to parent
};

const countCompletedTask = (tasks) => {
  // Filter tasks where the 'completed' property is true
  const completedTasks = tasks.filter((task) => task.status === "completed");
  // Return the count of completed tasks
  return completedTasks.length;
};

// const checkDueTask = (tasks) => {
//   const today = new Date().toISOString().split("T")[0];

//   const dueTasks = tasks.filter((task) => task.due_date >= today);
//   // Return the count of completed tasks
//   return dueTasks.length > 0;
// };

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
  <div class="flex flex-row flex-nowrap space-x-2 overflow-x-auto scrollbar-hide">
    <div
      v-for="status in service_status"
      :key="status.id"
      class="overflow-hidden min-w-[240px]"
    >
      <div class="text-center font-semibol">
        <!-- Status Name -->
        <div
          class="h-16 flex items-center justify-center border p-3 text-sm uppercase font-semibold"
        >
          <p>{{ status.name }} ({{ status.work_orders.length }})</p>
        </div>

        <!-- Work Orders List -->
        <ScrollArea class="h-[70vh] overflow-y-auto border-t pt-2 mb-5">
          <div
            @click="handleWorkOrder(work_order)"
            v-for="work_order in status.work_orders"
            :key="work_order.id"
            class="mb-2 rounded-lg p-4 text-white cursor-pointer hover:shadow-lg transition-all"
            :class="{
              'bg-destructive': checkDueTask(work_order.tasks) === 'red',
              'bg-primary': checkDueTask(work_order.tasks) === 'blue',
              'bg-green-500': checkDueTask(work_order.tasks) === 'green',
            }"
          >
            <!-- Work Order Number & Date -->
            <div class="flex justify-between items-center border-b pb-2 mb-2">
              <h1 class="text-lg font-semibold">{{ work_order.work_order_no }}</h1>
              <p class="text-xs text-gray-200">
                📅 {{ formatDate(work_order.created_date) }}
              </p>
            </div>
            <!-- Location -->
            <p class="text-sm text-gray-100">{{ work_order.location }}</p>

            <!-- Requested Info -->
            <div class="flex justify-between items-center mt-4">
              <div class="flex gap-1 items-center">
                <p class="text-xs" v-if="work_order.tasks.length > 0">
                  {{ countCompletedTask(work_order.tasks) }}/{{ work_order.tasks.length }}
                  tasks
                </p>
              </div>
              <div
                v-if="work_order.requested_by"
                class="flex justify-end items-center gap-2"
              >
                <p class="text-sm text-gray-100">
                  {{ work_order.requested_by?.first_name }}
                  {{ work_order.requested_by?.last_name }}
                </p>
                <Avatar class="w-5 h-5">
                  <AvatarImage
                    :src="
                      work_order?.requested_by?.user?.profile_photo_url || 'default.jpg'
                    "
                  />
                  <AvatarFallback>
                    {{ work_order.requested_by?.first_name?.charAt(0)
                    }}{{ work_order.requested_by?.last_name?.charAt(0) }}
                  </AvatarFallback>
                </Avatar>
              </div>
            </div>
          </div>

          <ScrollBar orientation="vertical" />
        </ScrollArea>
      </div>
    </div>
  </div>
</template>
