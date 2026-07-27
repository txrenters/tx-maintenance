<script setup>
import { ref, watch, onMounted, nextTick, computed } from "vue";
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
import AutomationToggle from "@/Components/WorkOrder/AutomationToggle.vue";

const props = defineProps({
    ownerConversation: Array,
    workOrderOwners: Array,
    isLoading: Boolean,
    workOrder: Object,
});

const emit = defineEmits(["update-owner-convo"]);
const { toast } = useToast();

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
const selectedOwner = ref("");
const owner_phone_number = ref("");
const chatContainer = ref(null); // Reference to the chat container for auto-scrolling

// Image attachment functionality
const selectedImages = ref([]);
const fileInput = ref(null);

const page = usePage();
const woc = ref(props.workOrder.woc);
const woc_phone_number = ref(
    props.workOrder.woc?.woc_number?.twilio_phone_number?.phone_number
        ?? page.props.maintenance_twilio_phone_number
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
// Auto-resize textarea
const autoResize = (event) => {
    const textarea = event.target;
    textarea.style.height = "auto";
    textarea.style.height = Math.min(textarea.scrollHeight, 200) + "px";
};

// Handle file selection
const handleImageSelect = (event) => {
    const files = Array.from(event.target.files || []);
    files.forEach((file) => {
        const isImage = file.type.startsWith("image/");
        const isVideo = file.type.startsWith("video/");
        const isPdf = file.type === "application/pdf";
        if (!isImage && !isVideo && !isPdf) {
            toast({
                variant: "destructive",
                title: "Invalid file type",
                description: "Please select an image, video, or PDF file",
            });
            return;
        }
        if (file.size > 50 * 1024 * 1024) {
            toast({
                variant: "destructive",
                title: "File too large",
                description: `${file.name} exceeds 50MB limit`,
            });
            return;
        }
        // PDFs get a filename tile rather than a data-URL thumbnail.
        if (isPdf) {
            selectedImages.value.push({ file, preview: null, isPdf: true });
            return;
        }
        const reader = new FileReader();
        reader.onload = (e) => {
            selectedImages.value.push({ file, preview: e.target.result, isVideo });
        };
        reader.readAsDataURL(file);
    });
    if (fileInput.value) {
        fileInput.value.value = "";
    }
};

// Remove selected image
const removeImage = (index) => {
    selectedImages.value.splice(index, 1);
};

// Trigger file input
const triggerFileInput = () => {
    fileInput.value?.click();
};
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

    if (!messageBody.value && selectedImages.value.length === 0) {
        toast({
            variant: "destructive",
            title: "Uh oh! Something went wrong.",
            description: "Please type a message or select an image to send!",
        });
        loading.value = false;

        return;
    }

    if (messageBody.value.trim() !== "" || selectedImages.value.length > 0) {

        // Create FormData for file upload
        const formData = new FormData();
        formData.append("text", displayMessage.value || "");
        formData.append("sender_phone_number", woc_phone_number.value);
        formData.append("receiver_phone_number", owner_phone_number.value);
        formData.append("work_order_id", props.workOrder.id);
        formData.append("conversation_type", "owner");

        selectedImages.value.forEach((img) => {
            formData.append("images[]", img.file);
        });

        router.post(route("work_order.conversation.send"), formData, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                toast({
                    title: "Success",
                    description: "Message sent. Delivery may take a moment.",
                });
                emit("update-owner-convo");
                messageBody.value = "";
                // Reset textarea height
                const textarea = document.querySelector('textarea[placeholder="Type your message..."]');
                if (textarea) textarea.style.height = "auto";
                selectedImages.value = []; // Clear selected images
                scrollToBottom(); // Scroll to the bottom after sending a message
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
    <div>
        <div class="grid gap-3 overflow-y-auto px-6">
            <div class="flex items-center justify-between gap-2 mb-3">
                <p class="font-semibold uppercase text-xs">
                    Owner Conversation
                </p>
                <AutomationToggle
                    v-if="workOrder?.id"
                    :work-order-id="workOrder.id"
                    channel="owner"
                />
            </div>

            <div class="flex justify-between gap-2 mb-2">
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

                <div class="flex flex-col text-left" v-if="woc">
                    <div class="flex gap-2 items-center">
                        <Avatar class="w-5 h-5">
                            <AvatarImage
                                :src="woc?.profile_photo_url || 'default.jpg'"
                            />
                            <AvatarFallback>
                                {{ woc?.name?.charAt(0) }}
                            </AvatarFallback>
                        </Avatar>
                        {{ woc?.name }}
                    </div>
                    {{ woc?.woc_number?.twilio_phone_number?.phone_number }}
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
                        :messages="ownerConversation"
                        :sender="woc_phone_number"
                    />
                </ScrollArea>
            </div>

            <!-- Image Preview -->
            <div
                v-if="selectedImages.length > 0"
                class="mb-4 p-3 border rounded-lg bg-muted/20"
            >
                <div class="flex flex-wrap gap-2">
                    <div
                        v-for="(img, index) in selectedImages"
                        :key="index"
                        class="relative"
                    >
                        <video
                            v-if="img.isVideo"
                            :src="img.preview"
                            class="w-20 h-20 object-cover rounded-lg border bg-black"
                            muted
                            playsinline
                        />
                        <div
                            v-else-if="img.isPdf"
                            class="w-20 h-20 flex flex-col items-center justify-center gap-1 rounded-lg border bg-muted p-1 text-center"
                        >
                            <span class="text-2xl">📄</span>
                            <span class="w-full truncate text-[10px] text-muted-foreground">{{ img.file.name }}</span>
                        </div>
                        <img
                            v-else
                            :src="img.preview"
                            :alt="img.file.name"
                            class="w-20 h-20 object-cover rounded-lg border"
                        />
                        <Button
                            size="icon"
                            variant="destructive"
                            class="absolute -top-2 -right-2 h-6 w-6"
                            @click="removeImage(index)"
                        >
                            <X class="h-3 w-3" />
                        </Button>
                    </div>
                </div>
                <p class="text-xs text-muted-foreground mt-2">
                    {{ selectedImages.length }} image{{ selectedImages.length > 1 ? 's' : '' }} selected
                </p>
            </div>

            <!-- Message Input -->
            <div class="relative w-full mt-4 mb-6">
                <!-- Hidden file input -->
                <input
                    ref="fileInput"
                    type="file"
                    accept="image/*,video/*,application/pdf"
                    multiple
                    @change="handleImageSelect"
                    class="hidden"
                />

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
                        <Send v-if="!isLoading || loading" class="h-4 w-4" />
                        <Loader2 v-else class="w-4 h-4 animate-spin" />
                    </Button>
                </div>
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


