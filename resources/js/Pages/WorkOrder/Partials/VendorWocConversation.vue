<script setup>
import { ref, watch, onMounted, nextTick } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { Loader2, Send } from "lucide-vue-next";
import { useToast } from "@/Components/ui/toast/use-toast";
import MessageCard from "@/Components/MessageCard.vue";

const props = defineProps({
    wocConversation: Array,
    workOrderTenants: Array,
    workOrderVendors: Array,
    isLoading: Boolean,
    workOrder: Object,
});

const newMessage = ref("");
const chatContainer = ref(null); // Reference to the chat container for auto-scrolling

const emit = defineEmits(["update-vendor-convo"]);

const page = usePage();
const { toast } = useToast();

const woc = ref(props.workOrder.woc);
const woc_phone_number = ref(
    props.workOrder.woc?.woc_number?.twilio_phone_number.phone_number
);

const vendor_phone_number = ref(page.props.auth.user.vendor.twilio_number);

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
                sender_phone_number: vendor_phone_number.value,
                receiver_phone_number: woc_phone_number.value,
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
    () => props.wocConversation,
    () => {
        scrollToBottom();
    },
    { deep: true }
);

onMounted(() => {
    scrollToBottom();
});
</script>

<template>
    <div class="grid gap-3 overflow-y-auto px-6">
        <p class="font-semibold uppercase text-xs mb-3">WOC Conversation</p>
        <div class="flex justify-between gap-2 mb-2">
            <div>
                <div class="flex gap-2">
                    <div class="flex flex-col text-left">
                        <div class="flex gap-2 items-center">
                            <Avatar class="w-5 h-5">
                                <AvatarImage
                                    :src="
                                        woc?.profile_photo_url || 'default.jpg'
                                    "
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
                <p class="text-xs text-gray-500">
                    Please include the country code (e.g. +1)
                </p>
            </div>
            <div class="flex gap-2">
                <div class="flex flex-col text-left">
                    <div class="flex gap-2 items-center">
                        <Avatar class="w-5 h-5">
                            <AvatarImage
                                :src="
                                    page.props.auth.user?.profile_photo_url ||
                                    'default.jpg'
                                "
                            />
                            <AvatarFallback>
                                {{ page.props.auth.user.name?.charAt(0) }}
                            </AvatarFallback>
                        </Avatar>
                        {{ page.props.auth.user.name }}
                    </div>
                    {{ page.props.auth.user.vendor.twilio_number }}
                </div>
            </div>
        </div>

        <div class="flex flex-col gap-4 overflow-y-auto" ref="chatContainer">
            <ScrollArea class="bg-secondary h-[520px] rounded-md p-3">
                <div class="flex justify-center" v-if="isLoading || loading">
                    <Loader2 class="w-12 h-12 animate-spin text-primary" />
                </div>
                <MessageCard
                    v-else
                    :messages="wocConversation"
                    :sender="woc_phone_number"
                />
            </ScrollArea>
        </div>
        <div class="relative w-full mt-4 mb-6">
            <Textarea
                v-model="newMessage"
                @keyup.enter.exact="sendMessage"
                placeholder="Type your message..."
                class="w-full resize-none rounded-2xl border py-3 pr-20 pl-4"
                rows="1"
                :disabled="loading"
            />
            <Button
                size="icon"
                variant="ghost"
                @click.prevent="sendMessage"
                :disabled="isLoading || loading"
                class="absolute top-1/2 right-2 -translate-y-1/2"
            >
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
