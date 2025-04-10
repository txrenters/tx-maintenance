<script setup>
import { ref, watch, onMounted, nextTick } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { Loader2, Send } from "lucide-vue-next";
import { useToast } from "@/Components/ui/toast/use-toast";
import MessageCard from "@/Components/MessageCard.vue";

const props = defineProps({
    vendorConversation: Array,
    workOrderTenants: Array,
    workOrderVendors: Array,
    isLoading: Boolean,
    workOrder: Object,
});

const newMessage = ref("");
const selectedVendor = ref("");
const vendor_phone_number = ref("");
const chatContainer = ref(null); // Reference to the chat container for auto-scrolling

const emit = defineEmits(["update-vendor-convo"]);

const page = usePage();
const { toast } = useToast();

const woc = ref(props.workOrder.woc);
const woc_phone_number = ref(
    props.workOrder.woc?.woc_number?.twilio_phone_number.phone_number
);

const loading = ref(false);

const sendMessage = () => {
    loading.value = true;
    if (!vendor_phone_number.value) {
        toast({
            variant: "destructive",
            title: "Uh oh! Something went wrong.",
            description:
                "There was a problem with your request. Please select a receiver!",
        });
        loading.value = false;

        return;
    }

    if (!newMessage.value) {
        toast({
            variant: "destructive",
            title: "Uh oh! Something went wrong.",
            description:
                "There was a problem with your request. Please type a message!",
        });
        loading.value = false;

        return;
    }

    if (newMessage.value.trim() !== "") {
        router.post(
            route("work_order.vendor.conversation.send"),
            {
                text: newMessage.value,
                sender_phone_number: woc_phone_number.value,
                receiver_phone_number: vendor_phone_number.value,
                work_order_id: props.workOrder.id,
                conversation_type: "vendor",
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
                        sender_number: woc_phone_number.value,
                        receiver_number: vendor_phone_number.value,
                        message: newMessage.value,
                        created_at: new Date().toISOString(), // Current timestamp
                    });
                    newMessage.value = "";
                    scrollToBottom();
                    emit("update-vendor-convo");
                },
                onError: () => {
                    toast({
                        variant: "destructive",
                        title: "Uh oh! Something went wrong.",
                        description:
                            "There was a problem with your request. Please try again!",
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

watch(
    () => props.vendorConversation,
    () => {
        scrollToBottom();
    },
    { deep: true }
);
watch(selectedVendor, (newVendor) => {
    if (newVendor) {
        const foundVendor = props.workOrder.vendors.find(
            (vendor) => vendor.id == newVendor
        );
        vendor_phone_number.value = foundVendor
            ? foundVendor.twilio_number
            : "";
    }
});

// Scroll to the bottom when the component mounts or when the conversation updates
onMounted(() => {
    scrollToBottom();
});
</script>

<template>
    <div class="overflow-y-auto px-6 w-full min-h-[300px]">
        <p class="font-semibold uppercase text-xs mb-3">Vendor Conversation</p>
        <div
            class="flex flex-col-reverse sm:flex-row sm:flex-wrap justify-between gap-2 mb-2"
        >
            <div>
                <div class="flex gap-2">
                    <Select v-model="selectedVendor">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Select a vendor" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                <template
                                    v-for="vendor in workOrder.vendors"
                                    :key="vendor.id"
                                >
                                    <SelectItem
                                        :value="String(vendor.id)"
                                        :selected="vendor.twilio_number"
                                    >
                                        {{ vendor.name }} -
                                        {{ vendor?.twilio_number }}
                                    </SelectItem>
                                </template>
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                    <Input
                        placeholder="Custom number"
                        class=""
                        v-model="vendor_phone_number"
                    />
                </div>
                <p class="text-xs text-gray-500">
                    Please include the country code (e.g. +1)
                </p>
            </div>

            <div class="flex flex-col text-left">
                <div class="flex gap-2 items-center">
                    <Avatar class="w-5 h-5">
                        <AvatarImage
                            :src="woc?.profile_photo_url || 'default.jpg'"
                        />
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
            <div class="flex justify-center" v-if="loading || isLoading">
                <Loader2 class="w-12 h-12 animate-spin text-primary" />
            </div>
            <div
                class="h-80 overflow-y-auto space-y-2 flex flex-col"
                v-else
                ref="chatContainer"
            >
                <MessageCard
                    :messages="vendorConversation"
                    :sender="woc_phone_number"
                />
            </div>
        </div>

        <div class="flex items-center gap-2 mt-4 mb-6">
            <Input
                v-model="newMessage"
                placeholder="Type a message..."
                class="flex-1"
                @keyup.enter="sendMessage"
            />
            <Button
                @click.prevent="sendMessage"
                :disabled="loading || isLoading"
                size="icon"
            >
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
