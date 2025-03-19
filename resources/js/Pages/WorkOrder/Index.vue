<script setup>
import { ref } from "vue";
import { router, useForm, usePoll } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import { useToast } from "@/Components/ui/toast/use-toast";
import WorkOrderCard from "./Partials/WorkOrderCard.vue";
import TabSwitcher from "./Partials/TabSwitcher.vue";
import WorkOrderDetails from "./Partials/WorkOrderDetails.vue";
import WorkOrderTask from "./Partials/WorkOrderTask.vue";
import VendorConversation from "./Partials/VendorConversation.vue";
import TenantConversation from "./Partials/TenantConversation.vue";
import OwnerConversation from "./Partials/OwnerConversation.vue";
import ServiceSchedule from "./Partials/ServiceSchedule.vue";
import Attachments from "./Partials/Attachments.vue";

import {
  ClipboardList,
  MessagesSquare,
  ListChecks,
  MessageSquareText,
  MessageSquareShare,
  Calendar,
  Paperclip,
  FileText,
  FileDown,
} from "lucide-vue-next";

const { toast } = useToast();
defineOptions({ layout: AppLayout });

const props = defineProps({
  title: String,
  service_status: Object,
  vendors: Object,
  categories: Object,
  filter: Object,
});

const url = ref(route("work_orders.index"));
const search = ref(props.filter.search);

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
  vendor_id: "",
  vendors: Array,
  vendors: Array,
  managed_by: "",
  requested: "",
  local_status: "",
  vendor_notes: "",
  woc: "",
});

const closeWorkOrderForm = useForm({
  id: "",
});

const activeTab = ref("details");
const tabButtons = [
  { name: "details", tooltip: "Details", icon: ClipboardList },
  { name: "tasks", tooltip: "Tasks", icon: ListChecks },
  { name: "vendor_conversation", tooltip: "Vendor and Tenant Conversation", icon: "VOT" },
  {
    name: "owner_conversation",
    tooltip: "Owner and WOC Conversation",
    icon: MessageSquareText,
  },
  {
    name: "tenant_conversation",
    tooltip: "Tenant and WOC Conversation",
    icon: MessageSquareShare,
  },
  { name: "service_schedule", tooltip: "Service Schedule", icon: Calendar },
  { name: "attachments", tooltip: "Attachments", icon: Paperclip },
  { name: "invoices", tooltip: "Generate Invoice", icon: FileText },
  { name: "reports", tooltip: "Reports", icon: FileDown },
];

const switchTab = (tabName) => {
  activeTab.value = tabName;
  workOrderTasks.value = [];

  if (activeTab.value === "tasks" && workOrderForm.id) {
    fetchWorkOrderTask(workOrderForm.id);
  }

  if (activeTab.value === "details" && workOrderForm.id) {
    handleWorkOrder(workOrderForm.id); // Fetch latest data when switching to "Details"
  }

  if (activeTab.value === "vendor_conversation" && workOrderForm.id) {
    fetchVendorConversation(workOrderForm.id);
  }

  if (activeTab.value === "tenant_conversation" && workOrderForm.id) {
    fetchTenantConversation(workOrderForm.id);
  }

  if (activeTab.value === "owner_conversation" && workOrderForm.id) {
    fetchOwnerConversation(workOrderForm.id);
  }

  if (activeTab.value === "service_schedule" && workOrderForm.id) {
    fetchVendorServiceSchedules(workOrderForm.id);
  }

  if (activeTab.value === "attachments" && workOrderForm.id) {
    fetchAttachments(workOrderForm.id);
  }
};

const isLoading = ref(false);

const ownerConversation = ref([]);
const workOrderOwners = ref([]);

const fetchOwnerConversation = async (workOrderId) => {
  try {
    isLoading.value = true;
    const response = await axios.get(route("work_order.owner_conversation", workOrderId));

    ownerConversation.value = response.data.owner_conversation;
    workOrderOwners.value = response.data.owners;
  } catch (error) {
    console.error("Error fetching tasks:", error);
  } finally {
    isLoading.value = false;
  }
};

const tenantConversation = ref([]);
const workOrderTenants = ref([]);

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

const vendorConversation = ref([]);
const workOrderVendors = ref([]);

const fetchVendorConversation = async (workOrderId) => {
  try {
    isLoading.value = true;
    const response = await axios.get(
      route("work_order.vendor_conversation", workOrderId)
    );

    vendorConversation.value = response.data.vendor_conversation;
    workOrderTenants.value = response.data.tenants;
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
    const response = await axios.get(route("api.work_order.tasks", workOrderId));
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
    const response = await axios.get(route("work_order.service_schedules", workOrderId));
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
    const response = await axios.get(route("api.attachments.show", workOrderId));

    workOrderAttachments.value = response.data.attachments;
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
        description: "There was a problem with your request. Please try again!",
      });
    },
    only: ["service_status"],
  });
};

const handleCloseOrderSubmit = () => {
  closeWorkOrderForm.put(route("work_orders.close", closeWorkOrderForm.id), {
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
        description: "There was a problem with your request. Please try again!",
      });
    },
    only: ["service_status"],
  });
};

const handleEmergencySubmit = () => {
  router.put(
    route("work_orders.emergency.change", {
      workOrder: closeWorkOrderForm.id, // Ensure workOrder ID is included
      is_emergency: workOrderForm.is_emergency,
    }),
    {
      preserveState: true,
      preserveScroll: true,
      onSuccess: () => {
        toast({
          title: "Success",
          description: "Work order emergency has been changed successfully!",
        });
        handleWorkOrder(workOrderForm.id); // Fetch latest data when switching to "Details"
      },
      onError: () => {
        toast({
          variant: "destructive",
          title: "Uh oh! Something went wrong.",
          description: "There was a problem with your request. Please try again!",
        });
      },
      only: ["service_status"],
    }
  );
};

const handleVendorSubmit = () => {
  isLoading.value = true;
  router.put(
    route("work_orders.vendor.change", workOrderForm.id),
    {
      vendors: workOrderForm.vendors,
    },
    {
      preserveState: true,
      preserveScroll: true,
      onSuccess: () => {
        toast({
          title: "Success",
          description: "Vendor has been set successfully!",
        });
        handleWorkOrder(workOrderForm.id); // Fetch latest data when switching to "Details"
      },
      onError: () => {
        toast({
          variant: "destructive",
          title: "Uh oh! Something went wrong.",
          description: "There was a problem with your request. Please try again!",
        });
      },
      only: ["service_status"],
    }
  );
  isLoading.value = false;
};

const handleWorkOrder = async (orderId) => {
  workOrderForm.reset();
  activeTab.value = "details";
  workOrderTasks.value = [];
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
    workOrderForm.vendors =
      order.service_status.name === "New"
        ? Object.values(order.vendors).map((vendor) => vendor.name)
        : order.vendors;
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
    workOrderForm.source = order.source;
    workOrderForm.service_status = order.service_status.name;
    workOrderForm.service_status_id = order.service_status.id;
    workOrderForm.category = order.category;
    workOrderForm.created_date = order.created_date ? order.created_date : "";
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
usePoll(5000, { only: ["service_status"] });
</script>
<template>
  <Head :title="title" />

  <div class="flex">
    <SearchBar :url="url" v-model="search" />
  </div>
  <!-- Scrollable Service Status Area -->
  <ScrollArea class="w-[90vw] sm:w-[85vw] md:w-[75vw] lg:w-[70vw] xl:w-[75vw]">
    <WorkOrderCard :service_status="service_status" @showWorkOrder="handleWorkOrder" />
    <ScrollBar orientation="horizontal" />
  </ScrollArea>

  <Dialog v-model:open="openWorkOrder">
    <DialogContent
      class="sm:max-w-[800px] grid-rows-[auto_minmax(0,1fr)_auto] p-0 max-h-[95dvh]"
    >
      <DialogHeader class="p-6 pb-0 text-left">
        <DialogTitle class="text-2xl text-primary">
          <p v-if="!isLoading">#{{ workOrderForm.work_order_no }}</p>
        </DialogTitle>
        <DialogDescription>
          <div class="flex gap-2 mb-2" v-if="!isLoading">
            <Badge
              :variant="workOrderForm.priority === 'High' ? 'destructive' : 'outline'"
              >Priority: {{ workOrderForm.priority }}</Badge
            >
            <Badge variant="outline">Status: {{ workOrderForm.status }}</Badge>
            <Badge
              v-if="workOrderForm.is_emergency !== null"
              :variant="
                workOrderForm.is_emergency === 'Non-emergency' ? 'outline' : 'destructive'
              "
              >{{ workOrderForm.is_emergency }}</Badge
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
        @emergencyChanged="handleEmergencySubmit"
        @vendorChanged="handleVendorSubmit"
        v-if="activeTab === 'details'"
      />

      <WorkOrderTask
        :workOrderTasks="workOrderTasks"
        :service_status="service_status"
        :isEmergency="workOrderForm.is_emergency"
        :workOrder="workOrderForm"
        :isLoading="isLoading"
        @update-task-status="fetchWorkOrderTask(workOrderForm.id)"
        v-if="activeTab === 'tasks'"
      />

      <VendorConversation
        :vendorConversation="vendorConversation"
        :workOrderTenants="workOrderTenants"
        :workOrderVendors="workOrderVendors"
        :workOrder="workOrderForm"
        :isLoading="isLoading"
        v-if="activeTab === 'vendor_conversation'"
      />

      <OwnerConversation
        :ownerConversation="ownerConversation"
        :workOrderOwners="workOrderOwners"
        :workOrder="workOrderForm"
        :isLoading="isLoading"
        v-if="activeTab === 'owner_conversation'"
      />

      <TenantConversation
        :tenantConversation="tenantConversation"
        :workOrderTenants="workOrderTenants"
        :workOrder="workOrderForm"
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
    </DialogContent>
  </Dialog>
</template>
