<script setup>
import { ref, watch, onMounted, nextTick, computed } from "vue";
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

const messageBody = ref("");
const MAX_MESSAGE_LENGTH = 1600;
const includeRefSuffix = ref(true);
const refSuffix = computed(() => {
    const workOrderNo = props.workOrder?.work_order_no;
    return workOrderNo ? ` (Ref: WO#${workOrderNo})` : "";
});
const activeRefSuffix = computed(() =>
    includeRefSuffix.value ? refSuffix.value : ""
);
const maxBodyLength = computed(() =>
    Math.max(0, MAX_MESSAGE_LENGTH - activeRefSuffix.value.length)
);
const displayMessage = computed({
    get: () => `${messageBody.value}${activeRefSuffix.value}`,
    set: (value) => {
        const suffix = refSuffix.value;
        const withSuffixLimit = Math.max(0, MAX_MESSAGE_LENGTH - suffix.length);
        if (!suffix) {
            messageBody.value = value.slice(0, MAX_MESSAGE_LENGTH);
            return;
        }
        if (value.endsWith(suffix)) {
            includeRefSuffix.value = true;
            messageBody.value = value.slice(0, -suffix.length).slice(0, withSuffixLimit);
            return;
        }
        if (value.includes(suffix)) {
            includeRefSuffix.value = true;
            messageBody.value = value.replace(suffix, "").slice(0, withSuffixLimit);
            return;
        }
        includeRefSuffix.value = false;
        messageBody.value = value.slice(0, MAX_MESSAGE_LENGTH);
    },
});
const messageCount = computed(() => displayMessage.value.length);
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
    props.workOrder.woc?.woc_number?.twilio_phone_number?.phone_number
        ?? page.props.maintenance_twilio_phone_number
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

    if (!messageBody.value && attachedImages.value.length === 0) {
        toast({
            variant: "destructive",
            title: "Uh oh! Something went wrong.",
            description:
                "There was a problem with your request. Please type a message or attach an image!",
        });
        loading.value = false;

        return;
    }

    if (messageBody.value.trim() !== "" || attachedImages.value.length > 0) {
        const formData = new FormData();
        formData.append("text", displayMessage.value);
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
                    description: "Message queued with Twilio. Delivery pending.",
                });
                messageBody.value = "";
                // Reset textarea height
                const textarea = document.querySelector(
                    'textarea[placeholder="Type your message..."]'
                );
                if (textarea) textarea.style.height = "auto";
                attachedImages.value = [];
                scrollToBottom();
                emit("update-vendor-convo");
            },
            onError: (errors) => {
                const errorMessage =
                    errors.message ||
                    Object.values(errors)[0] ||
                    "There was a problem with your request. Please try again!";
                toast({
                    variant: "destructive",
                    title: "Uh oh! Something went wrong.",
                    description: errorMessage,
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
        const foundVendor = props.workOrderVendors.find(
            (vendor) => vendor.id == newVendor
        );
        vendor_phone_number.value = foundVendor ? foundVendor.user?.phone : "";
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
                                        v-for="vendor in workOrderVendors"
                                        :key="vendor.id"
                                    >
                                        <SelectItem :value="String(vendor.id)">
                                            {{ vendor.name }}
                                            <template
                                                v-if="vendor?.user?.phone"
                                            >
                                                - {{ vendor.user.phone }}
                                            </template>
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
                    {{ woc?.woc_number?.twilio_phone_number?.phone_number }}
                </div>
            </div>

            <div
                class="flex flex-col gap-4 overflow-y-auto"
                ref="chatContainer"
            >
                <ScrollArea
                    class="bg-secondary h-[50vh] max-h-[520px] min-h-[300px] rounded-md p-3"
                >
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
                    v-model="displayMessage"
                    placeholder="Type your message..."
                    class="w-full resize-none rounded-2xl border py-3 pr-24 min-h-[44px] max-h-[200px] overflow-y-auto"
                    rows="3"
                    @input="autoResize"
                    :disabled="loading"
                    :maxlength="MAX_MESSAGE_LENGTH"
                />
                <p class="text-xs text-muted-foreground text-right mt-1 pr-2">
                    {{ messageCount }}/{{ MAX_MESSAGE_LENGTH }}
                </p>
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
                        <Send v-if="!isLoading && !loading" class="h-4 w-4" />
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

