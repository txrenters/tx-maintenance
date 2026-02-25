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
    ownerConversation: Array,
    isLoading: Boolean,
    workOrder: Object,
});
const newMessage = ref("");
const chatContainer = ref(null); // Reference to the chat container for auto-scrolling

const emit = defineEmits(["update-owner-convo"]);

const page = usePage();
const { toast } = useToast();

const loading = ref(false);
// Auto-resize textarea
const autoResize = (event) => {
    const textarea = event.target;
    textarea.style.height = "auto";
    textarea.style.height = Math.min(textarea.scrollHeight, 200) + "px";
};

// Image attachment functionality
const selectedImage = ref(null);
const imagePreview = ref(null);
const fileInput = ref(null);

const owner_phone_number = page.props.auth.user.phone;
const woc = ref(props.workOrder.woc);
const woc_phone_number = ref(
    props.workOrder.woc?.woc_number?.twilio_phone_number?.phone_number
        ?? page.props.maintenance_twilio_phone_number
);

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
        formData.append("sender_phone_number", owner_phone_number);
        formData.append("receiver_phone_number", woc_phone_number.value);
        formData.append("work_order_id", props.workOrder.id);
        formData.append("conversation_type", "owner");

        // Add image if selected
        if (selectedImage.value) {
            formData.append("image", selectedImage.value);
        }

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
                removeImage(); // Clear the selected image
                scrollToBottom();
                emit("update-owner-convo");
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
    <div>
        <div class="grid gap-3 overflow-y-auto px-6">
            <p class="font-semibold uppercase text-xs mb-3">
                Work Order Coordinator Conversation
            </p>

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
                        :sender="owner_phone_number"
                    />
                </ScrollArea>
            </div>

            <!-- Image Preview -->
            <div
                v-if="imagePreview"
                class="mb-4 p-3 border rounded-lg bg-muted/20"
            >
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
                        <p class="text-sm font-medium">
                            {{ selectedImage?.name }}
                        </p>
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
