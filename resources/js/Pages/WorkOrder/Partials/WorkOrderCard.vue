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
        <ScrollArea class="h-[600px] overflow-y-auto border-t pt-2 mb-5">
          <div
            @click="handleWorkOrder(work_order)"
            v-for="work_order in status.work_orders"
            :key="work_order.id"
            class="mb-2 rounded-lg p-4 text-white cursor-pointer shadow-md"
            :class="{
              'bg-destructive': work_order.is_emergency === 1,
              'bg-primary': work_order.is_emergency || !work_order.is_emergency,
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

            <!-- Owner Info -->
            <div
              v-if="work_order.requested_by"
              class="flex justify-end mt-4 items-center gap-2"
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

          <ScrollBar orientation="vertical" />
        </ScrollArea>
      </div>
    </div>
  </div>
</template>
