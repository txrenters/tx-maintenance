<script setup>
import { ref, watch, onMounted, computed, onBeforeUnmount } from "vue";
import { router, useForm, usePoll, usePage, Deferred } from "@inertiajs/vue3";
import axios from "axios";
import AppLayout from "@/Layouts/AppLayout.vue";
import { useToast } from "@/Components/ui/toast/use-toast";
import WorkOrderCard from "./Partials/WorkOrderCard.vue";
import TabSwitcher from "./Partials/TabSwitcher.vue";
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
import Recommendation from "./Partials/Recommendation.vue";
import debounce from "lodash/debounce";

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
    Download,
    RefreshCw,
    ScanSearch,
    Search,
    Loader2Icon,
    Loader2,
    Sparkles,
    ExternalLink,
    History,
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
import { useEchoPublic } from "@laravel/echo-vue";
import SearchBar from "@/Components/SearchBar.vue";
import VendorTenantConversation from "./Partials/VendorTenantConversation.vue";
import OwnerVendorConversation from "./Partials/OwnerVendorConversation.vue";
import { Skeleton } from "@/Components/ui/skeleton";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from "@/Components/ui/dropdown-menu";
import { useRecentWorkOrders } from "@/composables/useRecentWorkOrders";

const { recentWorkOrders, rememberWorkOrder, forgetWorkOrder, openedAgo } =
    useRecentWorkOrders();

// When the work order modal closes, take the user back to that card on the
// board and flash it — it may have moved to another column (e.g. New) after a
// status change, and this saves them from hunting for it.
const focusSignal = ref(null);
let focusToken = 0;

const props = defineProps({
    title: String,
    service_status: Object,
    vendors: Object,
    categories: Object,
    users: Object,
    filter: Object,
});

const url = ref(route("work_orders.index"));
const search = ref(props.filter.search ?? "");
const filter_vendor = ref(props.filter.vendor ?? "");
const filter_category = ref(props.filter.category ?? "");
const filter_emergency = ref(props.filter.emergency ?? "");
// Card color filter (red = overdue/emergency, blue = due today, green =
// upcoming). Colors are computed client-side per card, so this filter is
// applied on the board itself rather than via a server query.
const filter_color = ref("all");

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
    is_repeat_issue: "",
    repeat_count: 0,
    is_approved: "",
    vendor_id: "",
    vendors: [],
    managed_by: "",
    requested: "",
    owners: [],
    local_status: "",
    woc: "",
    building: null,
    propertyware_id: "",
});

const closeWorkOrderForm = useForm({
    id: "",
});

const activeTab = ref("details");
const tabButtons = [
    {
        name: "recommendation",
        tooltip: "Recommendation",
        icon: Sparkles,
        requires: ["admin", "woc", "vendor", "owner", "tenant"],
    },
    {
        name: "details",
        tooltip: "Details",
        icon: ClipboardList,
        requires: ["admin", "woc", "vendor", "owner", "tenant"],
    },
    {
        name: "tasks",
        tooltip: "Tasks",
        icon: ListChecks,
        requires: ["admin", "woc", "vendor"],
    },
    {
        name: "notes",
        tooltip: "Notes",
        icon: Notebook,
        requires: ["admin", "woc", "vendor", "owner", "tenant"],
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
        requires: ["admin", "woc", "vendor"],
    },
    {
        name: "attachments",
        tooltip: "Attachments",
        icon: Paperclip,
        requires: ["admin", "woc", "vendor", "owner", "tenant"],
    },
    {
        name: "invoices",
        tooltip: "Invoice",
        icon: FileText,
        requires: ["admin", "woc", "vendor"],
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

    if (activeTab.value === "recommendation" && workOrderForm.id) {
        fetchRecommendation(workOrderForm.id);
    }

    if (activeTab.value === "tasks" && workOrderForm.id) {
        fetchWorkOrderTask(workOrderForm.id);
    }

    if (activeTab.value === "details" && workOrderForm.id) {
        handleWorkOrder(workOrderForm.id); // Fetch latest data when switching to "Details"
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

        console.log(response.data);
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
const workOrderDocuments = ref([]);
const fetchAttachments = async (workOrderId) => {
    try {
        isLoading.value = true;
        const response = await axios.get(
            route("api.attachments.show", workOrderId)
        );

        workOrderAttachments.value = response.data.attachments;
        workOrderDocuments.value = response.data.documents ?? [];
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
const recommendation = ref(null);
const isGeneratingRecommendation = ref(false);
const fetchVendors = async (workOrderId) => {
    try {
        isLoading.value = true;
        const response = await axios.get(
            route("api.work_order_notes.show", workOrderId)
        );
        workOrderVendorData.value = response.data.vendors;

        console.log(workOrderId);
    } catch (error) {
        console.error("Error fetching tasks:", error);
    } finally {
        isLoading.value = false;
    }
};

const fetchRecommendation = async (workOrderId) => {
    try {
        isLoading.value = true;
        const response = await axios.get(
            route("work_orders.recommendation.show", workOrderId)
        );

        recommendation.value = response.data.recommendation;
    } catch (error) {
        console.error("Error fetching recommendation:", error);
    } finally {
        isLoading.value = false;
    }
};

const generateRecommendation = async () => {
    try {
        isGeneratingRecommendation.value = true;
        const response = await axios.post(
            route("work_orders.recommendation.generate", workOrderForm.id)
        );

        recommendation.value = response.data.recommendation;

        toast({
            title: "Success",
            description: "Recommendation generated successfully!",
        });
    } catch (error) {
        console.error("Error generating recommendation:", error);
        toast({
            variant: "destructive",
            title: "Uh oh! Something went wrong.",
            description: "Failed to generate recommendation.",
        });
    } finally {
        isGeneratingRecommendation.value = false;
    }
};

const assignRecommendedVendor = (vendor) => {
    if (!vendor?.name) {
        return;
    }

    router.put(
        route("work_orders.vendor.change", workOrderForm.id),
        { vendors: [vendor.name] },
        {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                workOrderVendors.value = [vendor];
                toast({
                    title: "Success",
                    description: "Recommended vendor assigned successfully!",
                });
            },
            onError: () => {
                toast({
                    variant: "destructive",
                    title: "Uh oh! Something went wrong.",
                    description: "Failed to assign recommended vendor.",
                });
            },
        }
    );
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

// On modal close, flash the card of the work order that was open so it is easy
// to find again after it may have moved columns.
watch(openWorkOrder, (isOpen, wasOpen) => {
    if (wasOpen && !isOpen && workOrderForm.id) {
        focusSignal.value = { id: workOrderForm.id, token: ++focusToken };
    }
});

const handleWorkOrder = async (orderId) => {
    workOrderForm.reset();
    activeTab.value = "details";
    workOrderTasks.value = [];
    recommendation.value = null;
    isGeneratingRecommendation.value = false;
    openWorkOrder.value = true;
    isLoading.value = true;

    try {
        const response = await axios.get(route("work_orders.show", orderId));
        const order = response.data; // Assuming the API returns the work order details

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
        workOrderForm.is_repeat_issue = order.is_repeat_issue;
        workOrderForm.repeat_count = order.repeat_count ?? 0;

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
        workOrderForm.building = order.building ?? null;
        workOrderForm.propertyware_id = order.propertyware_id;

        // Reset close form
        closeWorkOrderForm.reset();
        closeWorkOrderForm.id = order.id;

        rememberWorkOrder(order);
    } catch (error) {
        console.error("Failed to fetch work order:", error);

        // A stale Recent entry (or link) can point at a deleted work order —
        // don't leave a broken empty modal open.
        openWorkOrder.value = false;

        if (error.response?.status === 404) {
            forgetWorkOrder(typeof orderId === "object" ? orderId?.id : orderId);
            toast({
                variant: "destructive",
                title: "Work order not found",
                description: "It may have been deleted. Removed it from your Recent list.",
            });
        } else {
            toast({
                variant: "destructive",
                title: "Uh oh! Something went wrong.",
                description: "Failed to load the work order. Please try again!",
            });
        }
    }
    isLoading.value = false;
};

const openImportWorkOrder = ref(false);
const importWorkOrderForm = useForm({
    work_order_no: "",
});

const handleImportWorkOrder = () => {
    importWorkOrderForm.post(route("work_orders.import"), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            toast({
                title: "Success",
                description: "Work order has been imported successfully!",
            });
            openImportWorkOrder.value = false;
            importWorkOrderForm.reset(); // Reset the form
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

const df = new DateFormatter("en-US", {
    dateStyle: "medium",
});

const date_range = ref({
    start: "",
    end: "",
});

const formatDate = (d) => (d ? d.toString() : null);

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

const fetchFilteredData = debounce(() => {
    const startDate = formatDate(date_range.value?.start);
    const endDate = formatDate(date_range.value?.end);

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

const filterEmergency = () => {
    const value =
        filter_emergency.value && filter_emergency.value !== "all"
            ? filter_emergency.value
            : null;

    router.visit(url.value, {
        method: "get",
        data: { emergency: value },
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ["service_status"],
    });
};

watch(date_range, fetchFilteredData, { deep: true });
watch(filter_vendor, filterVendor);
watch(filter_category, filterCategory);
watch(filter_emergency, filterEmergency);

const page = usePage();
</script>
<template>
    <Head :title="title" />

    <div class="flex gap-3 flex-col sm:flex-row items-center">
        <SearchBar :url="url" v-model="search" />
        <div
            class="flex gap-2 items-center w-full flex-wrap"
            v-if="
                $page.props.auth.user.roles.includes('admin') ||
                $page.props.auth.user.roles.includes('woc')
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

            <Select
                :modelValue="String(filter_emergency)"
                @update:modelValue="(value) => (filter_emergency = value)"
            >
                <SelectTrigger class="w-full sm:w-[200px]">
                    <SelectValue placeholder="Emergency status" />
                </SelectTrigger>
                <SelectContent>
                    <SelectGroup>
                        <SelectItem value="all">All</SelectItem>
                        <SelectItem value="emergency">Emergency</SelectItem>
                        <SelectItem value="non_emergency">
                            Non-emergency
                        </SelectItem>
                    </SelectGroup>
                </SelectContent>
            </Select>

            <Select
                :modelValue="String(filter_color)"
                @update:modelValue="(value) => (filter_color = value)"
            >
                <SelectTrigger class="w-full sm:w-[200px]">
                    <SelectValue placeholder="Card color" />
                </SelectTrigger>
                <SelectContent>
                    <SelectGroup>
                        <SelectItem value="all">All colors</SelectItem>
                        <SelectItem value="red">
                            <span class="flex items-center gap-2">
                                <span
                                    class="h-3 w-3 rounded-full bg-destructive"
                                ></span>
                                Red — Overdue / Emergency
                            </span>
                        </SelectItem>
                        <SelectItem value="blue">
                            <span class="flex items-center gap-2">
                                <span
                                    class="h-3 w-3 rounded-full bg-primary"
                                ></span>
                                Blue — Due today
                            </span>
                        </SelectItem>
                        <SelectItem value="green">
                            <span class="flex items-center gap-2">
                                <span
                                    class="h-3 w-3 rounded-full bg-green-500"
                                ></span>
                                Green — Upcoming
                            </span>
                        </SelectItem>
                    </SelectGroup>
                </SelectContent>
            </Select>
        </div>

        <div class="flex gap-2 w-full justify-end">
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button variant="outline" class="shrink-0">
                        <History class="mr-2 h-4 w-4" />
                        Recent
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-80 max-h-96 overflow-y-auto">
                    <DropdownMenuLabel>Recently opened work orders</DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    <template v-if="recentWorkOrders.length">
                        <DropdownMenuItem
                            v-for="recent in recentWorkOrders"
                            :key="recent.id"
                            class="cursor-pointer"
                            @click="handleWorkOrder(recent.id)"
                        >
                            <div class="flex w-full items-center justify-between gap-3">
                                <span class="shrink-0 font-semibold">
                                    #{{ recent.work_order_no }}
                                </span>
                                <span class="min-w-0 truncate text-xs text-muted-foreground">
                                    {{ recent.location || recent.category }}
                                </span>
                                <span class="shrink-0 text-[10px] text-muted-foreground">
                                    {{ openedAgo(recent) }}
                                </span>
                            </div>
                        </DropdownMenuItem>
                    </template>
                    <DropdownMenuItem v-else disabled>
                        Nothing opened yet
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

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
            <a
                :href="
                    route('work_orders.export', {
                        vendor: filter_vendor,
                        search: search,
                        start_date: date_range?.start?.toString(),
                        end_date: date_range?.end?.toString(),
                    })
                "
                class="bg-primary px-3 py-3 rounded text-white hover:bg-primary/80"
                title="Download work orders"
            >
                <Download class="w-4 h-4" />
            </a>
            <Button
                v-if="
                    $page.props.auth.user.roles.includes('admin') ||
                    $page.props.auth.user.roles.includes('woc')
                "
                class="bg-primary px-3 py-3 rounded text-white hover:bg-primary/80"
                size="icon"
                title="Import Work Order"
                @click="openImportWorkOrder = true"
                ><ScanSearch class="w-4 h-4" />
            </Button>
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

    <ScrollArea
        class="w-[90vw] sm:w-[85vw] md:w-[75vw] lg:w-[70vw] xl:w-[75vw]"
    >
        <Deferred data="service_status">
            <template #fallback>
                <div class="mb-5">
                    <div class="flex gap-3 mb-3">
                        <Skeleton
                            v-for="value in 6"
                            :key="value"
                            class="h-[80px] w-[240px]"
                        />
                    </div>
                    <div
                        class="flex gap-3 mb-3"
                        v-for="value in 3"
                        :key="value"
                    >
                        <Skeleton
                            v-for="value in 6"
                            :key="value"
                            class="h-[180px] w-[240px] rounded-lg"
                        />
                    </div>
                </div>
            </template>
            <WorkOrderCard
                :service_status="service_status"
                :focus-signal="focusSignal"
                :color-filter="filter_color"
                @showWorkOrder="handleWorkOrder"
            />
        </Deferred>
        <ScrollBar orientation="horizontal" />
    </ScrollArea>

    <div class="">
        <span class="text-gray-600">Drag/swipe the scrollbar →</span>
    </div>

    <Dialog v-model:open="openWorkOrder">
        <DialogScrollContent
            class="flex w-full !max-w-4xl grid-rows-[auto_minmax(0,1fr)_auto] flex-col p-0 md:max-w-2xl"
        >
            <DialogHeader class="p-6 pb-0 text-left">
                <DialogTitle class="text-2xl text-primary">
                    <div v-if="!isLoading" class="flex items-center gap-3">
                        <p>#{{ workOrderForm.work_order_no }}</p>
                        <a
                            v-if="workOrderForm.propertyware_id"
                            :href="`https://app.propertyware.com/pw/maintenance/work_order_detail.do?entityID=${workOrderForm.propertyware_id}`"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex items-center gap-1 rounded-md border border-input bg-background px-2.5 py-1 text-xs font-medium text-primary transition-colors hover:bg-muted"
                        >
                            <ExternalLink class="h-3.5 w-3.5" />
                            PropertyWare
                        </a>
                    </div>
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
                            v-if="workOrderForm.is_repeat_issue"
                            class="bg-amber-500 text-white hover:bg-amber-500"
                            >Repeat{{
                                Number(workOrderForm.repeat_count) > 1
                                    ? ` · ${workOrderForm.repeat_count}×`
                                    : ""
                            }}</Badge
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

            <Recommendation
                :recommendation="recommendation"
                :isLoading="isLoading"
                :isGenerating="isGeneratingRecommendation"
                @generate="generateRecommendation"
                @assign="assignRecommendedVendor"
                v-if="activeTab === 'recommendation'"
            />

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

            <vendorOwnerConversation
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
                :workOrderDocuments="workOrderDocuments"
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

    <Dialog v-model:open="openImportWorkOrder">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Import Work Order</DialogTitle>
                <DialogDescription>
                    Enter work order number and click import to save the data.
                </DialogDescription>
            </DialogHeader>

            <div class="my-2">
                <Input
                    v-model="importWorkOrderForm.work_order_no"
                    type="number"
                    placeholder="Enter work order no"
                    class="mt-2"
                    :class="{
                        'border-destructive':
                            importWorkOrderForm.errors.work_order_no,
                    }"
                />
                <span class="text-xs text-destructive">{{
                    importWorkOrderForm.errors.work_order_no
                }}</span>

                <div class="text-xs text-muted-foreground mt-2">
                    <p>
                        This process may take some time depending on the work
                        orders.
                        <span v-if="importWorkOrderForm.processing">
                            Please don't close...
                        </span>
                    </p>
                </div>
            </div>

            <DialogFooter>
                <Button
                    type="submit"
                    :disabled="importWorkOrderForm.processing"
                    @click.prevent="handleImportWorkOrder"
                >
                    <Loader2
                        v-if="importWorkOrderForm.processing"
                        class="w-4 h-4 animate-spin"
                    />
                    <div>
                        <span v-if="importWorkOrderForm.processing">
                            Importing...
                        </span>
                        <span v-else>Import</span>
                    </div>
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
