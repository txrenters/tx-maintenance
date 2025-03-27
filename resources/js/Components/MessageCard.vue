<script setup>
import { DateTime } from "luxon";

const props = defineProps({
  messages: Object,
  sender: String,
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
    v-for="msg in messages"
    :key="msg.id"
    class="p-2 rounded-lg text-sm w-fit max-w-[75%]"
    :class="
      msg.sender_number === sender
        ? 'bg-blue-500 text-white self-end'
        : 'bg-gray-200 text-gray-900 self-start'
    "
  >
    <div class="flex flex-col gap-2">
      <div
        class="flex gap-1 items-center"
        :class="msg.sender_number === sender ? 'flex-row-reverse' : 'flex-row'"
      >
        <p
          class="text-xs"
          :class="msg.sender_number === sender ? 'flex-row-reverse' : 'flex-row'"
        >
          {{ msg.sender_number }}
        </p>
      </div>

      <p
        class="font-bold"
        :class="msg.sender_number === sender ? 'text-right' : 'text-left'"
      >
        {{ msg.message }}
      </p>
      <p
        class="text-xs"
        :class="msg.sender_number === sender ? 'text-right' : 'text-left'"
      >
        {{ formatDate(msg.created_at) }}
      </p>
    </div>
  </div>
</template>
