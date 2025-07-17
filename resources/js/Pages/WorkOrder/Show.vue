<script setup>
import { computed, ref } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import { Head, Link, router } from "@inertiajs/vue3";
import {
    Wrench,
    Calendar,
    User,
    Phone,
    MapPin,
    Clock,
    CheckCircle,
    Circle,
    AlertCircle,
    Building,
    DollarSign,
    FileText,
    MessageSquare,
    Paperclip,
    Truck,
    Edit,
    ArrowLeft,
    UserCheck,
    AlertTriangle,
    CalendarDays,
    Settings,
    CreditCard,
    Phone as PhoneIcon,
    Mail,
    MapPinIcon,
    Activity,
    List,
    Package,
    Timer,
    Star,
    Eye,
    Download,
    Plus,
    X,
    Save,
    Trash2,
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
import { Textarea } from "@/Components/ui/textarea";
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/Components/ui/tabs";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from "@/Components/ui/dialog";
import { Alert, AlertDescription } from "@/Components/ui/alert";
import { Separator } from "@/Components/ui/separator";
import { useToast } from "@/Components/ui/toast/use-toast";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    workOrder: Object,
    conversations: Array,
    tasks: Array,
    invoices: Array,
    notes: Array,
    attachments: Array,
    vendors: Array,
    serviceStatuses: Array,
});

const { toast } = useToast();

const activeTab = ref("overview");

// Status variants for badges
const getStatusVariant = (status) => {
    switch (status?.toLowerCase()) {
        case "open":
            return "default";
        case "closed":
            return "secondary";
        case "pending":
            return "destructive";
        case "completed":
            return "success";
        case "in_progress":
            return "warning";
        default:
            return "outline";
    }
};

const getPriorityVariant = (priority) => {
    switch (priority?.toLowerCase()) {
        case "high":
        case "emergency":
            return "destructive";
        case "medium":
            return "default";
        case "low":
            return "secondary";
        default:
            return "outline";
    }
};

const formatDate = (dateString) => {
    if (!dateString) return "N/A";
    const date = new Date(dateString);
    return date.toLocaleDateString() + " " + date.toLocaleTimeString();
};

const formatCurrency = (amount) => {
    if (!amount) return "$0.00";
    return new Intl.NumberFormat("en-US", {
        style: "currency",
        currency: "USD",
    }).format(amount);
};

// Computed properties
const totalInvoiceAmount = computed(() => {
    return (
        props.invoices?.reduce(
            (sum, invoice) => sum + parseFloat(invoice.amount || 0),
            0
        ) || 0
    );
});

const unreadConversations = computed(() => {
    return props.conversations?.filter((conv) => !conv.is_read) || [];
});

const completedTasks = computed(() => {
    return props.tasks?.filter((task) => task.status === "completed") || [];
});

const pendingTasks = computed(() => {
    return props.tasks?.filter((task) => task.status === "pending") || [];
});

// Modal states
const showAddNoteModal = ref(false);
const showAddTaskModal = ref(false);
const newNote = ref("");
const newTask = ref({
    title: "",
    description: "",
    assigned_to: "",
    due_date: "",
});

// Functions
const addNote = () => {
    if (!newNote.value.trim()) return;

    router.post(
        route("work_order_notes.store"),
        {
            work_order_id: props.workOrder.id,
            note: newNote.value,
        },
        {
            onSuccess: () => {
                newNote.value = "";
                showAddNoteModal.value = false;
                toast({
                    title: "Note Added",
                    description: "The note has been successfully added.",
                });
            },
        }
    );
};

const addTask = () => {
    if (!newTask.value.title.trim()) return;

    router.post(
        route("work_order_tasks.store"),
        {
            work_order_id: props.workOrder.id,
            ...newTask.value,
        },
        {
            onSuccess: () => {
                newTask.value = {
                    title: "",
                    description: "",
                    assigned_to: "",
                    due_date: "",
                };
                showAddTaskModal.value = false;
                toast({
                    title: "Task Added",
                    description: "The task has been successfully added.",
                });
            },
        }
    );
};

const updateWorkOrderStatus = (status) => {
    router.put(
        route("work_orders.update", props.workOrder.id),
        {
            status: status,
        },
        {
            onSuccess: () => {
                toast({
                    title: "Status Updated",
                    description: `Work order status updated to ${status}.`,
                });
            },
        }
    );
};
</script>

<template>
    <Head :title="title" />

    <div class="space-y-6">
        <!-- Header -->
        <div class="flex justify-between items-start">
            <div class="flex items-center gap-4">
                <div>
                    <h1 class="text-3xl font-bold flex items-center gap-2">
                        <Wrench class="w-8 h-8" />
                        {{ workOrder.work_order_no }}
                    </h1>
                    <p class="text-gray-600 mt-1">
                        Created {{ formatDate(workOrder.created_date) }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Status Cards -->
        <div class="grid gap-4 md:grid-cols-4">
            <Card>
                <CardHeader
                    class="flex flex-row items-center justify-between space-y-0 pb-2"
                >
                    <CardTitle class="text-sm font-medium">Status</CardTitle>
                    <Activity class="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                    <Badge
                        :variant="getStatusVariant(workOrder.status)"
                        class="text-lg"
                    >
                        {{ workOrder.status || "Unknown" }}
                    </Badge>
                    <p class="text-xs text-muted-foreground mt-2">
                        Service: {{ workOrder.service_status?.name || "N/A" }}
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader
                    class="flex flex-row items-center justify-between space-y-0 pb-2"
                >
                    <CardTitle class="text-sm font-medium">Priority</CardTitle>
                    <AlertTriangle class="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                    <Badge
                        :variant="getPriorityVariant(workOrder.priority)"
                        class="text-lg"
                    >
                        {{ workOrder.priority || "Normal" }}
                    </Badge>
                    <p class="text-xs text-muted-foreground mt-2">
                        {{ workOrder.emergency ? "Emergency" : "Standard" }}
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader
                    class="flex flex-row items-center justify-between space-y-0 pb-2"
                >
                    <CardTitle class="text-sm font-medium"
                        >Total Cost</CardTitle
                    >
                    <DollarSign class="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                    <div class="text-2xl font-bold">
                        {{ formatCurrency(totalInvoiceAmount) }}
                    </div>
                    <p class="text-xs text-muted-foreground mt-2">
                        {{ invoices?.length || 0 }} invoice(s)
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader
                    class="flex flex-row items-center justify-between space-y-0 pb-2"
                >
                    <CardTitle class="text-sm font-medium">Tasks</CardTitle>
                    <List class="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                    <div class="text-2xl font-bold">
                        {{ completedTasks.length }}/{{ tasks?.length || 0 }}
                    </div>
                    <p class="text-xs text-muted-foreground mt-2">
                        {{ pendingTasks.length }} pending
                    </p>
                </CardContent>
            </Card>
        </div>

        <!-- Main Content Tabs -->
        <Tabs v-model="activeTab" class="w-full">
            <TabsList class="grid w-full grid-cols-6">
                <TabsTrigger value="overview">Overview</TabsTrigger>
                <TabsTrigger value="conversations">Messages</TabsTrigger>
                <TabsTrigger value="tasks">Tasks</TabsTrigger>
                <TabsTrigger value="invoices">Invoices</TabsTrigger>
                <TabsTrigger value="attachments">Files</TabsTrigger>
                <TabsTrigger value="notes">Notes</TabsTrigger>
            </TabsList>

            <!-- Overview Tab -->
            <TabsContent value="overview" class="space-y-6">
                <div class="grid gap-6 md:grid-cols-2">
                    <!-- Work Order Details -->
                    <Card>
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2">
                                <FileText class="w-5 h-5" />
                                Work Order Details
                            </CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-4">
                            <div class="grid gap-3">
                                <div>
                                    <label
                                        class="text-sm font-medium text-gray-600"
                                        >Work Order Number</label
                                    >
                                    <p class="font-mono">
                                        {{ workOrder.work_order_no }}
                                    </p>
                                </div>
                                <div>
                                    <label
                                        class="text-sm font-medium text-gray-600"
                                        >Description</label
                                    >
                                    <p class="text-sm">
                                        {{
                                            workOrder.description ||
                                            "No description provided"
                                        }}
                                    </p>
                                </div>
                                <div>
                                    <label
                                        class="text-sm font-medium text-gray-600"
                                        >Location</label
                                    >
                                    <p class="text-sm flex items-center gap-2">
                                        <MapPin class="w-4 h-4 text-gray-500" />
                                        {{
                                            workOrder.location ||
                                            "No location specified"
                                        }}
                                    </p>
                                </div>
                                <div>
                                    <label
                                        class="text-sm font-medium text-gray-600"
                                        >Created Date</label
                                    >
                                    <p class="text-sm">
                                        {{ formatDate(workOrder.created_date) }}
                                    </p>
                                </div>
                                <div v-if="workOrder.due_date">
                                    <label
                                        class="text-sm font-medium text-gray-600"
                                        >Due Date</label
                                    >
                                    <p class="text-sm">
                                        {{ formatDate(workOrder.due_date) }}
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- People Involved -->
                    <Card>
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2">
                                <User class="w-5 h-5" />
                                People Involved
                            </CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-4">
                            <div v-if="workOrder.requested_by">
                                <label class="text-sm font-medium text-gray-600"
                                    >Requested By</label
                                >
                                <div class="flex items-center gap-2 mt-1">
                                    <User class="w-4 h-4 text-gray-500" />
                                    <span>{{
                                        workOrder.requested_by.name
                                    }}</span>
                                </div>
                                <div
                                    class="flex items-center gap-2 mt-1 text-sm text-gray-600"
                                >
                                    <Phone class="w-4 h-4" />
                                    <span>{{
                                        workOrder.requested_by.phone || "N/A"
                                    }}</span>
                                </div>
                            </div>

                            <div v-if="workOrder.managed_by">
                                <label class="text-sm font-medium text-gray-600"
                                    >Managed By</label
                                >
                                <div class="flex items-center gap-2 mt-1">
                                    <UserCheck class="w-4 h-4 text-gray-500" />
                                    <span>{{ workOrder.managed_by.name }}</span>
                                </div>
                                <div
                                    class="flex items-center gap-2 mt-1 text-sm text-gray-600"
                                >
                                    <Phone class="w-4 h-4" />
                                    <span>{{
                                        workOrder.managed_by.phone || "N/A"
                                    }}</span>
                                </div>
                            </div>

                            <div v-if="workOrder.woc">
                                <label class="text-sm font-medium text-gray-600"
                                    >Work Order Coordinator</label
                                >
                                <div class="flex items-center gap-2 mt-1">
                                    <Settings class="w-4 h-4 text-gray-500" />
                                    <span>{{ workOrder.woc.name }}</span>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <!-- Vendors -->
                <Card v-if="workOrder.vendors && workOrder.vendors.length > 0">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <Truck class="w-5 h-5" />
                            Assigned Vendors
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div
                                v-for="vendor in workOrder.vendors"
                                :key="vendor.id"
                                class="border rounded-lg p-4"
                            >
                                <div class="flex items-center gap-2 mb-2">
                                    <Truck class="w-4 h-4 text-gray-500" />
                                    <span class="font-medium">{{
                                        vendor.name
                                    }}</span>
                                </div>
                                <div class="space-y-1 text-sm text-gray-600">
                                    <div v-if="vendor.pivot?.cost_estimate">
                                        <span class="font-medium"
                                            >Cost Estimate:</span
                                        >
                                        {{
                                            formatCurrency(
                                                vendor.pivot.cost_estimate
                                            )
                                        }}
                                    </div>
                                    <div v-if="vendor.pivot?.time_estimate">
                                        <span class="font-medium"
                                            >Time Estimate:</span
                                        >
                                        {{ vendor.pivot.time_estimate }}
                                    </div>
                                    <div
                                        v-if="vendor.pivot?.scheduled_end_date"
                                    >
                                        <span class="font-medium"
                                            >Scheduled End:</span
                                        >
                                        {{
                                            formatDate(
                                                vendor.pivot.scheduled_end_date
                                            )
                                        }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </TabsContent>

            <!-- Conversations Tab -->
            <TabsContent value="conversations" class="space-y-4">
                <div class="flex justify-between items-center">
                    <div>
                        <h3 class="text-lg font-semibold">
                            Messages & Conversations
                        </h3>
                        <p class="text-sm text-gray-600">
                            {{ unreadConversations.length }} unread of
                            {{ conversations?.length || 0 }} total
                        </p>
                    </div>
                </div>

                <div
                    v-if="conversations && conversations.length > 0"
                    class="space-y-4"
                >
                    <Card
                        v-for="conversation in conversations"
                        :key="conversation.id"
                    >
                        <CardContent class="pt-6">
                            <div class="flex items-start gap-4">
                                <div
                                    class="flex-shrink-0 w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center"
                                >
                                    <MessageSquare
                                        class="w-4 h-4 text-blue-600"
                                    />
                                </div>
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="font-medium">{{
                                            conversation.sender_name ||
                                            "Unknown"
                                        }}</span>
                                        <Badge
                                            :variant="
                                                conversation.is_read
                                                    ? 'secondary'
                                                    : 'default'
                                            "
                                            class="text-xs"
                                        >
                                            {{
                                                conversation.is_read
                                                    ? "Read"
                                                    : "Unread"
                                            }}
                                        </Badge>
                                        <span class="text-xs text-gray-500">{{
                                            formatDate(conversation.created_at)
                                        }}</span>
                                    </div>
                                    <p class="text-sm">
                                        {{ conversation.message }}
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
                <div v-else class="text-center py-8 text-gray-500">
                    <MessageSquare
                        class="w-12 h-12 mx-auto mb-4 text-gray-400"
                    />
                    <p>No conversations found for this work order.</p>
                </div>
            </TabsContent>

            <!-- Tasks Tab -->
            <TabsContent value="tasks" class="space-y-4">
                <div class="flex justify-between items-center">
                    <div>
                        <h3 class="text-lg font-semibold">Tasks</h3>
                        <p class="text-sm text-gray-600">
                            {{ completedTasks.length }} completed of
                            {{ tasks?.length || 0 }} total
                        </p>
                    </div>
                </div>

                <div v-if="tasks && tasks.length > 0" class="space-y-4">
                    <Card v-for="task in tasks" :key="task.id">
                        <CardContent class="pt-6">
                            <div class="flex items-start gap-4">
                                <div class="flex-shrink-0 mt-1">
                                    <CheckCircle
                                        v-if="task.status === 'completed'"
                                        class="w-5 h-5 text-green-600"
                                    />
                                    <Circle
                                        v-else
                                        class="w-5 h-5 text-gray-400"
                                    />
                                </div>
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="font-medium">{{
                                            task.title
                                        }}</span>
                                        <Badge
                                            :variant="
                                                getStatusVariant(task.status)
                                            "
                                            class="text-xs"
                                        >
                                            {{ task.status }}
                                        </Badge>
                                    </div>
                                    <p class="text-sm text-gray-600 mb-2">
                                        {{ task.description }}
                                    </p>
                                    <div
                                        class="flex items-center gap-4 text-xs text-gray-500"
                                    >
                                        <span
                                            >Created:
                                            {{
                                                formatDate(task.created_at)
                                            }}</span
                                        >
                                        <span v-if="task.due_date"
                                            >Due:
                                            {{
                                                formatDate(task.due_date)
                                            }}</span
                                        >
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
                <div v-else class="text-center py-8 text-gray-500">
                    <List class="w-12 h-12 mx-auto mb-4 text-gray-400" />
                    <p>No tasks found for this work order.</p>
                </div>
            </TabsContent>

            <!-- Invoices Tab -->
            <TabsContent value="invoices" class="space-y-4">
                <div class="flex justify-between items-center">
                    <div>
                        <h3 class="text-lg font-semibold">Invoices</h3>
                        <p class="text-sm text-gray-600">
                            Total: {{ formatCurrency(totalInvoiceAmount) }}
                        </p>
                    </div>
                </div>

                <div v-if="invoices && invoices.length > 0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Invoice #</TableHead>
                                <TableHead>Amount</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Date</TableHead>
                                <TableHead>Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="invoice in invoices"
                                :key="invoice.id"
                            >
                                <TableCell class="font-mono">{{
                                    invoice.invoice_number
                                }}</TableCell>
                                <TableCell>{{
                                    formatCurrency(invoice.amount)
                                }}</TableCell>
                                <TableCell>
                                    <Badge
                                        :variant="
                                            getStatusVariant(invoice.status)
                                        "
                                    >
                                        {{ invoice.status }}
                                    </Badge>
                                </TableCell>
                                <TableCell>{{
                                    formatDate(invoice.created_at)
                                }}</TableCell>
                                <TableCell>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="gap-1"
                                    >
                                        <Eye class="w-4 h-4" />
                                        View
                                    </Button>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
                <div v-else class="text-center py-8 text-gray-500">
                    <CreditCard class="w-12 h-12 mx-auto mb-4 text-gray-400" />
                    <p>No invoices found for this work order.</p>
                </div>
            </TabsContent>

            <!-- Attachments Tab -->
            <TabsContent value="attachments" class="space-y-4">
                <div class="flex justify-between items-center">
                    <div>
                        <h3 class="text-lg font-semibold">
                            Files & Attachments
                        </h3>
                        <p class="text-sm text-gray-600">
                            {{ attachments?.length || 0 }} file(s)
                        </p>
                    </div>
                </div>

                <div
                    v-if="attachments && attachments.length > 0"
                    class="grid gap-4 md:grid-cols-2 lg:grid-cols-3"
                >
                    <Card
                        v-for="attachment in attachments"
                        :key="attachment.id"
                    >
                        <CardContent class="pt-6">
                            <div class="flex items-start gap-3">
                                <Paperclip class="w-5 h-5 text-gray-500 mt-1" />
                                <div class="flex-1">
                                    <p class="font-medium truncate">
                                        {{ attachment.filename }}
                                    </p>
                                    <p class="text-sm text-gray-600">
                                        {{
                                            attachment.file_size ||
                                            "Unknown size"
                                        }}
                                    </p>
                                    <p class="text-xs text-gray-500 mt-1">
                                        {{ formatDate(attachment.created_at) }}
                                    </p>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="mt-2 gap-1"
                                    >
                                        <Download class="w-4 h-4" />
                                        Download
                                    </Button>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
                <div v-else class="text-center py-8 text-gray-500">
                    <Paperclip class="w-12 h-12 mx-auto mb-4 text-gray-400" />
                    <p>No attachments found for this work order.</p>
                </div>
            </TabsContent>

            <!-- Notes Tab -->
            <TabsContent value="notes" class="space-y-4">
                <div class="flex justify-between items-center">
                    <div>
                        <h3 class="text-lg font-semibold">Notes</h3>
                        <p class="text-sm text-gray-600">
                            {{ notes?.length || 0 }} note(s)
                        </p>
                    </div>
                </div>

                <div v-if="notes && notes.length > 0" class="space-y-4">
                    <Card v-for="note in notes" :key="note.id">
                        <CardContent class="pt-6">
                            <div class="flex items-start gap-4">
                                <div
                                    class="flex-shrink-0 w-8 h-8 rounded-full bg-gray-100 flex items-center justify-center"
                                >
                                    <FileText class="w-4 h-4 text-gray-600" />
                                </div>
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="font-medium">{{
                                            note.user?.name || "Unknown"
                                        }}</span>
                                        <span class="text-xs text-gray-500">{{
                                            formatDate(note.created_at)
                                        }}</span>
                                    </div>
                                    <p class="text-sm whitespace-pre-wrap">
                                        {{ note.note }}
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
                <div v-else class="text-center py-8 text-gray-500">
                    <FileText class="w-12 h-12 mx-auto mb-4 text-gray-400" />
                    <p>No notes found for this work order.</p>
                </div>
            </TabsContent>
        </Tabs>
    </div>
</template>
