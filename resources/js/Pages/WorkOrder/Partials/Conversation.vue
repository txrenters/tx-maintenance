<script setup>
import { ref, watch } from "vue";
import MessageCard from "@/Components/MessageCard.vue";
import { DateTime } from "luxon";

const props = defineProps({
  workOrder: Object,
  vendorConversation: Object,
  ownerConversation: Object,
  tenantConversation: Object,
  vendorTenantConversation: Object,
  isLoading: Boolean,
});

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
  <div class="overflow-y-auto px-6 w-full min-h-[300px]">
    <p class="font-semibold uppercase text-xs mb-3">Conversations</p>

    <div class="mb-4 p-2 border">
      <p class="mb-2 font-bold">WOC and Vendor</p>
      <div
        class="bg-secondary p-2 mb-2 text-xs"
        v-for="conversation in [...vendorConversation].reverse()"
        :key="conversation.id"
      >
        <p><strong>From:</strong> {{ conversation.sender_number }}</p>
        <p><strong>To:</strong> {{ conversation.receiver_number }}</p>
        <p><strong>Message:</strong> {{ conversation.message }}</p>
        <small>{{ formatDate(conversation.created_at) }}</small>
      </div>
    </div>
    <div class="mb-4 p-2 border">
      <p class="mb-2 font-bold">WOC and Owner</p>
      <div
        class="bg-secondary p-2 mb-2 text-xs"
        v-for="conversation in [...ownerConversation].reverse()"
        :key="conversation.id"
      >
        <p><strong>From:</strong> {{ conversation.sender_number }}</p>
        <p><strong>To:</strong> {{ conversation.receiver_number }}</p>
        <p><strong>Message:</strong> {{ conversation.message }}</p>
        <small>{{ formatDate(conversation.created_at) }}</small>
      </div>
    </div>
    <div class="mb-4 p-2 border">
      <p class="mb-2 font-bold">WOC and Tenant</p>
      <div
        class="bg-secondary p-2 mb-2 text-xs"
        v-for="conversation in [...tenantConversation].reverse()"
        :key="conversation.id"
      >
        <p><strong>From:</strong> {{ conversation.sender_number }}</p>
        <p><strong>To:</strong> {{ conversation.receiver_number }}</p>
        <p><strong>Message:</strong> {{ conversation.message }}</p>
        <small>{{ formatDate(conversation.created_at) }}</small>
      </div>
    </div>
    <div class="mb-14 p-2 border">
      <p class="mb-2 font-bold">Vendor and Tenant</p>
      <div
        class="bg-secondary p-2 mb-2 text-xs"
        v-for="conversation in [...vendorTenantConversation].reverse()"
        :key="conversation.id"
      >
        <p><strong>From:</strong> {{ conversation.sender_number }}</p>
        <p><strong>To:</strong> {{ conversation.receiver_number }}</p>
        <p><strong>Message:</strong> {{ conversation.message }}</p>
        <small>{{ formatDate(conversation.created_at) }}</small>
      </div>
    </div>
  </div>
</template>
