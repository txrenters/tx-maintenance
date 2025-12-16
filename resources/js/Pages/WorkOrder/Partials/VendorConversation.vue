<script setup>
import { ref, watch, onMounted, nextTick } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { Loader2, Send, Paperclip, X } from "lucide-vue-next";
import { useToast } from "@/Components/ui/toast/use-toast";
import MessageCard from "@/Components/MessageCard.vue";
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/Components/ui/select";
import { Input } from "@/Components/ui/input";
import { Avatar, AvatarFallback, AvatarImage } from "@/Components/ui/avatar";
import { ScrollArea } from "@/Components/ui/scroll-area";
import { Textarea } from "@/Components/ui/textarea";
import { Button } from "@/Components/ui/button";

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
const attachedImages = ref([]);
const fileInputRef = ref(null);

const emit = defineEmits(["update-vendor-convo"]);

const page = usePage();
const { toast } = useToast();

const woc = ref(props.workOrder.woc);
const woc_phone_number = ref(
    props.workOrder.woc?.woc_number?.twilio_phone_number.phone_number
);

const loading = ref(false);

// Auto-resize textarea
const autoResize = (event) => {
    const textarea = event.target;
    textarea.style.height = "auto";
    textarea.style.height = Math.min(textarea.scrollHeight, 200) + "px";
};

const triggerFileInput = () => {
    if (fileInputRef.value) {
        fileInputRef.value.click();
    }
};

const handleFileSelect = (event) => {
    const files = Array.from(event.target.files || []);
    const imageFiles = files.filter((file) => file.type.startsWith("image/"));

    if (imageFiles.length !== files.length) {
        toast({
            variant: "destructive",
            title: "Invalid file type",
            description: "Only image files are allowed.",
        });
    }

    imageFiles.forEach((file) => {
        if (file.size > 10 * 1024 * 1024) {
            // 10MB limit
            toast({
                variant: "destructive",
                title: "File too large",
                description: `${file.name} is too large. Maximum size is 10MB.`,
            });
            return;
        }

        const reader = new FileReader();
        reader.onload = (e) => {
            attachedImages.value.push({
                file,
                url: e.target.result,
                name: file.name,
            });
        };
        reader.readAsDataURL(file);
    });

    // Clear the input so the same file can be selected again
    event.target.value = "";
};

const removeImage = (index) => {
    attachedImages.value.splice(index, 1);
};

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

    if (!newMessage.value && attachedImages.value.length === 0) {
        toast({
            variant: "destructive",
            title: "Uh oh! Something went wrong.",
            description:
                "There was a problem with your request. Please type a message or attach an image!",
        });
        loading.value = false;

        return;
    }

    if (newMessage.value.trim() !== "" || attachedImages.value.length > 0) {
        const formData = new FormData();
        formData.append("text", newMessage.value);
        formData.append("sender_phone_number", woc_phone_number.value);
        formData.append("receiver_phone_number", vendor_phone_number.value);
        formData.append("work_order_id", props.workOrder.id);
        formData.append("conversation_type", "vendor");

        // Add image files to FormData
        attachedImages.value.forEach((image, index) => {
            formData.append(`images[${index}]`, image.file);
        });

        router.post(route("work_order.conversation.send"), formData, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                toast({
                    title: "Success",
                    description: "Message has been sent successfully!",
                });
                newMessage.value = "";
                // Reset textarea height
                const textarea = document.querySelector('textarea[placeholder="Type your message..."]');
                if (textarea) textarea.style.height = "auto";
                attachedImages.value = [];
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
    <div>
        <div class="grid gap-3 overflow-y-auto px-6">
            <p class="font-semibold uppercase text-xs mb-3">
                Vendor Conversation
            </p>
            <div class="flex justify-between gap-2 mb-2">
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

            <div
                class="flex flex-col gap-4 overflow-y-auto"
                ref="chatContainer"
            >
                <ScrollArea class="bg-secondary h-[50vh] max-h-[520px] min-h-[300px] rounded-md p-3">
                    <div
                        class="flex justify-center"
                        v-if="isLoading || loading"
                    >
                        <Loader2 class="w-12 h-12 animate-spin text-primary" />
                    </div>
                    <MessageCard
                        v-else
                        :messages="vendorConversation"
                        :sender="woc_phone_number"
                    />
                </ScrollArea>
            </div>

            <!-- Image attachments preview -->
            <div v-if="attachedImages.length > 0" class="mb-4">
                <div
                    class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2"
                >
                    <div
                        v-for="(image, index) in attachedImages"
                        :key="index"
                        class="relative group"
                    >
                        <img
                            :src="image.url"
                            :alt="image.name"
                            class="w-full h-20 object-cover rounded-lg border"
                        />
                        <Button
                            size="icon"
                            variant="destructive"
                            class="absolute -top-2 -right-2 w-6 h-6 rounded-full opacity-0 group-hover:opacity-100 transition-opacity"
                            @click="removeImage(index)"
                        >
                            <X class="w-3 h-3" />
                        </Button>
                    </div>
                </div>
            </div>

            <div class="relative w-full mt-4 mb-6">
                <Textarea
                    v-model="newMessage"
                    placeholder="Type your message..."
                    class="w-full resize-none rounded-2xl border py-3 pr-24 min-h-[44px] max-h-[200px] overflow-y-auto"
                    rows="3"
                    @input="autoResize"
                    :disabled="loading"
                />
                <div class="flex absolute top-3 right-2">
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

                <!-- Hidden file input -->
                <input
                    ref="fileInputRef"
                    type="file"
                    multiple
                    accept="image/*"
                    @change="handleFileSelect"
                    class="hidden"
                />
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
