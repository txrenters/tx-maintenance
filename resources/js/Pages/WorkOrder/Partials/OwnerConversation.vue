<script setup>
import { ref, watch, onMounted, nextTick } from "vue";
import { router } from "@inertiajs/vue3";
import { Loader2, Send } from "lucide-vue-next";
import { useToast } from "@/Components/ui/toast/use-toast";
import MessageCard from "@/Components/MessageCard.vue";

const props = defineProps({
    ownerConversation: Array,
    workOrderOwners: Array,
    isLoading: Boolean,
    workOrder: Object,
});

const emit = defineEmits(["update-owner-convo"]);
const { toast } = useToast();

const newMessage = ref("");
const selectedOwner = ref("");
const owner_phone_number = ref("");
const chatContainer = ref(null); // Reference to the chat container for auto-scrolling

const woc = ref(props.workOrder.woc);
const woc_phone_number = ref(
    props.workOrder.woc?.woc_number?.twilio_phone_number.phone_number
);

watch(selectedOwner, (newOwner) => {
    if (newOwner) {
        const foundOwner = props.workOrderOwners.find(
            (owner) => owner.id == newOwner
        );
        owner_phone_number.value = foundOwner ? foundOwner.phone : "";
    }
});

const loading = ref(false);
const sendMessage = () => {
    loading.value = true;
    if (!owner_phone_number.value) {
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
                    emit("update-owner-convo");
                    newMessage.value = "";
                    scrollToBottom(); // Scroll to the bottom after sending a message
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
        <p class="font-semibold uppercase text-xs mb-3">Owner Conversation</p>

        <div
            class="flex flex-col-reverse sm:flex-row sm:flex-wrap justify-between gap-2 mb-2"
        >
            <div>
                <div class="flex gap-2">
                    <Select v-model="selectedOwner">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Select an owner" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                <template
                                    v-for="owner in workOrderOwners"
                                    :key="owner.id"
                                >
                                    <SelectItem
                                        :value="String(owner.id)"
                                        :selected="owner.phone"
                                    >
                                        {{ owner.first_name }}
                                        {{ owner.last_name }} -
                                        {{ owner?.phone }}
                                    </SelectItem>
                                </template>
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                    <Input
                        placeholder="Custom number"
                        class=""
                        v-model="owner_phone_number"
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
        <div
            class="overflow-y-auto space-y-2 flex flex-col"
            ref="chatContainer"
        >
            <ScrollArea class="border p-3 h-[520px] bg-secondary">
                <div class="flex justify-center" v-if="isLoading || loading">
                    <Loader2 class="w-12 h-12 animate-spin text-primary" />
                </div>
                <MessageCard
                    v-else
                    :messages="ownerConversation"
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
