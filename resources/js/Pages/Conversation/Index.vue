<script setup>
import { DateTime } from "luxon";
import MessageCard2 from "@/Components/MessageCard2.vue";

const props = defineProps({
  title: String,
  conversations: Object,
});

console.log(props.conversations);

const formatDate = (date) => {
  if (!date) return "------";

  let parsedDate;

  if (typeof date === "string") {
    if (date.includes("T")) {
      parsedDate = DateTime.fromISO(date, { zone: "utc" });
    } else {
      parsedDate = DateTime.fromFormat(date, "yyyy-MM-dd", { zone: "utc" });
    }
  } else if (date instanceof Date) {
    parsedDate = DateTime.fromJSDate(date);
  } else {
    return "Invalid Date";
  }

  return parsedDate.isValid
    ? parsedDate.toFormat("EEE, MMMM d, yyyy hh:mm a")
    : "Invalid Date";
};
</script>

<template>
  <Head :title="title" />

  <div class="p-6">
    <h1 class="text-2xl font-bold uppercase mb-3">
      {{ title }} : #{{ conversations.work_order_no }}
    </h1>
    <div class="mb-4 p-2 border">
      <p class="mb-2 font-bold">WOC and Vendor</p>

      <MessageCard2 :messages="conversations.vendor_conversation" />
    </div>
    <div class="mb-4 p-2 border">
      <p class="mb-2 font-bold">WOC and Owner</p>

      <MessageCard2 :messages="conversations.owner_conversation" />
    </div>
    <div class="mb-4 p-2 border">
      <p class="mb-2 font-bold">WOC and Tenant</p>

      <MessageCard2 :messages="conversations.tenant_conversation" />
    </div>
    <div class="mb-14 p-2 border">
      <p class="mb-2 font-bold">Vendor and Tenant</p>
      <MessageCard2 :messages="conversations.vendor_tenant_conversation" />
    </div>
  </div>
</template>
