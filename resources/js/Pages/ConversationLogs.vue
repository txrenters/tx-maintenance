<script setup>
import { computed, ref } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import { Head, Link } from "@inertiajs/vue3";
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
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from "@/Components/ui/card";
import { Badge } from "@/Components/ui/badge";
import { Button } from "@/Components/ui/button";
import { Input } from "@/Components/ui/input";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/Components/ui/select";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/Components/ui/table";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from "@/Components/ui/dialog";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    conversations: Array,
    workOrders: Array,
    stats: Object,
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
    let filtered = props.conversations;

    if (searchTerm.value) {
        filtered = filtered.filter(
            (conv) =>
                conv.message
                    ?.toLowerCase()
                    .includes(searchTerm.value.toLowerCase()) ||
                conv.work_order?.work_order_no
                    ?.toString()
                    .toLowerCase()
                    .includes(searchTerm.value.toLowerCase()) ||
                conv.sender_name
                    ?.toLowerCase()
                    .includes(searchTerm.value.toLowerCase())
        );
    }

    if (statusFilter.value !== "all") {
        filtered = filtered.filter((conv) => {
            if (statusFilter.value === "read") return conv.is_read;
            if (statusFilter.value === "unread") return !conv.is_read;
            return true;
        });
    }

    if (workOrderFilter.value !== "all") {
        filtered = filtered.filter(
            (conv) => conv.work_order_id == workOrderFilter.value
        );
    }

    return filtered;
});

const formatDate = (dateString) => {
    const date = new Date(dateString);
    return date.toLocaleDateString() + " " + date.toLocaleTimeString();
};

const truncateMessage = (message, length = 100) => {
    if (message.length <= length) return message;
    return message.substring(0, length) + "...";
};
</script>

<template>
    <Head :title="title" />

    <div class="space-y-6">
        <!-- Header -->
        <div class="flex justify-between items-center">
            <div>
                <h1 class="text-3xl font-bold">Conversation Logs</h1>
                <p class="mt-2">
                    View all SMS conversations and messages related to work
                    orders
                </p>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="grid gap-4 md:grid-cols-4">
            <Card>
                <CardHeader
                    class="flex flex-row items-center justify-between space-y-0 pb-2"
                >
                    <CardTitle class="text-sm font-medium"
                        >Total Messages</CardTitle
                    >
                    <MessageSquare class="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                    <div class="text-2xl font-bold">
                        {{ stats?.total || 0 }}
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader
                    class="flex flex-row items-center justify-between space-y-0 pb-2"
                >
                    <CardTitle class="text-sm font-medium"
                        >Unread Messages</CardTitle
                    >
                    <Circle class="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                    <div class="text-2xl font-bold">
                        {{ stats?.unread || 0 }}
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader
                    class="flex flex-row items-center justify-between space-y-0 pb-2"
                >
                    <CardTitle class="text-sm font-medium"
                        >Read Messages</CardTitle
                    >
                    <CheckCircle class="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                    <div class="text-2xl font-bold">{{ stats?.read || 0 }}</div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader
                    class="flex flex-row items-center justify-between space-y-0 pb-2"
                >
                    <CardTitle class="text-sm font-medium"
                        >Active Conversations</CardTitle
                    >
                    <Phone class="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                    <div class="text-2xl font-bold">
                        {{ stats?.active_conversations || 0 }}
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Filters -->
        <Card>
            <CardHeader>
                <CardTitle>Filter Conversations</CardTitle>
            </CardHeader>
            <CardContent>
                <div class="grid gap-4 md:grid-cols-3">
                    <div class="space-y-2">
                        <label class="text-sm font-medium">Search</label>
                        <div class="relative">
                            <Search
                                class="absolute left-3 top-3 h-4 w-4 text-gray-400"
                            />
                            <Input
                                v-model="searchTerm"
                                type="search"
                                placeholder="Search messages, work orders, or senders..."
                                class="pl-10"
                            />
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label class="text-sm font-medium">Status</label>
                        <Select v-model="statusFilter">
                            <SelectTrigger>
                                <SelectValue placeholder="Select status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all"
                                    >All Messages</SelectItem
                                >
                                <SelectItem value="read">Read Only</SelectItem>
                                <SelectItem value="unread"
                                    >Unread Only</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="space-y-2">
                        <label class="text-sm font-medium">Work Order</label>
                        <Select v-model="workOrderFilter">
                            <SelectTrigger>
                                <SelectValue placeholder="Select work order" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all"
                                    >All Work Orders</SelectItem
                                >
                                <SelectItem
                                    v-for="workOrder in workOrders"
                                    :key="workOrder.id"
                                    :value="workOrder.id.toString()"
                                >
                                    {{ workOrder.work_order_no }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>
            </CardContent>
        </Card>

        <!-- Conversations Table -->
        <Card>
            <CardHeader>
                <div class="flex justify-between items-center">
                    <div>
                        <CardTitle>Conversations</CardTitle>
                        <CardDescription>
                            {{ filteredConversations.length }} of
                            {{ conversations.length }} messages
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Status</TableHead>
                            <TableHead>Work Order</TableHead>
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
                            <TableCell>
                                <Badge
                                    :variant="
                                        conversation.is_read
                                            ? 'secondary'
                                            : 'default'
                                    "
                                >
                                    <CheckCircle
                                        v-if="conversation.is_read"
                                        class="w-3 h-3 mr-1"
                                    />
                                    <Circle v-else class="w-3 h-3 mr-1" />
                                    {{
                                        conversation.is_read ? "Read" : "Unread"
                                    }}
                                </Badge>
                            </TableCell>
                            <TableCell>
                                <Link
                                    v-if="conversation.work_order"
                                    :href="
                                        route(
                                            'work_orders.details',
                                            conversation.work_order_id
                                        )
                                    "
                                    class="text-blue-600 hover:text-blue-800 font-medium"
                                >
                                    {{ conversation.work_order.work_order_no }}
                                </Link>
                                <span v-else class="text-gray-500">
                                    No Work Order
                                </span>
                            </TableCell>
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
                                <div class="flex items-center gap-2">
                                    <Badge
                                        :variant="
                                            selectedConversation.is_read
                                                ? 'secondary'
                                                : 'default'
                                        "
                                        class="text-xs"
                                    >
                                        <CheckCircle
                                            v-if="selectedConversation.is_read"
                                            class="w-3 h-3 mr-1"
                                        />
                                        <Circle v-else class="w-3 h-3 mr-1" />
                                        {{
                                            selectedConversation.is_read
                                                ? "Read"
                                                : "Unread"
                                        }}
                                    </Badge>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Work Order Information -->
                    <div class="space-y-3">
                        <h4 class="font-medium">Work Order</h4>
                        <div
                            v-if="selectedConversation.work_order"
                            class="flex items-center gap-2 text-sm"
                        >
                            <Wrench class="w-4 h-4 text-gray-500" />
                            <Link
                                :href="
                                    route(
                                        'work_orders.details',
                                        selectedConversation.work_order_id
                                    )
                                "
                                class="text-blue-600 hover:text-blue-800 font-medium"
                            >
                                {{
                                    selectedConversation.work_order
                                        .work_order_no
                                }}
                            </Link>
                        </div>
                        <div v-else class="text-sm text-gray-500">
                            No associated work order
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-2 mt-6">
                    <Button variant="outline" @click="closeViewModal">
                        Close
                    </Button>
                    <Button v-if="selectedConversation?.work_order" as-child>
                        <Link
                            :href="
                                route(
                                    'work_orders.details',
                                    selectedConversation.work_order_id
                                )
                            "
                        >
                            View Work Order
                        </Link>
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
