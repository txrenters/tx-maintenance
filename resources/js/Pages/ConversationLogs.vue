<script setup>
import { computed, reactive, ref } from "vue";
import { Head, Link, router } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import Pagination from "@/Components/Pagination.vue";
import PaginationResultRange from "@/Components/PaginationResultRange.vue";
import { Badge } from "@/Components/ui/badge";
import { Button } from "@/Components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/Components/ui/card";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/Components/ui/dialog";
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
    AlertCircle,
    Briefcase,
    Eye,
    Filter,
    MessageSquare,
    RefreshCcw,
    Search,
    Wrench,
} from "lucide-vue-next";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    messages: Object,
    workOrders: Array,
    jobs: Array,
    stats: Object,
    filters: Object,
    deliveryStatuses: Array,
    canViewJobMessages: Boolean,
});

const isFilterModalOpen = ref(false);
const isViewModalOpen = ref(false);
const selectedMessage = ref(null);

const filterForm = reactive({
    search: props.filters?.search || "",
    source: props.filters?.source || "all",
    work_order_id: props.filters?.work_order_id || "all",
    jobber_id: props.filters?.jobber_id || "all",
    read_status: props.filters?.read_status || "all",
    delivery_status: props.filters?.delivery_status || "all",
});

const messagesData = computed(() => props.messages?.data || []);
const activeFiltersCount = computed(() => {
    return [
        filterForm.search.trim() !== "",
        filterForm.source !== "all",
        filterForm.work_order_id !== "all",
        filterForm.jobber_id !== "all",
        filterForm.read_status !== "all",
        filterForm.delivery_status !== "all",
    ].filter(Boolean).length;
});

const sourceLabel = (source) => {
    if (source === "work_order") return "Work Order";
    if (source === "job") return "Job Text";
    return "Message";
};

const twilioStatusLabel = (status) => {
    if (!status) return "Pending";
    return String(status)
        .replace(/_/g, " ")
        .replace(/\b\w/g, (char) => char.toUpperCase());
};

const twilioStatusVariant = (status) => {
    switch (String(status || "").toLowerCase()) {
        case "delivered":
        case "read":
            return "secondary";
        case "failed":
        case "undelivered":
        case "canceled":
            return "destructive";
        default:
            return "outline";
    }
};

const normalizeFilterValue = (value) => {
    if (value === undefined || value === null) return "all";
    if (String(value).trim() === "") return "all";
    return String(value);
};

const resetFilterForm = () => {
    filterForm.search = props.filters?.search || "";
    filterForm.source = props.filters?.source || "all";
    filterForm.work_order_id = normalizeFilterValue(
        props.filters?.work_order_id
    );
    filterForm.jobber_id = normalizeFilterValue(props.filters?.jobber_id);
    filterForm.read_status = props.filters?.read_status || "all";
    filterForm.delivery_status = props.filters?.delivery_status || "all";
};

const openFilters = () => {
    resetFilterForm();
    isFilterModalOpen.value = true;
};

const applyFilters = () => {
    const query = {};

    if (filterForm.search.trim() !== "") query.search = filterForm.search.trim();
    if (filterForm.source !== "all") query.source = filterForm.source;
    if (filterForm.work_order_id !== "all")
        query.work_order_id = filterForm.work_order_id;
    if (filterForm.jobber_id !== "all") query.jobber_id = filterForm.jobber_id;
    if (filterForm.read_status !== "all")
        query.read_status = filterForm.read_status;
    if (filterForm.delivery_status !== "all")
        query.delivery_status = filterForm.delivery_status;

    router.get(route("conversation_logs.index"), query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
    isFilterModalOpen.value = false;
};

const clearAllFilters = () => {
    filterForm.search = "";
    filterForm.source = "all";
    filterForm.work_order_id = "all";
    filterForm.jobber_id = "all";
    filterForm.read_status = "all";
    filterForm.delivery_status = "all";

    router.get(route("conversation_logs.index"), {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
    isFilterModalOpen.value = false;
};

const openViewModal = (message) => {
    selectedMessage.value = message;
    isViewModalOpen.value = true;
};

const closeViewModal = () => {
    selectedMessage.value = null;
    isViewModalOpen.value = false;
};

const formatDate = (dateString) => {
    if (!dateString) return "N/A";
    const date = new Date(dateString);

    return date.toLocaleString("en-US", {
        timeZone: "America/Chicago",
        year: "numeric",
        month: "short",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
    });
};

const truncateMessage = (message, length = 120) => {
    const value = String(message || "");
    if (value.length <= length) return value;
    return `${value.slice(0, length)}...`;
};
</script>

<template>
    <Head :title="title || 'Messages'" />

    <div class="space-y-6">
        <Card
            class="border-none bg-gradient-to-r from-slate-900 via-slate-800 to-slate-900 text-white"
        >
            <CardContent class="pt-6">
                <div
                    class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between"
                >
                    <div class="space-y-1">
                        <h1 class="text-2xl font-bold md:text-3xl">Messages</h1>
                        <p class="text-sm text-slate-300">
                            Unified feed for Work Order and Job text messages
                            with Twilio delivery status.
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <Button
                            variant="secondary"
                            class="gap-2"
                            @click="openFilters"
                        >
                            <Filter class="h-4 w-4" />
                            Filters
                            <Badge
                                v-if="activeFiltersCount"
                                variant="default"
                                class="ml-1 bg-slate-900 text-white"
                            >
                                {{ activeFiltersCount }}
                            </Badge>
                        </Button>
                        <Button
                            variant="outline"
                            class="gap-2 border-slate-500 bg-transparent text-white hover:bg-slate-700"
                            @click="clearAllFilters"
                        >
                            <RefreshCcw class="h-4 w-4" />
                            Reset
                        </Button>
                    </div>
                </div>
            </CardContent>
        </Card>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <Card>
                <CardHeader class="pb-2">
                    <CardTitle class="text-xs font-medium text-muted-foreground"
                        >Total</CardTitle
                    >
                </CardHeader>
                <CardContent class="pt-0">
                    <p class="text-2xl font-semibold">{{ stats?.total || 0 }}</p>
                </CardContent>
            </Card>
            <Card>
                <CardHeader class="pb-2">
                    <CardTitle class="text-xs font-medium text-muted-foreground"
                        >Work Order</CardTitle
                    >
                </CardHeader>
                <CardContent class="pt-0">
                    <p class="text-2xl font-semibold">
                        {{ stats?.work_order_total || 0 }}
                    </p>
                </CardContent>
            </Card>
            <Card>
                <CardHeader class="pb-2">
                    <CardTitle class="text-xs font-medium text-muted-foreground"
                        >Job Text</CardTitle
                    >
                </CardHeader>
                <CardContent class="pt-0">
                    <p class="text-2xl font-semibold">
                        {{ stats?.job_total || 0 }}
                    </p>
                </CardContent>
            </Card>
            <Card>
                <CardHeader class="pb-2">
                    <CardTitle class="text-xs font-medium text-muted-foreground"
                        >Delivery Tracked</CardTitle
                    >
                </CardHeader>
                <CardContent class="pt-0">
                    <p class="text-2xl font-semibold">
                        {{ stats?.with_delivery_status || 0 }}
                    </p>
                </CardContent>
            </Card>
            <Card>
                <CardHeader class="pb-2">
                    <CardTitle class="text-xs font-medium text-muted-foreground"
                        >Delivery Failed</CardTitle
                    >
                </CardHeader>
                <CardContent class="pt-0">
                    <p class="text-2xl font-semibold text-red-600">
                        {{ stats?.failed || 0 }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader class="pb-2">
                <div class="flex items-center justify-between gap-2">
                    <CardTitle class="text-lg">Message Feed</CardTitle>
                    <span class="text-xs text-muted-foreground">
                        {{ messages?.total || 0 }} result(s)
                    </span>
                </div>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Source</TableHead>
                            <TableHead>Reference</TableHead>
                            <TableHead>From / To</TableHead>
                            <TableHead>Message</TableHead>
                            <TableHead>Twilio Status</TableHead>
                            <TableHead>Timestamp</TableHead>
                            <TableHead class="w-[100px]">Action</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="message in messagesData"
                            :key="message.row_id"
                        >
                            <TableCell>
                                <Badge
                                    :variant="
                                        message.source === 'work_order'
                                            ? 'default'
                                            : 'secondary'
                                    "
                                >
                                    {{ sourceLabel(message.source) }}
                                </Badge>
                            </TableCell>
                            <TableCell>
                                <div v-if="message.source === 'work_order'">
                                    <Link
                                        v-if="message.work_order_id"
                                        :href="
                                            route(
                                                'work_orders.details',
                                                message.work_order_id
                                            )
                                        "
                                        class="inline-flex items-center gap-1 text-sm font-medium text-blue-600 hover:text-blue-800"
                                    >
                                        <Wrench class="h-3.5 w-3.5" />
                                        {{
                                            message.work_order_no ||
                                            `WO #${message.work_order_id}`
                                        }}
                                    </Link>
                                    <span
                                        v-else
                                        class="text-sm text-muted-foreground"
                                        >No work order</span
                                    >
                                </div>
                                <div v-else>
                                    <Link
                                        v-if="message.jobber_id"
                                        :href="
                                            route(
                                                'jobber.jobDetails',
                                                message.jobber_id
                                            )
                                        "
                                        class="inline-flex items-center gap-1 text-sm font-medium text-emerald-700 hover:text-emerald-900"
                                    >
                                        <Briefcase class="h-3.5 w-3.5" />
                                        {{
                                            message.job_number ||
                                            `Job #${message.jobber_id}`
                                        }}
                                    </Link>
                                    <span
                                        v-else
                                        class="text-sm text-muted-foreground"
                                        >No job</span
                                    >
                                </div>
                            </TableCell>
                            <TableCell>
                                <p class="text-xs">
                                    <span class="font-medium">From:</span>
                                    {{ message.sender_number || "N/A" }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    <span class="font-medium">To:</span>
                                    {{ message.receiver_number || "N/A" }}
                                </p>
                            </TableCell>
                            <TableCell>
                                <p
                                    class="max-w-[420px] whitespace-pre-wrap break-words text-sm"
                                >
                                    {{
                                        truncateMessage(message.message) ||
                                        "No text content"
                                    }}
                                </p>
                            </TableCell>
                            <TableCell>
                                <div class="space-y-1">
                                    <Badge
                                        :variant="
                                            twilioStatusVariant(
                                                message.twilio_status
                                            )
                                        "
                                    >
                                        {{
                                            twilioStatusLabel(
                                                message.twilio_status
                                            )
                                        }}
                                    </Badge>
                                    <p
                                        v-if="message.twilio_error_message"
                                        class="max-w-[260px] text-xs text-red-600 line-clamp-2"
                                    >
                                        {{ message.twilio_error_message }}
                                    </p>
                                </div>
                            </TableCell>
                            <TableCell class="text-sm text-muted-foreground">
                                {{ formatDate(message.created_at) }}
                            </TableCell>
                            <TableCell>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="gap-1"
                                    @click="openViewModal(message)"
                                >
                                    <Eye class="h-4 w-4" />
                                    View
                                </Button>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>

                <div
                    v-if="messagesData.length === 0"
                    class="flex flex-col items-center justify-center py-12 text-center"
                >
                    <MessageSquare
                        class="mb-3 h-10 w-10 text-muted-foreground"
                    />
                    <p class="text-sm font-medium">No messages found</p>
                    <p class="text-xs text-muted-foreground">
                        Try adjusting the filters.
                    </p>
                </div>

                <div class="mt-4 flex items-center justify-between">
                    <PaginationResultRange :data="messages" />
                    <Pagination :pagination="messages?.links || []" />
                </div>
            </CardContent>
        </Card>

        <Dialog v-model:open="isFilterModalOpen">
            <DialogContent class="max-w-2xl">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        <Filter class="h-4 w-4" />
                        Filter Messages
                    </DialogTitle>
                    <DialogDescription>
                        Apply filters to work order and job text messages.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4 py-2 md:grid-cols-2">
                    <div class="space-y-2 md:col-span-2">
                        <label class="text-sm font-medium">Search</label>
                        <div class="relative">
                            <Search
                                class="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground"
                            />
                            <Input
                                v-model="filterForm.search"
                                type="search"
                                placeholder="Message, phone number, WO#, Job#"
                                class="pl-9"
                            />
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label class="text-sm font-medium">Source</label>
                        <Select v-model="filterForm.source">
                            <SelectTrigger>
                                <SelectValue placeholder="All sources" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All</SelectItem>
                                <SelectItem value="work_order"
                                    >Work Order</SelectItem
                                >
                                <SelectItem
                                    v-if="canViewJobMessages"
                                    value="job"
                                    >Job Text</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="space-y-2">
                        <label class="text-sm font-medium">Read Status</label>
                        <Select v-model="filterForm.read_status">
                            <SelectTrigger>
                                <SelectValue placeholder="All read states" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All</SelectItem>
                                <SelectItem value="read">Read</SelectItem>
                                <SelectItem value="unread">Unread</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="space-y-2">
                        <label class="text-sm font-medium">Work Order</label>
                        <Select v-model="filterForm.work_order_id">
                            <SelectTrigger>
                                <SelectValue placeholder="All work orders" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All</SelectItem>
                                <SelectItem
                                    v-for="workOrder in workOrders || []"
                                    :key="workOrder.id"
                                    :value="String(workOrder.id)"
                                >
                                    {{ workOrder.work_order_no }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="space-y-2">
                        <label class="text-sm font-medium">Job</label>
                        <Select
                            v-model="filterForm.jobber_id"
                            :disabled="!canViewJobMessages"
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="All jobs" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All</SelectItem>
                                <SelectItem
                                    v-for="job in jobs || []"
                                    :key="job.id"
                                    :value="String(job.id)"
                                >
                                    {{
                                        job.job_number || `Job #${job.id}`
                                    }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="space-y-2 md:col-span-2">
                        <label class="text-sm font-medium"
                            >Twilio Delivery Status</label
                        >
                        <Select v-model="filterForm.delivery_status">
                            <SelectTrigger>
                                <SelectValue
                                    placeholder="All delivery statuses"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All</SelectItem>
                                <SelectItem
                                    v-for="status in deliveryStatuses || []"
                                    :key="status"
                                    :value="status"
                                >
                                    {{ twilioStatusLabel(status) }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <DialogFooter class="gap-2">
                    <Button variant="outline" @click="clearAllFilters"
                        >Clear</Button
                    >
                    <Button @click="applyFilters">Apply Filters</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="isViewModalOpen">
            <DialogContent class="max-w-3xl">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        <Eye class="h-4 w-4" />
                        Message Details
                    </DialogTitle>
                    <DialogDescription>
                        Full message metadata including Twilio delivery status.
                    </DialogDescription>
                </DialogHeader>

                <div v-if="selectedMessage" class="space-y-5">
                    <div class="grid gap-4 rounded-md border p-4 md:grid-cols-2">
                        <div class="space-y-2">
                            <p class="text-xs uppercase text-muted-foreground">
                                Source
                            </p>
                            <Badge
                                :variant="
                                    selectedMessage.source === 'work_order'
                                        ? 'default'
                                        : 'secondary'
                                "
                            >
                                {{ sourceLabel(selectedMessage.source) }}
                            </Badge>
                        </div>
                        <div class="space-y-2">
                            <p class="text-xs uppercase text-muted-foreground">
                                Created At
                            </p>
                            <p class="text-sm font-medium">
                                {{ formatDate(selectedMessage.created_at) }}
                            </p>
                        </div>
                        <div class="space-y-2">
                            <p class="text-xs uppercase text-muted-foreground">
                                From
                            </p>
                            <p class="text-sm">
                                {{ selectedMessage.sender_number || "N/A" }}
                            </p>
                        </div>
                        <div class="space-y-2">
                            <p class="text-xs uppercase text-muted-foreground">
                                To
                            </p>
                            <p class="text-sm">
                                {{ selectedMessage.receiver_number || "N/A" }}
                            </p>
                        </div>
                    </div>

                    <div class="rounded-md border p-4">
                        <p class="mb-2 text-xs uppercase text-muted-foreground">
                            Message
                        </p>
                        <p class="whitespace-pre-wrap break-words text-sm">
                            {{ selectedMessage.message || "No text content" }}
                        </p>
                    </div>

                    <div class="grid gap-4 rounded-md border p-4 md:grid-cols-2">
                        <div class="space-y-2">
                            <p class="text-xs uppercase text-muted-foreground">
                                Twilio Status
                            </p>
                            <Badge
                                :variant="
                                    twilioStatusVariant(
                                        selectedMessage.twilio_status
                                    )
                                "
                            >
                                {{
                                    twilioStatusLabel(
                                        selectedMessage.twilio_status
                                    )
                                }}
                            </Badge>
                        </div>
                        <div class="space-y-2">
                            <p class="text-xs uppercase text-muted-foreground">
                                Twilio SID
                            </p>
                            <p class="text-sm">
                                {{ selectedMessage.twilio_sid || "N/A" }}
                            </p>
                        </div>
                        <div
                            v-if="selectedMessage.twilio_error_message"
                            class="space-y-2 md:col-span-2"
                        >
                            <p
                                class="flex items-center gap-1 text-xs uppercase text-red-600"
                            >
                                <AlertCircle class="h-3.5 w-3.5" />
                                Delivery Error
                            </p>
                            <p class="text-sm text-red-700">
                                {{ selectedMessage.twilio_error_message }}
                            </p>
                        </div>
                    </div>
                </div>

                <DialogFooter class="gap-2">
                    <Button variant="outline" @click="closeViewModal"
                        >Close</Button
                    >
                    <Button
                        v-if="
                            selectedMessage?.source === 'work_order' &&
                            selectedMessage?.work_order_id
                        "
                        as-child
                    >
                        <Link
                            :href="
                                route(
                                    'work_orders.details',
                                    selectedMessage.work_order_id
                                )
                            "
                        >
                            <Wrench class="mr-1 h-4 w-4" />
                            Open Work Order
                        </Link>
                    </Button>
                    <Button
                        v-if="
                            selectedMessage?.source === 'job' &&
                            selectedMessage?.jobber_id
                        "
                        as-child
                    >
                        <Link
                            :href="
                                route(
                                    'jobber.jobDetails',
                                    selectedMessage.jobber_id
                                )
                            "
                        >
                            <Briefcase class="mr-1 h-4 w-4" />
                            Open Job
                        </Link>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
