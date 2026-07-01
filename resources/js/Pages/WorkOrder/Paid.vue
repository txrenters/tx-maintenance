<script setup>
import { ref, watch, computed } from "vue";
import { router, useForm, usePage, Deferred } from "@inertiajs/vue3";
import axios from "axios";
import AppLayout from "@/Layouts/AppLayout.vue";
import { useToast } from "@/Components/ui/toast/use-toast";
import WorkOrderDetails from "./Partials/WorkOrderDetails.vue";
import WorkOrderTask from "./Partials/WorkOrderTask.vue";
import VendorWocConversation from "./Partials/VendorWocConversation.vue";
import VendorOwnerConversation from "./Partials/VendorOwnerConversation.vue";
import VendorConversation from "./Partials/VendorConversation.vue";
import TenantConversation from "./Partials/TenantConversation.vue";
import OwnerConversation from "./Partials/OwnerConversation.vue";
import OwnerWocConversation from "./Partials/OwnerWocConversation.vue";
import Conversation from "./Partials/Conversation.vue";
import ServiceSchedule from "./Partials/ServiceSchedule.vue";
import Attachments from "./Partials/Attachments.vue";
import Invoices from "./Partials/Invoices.vue";
import Notes from "./Partials/Notes.vue";
import VendorEdit from "./Partials/VendorEdit.vue";
import TabSwitcher from "./Partials/TabSwitcher.vue";
import VendorTenantConversation from "./Partials/VendorTenantConversation.vue";
import OwnerVendorConversation from "./Partials/OwnerVendorConversation.vue";
import debounce from "lodash/debounce";
import { DateTime } from "luxon";

import {
    ClipboardList,
    ListChecks,
    Calendar,
    Paperclip,
    FileText,
    NotebookPen,
    Notebook,
    MessagesSquare,
    CalendarIcon,
    RefreshCw,
    Truck,
    Tag,
    UserRoundPen,
    CircleCheckBig,
} from "lucide-vue-next";

const { toast } = useToast();
defineOptions({ layout: AppLayout });

import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from "@/Components/ui/popover";
import { RangeCalendar } from "@/Components/ui/range-calendar";
import { DateFormatter, getLocalTimeZone } from "@internationalized/date";
import SearchBar from "@/Components/SearchBar.vue";
import { Skeleton } from "@/Components/ui/skeleton";
import { Head, Link } from "@inertiajs/vue3";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogScrollContent,
} from "@/Components/ui/dialog";
import { Button } from "@/Components/ui/button";
import { Badge } from "@/Components/ui/badge";
import { Separator } from "@/Components/ui/separator";
import { ScrollArea, ScrollBar } from "@/Components/ui/scroll-area";
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/Components/ui/select";
import { Input } from "@/Components/ui/input";

const props = defineProps({
    title: String,
    service_status: Object,
    vendors: Object,
    categories: Object,
    users: Object,
    filter: Object,
});

const url = ref(route("work_orders.paid"));
const search = ref(props.filter.search ?? "");
const filter_vendor = ref(props.filter.vendor ?? "");
const filter_category = ref(props.filter.category ?? "");

const openWorkOrder = ref(false);

const workOrderForm = useForm({
    id: "",
    work_order_no: "",
    category: "",
    type: "",
    priority: "",
    location: "",
    status: "",
    total_cost: "",
    total_hour_work: "",
    authorized_to_enter: "",
    source: "",
    cost_estimate: "",
    hour_estimate: "",
    additional_work_needed_reschedule: "",
    last_modified: "",
    closing_comments: "",
    latest_update_comments: "",
    service_status: "",
    service_status_id: "",
    zone: "",
    created_date: "",
    scheduled_end_date: "",
    end_date: "",
    management_plan: "",
    description: "",
    vendor_notes: "",
    is_emergency: "",
    is_approved: "",
    vendor_id: "",
    vendors: [],
    managed_by: "",
    requested: "",
    owners: [],
    local_status: "",
    woc: "",
});

const closeWorkOrderForm = useForm({
    id: "",
});

const activeTab = ref("details");
const tabButtons = [
    {
        name: "details",
        tooltip: "Details",
        icon: ClipboardList,
        requires: ["admin", "woc", "vendor", "owner", "tenant", "accounting"],
    },
    {
        name: "tasks",
        tooltip: "Tasks",
        icon: ListChecks,
        requires: ["admin", "woc", "vendor", "accounting"],
    },
    {
        name: "notes",
        tooltip: "Notes",
        icon: Notebook,
        requires: ["admin", "woc", "vendor", "owner", "tenant", "accounting"],
    },
    {
        name: "vendor_edit",
        tooltip: "Vendor Edit",
        icon: NotebookPen,
        requires: ["admin", "woc", "vendor"],
    },
    {
        name: "vendor_woc_conversation",
        tooltip: "WOC Conversation",
        icon: "W",
        requires: ["vendor"],
    },
    {
        name: "vendor_owner_conversation",
        tooltip: "Owner Conversation",
        icon: "O",
        requires: ["vendor"],
    },
    {
        name: "vendor_tenant_conversation",
        tooltip: "Tenant Conversation",
        icon: "T",
        requires: ["vendor"],
    },
    {
        name: "vendor_conversation",
        tooltip: "Vendor Conversation",
        icon: "V",
        requires: ["admin", "woc"],
    },
    {
        name: "owner_conversation",
        tooltip: "Owner Conversation",
        icon: "O",
        requires: ["admin", "woc"],
    },
    {
        name: "tenant_conversation",
        tooltip: "Tenant Conversation",
        icon: "T",
        requires: ["admin", "woc"],
    },
    {
        name: "owner_woc_conversation",
        tooltip: "Work Order Coordinator Conversation",
        icon: "W",
        requires: ["owner"],
    },
    {
        name: "owner_vendor_conversation",
        tooltip: "Vendor Conversation",
        icon: "V",
        requires: ["owner"],
    },
    {
        name: "tenant_woc_conversation",
        tooltip: "Work Order Coordinator Conversation",
        icon: "W",
        requires: ["tenant"],
    },
    {
        name: "tenant_vendor_conversation",
        tooltip: "Vendor Conversation",
        icon: "V",
        requires: ["tenant"],
    },
    {
        name: "service_schedule",
        tooltip: "Service Schedule",
        icon: Calendar,
        requires: ["admin", "woc", "vendor", "accounting"],
    },
    {
        name: "attachments",
        tooltip: "Attachments",
        icon: Paperclip,
        requires: ["admin", "woc", "vendor", "owner", "tenant", "accounting"],
    },
    {
        name: "invoices",
        tooltip: "Invoice",
        icon: FileText,
        requires: ["admin", "woc", "vendor", "accounting"],
    },
    {
        name: "conversation",
        tooltip: "Conversation",
        icon: MessagesSquare,
        requires: ["admin", "woc"],
    },
];

const switchTab = (tabName) => {
    activeTab.value = tabName;
    workOrderTasks.value = [];

    if (activeTab.value === "tasks" && workOrderForm.id) {
        fetchWorkOrderTask(workOrderForm.id);
    }

    if (activeTab.value === "details" && workOrderForm.id) {
        handleWorkOrder(workOrderForm.id);
    }

    if (activeTab.value === "vendor_tenant_conversation" && workOrderForm.id) {
        fetchVendorTenantConversation(workOrderForm.id);
    }

    if (
        (activeTab.value === "vendor_owner_conversation" && workOrderForm.id) ||
        (activeTab.value === "owner_vendor_conversation" && workOrderForm.id)
    ) {
        fetchVendorOwnerConversation(workOrderForm.id);
    }

    if (
        (activeTab.value === "vendor_conversation" && workOrderForm.id) ||
        (activeTab.value === "vendor_woc_conversation" && workOrderForm.id)
    ) {
        fetchVendorConversation(workOrderForm.id);
    }

    if (activeTab.value === "tenant_conversation" && workOrderForm.id) {
        fetchTenantConversation(workOrderForm.id);
    }

    if (activeTab.value === "owner_conversation" && workOrderForm.id) {
        fetchOwnerConversation(workOrderForm.id);
    }

    if (activeTab.value === "owner_woc_conversation" && workOrderForm.id) {
        fetchOwnerConversation(workOrderForm.id);
    }

    if (activeTab.value === "conversation" && workOrderForm.id) {
        fetchVendorTenantConversation(workOrderForm.id);
        fetchVendorConversation(workOrderForm.id);
        fetchTenantConversation(workOrderForm.id);
        fetchOwnerConversation(workOrderForm.id);
    }

    if (activeTab.value === "service_schedule" && workOrderForm.id) {
        fetchVendorServiceSchedules(workOrderForm.id);
    }

    if (activeTab.value === "attachments" && workOrderForm.id) {
        fetchAttachments(workOrderForm.id);
    }

    if (activeTab.value === "invoices" && workOrderForm.id) {
        fetchInvoices(workOrderForm.id);
    }

    if (activeTab.value === "notes" && workOrderForm.id) {
        fetchNotes(workOrderForm.id);
    }

    if (activeTab.value === "vendor_edit" && workOrderForm.id) {
        fetchVendors(workOrderForm.id);
    }
};

const isLoading = ref(false);

const ownerConversation = ref([]);
const workOrderOwners = ref([]);
const workOrderTenants = ref([]);
const workOrderVendors = ref([]);

const fetchOwnerConversation = async (workOrderId) => {
    try {
        isLoading.value = true;
        const response = await axios.get(
            route("work_order.owner_conversation", workOrderId)
        );

        ownerConversation.value = response.data.owner_conversation;
        workOrderOwners.value = response.data.owners;
    } catch (error) {
        console.error("Error fetching tasks:", error);
    } finally {
        isLoading.value = false;
    }
};

const tenantConversation = ref([]);

const fetchTenantConversation = async (workOrderId) => {
    try {
        isLoading.value = true;
        const response = await axios.get(
            route("work_order.tenant_conversation", workOrderId)
        );

        tenantConversation.value = response.data.tenant_conversation;
        workOrderTenants.value = response.data.tenants;
    } catch (error) {
        console.error("Error fetching tasks:", error);
    } finally {
        isLoading.value = false;
    }
};

const vendorTenantConversation = ref([]);

const fetchVendorTenantConversation = async (workOrderId) => {
    try {
        isLoading.value = true;
        const response = await axios.get(
            route("work_order.vendor_tenant_conversation", workOrderId)
        );

        vendorTenantConversation.value =
            response.data.vendor_tenant_conversation;
        workOrderTenants.value = response.data.tenants;
    } catch (error) {
        console.error("Error fetching tasks:", error);
    } finally {
        isLoading.value = false;
    }
};

const vendorConversation = ref([]);

const fetchVendorConversation = async (workOrderId) => {
    try {
        isLoading.value = true;
        const response = await axios.get(
            route("work_order.vendor_conversation", workOrderId)
        );

        vendorConversation.value = response.data.vendor_conversation;
        workOrderVendors.value = response.data.vendors;
    } catch (error) {
        console.error("Error fetching tasks:", error);
    } finally {
        isLoading.value = false;
    }
};

const vendorOwnerConversation = ref([]);

const fetchVendorOwnerConversation = async (workOrderId) => {
    try {
        isLoading.value = true;
        const response = await axios.get(
            route("work_order.vendor_owner_conversation", workOrderId)
        );

        vendorOwnerConversation.value = response.data.vendor_owner_conversation;
        workOrderOwners.value = response.data.owners;
        workOrderVendors.value = response.data.vendors;
    } catch (error) {
        console.error("Error fetching tasks:", error);
    } finally {
        isLoading.value = false;
    }
};

const workOrderTasks = ref([]);
const fetchWorkOrderTask = async (workOrderId) => {
    try {
        isLoading.value = true;
        const response = await axios.get(
            route("api.work_order.tasks", workOrderId)
        );
        workOrderTasks.value = response.data.tasks;
    } catch (error) {
        console.error("Error fetching tasks:", error);
    } finally {
        isLoading.value = false;
    }
};

const vendorServiceSchedules = ref([]);
const fetchVendorServiceSchedules = async (workOrderId) => {
    try {
        isLoading.value = true;
        const response = await axios.get(
            route("work_order.service_schedules", workOrderId)
        );
        vendorServiceSchedules.value = response.data.service_schedules;
        workOrderVendors.value = response.data.vendors;
        workOrderTenants.value = response.data.tenants;
    } catch (error) {
        console.error("Error fetching tasks:", error);
    } finally {
        isLoading.value = false;
    }
};

const workOrderAttachments = ref([]);
const fetchAttachments = async (workOrderId) => {
    try {
        isLoading.value = true;
        const response = await axios.get(
            route("api.attachments.show", workOrderId)
        );

        workOrderAttachments.value = response.data.attachments;
    } catch (error) {
        console.error("Error fetching tasks:", error);
    } finally {
        isLoading.value = false;
    }
};

const workOrderInvoices = ref([]);
const fetchInvoices = async (workOrderId) => {
    try {
        isLoading.value = true;
        const response = await axios.get(
            route("api.invoices.index", workOrderId)
        );
        workOrderInvoices.value = response.data.invoices;
    } catch (error) {
        console.error("Error fetching tasks:", error);
    } finally {
        isLoading.value = false;
    }
};

const workOrderNotes = ref([]);
const fetchNotes = async (workOrderId) => {
    try {
        isLoading.value = true;
        const response = await axios.get(
            route("api.work_order_notes.show", workOrderId)
        );

        workOrderNotes.value = response.data.notes;
    } catch (error) {
        console.error("Error fetching tasks:", error);
    } finally {
        isLoading.value = false;
    }
};

const workOrderVendorData = ref([]);
const fetchVendors = async (workOrderId) => {
    try {
        isLoading.value = true;
        const response = await axios.get(
            route("api.work_order_notes.show", workOrderId)
        );
        workOrderVendorData.value = response.data.vendors;
    } catch (error) {
        console.error("Error fetching tasks:", error);
    } finally {
        isLoading.value = false;
    }
};

const handleUpdateSubmit = () => {
    workOrderForm.put(route("work_orders.update", workOrderForm.id), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            toast({
                title: "Success",
                description: "Work order has been updated successfully!",
            });
        },
        onError: () => {
            toast({
                variant: "destructive",
                title: "Uh oh! Something went wrong.",
                description:
                    "There was a problem with your request. Please try again!",
            });
        },
        only: ["service_status"],
    });
};

const handleCloseOrderSubmit = () => {
    closeWorkOrderForm.put(route("work_orders.close", workOrderForm.id), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            toast({
                title: "Success",
                description: "Work order has been closed successfully!",
            });
            openWorkOrder.value = false;
        },
        onError: () => {
            toast({
                variant: "destructive",
                title: "Uh oh! Something went wrong.",
                description:
                    "There was a problem with your request. Please try again!",
            });
        },
        only: ["service_status"],
    });
};

// Flatten all work orders from service status array
const allWorkOrders = computed(() => {
    if (!props.service_status) return [];
    return props.service_status.flatMap((status) => status.work_orders || []);
});

const formatDate = (date) => {
    if (!date) return "------";

    let parsedDate;

    if (typeof date === "string") {
        if (date.includes("T")) {
            parsedDate = DateTime.fromISO(date, { zone: "utc" });
        } else {
            parsedDate = DateTime.fromFormat(date, "yyyy-MM-dd HH:mm:ss", {
                zone: "utc",
            });
        }
    } else if (date instanceof Date) {
        parsedDate = DateTime.fromJSDate(date);
    } else {
        return "Invalid Date";
    }

    return parsedDate.isValid
        ? parsedDate.toFormat("MM/dd/yyyy")
        : "Invalid Date";
};

const countCompletedTask = (tasks) => {
    const completedTasks = tasks.filter((task) => task.status === "completed");
    return completedTasks.length;
};

const checkDueTask = (tasks, scheduled_end_date) => {
    const today = new Date().toISOString().split("T")[0];

    let bgColor = "green";

    if (scheduled_end_date) {
        if (scheduled_end_date === today) return "blue";
        if (scheduled_end_date < today) return "red";
        if (scheduled_end_date > today) return "green";
    }

    const pendingTasks = tasks.filter((task) => task.status === "pending");

    if (pendingTasks.some((task) => task.due_date < today)) {
        bgColor = "red";
    } else if (pendingTasks.some((task) => task.due_date === today)) {
        bgColor = "blue";
    }

    return bgColor;
};

const handleWorkOrder = async (orderId) => {
    workOrderForm.reset();
    activeTab.value = "details";
    workOrderTasks.value = [];
    openWorkOrder.value = true;
    isLoading.value = true;

    try {
        const response = await axios.get(
            route(
                "work_orders.show",
                typeof orderId === "object" ? orderId.id : orderId
            )
        );
        const order = response.data;

        workOrderForm.id = order.id;
        workOrderForm.work_order_no = order.work_order_no;
        workOrderForm.description = order.description;
        workOrderForm.location = order.location;
        workOrderForm.managed_by = order.managed_by;
        workOrderForm.requested = order.requested_by;
        workOrderVendors.value = order.vendors ?? [];
        workOrderForm.vendors =
            order.local_status === "Created"
                ? Object.values(order.vendors).map((vendor) => vendor.name)
                : order.vendors;
        workOrderForm.is_approved = order.is_approved;
        workOrderForm.approved_date = order.approved_date;
        workOrderForm.approval_comments = order.approval_comments;
        workOrderForm.owners = order.owners;
        workOrderForm.management_plan = order.management_plan;
        workOrderForm.priority = order.priority;
        workOrderForm.status = order.status;
        workOrderForm.is_emergency =
            order.is_emergency === null
                ? null
                : order.is_emergency
                ? "Emergency"
                : "Non-emergency";

        workOrderForm.local_status = order.local_status;

        workOrderForm.total_cost = order.total_cost ?? "0";
        workOrderForm.total_hour_work = order.total_hour_work ?? "0";
        workOrderForm.cost_estimate = order.cost_estimate ?? "0";
        workOrderForm.hour_estimate = order.hour_estimate ?? "0";
        workOrderForm.type = order.type;
        workOrderForm.closing_comments = order.closing_comments;
        workOrderForm.latest_update_comments = order.latest_update_comments;
        workOrderForm.source = order.source;
        workOrderForm.service_status = order.service_status.name;
        workOrderForm.service_status_id = order.service_status.id;
        workOrderForm.category = order.category;
        workOrderForm.created_date = order.created_date
            ? order.created_date
            : "";
        workOrderForm.scheduled_end_date = order.scheduled_end_date
            ? order.scheduled_end_date
            : "";
        workOrderForm.end_date = order.end_date ? new Date(order.end_date) : "";
        workOrderForm.authorized_to_enter = order.authorized_to_enter;
        workOrderForm.additional_work_needed_reschedule =
            order.additional_work_needed_reschedule;
        workOrderForm.zone = order.zone;
        workOrderForm.vendor_notes = order.vendor_notes;

        workOrderForm.woc = order.woc;

        // Reset close form
        closeWorkOrderForm.reset();
        closeWorkOrderForm.id = order.id;
    } catch (error) {
        console.error("Failed to fetch work order:", error);
    }
    isLoading.value = false;
};

const df = new DateFormatter("en-US", {
    dateStyle: "medium",
});

const date_range = ref({
    start: "",
    end: "",
});

const formatDateRange = (d) => (d ? d.toString() : null);

const filterVendor = debounce(() => {
    const newQuery = { vendor: filter_vendor.value || null };

    router.visit(url.value, {
        method: "get",
        data: newQuery,
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ["service_status"],
    });
}, 2000);

const filterCategory = debounce(() => {
    const newQuery = { category: filter_category.value || null };

    router.visit(url.value, {
        method: "get",
        data: newQuery,
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ["service_status"],
    });
}, 2000);

const fetchFilteredData = debounce(() => {
    const startDate = formatDateRange(date_range.value?.start);
    const endDate = formatDateRange(date_range.value?.end);

    const newQuery = { start_date: startDate, end_date: endDate };

    router.visit(url.value, {
        method: "get",
        data: newQuery,
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ["service_status"],
    });
}, 2000);

watch(date_range, fetchFilteredData, { deep: true });
watch(filter_vendor, filterVendor);
watch(filter_category, filterCategory);

const page = usePage();
</script>

<template>
    <Head :title="title" />

    <div class="flex gap-3 flex-col sm:flex-row items-center mb-6">
        <SearchBar :url="url" v-model="search" />
        <div
            class="flex gap-2 items-center w-full"
            v-if="
                $page.props.auth.user.roles.includes('admin') ||
                $page.props.auth.user.roles.includes('woc') ||
                $page.props.auth.user.roles.includes('accounting')
            "
        >
            <Select
                :modelValue="String(filter_vendor)"
                @update:modelValue="(value) => (filter_vendor = value)"
            >
                <SelectTrigger class="w-full sm:w-[250px]">
                    <SelectValue placeholder="Select a vendor" />
                </SelectTrigger>
                <SelectContent>
                    <SelectGroup>
                        <template v-for="vendor in vendors" :key="vendor.id">
                            <SelectItem :value="String(vendor.id)">
                                {{ vendor.name }}
                            </SelectItem>
                        </template>
                    </SelectGroup>
                </SelectContent>
            </Select>

            <Select
                :modelValue="String(filter_category)"
                @update:modelValue="(value) => (filter_category = value)"
            >
                <SelectTrigger class="w-full sm:w-[250px]">
                    <SelectValue placeholder="Select a category" />
                </SelectTrigger>
                <SelectContent>
                    <SelectGroup>
                        <template
                            v-for="category in categories"
                            :key="category.id"
                        >
                            <SelectItem :value="String(category.name)">
                                {{ category.name }}
                            </SelectItem>
                        </template>
                    </SelectGroup>
                </SelectContent>
            </Select>
        </div>

        <div class="flex gap-2 w-full justify-end">
            <Popover>
                <PopoverTrigger as-child>
                    <Button
                        variant="outline"
                        :class="[
                            'w-full justify-start text-left text-xs font-normal sm:w-[220px]',
                            !date_range.start ? 'text-muted-foreground' : '',
                        ]"
                    >
                        <CalendarIcon class="mr-2 h-4 w-4" />
                        <template v-if="date_range.start">
                            <template v-if="date_range.end">
                                {{
                                    df.format(
                                        date_range.start.toDate(
                                            getLocalTimeZone()
                                        )
                                    )
                                }}
                                -
                                {{
                                    df.format(
                                        date_range.end.toDate(
                                            getLocalTimeZone()
                                        )
                                    )
                                }}
                            </template>
                            <template v-else>
                                {{
                                    df.format(
                                        date_range.start.toDate(
                                            getLocalTimeZone()
                                        )
                                    )
                                }}
                            </template>
                        </template>
                        <template v-else> Pick a date </template>
                    </Button>
                </PopoverTrigger>
                <PopoverContent class="w-auto p-0">
                    <RangeCalendar
                        v-model="date_range"
                        initial-focus
                        :number-of-months="2"
                        @update:start-value="
                            (startDate) => (date_range.start = startDate)
                        "
                        @update:end-value="
                            (endDate) => (date_range.end = endDate)
                        "
                    />
                </PopoverContent>
            </Popover>
            <Link
                class="bg-primary px-3 py-3 rounded text-white hover:bg-primary/80"
                size="icon"
                title="Refresh"
                :href="url"
                preserve-scroll
                :only="['service_status']"
            >
                <RefreshCw class="w-4 h-4" />
            </Link>
        </div>
    </div>

    <Deferred data="service_status">
        <template #fallback>
            <div
                class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 gap-4"
            >
                <Skeleton
                    v-for="value in 12"
                    :key="value"
                    class="h-[200px] w-full rounded-lg"
                />
            </div>
        </template>

        <div
            v-if="allWorkOrders.length > 0"
            class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 gap-4"
        >
            <div
                v-for="work_order in allWorkOrders"
                :key="work_order.id"
                @click="handleWorkOrder(work_order)"
                class="rounded-lg p-4 text-white cursor-pointer hover:shadow-lg transition-all"
                :class="{
                    'bg-destructive':
                        checkDueTask(
                            work_order.tasks,
                            work_order.scheduled_end_date
                        ) == 'red',
                    'bg-primary':
                        checkDueTask(
                            work_order.tasks,
                            work_order.scheduled_end_date
                        ) == 'blue',
                    'bg-green-500':
                        checkDueTask(
                            work_order.tasks,
                            work_order.scheduled_end_date
                        ) == 'green',
                    'bg-gray-600': work_order.status === 'Closed',
                }"
            >
                <!-- Work Order Number & Date -->
                <div
                    class="flex justify-between items-center border-b pb-2 mb-2"
                >
                    <h1 class="text-lg font-semibold">
                        {{ work_order.work_order_no }}
                    </h1>
                    <p class="text-xs text-gray-200">
                        📅 {{ formatDate(work_order.created_date) }}
                    </p>
                </div>

                <!-- Location -->
                <p class="text-sm text-gray-100 font-semibold">
                    {{ work_order.location }}
                </p>
                <div class="flex gap-2 justify-center">
                    <p
                        class="text-xs text-gray-100 flex items-center gap-1 justify-center"
                    >
                        <Tag class="w-3 h-3" />{{ work_order.category }}
                    </p>
                    <p
                        v-if="work_order.is_approved"
                        class="text-xs text-gray-100 flex items-center gap-1 justify-center"
                    >
                        <CircleCheckBig class="w-3 h-3" />Approved
                    </p>
                </div>

                <div
                    v-if="work_order.requested_by"
                    class="flex text-left gap-1 mb-1 mt-2"
                >
                    <UserRoundPen class="w-4 h-4" />
                    <p class="text-xs text-gray-100 uppercase">
                        {{ work_order.requested_by?.first_name }}
                        {{ work_order.requested_by?.last_name }}
                    </p>
                </div>
                <div v-else class="flex text-left mb-1 mt-2">
                    <UserRoundPen class="w-4 h-4" />
                    <p class="text-xs text-gray-100 uppercase">
                        {{ work_order.owners[0]?.first_name }}
                        {{ work_order.owners[0]?.last_name }}
                    </p>
                </div>
                <p
                    class="text-xs text-gray-100"
                    v-for="vendor in work_order.vendors"
                    :key="vendor.id"
                >
                    <span class="flex gap-1 text-left uppercase">
                        <Truck class="w-4 h-4" />{{ vendor.name }}</span
                    >
                </p>

                <!-- Task Progress -->
                <div class="flex justify-between items-center mt-1">
                    <div class="flex gap-1 items-center">
                        <p class="text-xs" v-if="work_order.tasks.length > 0">
                            {{ countCompletedTask(work_order.tasks) }}/{{
                                work_order.tasks.length
                            }}
                            tasks
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div v-else class="text-center py-16">
            <p class="text-gray-500 dark:text-gray-400">No work orders found</p>
        </div>
    </Deferred>

    <Dialog v-model:open="openWorkOrder">
        <DialogScrollContent
            class="flex w-full !max-w-4xl grid-rows-[auto_minmax(0,1fr)_auto] flex-col p-0 md:max-w-2xl"
        >
            <DialogHeader class="p-6 pb-0 text-left">
                <DialogTitle class="text-2xl text-primary">
                    <p v-if="!isLoading">#{{ workOrderForm.work_order_no }}</p>
                </DialogTitle>
                <DialogDescription>
                    <div class="flex gap-2 mb-2 flex-wrap" v-if="!isLoading">
                        <Badge
                            :variant="
                                workOrderForm.priority === 'High'
                                    ? 'destructive'
                                    : 'outline'
                            "
                            >Priority: {{ workOrderForm.priority }}</Badge
                        >
                        <Badge variant="outline"
                            >Status: {{ workOrderForm.status }}</Badge
                        >
                        <Badge
                            v-if="workOrderForm.is_emergency !== null"
                            :variant="
                                workOrderForm.is_emergency === 'Non-emergency'
                                    ? 'outline'
                                    : 'destructive'
                            "
                            >{{ workOrderForm.is_emergency }}</Badge
                        >
                        <Badge
                            v-if="workOrderForm.is_approved"
                            variant="outline"
                            >Approved</Badge
                        >
                    </div>
                </DialogDescription>
                <div class="flex justify-center gap-2 flex-wrap">
                    <TabSwitcher
                        :buttons="tabButtons"
                        :activeTab="activeTab"
                        @switchTab="switchTab"
                    />
                </div>
            </DialogHeader>
            <Separator />

            <WorkOrderDetails
                :workOrder="workOrderForm"
                :categories="categories"
                :vendors="vendors"
                :closeWorkOrderForm="closeWorkOrderForm"
                :isLoading="isLoading"
                @save="handleUpdateSubmit"
                @close="handleCloseOrderSubmit"
                @delete="openWorkOrder = false"
                @update-workOrder="handleWorkOrder(workOrderForm.id)"
                v-if="activeTab === 'details'"
            />

            <WorkOrderTask
                :workOrderTasks="workOrderTasks"
                :service_status="service_status"
                :isEmergency="workOrderForm.is_emergency"
                :workOrder="workOrderForm"
                :users="users"
                :isLoading="isLoading"
                @update-task-status="fetchWorkOrderTask(workOrderForm.id)"
                v-if="activeTab === 'tasks'"
            />

            <Conversation
                :workOrder="workOrderForm"
                :vendorConversation="vendorConversation"
                :ownerConversation="ownerConversation"
                :tenantConversation="tenantConversation"
                :vendorTenantConversation="vendorTenantConversation"
                :vendorWocConversation="vendorConversation"
                :isLoading="isLoading"
                v-if="activeTab === 'conversation'"
            />

            <VendorWocConversation
                :vendorWocConversation="vendorConversation"
                :workOrderVendors="workOrderVendors"
                @update-vendor-woc-convo="
                    fetchVendorConversation(workOrderForm.id)
                "
                :workOrder="workOrderForm"
                :isLoading="isLoading"
                v-if="activeTab === 'vendor_woc_conversation'"
            />

            <VendorOwnerConversation
                :vendorOwnerConversations="vendorOwnerConversation"
                :workOrderOwners="workOrderOwners"
                @update-vendor-owner-convo="
                    fetchVendorOwnerConversation(workOrderForm.id)
                "
                :workOrder="workOrderForm"
                :isLoading="isLoading"
                v-if="activeTab === 'vendor_owner_conversation'"
            />

            <VendorTenantConversation
                :vendorTenantConversations="vendorTenantConversation"
                :workOrderTenants="workOrderTenants"
                @update-vendor-tenant-convo="
                    fetchVendorTenantConversation(workOrderForm.id)
                "
                :workOrder="workOrderForm"
                :isLoading="isLoading"
                v-if="activeTab === 'vendor_tenant_conversation'"
            />

            <VendorConversation
                :vendorConversation="vendorConversation"
                :workOrderVendors="workOrderVendors"
                @update-vendor-convo="fetchVendorConversation(workOrderForm.id)"
                :workOrder="workOrderForm"
                :isLoading="isLoading"
                v-if="activeTab === 'vendor_conversation'"
            />

            <OwnerConversation
                :ownerConversation="ownerConversation"
                :workOrderOwners="workOrderOwners"
                :workOrder="workOrderForm"
                @update-owner-convo="fetchOwnerConversation(workOrderForm.id)"
                :isLoading="isLoading"
                v-if="activeTab === 'owner_conversation'"
            />

            <OwnerWocConversation
                :ownerConversation="ownerConversation"
                :workOrder="workOrderForm"
                @update-owner-convo="fetchOwnerConversation(workOrderForm.id)"
                :isLoading="isLoading"
                v-if="activeTab === 'owner_woc_conversation'"
            />

            <OwnerVendorConversation
                :ownerVendorConversation="vendorOwnerConversation"
                :workOrder="workOrderForm"
                :workOrderVendors="workOrderVendors"
                @update-owner-vendor-convo="
                    fetchVendorOwnerConversation(workOrderForm.id)
                "
                :isLoading="isLoading"
                v-if="activeTab === 'owner_vendor_conversation'"
            />

            <TenantConversation
                :tenantConversation="tenantConversation"
                :workOrderTenants="workOrderTenants"
                :workOrder="workOrderForm"
                @update-tenant-convo="fetchTenantConversation(workOrderForm.id)"
                :isLoading="isLoading"
                v-if="activeTab === 'tenant_conversation'"
            />

            <ServiceSchedule
                :vendorServiceSchedules="vendorServiceSchedules"
                :workOrderVendors="workOrderVendors"
                :workOrderTenants="workOrderTenants"
                :workOrder="workOrderForm"
                @fetch-schedule="fetchVendorServiceSchedules(workOrderForm.id)"
                :isLoading="isLoading"
                v-if="activeTab === 'service_schedule'"
            />

            <Attachments
                :workOrderAttachments="workOrderAttachments"
                :workOrder="workOrderForm"
                :isLoading="isLoading"
                @fetch-attachments="fetchAttachments(workOrderForm.id)"
                v-if="activeTab === 'attachments'"
            />

            <Invoices
                :workOrderInvoices="workOrderInvoices"
                :workOrder="workOrderForm"
                :assignedVendors="workOrderVendors"
                :isLoading="isLoading"
                @fetch-invoices="fetchInvoices(workOrderForm.id)"
                v-if="activeTab === 'invoices'"
            />

            <Notes
                :workOrderNotes="workOrderNotes"
                :workOrder="workOrderForm"
                :isLoading="isLoading"
                @fetch-notes="fetchNotes(workOrderForm.id)"
                v-if="activeTab === 'notes'"
            />

            <VendorEdit
                :workOrderVendorData="workOrderVendorData"
                :workOrder="workOrderForm"
                :isLoading="isLoading"
                @fetch-vendor="fetchVendors(workOrderForm.id)"
                v-if="activeTab === 'vendor_edit'"
            />
        </DialogScrollContent>
    </Dialog>
</template>
