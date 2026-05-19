<script setup>
import { ref, watch } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import axios from "axios";
import AppLayout from "@/Layouts/AppLayout.vue";
import { useToast } from "@/Components/ui/toast/use-toast";
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
import VendorTenantConversation from "./Partials/VendorTenantConversation.vue";
import OwnerVendorConversation from "./Partials/OwnerVendorConversation.vue";
import Recommendation from "./Partials/Recommendation.vue";
import {
    ClipboardList,
    ListChecks,
    Calendar,
    Paperclip,
    FileText,
    NotebookPen,
    Notebook,
    MessagesSquare,
    ArrowLeft,
    Sparkles,
    ExternalLink,
} from "lucide-vue-next";

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
    categories: Array,
    serviceStatuses: Array,
});

const { toast } = useToast();

// ── Tab configuration (mirrors Index.vue modal) ──────────────────────────────
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

// ── Work order form (same shape as Index.vue) ─────────────────────────────────
const order = props.workOrder;

const workOrderForm = useForm({
    id: order.id,
    work_order_no: order.work_order_no,
    description: order.description,
    location: order.location,
    managed_by: order.managed_by,
    requested: order.requested_by,
    vendors:
        order.local_status === "Created"
            ? Object.values(order.vendors ?? {}).map((v) => v.name)
            : (order.vendors ?? []),
    is_approved: order.is_approved,
    approved_date: order.approved_date,
    approval_comments: order.approval_comments,
    owners: order.owners ?? [],
    management_plan: order.management_plan,
    priority: order.priority,
    status: order.status,
    is_emergency:
        order.is_emergency === null
            ? null
            : order.is_emergency
              ? "Emergency"
              : "Non-emergency",
    local_status: order.local_status,
    total_cost: order.total_cost ?? "0",
    total_hour_work: order.total_hour_work ?? "0",
    cost_estimate: order.cost_estimate ?? "0",
    hour_estimate: order.hour_estimate ?? "0",
    type: order.type,
    closing_comments: order.closing_comments,
    latest_update_comments: order.latest_update_comments,
    source: order.source,
    service_status: order.service_status?.name,
    service_status_id: order.service_status?.id,
    category: order.category,
    created_date: order.created_date ?? "",
    scheduled_end_date: order.scheduled_end_date ?? "",
    end_date: order.end_date ? new Date(order.end_date) : "",
    authorized_to_enter: order.authorized_to_enter,
    additional_work_needed_reschedule: order.additional_work_needed_reschedule,
    zone: order.zone,
    vendor_notes: order.vendor_notes,
    woc: order.woc,
    building: order.building ?? null,
    propertyware_id: order.propertyware_id,
});

const closeWorkOrderForm = useForm({ id: order.id });

// ── Reactive data for tab content ─────────────────────────────────────────────
const isLoading = ref(false);
const workOrderTasks = ref(props.tasks ?? []);
const workOrderNotes = ref(props.notes ?? []);
const workOrderAttachments = ref(props.attachments ?? []);
const workOrderInvoices = ref(props.invoices ?? []);
const workOrderVendorData = ref([]);
const recommendation = ref(null);
const isGeneratingRecommendation = ref(false);

const ownerConversation = ref([]);
const tenantConversation = ref([]);
const vendorConversation = ref([]);
const vendorOwnerConversation = ref([]);
const vendorTenantConversation = ref([]);
const workOrderOwners = ref([]);
const workOrderTenants = ref([]);
const workOrderVendors = ref(order.vendors ?? []);

watch(
    () => props.workOrder?.vendors,
    (vendors) => {
        workOrderVendors.value = vendors ?? [];
    },
);

// ── Fetch helpers (same as Index.vue) ─────────────────────────────────────────
const fetchOwnerConversation = async () => {
    try {
        isLoading.value = true;
        const res = await axios.get(
            route("work_order.owner_conversation", workOrderForm.id),
        );
        ownerConversation.value = res.data.owner_conversation;
        workOrderOwners.value = res.data.owners;
    } catch (e) {
        console.error(e);
    } finally {
        isLoading.value = false;
    }
};

const fetchTenantConversation = async () => {
    try {
        isLoading.value = true;
        const res = await axios.get(
            route("work_order.tenant_conversation", workOrderForm.id),
        );
        tenantConversation.value = res.data.tenant_conversation;
        workOrderTenants.value = res.data.tenants;
    } catch (e) {
        console.error(e);
    } finally {
        isLoading.value = false;
    }
};

const fetchVendorTenantConversation = async () => {
    try {
        isLoading.value = true;
        const res = await axios.get(
            route("work_order.vendor_tenant_conversation", workOrderForm.id),
        );
        vendorTenantConversation.value = res.data.vendor_tenant_conversation;
        workOrderTenants.value = res.data.tenants;
    } catch (e) {
        console.error(e);
    } finally {
        isLoading.value = false;
    }
};

const fetchVendorConversation = async () => {
    try {
        isLoading.value = true;
        const res = await axios.get(
            route("work_order.vendor_conversation", workOrderForm.id),
        );
        vendorConversation.value = res.data.vendor_conversation;
        workOrderVendors.value = res.data.vendors;
    } catch (e) {
        console.error(e);
    } finally {
        isLoading.value = false;
    }
};

const fetchVendorOwnerConversation = async () => {
    try {
        isLoading.value = true;
        const res = await axios.get(
            route("work_order.vendor_owner_conversation", workOrderForm.id),
        );
        vendorOwnerConversation.value = res.data.vendor_owner_conversation;
        workOrderOwners.value = res.data.owners;
        workOrderVendors.value = res.data.vendors;
    } catch (e) {
        console.error(e);
    } finally {
        isLoading.value = false;
    }
};

const fetchWorkOrderTask = async () => {
    try {
        isLoading.value = true;
        const res = await axios.get(
            route("api.work_order.tasks", workOrderForm.id),
        );
        workOrderTasks.value = res.data.tasks;
    } catch (e) {
        console.error(e);
    } finally {
        isLoading.value = false;
    }
};

const vendorServiceSchedules = ref([]);
const fetchVendorServiceSchedules = async () => {
    try {
        isLoading.value = true;
        const res = await axios.get(
            route("work_order.service_schedules", workOrderForm.id),
        );
        vendorServiceSchedules.value = res.data.service_schedules;
        workOrderVendors.value = res.data.vendors;
        workOrderTenants.value = res.data.tenants;
    } catch (e) {
        console.error(e);
    } finally {
        isLoading.value = false;
    }
};

const fetchAttachments = async () => {
    try {
        isLoading.value = true;
        const res = await axios.get(
            route("api.attachments.show", workOrderForm.id),
        );
        workOrderAttachments.value = res.data.attachments;
    } catch (e) {
        console.error(e);
    } finally {
        isLoading.value = false;
    }
};

const fetchInvoices = async () => {
    try {
        isLoading.value = true;
        const res = await axios.get(
            route("api.invoices.index", workOrderForm.id),
        );
        workOrderInvoices.value = res.data.invoices;
    } catch (e) {
        console.error(e);
    } finally {
        isLoading.value = false;
    }
};

const fetchNotes = async () => {
    try {
        isLoading.value = true;
        const res = await axios.get(
            route("api.work_order_notes.show", workOrderForm.id),
        );
        workOrderNotes.value = res.data.notes;
    } catch (e) {
        console.error(e);
    } finally {
        isLoading.value = false;
    }
};

const fetchVendors = async () => {
    try {
        isLoading.value = true;
        const res = await axios.get(
            route("api.work_order_notes.show", workOrderForm.id),
        );
        workOrderVendorData.value = res.data.vendors;
    } catch (e) {
        console.error(e);
    } finally {
        isLoading.value = false;
    }
};

const fetchRecommendation = async () => {
    try {
        isLoading.value = true;
        const res = await axios.get(
            route("work_orders.recommendation.show", workOrderForm.id),
        );
        recommendation.value = res.data.recommendation;
    } catch (e) {
        console.error(e);
    } finally {
        isLoading.value = false;
    }
};

const generateRecommendation = async () => {
    try {
        isGeneratingRecommendation.value = true;
        const res = await axios.post(
            route("work_orders.recommendation.generate", workOrderForm.id),
        );
        recommendation.value = res.data.recommendation;
        toast({
            title: "Success",
            description: "Recommendation generated successfully.",
        });
    } catch (e) {
        console.error(e);
        toast({
            variant: "destructive",
            title: "Error",
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
                    description: "Recommended vendor assigned.",
                });
            },
            onError: () => {
                toast({
                    variant: "destructive",
                    title: "Error",
                    description: "Failed to assign recommended vendor.",
                });
            },
        },
    );
};

// ── Tab switching (mirrors Index.vue switchTab) ───────────────────────────────
const switchTab = (tabName) => {
    activeTab.value = tabName;

    if (tabName === "recommendation") fetchRecommendation();
    if (tabName === "tasks") fetchWorkOrderTask();
    if (tabName === "notes") fetchNotes();
    if (tabName === "attachments") fetchAttachments();
    if (tabName === "invoices") fetchInvoices();
    if (tabName === "service_schedule") fetchVendorServiceSchedules();
    if (tabName === "vendor_edit") fetchVendors();
    if (
        tabName === "vendor_conversation" ||
        tabName === "vendor_woc_conversation"
    )
        fetchVendorConversation();
    if (
        tabName === "vendor_owner_conversation" ||
        tabName === "owner_vendor_conversation"
    )
        fetchVendorOwnerConversation();
    if (tabName === "vendor_tenant_conversation")
        fetchVendorTenantConversation();
    if (
        tabName === "owner_conversation" ||
        tabName === "owner_woc_conversation"
    )
        fetchOwnerConversation();
    if (
        tabName === "tenant_conversation" ||
        tabName === "tenant_woc_conversation"
    )
        fetchTenantConversation();
    if (tabName === "tenant_vendor_conversation")
        fetchVendorTenantConversation();
    if (tabName === "conversation") {
        fetchVendorTenantConversation();
        fetchVendorConversation();
        fetchTenantConversation();
        fetchOwnerConversation();
    }
};

// ── Save / close / delete ────────────────────────────────────────────────────
const handleUpdateSubmit = () => {
    workOrderForm.put(route("work_orders.update", workOrderForm.id), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () =>
            toast({ title: "Success", description: "Work order updated!" }),
        onError: () =>
            toast({
                variant: "destructive",
                title: "Error",
                description: "Failed to update work order.",
            }),
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
                description: "Work order closed!",
            });
            router.visit(route("work_orders.index"));
        },
        onError: () =>
            toast({
                variant: "destructive",
                title: "Error",
                description: "Failed to close work order.",
            }),
        only: ["service_status"],
    });
};
</script>

<template>
    <Head :title="title" />

    <Card>
        <CardHeader>
            <div class="flex items-center gap-3">
                <CardTitle class="text-primary text-xl">
                    #{{ workOrderForm.work_order_no }}
                </CardTitle>
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
            <div class="flex gap-2 flex-wrap mt-2">
                <Badge
                    :variant="
                        workOrderForm.priority === 'High'
                            ? 'destructive'
                            : 'outline'
                    "
                >
                    Priority: {{ workOrderForm.priority }}
                </Badge>
                <Badge variant="outline">
                    Status: {{ workOrderForm.status }}
                </Badge>
                <Badge
                    v-if="workOrderForm.is_emergency !== null"
                    :variant="
                        workOrderForm.is_emergency === 'Non-emergency'
                            ? 'outline'
                            : 'destructive'
                    "
                >
                    {{ workOrderForm.is_emergency }}
                </Badge>
                <Badge v-if="workOrderForm.is_approved" variant="outline">
                    Approved
                </Badge>
            </div>
        </CardHeader>

        <!-- Centered tab switcher -->
        <div class="flex justify-center px-6 pb-4 gap-3">
            <TabSwitcher
                :buttons="tabButtons"
                :activeTab="activeTab"
                @switchTab="switchTab"
            />
        </div>

        <Separator />

        <CardContent class="pt-4">
            <Recommendation
                v-if="activeTab === 'recommendation'"
                :recommendation="recommendation"
                :isLoading="isLoading"
                :isGenerating="isGeneratingRecommendation"
                @generate="generateRecommendation"
                @assign="assignRecommendedVendor"
            />

            <WorkOrderDetails
                v-if="activeTab === 'details'"
                :workOrder="workOrderForm"
                :categories="categories ?? []"
                :vendors="vendors"
                :closeWorkOrderForm="closeWorkOrderForm"
                :isLoading="isLoading"
                @save="handleUpdateSubmit"
                @close="handleCloseOrderSubmit"
                @delete="router.visit(route('work_orders.index'))"
                @update-workOrder="router.reload({ only: ['workOrder'] })"
            />

            <WorkOrderTask
                v-if="activeTab === 'tasks'"
                :workOrderTasks="workOrderTasks"
                :service_status="[]"
                :isEmergency="workOrderForm.is_emergency"
                :workOrder="workOrderForm"
                :users="[]"
                :isLoading="isLoading"
                @update-task-status="fetchWorkOrderTask"
            />

            <Conversation
                v-if="activeTab === 'conversation'"
                :workOrder="workOrderForm"
                :vendorConversation="vendorConversation"
                :ownerConversation="ownerConversation"
                :tenantConversation="tenantConversation"
                :vendorTenantConversation="vendorTenantConversation"
                :vendorWocConversation="vendorConversation"
                :isLoading="isLoading"
            />

            <VendorWocConversation
                v-if="activeTab === 'vendor_woc_conversation'"
                :vendorWocConversation="vendorConversation"
                :workOrderVendors="workOrderVendors"
                :workOrder="workOrderForm"
                :isLoading="isLoading"
                @update-vendor-woc-convo="fetchVendorConversation"
            />

            <VendorOwnerConversation
                v-if="activeTab === 'vendor_owner_conversation'"
                :vendorOwnerConversations="vendorOwnerConversation"
                :workOrderOwners="workOrderOwners"
                :workOrder="workOrderForm"
                :isLoading="isLoading"
                @update-vendor-owner-convo="fetchVendorOwnerConversation"
            />

            <VendorTenantConversation
                v-if="activeTab === 'vendor_tenant_conversation'"
                :vendorTenantConversations="vendorTenantConversation"
                :workOrderTenants="workOrderTenants"
                :workOrder="workOrderForm"
                :isLoading="isLoading"
                @update-vendor-tenant-convo="fetchVendorTenantConversation"
            />

            <VendorConversation
                v-if="activeTab === 'vendor_conversation'"
                :vendorConversation="vendorConversation"
                :workOrderVendors="workOrderVendors"
                :workOrder="workOrderForm"
                :isLoading="isLoading"
                @update-vendor-convo="fetchVendorConversation"
            />

            <OwnerConversation
                v-if="activeTab === 'owner_conversation'"
                :ownerConversation="ownerConversation"
                :workOrderOwners="workOrderOwners"
                :workOrder="workOrderForm"
                :isLoading="isLoading"
                @update-owner-convo="fetchOwnerConversation"
            />

            <OwnerWocConversation
                v-if="activeTab === 'owner_woc_conversation'"
                :ownerConversation="ownerConversation"
                :workOrder="workOrderForm"
                :isLoading="isLoading"
                @update-owner-convo="fetchOwnerConversation"
            />

            <OwnerVendorConversation
                v-if="activeTab === 'owner_vendor_conversation'"
                :ownerVendorConversation="vendorOwnerConversation"
                :workOrder="workOrderForm"
                :workOrderVendors="workOrderVendors"
                :isLoading="isLoading"
                @update-owner-vendor-convo="fetchVendorOwnerConversation"
            />

            <TenantConversation
                v-if="
                    activeTab === 'tenant_conversation' ||
                    activeTab === 'tenant_woc_conversation'
                "
                :tenantConversation="tenantConversation"
                :workOrderTenants="workOrderTenants"
                :workOrder="workOrderForm"
                :isLoading="isLoading"
                @update-tenant-convo="fetchTenantConversation"
            />

            <VendorTenantConversation
                v-if="activeTab === 'tenant_vendor_conversation'"
                :vendorTenantConversations="vendorTenantConversation"
                :workOrderTenants="workOrderTenants"
                :workOrder="workOrderForm"
                :isLoading="isLoading"
                @update-vendor-tenant-convo="fetchVendorTenantConversation"
            />

            <ServiceSchedule
                v-if="activeTab === 'service_schedule'"
                :vendorServiceSchedules="vendorServiceSchedules"
                :workOrderVendors="workOrderVendors"
                :workOrderTenants="workOrderTenants"
                :workOrder="workOrderForm"
                :isLoading="isLoading"
                @fetch-schedule="fetchVendorServiceSchedules"
            />

            <Attachments
                v-if="activeTab === 'attachments'"
                :workOrderAttachments="workOrderAttachments"
                :workOrder="workOrderForm"
                :isLoading="isLoading"
                @fetch-attachments="fetchAttachments"
            />

            <Invoices
                v-if="activeTab === 'invoices'"
                :workOrderInvoices="workOrderInvoices"
                :workOrder="workOrderForm"
                :assignedVendors="workOrderVendors"
                :isLoading="isLoading"
                @fetch-invoices="fetchInvoices"
            />

            <Notes
                v-if="activeTab === 'notes'"
                :workOrderNotes="workOrderNotes"
                :workOrder="workOrderForm"
                :isLoading="isLoading"
                @fetch-notes="fetchNotes"
            />

            <VendorEdit
                v-if="activeTab === 'vendor_edit'"
                :workOrderVendorData="workOrderVendorData"
                :workOrder="workOrderForm"
                :isLoading="isLoading"
                @fetch-vendor="fetchVendors"
            />
        </CardContent>
    </Card>
</template>
