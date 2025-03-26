<script setup>
import { ref, watch, onMounted, nextTick } from "vue";
import { router } from "@inertiajs/vue3";
import { Loader2, Send } from "lucide-vue-next";
import { DateTime } from "luxon";
import { useToast } from "@/Components/ui/toast/use-toast";
const { toast } = useToast();

const props = defineProps({
  ownerConversation: Array,
  workOrderOwners: Array,
  isLoading: Boolean,
  workOrder: Object,
});

const newMessage = ref("");
const selectedOwner = ref("");
const owner_phone_number = ref("");
const chatContainer = ref(null); // Reference to the chat container for auto-scrolling

const woc = ref(props.workOrder.woc);
const woc_phone_number = ref(
  props.workOrder.woc?.woc_number?.twilio_phone_number.phone_number
);

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

  return parsedDate.isValid ? parsedDate.toFormat("MM/dd/yyyy") : "Invalid Date";
};

watch(selectedOwner, (newOwner) => {
  if (newOwner) {
    const foundOwner = props.workOrderOwners.find((owner) => owner.id == newOwner);
    owner_phone_number.value = foundOwner ? foundOwner.mobile_phone : "";
  }
});

const loading = ref(false);
const sendMessage = () => {
  loading.value = true;
  if (!owner_phone_number.value) {
    toast({
      variant: "destructive",
      title: "Uh oh! Something went wrong.",
      description: "There was a problem with your request. Please select a receiver!",
    });
    loading.value = false;

    return;
  }

  if (!newMessage.value) {
    toast({
      variant: "destructive",
      title: "Uh oh! Something went wrong.",
      description: "There was a problem with your request. Please type a message!",
    });
    loading.value = false;

    return;
  }

  if (newMessage.value.trim() !== "") {
    console.log(woc_phone_number.value);

    router.post(
      route("work_order.vendor.conversation.send"),
      {
        text: newMessage.value,
        sender_phone_number: woc_phone_number.value,
        receiver_phone_number: owner_phone_number.value,
        work_order_id: props.workOrder.id,
        conversation_type: "owner",
      },
      {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
          toast({
            title: "Success",
            description: "Message has been sent successfully!",
          });
          props.ownerConversation.push({
            id: Date.now(), // Temporary ID
            sender_number: woc_phone_number.value,
            receiver_number: owner_phone_number.value,
            message: newMessage.value,
            created_at: new Date().toISOString(), // Current timestamp
          });
          newMessage.value = "";
          scrollToBottom(); // Scroll to the bottom after sending a message
        },
        onError: () => {
          toast({
            variant: "destructive",
            title: "Uh oh! Something went wrong.",
            description: "There was a problem with your request. Please try again!",
          });
        },
        onFinish: () => {
          loading.value = false;
          scrollToBottom(); // Scroll to the bottom after sending a message
        },
      }
    );
  }
};

const scrollToBottom = () => {
  nextTick(() => {
    if (chatContainer.value) {
      chatContainer.value.scrollTop = chatContainer.value.scrollHeight;
    }
  });
};

// Scroll to the bottom when the component mounts or when the conversation updates
onMounted(() => {
  scrollToBottom();
});

watch(
  () => props.ownerConversation,
  () => {
    scrollToBottom();
  },
  { deep: true }
);
</script>

<template>
  <div class="overflow-y-auto px-6 w-full min-h-[300px]">
    <p class="font-semibold uppercase text-xs mb-3">Owners Conversation</p>

    <div
      class="flex flex-col-reverse sm:flex-row sm:flex-wrap justify-between gap-2 mb-2"
    >
      <div class="flex gap-2">
        <Select v-model="selectedOwner">
          <SelectTrigger class="w-full">
            <SelectValue placeholder="Select an owner" />
          </SelectTrigger>
          <SelectContent>
            <SelectGroup>
              <template v-for="owner in workOrderOwners" :key="owner.id">
                <SelectItem :value="String(owner.id)" :selected="owner.phone">
                  {{ owner.first_name }} {{ owner.last_name }} -
                  {{ owner?.phone }}
                </SelectItem>
              </template>
            </SelectGroup>
          </SelectContent>
        </Select>
        <Input placeholder="Custom number" class="" v-model="owner_phone_number" />
      </div>
      <div class="flex flex-col text-left">
        <div class="flex gap-2 items-center">
          <Avatar class="w-5 h-5">
            <AvatarImage :src="woc?.profile_photo_url || 'default.jpg'" />
            <AvatarFallback>
              {{ woc.name?.charAt(0) }}
            </AvatarFallback>
          </Avatar>
          {{ woc.name }}
        </div>
        {{ woc.woc_number.twilio_phone_number.phone_number }}
      </div>
    </div>
    <div class="border p-3 min-h-[300px] bg-secondary">
      <div class="flex justify-center" v-if="isLoading || loading">
        <Loader2 class="w-12 h-12 animate-spin text-primary" />
      </div>
      <div
        class="h-80 overflow-y-auto space-y-2 flex flex-col"
        v-else
        ref="chatContainer"
      >
        <div
          v-for="msg in ownerConversation"
          :key="msg.id"
          class="p-2 rounded-lg text-sm w-fit max-w-[75%]"
          :class="
            msg.sender_number === woc_phone_number
              ? 'bg-blue-500 text-white self-end'
              : 'bg-gray-200 text-gray-900 self-start'
          "
        >
          <div class="flex flex-col gap-2">
            <div
              class="flex gap-1 items-center"
              :class="
                msg.sender_number === woc_phone_number ? 'flex-row-reverse' : 'flex-row'
              "
            >
              <p
                class="text-xs"
                :class="
                  msg.sender_number === woc_phone_number ? 'flex-row-reverse' : 'flex-row'
                "
              >
                {{ msg.sender_number }}
              </p>
            </div>

            <p
              class="font-bold"
              :class="msg.sender_number === woc_phone_number ? 'text-right' : 'text-left'"
            >
              {{ msg.message }}
            </p>
            <p
              class="text-xs"
              :class="msg.sender_number === woc_phone_number ? 'text-right' : 'text-left'"
            >
              {{ formatDate(msg.created_at) }}
            </p>
          </div>
        </div>
      </div>
    </div>

    <div class="flex items-center gap-2 mt-4 mb-6">
      <Input
        v-model="newMessage"
        placeholder="Type a message..."
        class="flex-1"
        @keyup.enter="sendMessage"
      />
      <Button @click.prevent="sendMessage" :disabled="isLoading || loading" size="icon">
        <Send v-if="!isLoading || loading" />
        <Loader2 v-else class="w-4 h-4 animate-spin" />
      </Button>
    </div>
  </div>
</template>

<style scoped>
::-webkit-scrollbar {
  width: 5px;
}
::-webkit-scrollbar-thumb {
  background: #ccc;
  border-radius: 5px;
}
</style>
