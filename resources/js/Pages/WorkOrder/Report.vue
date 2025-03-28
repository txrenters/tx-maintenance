<script setup>
import { ref, computed } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import { DateTime } from "luxon";

defineOptions({ layout: AppLayout });

const props = defineProps({
  title: String,
  work_order: Object,
});

const formatDate = (date) => {
  if (!date) return "------";

  let parsedDate;

  try {
    if (typeof date === "string") {
      if (date.includes("T")) {
        // Handle ISO format (2025-03-06T17:41:20.000000Z)
        parsedDate = DateTime.fromISO(date, { zone: "utc" });
      } else if (date.includes("-")) {
        // Handle date string (2025-03-06 or 2025-03-06 23:10:06)
        parsedDate = DateTime.fromFormat(date.split(" ")[0], "yyyy-MM-dd", {
          zone: "utc",
        });
      } else {
        return "Invalid Date Format";
      }
    } else if (date instanceof Date) {
      parsedDate = DateTime.fromJSDate(date);
    } else {
      return "Invalid Date";
    }

    if (!parsedDate.isValid) return "Invalid Date";

    // Format as "Sat, March 29, 2025"
    return parsedDate.toFormat("EEE, MMMM d, yyyy");
  } catch (error) {
    console.error("Date formatting error:", error);
    return "Invalid Date";
  }
};

const totalCostEstimate = computed(() => {
  return props.work_order?.vendors?.reduce((total, vendor) => {
    return total + parseFloat(vendor.pivot?.cost_estimate || 0);
  }, 0);
});

const totalTimeEstimate = computed(() => {
  return (props.work_order?.vendors || []).reduce((total, vendor) => {
    return total + parseInt(vendor.pivot?.time_estimate || 0);
  }, 0);
});

const latestScheduledEndDate = computed(() => {
  return (props.work_order?.vendors || []).reduce((latestDate, vendor) => {
    const vendorDate = vendor.pivot?.scheduled_end_date
      ? new Date(vendor.pivot?.scheduled_end_date)
      : null;

    if (vendorDate && (!latestDate || vendorDate > latestDate)) {
      return vendorDate;
    }

    return latestDate;
  }, null);
});
</script>
<template>
  <Head :title="title" />
  <div class="flex justify-end">
    <Button>Download</Button>
  </div>
  <Card>
    <CardHeader
      class="text-center flex flex-col justify-center sm:flex-row sm:justify-between gap-4"
    >
      <div class="flex justify-center h-20">
        <img src="/logo-ct.png" />
      </div>
      <div class="flex flex-col justify-end gap-2 text-right">
        <CardTitle class="uppercase">{{ title }}</CardTitle>
        <CardTitle class="uppercase text-2xl"
          >Work Order No: #{{ work_order.work_order_no }}</CardTitle
        >
        <p class="uppercase font-semibold">
          Complete Date: {{ formatDate(work_order.completed_date) }}
        </p>
      </div>
    </CardHeader>
    <CardContent>
      <div class="grid grid-cols-2 gap-3 p-4 border mb-4">
        <div>
          <Label for="message">Managed by:</Label>
          <p>
            {{ work_order.managed_by?.first_name }} {{ work_order.managed_by?.last_name }}
          </p>
        </div>
        <div>
          <Label for="message">Requested by:</Label>
          <p>
            {{ work_order.requested_by?.first_name }}
            {{ work_order.requested_by?.last_name }}
          </p>
        </div>
        <div>
          <Label for="message">Location:</Label>
          <p>{{ work_order.location }}</p>
        </div>
        <div>
          <Label for="message">Category:</Label>
          <p>{{ work_order.category }}</p>
        </div>
        <div>
          <Label for="message">Type:</Label>
          <p>{{ work_order.type }}</p>
        </div>
        <div>
          <Label for="message">Authorized to enter:</Label>
          <p>{{ work_order.authorized_to_enter }}</p>
        </div>
        <div>
          <Label for="message">Source:</Label>
          <p>{{ work_order.source }}</p>
        </div>
        <div>
          <Label for="message">Total Cost:</Label>
          <p>{{ work_order.authorized_to_enter }}</p>
        </div>
        <div>
          <Label for="message">Total Hour Worked:</Label>
          <p>{{ work_order.authorized_to_enter }}</p>
        </div>
        <div>
          <Label for="message">Zone:</Label>
          <p>{{ work_order.zone }}</p>
        </div>
        <div>
          <Label for="message">Management Plan:</Label>
          <p>{{ work_order.management_plan }}</p>
        </div>
        <div>
          <Label for="message">Additional Work Needed:</Label>
          <p>{{ work_order.additional_work_needed_reschedule }}</p>
        </div>
        <div>
          <Label for="message">Closing Comments:</Label>
          <p>{{ work_order.closing_comments }}</p>
        </div>
        <div>
          <Label for="message">Description:</Label>
          <p>{{ work_order.decription }}</p>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-3 p-4 border mb-4">
        <p>Task Details</p>
        <div v-for="task in work_order.tasks" :key="task.id">
          <div class="border p-2">
            <p>{{ task.description }}</p>
          </div>
        </div>
      </div>
    </CardContent>
  </Card>
</template>
