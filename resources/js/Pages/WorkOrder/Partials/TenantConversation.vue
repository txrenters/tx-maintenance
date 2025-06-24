<script setup>
import { ref, watch, onMounted, nextTick } from "vue";
import { router } from "@inertiajs/vue3";
import { Loader2, Send } from "lucide-vue-next";
import { useToast } from "@/Components/ui/toast/use-toast";
const { toast } = useToast();
import MessageCard from "@/Components/MessageCard.vue";

const props = defineProps({
    tenantConversation: Array,
    workOrderTenants: Array,
    isLoading: Boolean,
    workOrder: Object,
});
const emit = defineEmits(["update-tenant-convo"]);

const newMessage = ref("");
const selectedTenant = ref("");
const tenant_phone_number = ref(props.workOrder?.requested?.mobile_phone);
const chatContainer = ref(null); // Reference to the chat container for auto-scrolling

const woc = ref(props.workOrder.woc);
const woc_phone_number = ref(
    props.workOrder.woc?.woc_number?.twilio_phone_number.phone_number
);

const loading = ref(false);
watch(selectedTenant, (newTenant) => {
    if (newTenant) {
        const foundTenant = props.workOrderTenants.find(
            (tenant) => tenant.id == newTenant
        );
        tenant_phone_number.value = foundTenant ? foundTenant.mobile_phone : "";
    }
});

const sendMessage = () => {
    loading.value = true;
    if (!tenant_phone_number.value) {
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
                receiver_phone_number: tenant_phone_number.value,
                work_order_id: props.workOrder.id,
                conversation_type: "tenant",
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
                    scrollToBottom(); // Scroll to the bottom after sending a message
                    emit("update-tenant-convo");
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
    () => props.tenantConversation,
    () => {
        scrollToBottom();
    },
    { deep: true }
);

console.log(props.workOrderTenants);
</script>

<template>
    <div class="grid gap-3 overflow-y-auto px-6">
        <p class="font-semibold uppercase text-xs mb-3">Tenant Conversation</p>
        <div class="flex justify-between gap-2 mb-2">
            <div>
                <div class="flex gap-2">
                    <Select v-model="selectedTenant">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Select a tenant" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                <template
                                    v-for="tenant in workOrderTenants"
                                    :key="tenant.id"
                                >
                                    <SelectItem
                                        :value="String(tenant.id)"
                                        :selected="
                                            tenant.mobile_phone ===
                                            workOrder.requested?.mobile_phone
                                        "
                                    >
                                        {{ tenant.first_name }}
                                        {{ tenant.last_name }} -
                                        {{ tenant?.mobile_phone }}
                                    </SelectItem>
                                </template>
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                    <Input
                        placeholder="Custom number"
                        class=""
                        v-model="tenant_phone_number"
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
        <div class="flex flex-col gap-4 overflow-y-auto" ref="chatContainer">
            <ScrollArea class="bg-secondary h-[520px] rounded-md">
                <div class="flex justify-center" v-if="isLoading || loading">
                    <Loader2 class="w-12 h-12 animate-spin text-primary" />
                </div>
                <MessageCard
                    v-else
                    :messages="tenantConversation"
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
    <!-- <div class="overflow-y-auto px-6 w-full min-h-[300px]">
        <p class="font-semibold uppercase text-xs mb-3">Tenant Conversation</p>

        <div
            class="flex flex-col-reverse sm:flex-row sm:flex-wrap justify-between gap-2 mb-2"
        >
            <div>
                <div class="flex gap-2">
                    <Select v-model="selectedTenant">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Select a tenant" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                <template
                                    v-for="tenant in workOrderTenants"
                                    :key="tenant.id"
                                >
                                    <SelectItem
                                        :value="String(tenant.id)"
                                        :selected="
                                            tenant.mobile_phone ===
                                            workOrder.requested?.mobile_phone
                                        "
                                    >
                                        {{ tenant.first_name }}
                                        {{ tenant.last_name }} -
                                        {{ tenant?.mobile_phone }}
                                    </SelectItem>
                                </template>
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                    <Input
                        placeholder="Custom number"
                        class=""
                        v-model="tenant_phone_number"
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
        <div class="flex flex-col gap-4 overflow-y-auto" ref="chatContainer">
            <ScrollArea class="bg-secondary h-[520px] rounded-md">
                <div class="flex justify-center" v-if="isLoading || loading">
                    <Loader2 class="w-12 h-12 animate-spin text-primary" />
                </div>
                <MessageCard
                    v-else
                    :messages="tenantConversation"
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
    </div> -->
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
