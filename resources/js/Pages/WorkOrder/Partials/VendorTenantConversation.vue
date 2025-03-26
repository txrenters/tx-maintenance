<script setup>
import { ref, watch, onMounted, nextTick } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { Loader2, Send } from "lucide-vue-next";
import { DateTime } from "luxon";
import { useToast } from "@/Components/ui/toast/use-toast";
const { toast } = useToast();

const props = defineProps({
  vendorConversation: Array,
  workOrderTenants: Array,
  workOrderVendors: Array,
  isLoading: Boolean,
  workOrder: Object,
});

const page = usePage();

const newMessage = ref("");
const selectedTenant = ref("");
const tenant_phone_number = ref(props.workOrder.requested.mobile_phone);
const chatContainer = ref(null); // Reference to the chat container for auto-scrolling

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

watch(selectedTenant, (newTenant) => {
  if (newTenant) {
    const foundTenant = props.workOrderTenants.find((tenant) => tenant.id == newTenant);
    tenant_phone_number.value = foundTenant ? foundTenant.mobile_phone : "";
  }
});

const loading = ref(false);
const sendMessage = () => {
  loading.value = true;
  if (!tenant_phone_number.value) {
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
    router.post(
      route("work_order.vendor.conversation.send"),
      {
        text: newMessage.value,
        sender_phone_number: vendor_phone_number.value,
        receiver_phone_number: tenant_phone_number.value,
        work_order_id: props.workOrder.id,
        conversation_type: "vendor_tenant",
      },
      {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
          toast({
            title: "Success",
            description: "Message has been sent successfully!",
          });
          props.vendorConversation.push({
            id: Date.now(), // Temporary ID
            sender_number: vendor_phone_number.value,
            receiver_number: tenant_phone_number.value,
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
  () => props.vendorConversation,
  () => {
    scrollToBottom();
  },
  { deep: true }
);

const vendor_phone_number = page.props.auth.user.vendor.twilio_number;
</script>

<template>
  <div class="overflow-y-auto px-6 w-full min-h-[300px]">
    <p class="font-semibold uppercase text-xs mb-3">Tenant Conversation</p>
    <div
      class="flex flex-col-reverse sm:flex-row sm:flex-wrap justify-between gap-2 mb-2"
    >
      <div class="flex gap-2">
        <Select v-model="selectedTenant">
          <SelectTrigger class="w-full">
            <SelectValue placeholder="Select a tenant" />
          </SelectTrigger>
          <SelectContent>
            <SelectGroup>
              <template v-for="tenant in workOrderTenants" :key="tenant.id">
                <SelectItem
                  :value="String(tenant.id)"
                  :selected="tenant.mobile_phone === workOrder.requested.mobile_phone"
                >
                  {{ tenant.first_name }} {{ tenant.last_name }} -
                  {{ tenant?.mobile_phone }}
                </SelectItem>
              </template>
            </SelectGroup>
          </SelectContent>
        </Select>
        <Input placeholder="Custom number" class="" v-model="tenant_phone_number" />
      </div>
      <div class="flex flex-col text-left">
        <div class="flex gap-2 items-center">
          <Avatar class="w-5 h-5">
            <AvatarImage
              :src="page.props.auth.user?.profile_photo_url || 'default.jpg'"
            />
            <AvatarFallback>
              {{ page.props.auth.user.vendor.name?.charAt(0) }}
            </AvatarFallback>
          </Avatar>
          {{ page.props.auth.user.vendor.name }}
        </div>
        {{ page.props.auth.user.vendor.twilio_number }}
      </div>
    </div>

    <div class="border p-3 min-h-[300px] bg-secondary">
      <div class="flex justify-center" v-if="loading || isLoading">
        <Loader2 class="w-12 h-12 animate-spin text-primary" />
      </div>
      <div
        class="h-80 overflow-y-auto space-y-2 flex flex-col"
        v-else
        ref="chatContainer"
      >
        <div
          v-for="msg in vendorConversation"
          :key="msg.id"
          class="p-2 rounded-lg text-sm w-fit max-w-[75%]"
          :class="
            msg.sender_number === vendor_phone_number
              ? 'bg-blue-500 text-white self-end'
              : 'bg-gray-200 text-gray-900 self-start'
          "
        >
          <div
            class="flex flex-col gap-2"
            :class="{
              'items-end': msg.sender_number === vendor_phone_number,
              'items-start': msg.sender_number !== vendor_phone_number,
            }"
          >
            <!-- Sender info -->
            <div
              class="flex gap-1 items-center"
              :class="
                msg.sender_number === vendor_phone_number
                  ? 'flex-row-reverse'
                  : 'flex-row'
              "
            >
              <p class="text-xs">
                {{ msg.sender_number }}
              </p>
            </div>

            <!-- Message bubble -->
            <div
              class="p-1 rounded-lg"
              :class="{
                'bg-primary-500 text-white': msg.sender_number === vendor_phone_number,
                'bg-gray-200': msg.sender_number !== vendor_phone_number,
              }"
            >
              <p class="font-bold">
                {{ msg.message }}
              </p>
            </div>

            <!-- Timestamp -->
            <p class="text-xs">
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
      <Button @click.prevent="sendMessage" :disabled="loading || isLoading" size="icon">
        <Send v-if="!isLoading || isLoading" />
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
