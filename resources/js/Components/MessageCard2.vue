<script setup>
import { DateTime } from "luxon";

const props = defineProps({
  messages: Object,
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
  <div
    class="bg-secondary p-2 mb-2 text-xs"
    v-for="conversation in [...messages].reverse()"
    :key="conversation.id"
  >
    <p><strong>From:</strong> {{ conversation.sender_number }}</p>
    <p><strong>To:</strong> {{ conversation.receiver_number }}</p>
    <p><strong>Message:</strong> {{ conversation.message }}</p>
    <div v-if="conversation.is_mms" class="m-1">
      <img
        v-for="media in conversation.media"
        :key="media.key"
        :src="media.public_url"
        width="350"
      />
    </div>
    <small>{{ formatDate(conversation.created_at) }}</small>
  </div>
</template>
