<script setup>
import { ref, watch, computed } from "vue";
import { router, useForm, usePage } from "@inertiajs/vue3";
import axios from "axios";
import { useToast } from "@/Components/ui/toast/use-toast";
import { useWorkOrderModal } from "@/composables/useWorkOrderModal";

import TabSwitcher from "@/Pages/WorkOrder/Partials/TabSwitcher.vue";
import WorkOrderDetails from "@/Pages/WorkOrder/Partials/WorkOrderDetails.vue";
import WorkOrderTask from "@/Pages/WorkOrder/Partials/WorkOrderTask.vue";
import VendorWocConversation from "@/Pages/WorkOrder/Partials/VendorWocConversation.vue";
import VendorOwnerConversation from "@/Pages/WorkOrder/Partials/VendorOwnerConversation.vue";
import VendorConversation from "@/Pages/WorkOrder/Partials/VendorConversation.vue";
import TenantConversation from "@/Pages/WorkOrder/Partials/TenantConversation.vue";
import OwnerConversation from "@/Pages/WorkOrder/Partials/OwnerConversation.vue";
import OwnerWocConversation from "@/Pages/WorkOrder/Partials/OwnerWocConversation.vue";
import OwnerVendorConversation from "@/Pages/WorkOrder/Partials/OwnerVendorConversation.vue";
import VendorTenantConversation from "@/Pages/WorkOrder/Partials/VendorTenantConversation.vue";
import Conversation from "@/Pages/WorkOrder/Partials/Conversation.vue";
import ServiceSchedule from "@/Pages/WorkOrder/Partials/ServiceSchedule.vue";
import Attachments from "@/Pages/WorkOrder/Partials/Attachments.vue";
import Invoices from "@/Pages/WorkOrder/Partials/Invoices.vue";
import Notes from "@/Pages/WorkOrder/Partials/Notes.vue";
import VendorEdit from "@/Pages/WorkOrder/Partials/VendorEdit.vue";
import Recommendation from "@/Pages/WorkOrder/Partials/Recommendation.vue";
import EmailNotifications from "@/Pages/WorkOrder/Partials/EmailNotifications.vue";

import {
    ClipboardList,
    ListChecks,
    Calendar,
    Paperclip,
    FileText,
    NotebookPen,
    Notebook,
    MessagesSquare,
    Sparkles,
    ExternalLink,
    Mail,
} from "lucide-vue-next";

const { toast } = useToast();
const { state, close } = useWorkOrderModal();

// Supporting lists needed to render the modal, fetched once from the backend so this
// component is self-contained and can be mounted globally (independent of page props).
const categories = ref([]);
const vendors = ref([]);
const users = ref([]);
const serviceStatuses = ref([]);
let metaLoaded = false;

const loadMeta = async () => {
    if (metaLoaded) return;
    try {
        const response = await axios.get(route("api.work_order_modal.meta"));
        categories.value = response.data.categories;
        vendors.value = response.data.vendors;
        users.value = response.data.users;
        serviceStatuses.value = response.data.service_status;
        metaLoaded = true;
    } catch (error) {
        console.error("Failed to load work order modal meta:", error);
    }
};

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
    building: null,
    propertyware_id: "",
    jobber_web_uri: "",
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
        name: "email_notifications",
        tooltip: "Email Notifications",
        icon: Mail,
        requires: ["admin", "woc"],
    },
    {
        name: "conversation",
        tooltip: "Conversation",
        icon: MessagesSquare,
        requires: ["admin", "woc"],
    },
];

const page = usePage();

// A vendor can only message owners/tenants from their own Twilio number. When
// the vendor has no number configured, hide the Owner and Tenant conversation
// tabs so they can't open a thread they'd be unable to send from. Vendor-only —
// other roles are unaffected.
const visibleTabButtons = computed(() => {
    const user = page.props.auth.user;
    const isVendor = (user?.roles || []).includes("vendor");
    const hasTwilioNumber = Boolean(user?.vendor?.twilio_number);

    if (isVendor && !hasTwilioNumber) {
        return tabButtons.filter(
            (button) =>
                button.name !== "vendor_owner_conversation" &&
                button.name !== "vendor_tenant_conversation"
        );
    }

    return tabButtons;
});

const isLoading = ref(false);

const ownerConversation = ref([]);
const workOrderOwners = ref([]);
const workOrderTenants = ref([]);
const workOrderVendors = ref([]);
const tenantConversation = ref([]);
const vendorTenantConversation = ref([]);
const vendorConversation = ref([]);
const vendorOwnerConversation = ref([]);
const workOrderTasks = ref([]);
const vendorServiceSchedules = ref([]);
const workOrderAttachments = ref([]);
const workOrderDocuments = ref([]);
const workOrderInvoices = ref([]);
const workOrderNotes = ref([]);
const workOrderVendorData = ref([]);
const recommendation = ref(null);
const isGeneratingRecommendation = ref(false);
const emailNotifications = ref([]);
const emailNotificationsLoading = ref(false);

const fetchEmailNotifications = async (workOrderId) => {
    emailNotificationsLoading.value = true;
    try {
        const response = await axios.get(route("work_order.email.notifications", workOrderId));
        emailNotifications.value = response.data.emails ?? [];
    } catch (error) {
        console.error(error);
        toast({ variant: "destructive", title: "Email history unavailable" });
    } finally {
        emailNotificationsLoading.value = false;
    }
};

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
        handleWorkOrder(workOrderForm.id);
    }

    if (activeTab.value === "email_notifications" && workOrderForm.id) {
        fetchEmailNotifications(workOrderForm.id);
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
            close();
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

const handleWorkOrder = async (orderId) => {
    workOrderForm.reset();
    activeTab.value = "details";
    workOrderTasks.value = [];
    recommendation.value = null;
    isGeneratingRecommendation.value = false;
    isLoading.value = true;

    try {
        const response = await axios.get(route("work_orders.data", orderId));
        const order = response.data;

        workOrderForm.id = order.id;
        workOrderForm.work_order_no = order.work_order_no;
        workOrderForm.description = order.description;
        workOrderForm.location = order.location;
        workOrderForm.managed_by = order.managed_by;
        workOrderForm.requested = order.requested_by;
        workOrderVendors.value = order.vendors ?? [];
        workOrderForm.vendors = order.vendors ?? [];
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
        workOrderForm.building = order.building ?? null;
        workOrderForm.propertyware_id = order.propertyware_id;
        workOrderForm.jobber_web_uri = order.jobber_web_uri ?? "";

        closeWorkOrderForm.reset();
        closeWorkOrderForm.id = order.id;
    } catch (error) {
        console.error("Failed to fetch work order:", error);
    }
    isLoading.value = false;
};

// Open/refresh the modal whenever something requests it via the shared composable.
watch(
    () => [state.isOpen, state.workOrderId],
    ([isOpen, id]) => {
        if (isOpen && id) {
            loadMeta();
            handleWorkOrder(id);
        }
    }
);
</script>

<template>
    <Dialog v-model:open="state.isOpen">
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
                        <a
                            v-if="
                                workOrderForm.jobber_web_uri &&
                                ($page.props.auth.user.roles.includes(
                                    'admin',
                                ) ||
                                    $page.props.auth.user.roles.includes(
                                        'woc',
                                    ) ||
                                    $page.props.auth.user.roles.includes(
                                        'accounting',
                                    ))
                            "
                            :href="workOrderForm.jobber_web_uri"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex items-center gap-1 rounded-md border border-input bg-background px-2.5 py-1 text-xs font-medium text-primary transition-colors hover:bg-muted"
                        >
                            <ExternalLink class="h-3.5 w-3.5" />
                            Open in Jobber
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
                            v-if="workOrderForm.is_approved"
                            variant="outline"
                            >Approved</Badge
                        >
                    </div>
                </DialogDescription>
                <div class="flex justify-center gap-2 flex-wrap">
                    <TabSwitcher
                        :buttons="visibleTabButtons"
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
                @delete="close()"
                @update-workOrder="handleWorkOrder(workOrderForm.id)"
                v-if="activeTab === 'details'"
            />

            <EmailNotifications
                v-if="activeTab === 'email_notifications'"
                :emails="emailNotifications"
                :loading="emailNotificationsLoading"
            />

            <WorkOrderTask
                :workOrderTasks="workOrderTasks"
                :service_status="serviceStatuses"
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
</template>
