<script setup>
import { computed, ref } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import {
    MessageSquare,
    Calendar,
    User,
    Phone,
    Clock,
    CheckCircle,
    Circle,
    Search,
    Filter,
    Download,
    Eye,
    Wrench,
    PhoneCall,
} from "lucide-vue-next";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    conversations: Array,
});

const searchTerm = ref("");
const statusFilter = ref("all");
const workOrderFilter = ref("all");
const selectedConversation = ref(null);
const isViewModalOpen = ref(false);

const openViewModal = (conversation) => {
    selectedConversation.value = conversation;
    isViewModalOpen.value = true;
};

const closeViewModal = () => {
    selectedConversation.value = null;
    isViewModalOpen.value = false;
};

const filteredConversations = computed(() => {
    let filtered = props.conversations.data;

    if (searchTerm.value) {
        filtered = filtered.filter(
            (conv) =>
                conv.message
                    ?.toLowerCase()
                    .includes(searchTerm.value.toLowerCase()) ||
                conv.job_number
                    ?.toString()
                    .toLowerCase()
                    .includes(searchTerm.value.toLowerCase()) ||
                conv.client
                    ?.toLowerCase()
                    .includes(searchTerm.value.toLowerCase()) ||
                conv.sender_number
                    ?.toLowerCase()
                    .includes(searchTerm.value.toLowerCase()) ||
                conv.receiver_number
                    ?.toLowerCase()
                    .includes(searchTerm.value.toLowerCase())
        );
    }

    if (statusFilter.value !== "all") {
        filtered = filtered.filter((conv) => {
            if (statusFilter.value === "read") return conv.is_read;
            return true;
        });
    }

    return filtered;
});

const formatDate = (dateString) => {
    const date = new Date(dateString);
    const options = { timeZone: "America/Chicago" };

    return (
        date.toLocaleDateString("en-US", options) +
        " " +
        date.toLocaleTimeString("en-US", options)
    );
};

const truncateMessage = (message, length = 100) => {
    if (message.length <= length) return message;
    return message.substring(0, length) + "...";
};
</script>

<template>
    <Head :title="title" />

    <div class="space-y-6">
        <Card>
            <CardHeader>
                <!-- Filters -->
                <div class="grid gap-4 md:grid-cols-3">
                    <div class="space-y-2">
                        <div class="relative">
                            <Search
                                class="absolute left-3 top-3 h-4 w-4 text-gray-400"
                            />
                            <Input
                                v-model="searchTerm"
                                type="search"
                                placeholder="Search messages..."
                                class="pl-10"
                            />
                        </div>
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Job Number</TableHead>
                            <TableHead>Client</TableHead>
                            <TableHead>Sender</TableHead>
                            <TableHead>Receiver</TableHead>
                            <TableHead>Message</TableHead>
                            <TableHead>Date</TableHead>
                            <TableHead>Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="conversation in filteredConversations"
                            :key="conversation.id"
                        >
                            <TableCell
                                >{{ conversation.job_number }}
                            </TableCell>
                            <TableCell>{{ conversation.client }} </TableCell>
                            <TableCell>
                                <div class="flex items-center gap-2">
                                    <Phone class="w-4 h-4 text-gray-400" />
                                    <span>{{
                                        conversation.sender_number || "Unknown"
                                    }}</span>
                                </div>
                            </TableCell>
                            <TableCell>
                                <div class="flex items-center gap-2">
                                    <Phone class="w-4 h-4 text-gray-400" />
                                    <span>{{
                                        conversation.receiver_number ||
                                        "Unknown"
                                    }}</span>
                                </div>
                            </TableCell>
                            <TableCell>
                                <div class="max-w-md">
                                    <p class="text-sm">
                                        {{
                                            truncateMessage(
                                                conversation.message
                                            )
                                        }}
                                    </p>
                                </div>
                            </TableCell>
                            <TableCell>
                                <div
                                    class="flex items-center gap-2 text-sm text-gray-600"
                                >
                                    <Clock class="w-4 h-4" />
                                    {{ formatDate(conversation.created_at) }}
                                </div>
                            </TableCell>
                            <TableCell>
                                <div class="flex gap-2">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="gap-1"
                                        @click="openViewModal(conversation)"
                                    >
                                        <Eye class="w-4 h-4" />
                                        View
                                    </Button>
                                </div>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>

                <div
                    v-if="filteredConversations.length === 0"
                    class="text-center py-8"
                >
                    <MessageSquare
                        class="w-12 h-12 text-gray-400 mx-auto mb-4"
                    />
                    <p class="text-gray-500">
                        No conversations found matching your criteria
                    </p>
                </div>
                <div class="flex justify-between">
                    <PaginationResultRange :data="conversations" />
                    <Pagination :pagination="conversations.links" />
                </div>
            </CardContent>
        </Card>

        <!-- View Modal -->
        <Dialog v-model:open="isViewModalOpen">
            <DialogContent class="max-w-2xl">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        <MessageSquare class="w-5 h-5" />
                        Message Details
                    </DialogTitle>
                    <DialogDescription>
                        Full conversation details and metadata
                    </DialogDescription>
                </DialogHeader>

                <div v-if="selectedConversation" class="space-y-6">
                    <!-- Message Content -->
                    <div class="space-y-3">
                        <h3 class="font-semibold text-lg">Message</h3>
                        <div class="border p-4 rounded-lg">
                            <p class="text-sm whitespace-pre-wrap">
                                {{ selectedConversation.message }}
                            </p>
                        </div>
                    </div>

                    <!-- Metadata -->
                    <div class="grid gap-4 md:grid-cols-3">
                        <div class="space-y-3">
                            <h4 class="font-medium">Sender Information</h4>
                            <div class="space-y-2 text-sm">
                                <div class="flex items-center gap-2">
                                    <Phone class="w-4 h-4 text-gray-500" />
                                    <span class="font-medium">Phone:</span>
                                    <span>{{
                                        selectedConversation.sender_number ||
                                        "N/A"
                                    }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-3">
                            <h4 class="font-medium">Receiver Information</h4>
                            <div class="space-y-2 text-sm">
                                <div class="flex items-center gap-2">
                                    <Phone class="w-4 h-4 text-gray-500" />
                                    <span class="font-medium">Phone:</span>
                                    <span>{{
                                        selectedConversation.receiver_number ||
                                        "N/A"
                                    }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-3">
                            <h4 class="font-medium">Message Details</h4>
                            <div class="space-y-2 text-sm">
                                <div class="flex gap-1 flex-col">
                                    <div class="flex gap-2 items-center">
                                        <Clock class="w-4 h-4" />
                                        <span class="font-medium">Sent:</span>
                                    </div>

                                    <span>{{
                                        formatDate(
                                            selectedConversation.created_at
                                        )
                                    }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-2 mt-6">
                    <Button variant="outline" @click="closeViewModal">
                        Close
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
