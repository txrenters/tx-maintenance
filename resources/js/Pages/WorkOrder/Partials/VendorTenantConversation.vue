<script setup>
import { ref, watch, onMounted, nextTick } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { Loader2, Send, Paperclip, X, Image } from "lucide-vue-next";
import { useToast } from "@/Components/ui/toast/use-toast";
import MessageCard from "@/Components/MessageCard.vue";
import { Button } from "@/Components/ui/button";
import { Input } from "@/Components/ui/input";
import { Textarea } from "@/Components/ui/textarea";
import { ScrollArea, ScrollBar } from "@/Components/ui/scroll-area";
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/Components/ui/select";
import { Avatar, AvatarFallback, AvatarImage } from "@/Components/ui/avatar";

const props = defineProps({
    vendorConversation: Array,
    workOrderTenants: Array,
    workOrderVendors: Array,
    isLoading: Boolean,
    workOrder: Object,
});

const page = usePage();
const { toast } = useToast();
const emit = defineEmits(["update-vendor-tenant-convo"]);
const newMessage = ref("");
const selectedTenant = ref("");
const tenant_phone_number = ref(props.workOrder.requested.mobile_phone);
const chatContainer = ref(null); // Reference to the chat container for auto-scrolling
const vendor_phone_number = ref(page.props.auth.user.vendor.twilio_number);

watch(selectedTenant, (newTenant) => {
    if (newTenant) {
        const foundTenant = props.workOrderTenants.find(
            (tenant) => tenant.id == newTenant
        );
        tenant_phone_number.value = foundTenant ? foundTenant.mobile_phone : "";
    }
});

const loading = ref(false);

// Image attachment functionality
const selectedImage = ref(null);
const imagePreview = ref(null);
const fileInput = ref(null);

// Handle file selection
const handleImageSelect = (event) => {
    const file = event.target.files[0];
    if (file) {
        // Validate file type
        if (!file.type.startsWith("image/")) {
            toast({
                variant: "destructive",
                title: "Invalid file type",
                description:
                    "Please select an image file (JPG, PNG, GIF, etc.)",
            });
            return;
        }

        // Validate file size (5MB limit)
        if (file.size > 5 * 1024 * 1024) {
            toast({
                variant: "destructive",
                title: "File too large",
                description: "Please select an image smaller than 5MB",
            });
            return;
        }

        selectedImage.value = file;

        // Create preview URL
        const reader = new FileReader();
        reader.onload = (e) => {
            imagePreview.value = e.target.result;
        };
        reader.readAsDataURL(file);
    }
};

// Remove selected image
const removeImage = () => {
    selectedImage.value = null;
    imagePreview.value = null;
    if (fileInput.value) {
        fileInput.value.value = "";
    }
};

// Trigger file input
const triggerFileInput = () => {
    fileInput.value?.click();
};
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

    if (!newMessage.value && !selectedImage.value) {
        toast({
            variant: "destructive",
            title: "Uh oh! Something went wrong.",
            description: "Please type a message or select an image to send!",
        });
        loading.value = false;

        return;
    }

    if (newMessage.value.trim() !== "" || selectedImage.value) {
        // Create FormData for file upload
        const formData = new FormData();
        formData.append("text", newMessage.value || "");
        formData.append("sender_phone_number", vendor_phone_number.value);
        formData.append("receiver_phone_number", tenant_phone_number.value);
        formData.append("work_order_id", props.workOrder.id);
        formData.append("conversation_type", "vendor_tenant");

        // Add image if selected
        if (selectedImage.value) {
            formData.append("image", selectedImage.value);
        }

        router.post(route("work_order.vendor.conversation.send"), formData, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                toast({
                    title: "Success",
                    description: "Message has been sent successfully!",
                });
                newMessage.value = "";
                removeImage(); // Clear the selected image
                emit("update-vendor-tenant-convo");
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
        });
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
                                            workOrder.requested.mobile_phone
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
                            :src="
                                page.props.auth.user?.profile_photo_url ||
                                'default.jpg'
                            "
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
        <div class="flex flex-col gap-4 overflow-y-auto" ref="chatContainer">
            <ScrollArea class="bg-secondary h-[520px] rounded-md p-3">
                <div class="flex justify-center" v-if="isLoading || loading">
                    <Loader2 class="w-12 h-12 animate-spin text-primary" />
                </div>
                <MessageCard
                    v-else
                    :messages="vendorConversation"
                    :sender="woc_phone_number"
                />
            </ScrollArea>
        </div>

        <!-- Image Preview -->
        <div v-if="imagePreview" class="mb-4 p-3 border rounded-lg bg-muted/20">
            <div class="flex items-start gap-3">
                <div class="relative">
                    <img
                        :src="imagePreview"
                        alt="Selected image"
                        class="w-20 h-20 object-cover rounded-lg border"
                    />
                    <Button
                        size="icon"
                        variant="destructive"
                        class="absolute -top-2 -right-2 h-6 w-6"
                        @click="removeImage"
                    >
                        <X class="h-3 w-3" />
                    </Button>
                </div>
                <div class="flex-1">
                    <p class="text-sm font-medium">{{ selectedImage?.name }}</p>
                    <p class="text-xs text-muted-foreground">
                        {{ Math.round(selectedImage?.size / 1024) }}KB
                    </p>
                    <p class="text-xs text-muted-foreground mt-1">
                        Ready to send with your message
                    </p>
                </div>
            </div>
        </div>

        <!-- Message Input -->
        <div class="relative w-full mt-4 mb-6">
            <!-- Hidden file input -->
            <input
                ref="fileInput"
                type="file"
                accept="image/*"
                @change="handleImageSelect"
                class="hidden"
            />

            <Textarea
                v-model="newMessage"
                placeholder="Type your message..."
                class="w-full resize-none rounded-2xl border py-3 pr-24"
                rows="1"
                :disabled="loading"
            />

            <div class="flex absolute top-1/2 right-2 -translate-y-1/2">
                <!-- Attachment Button -->
                <Button
                    size="icon"
                    variant="ghost"
                    @click="triggerFileInput"
                    :disabled="loading"
                    title="Attach image"
                >
                    <Paperclip class="h-4 w-4" />
                </Button>

                <!-- Send Button -->
                <Button
                    size="icon"
                    variant="ghost"
                    @click.prevent="sendMessage"
                    :disabled="isLoading || loading"
                >
                    <Send v-if="!isLoading || loading" class="h-4 w-4" />
                    <Loader2 v-else class="w-4 h-4 animate-spin" />
                </Button>
            </div>
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
