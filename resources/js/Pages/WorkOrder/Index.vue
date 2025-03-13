<script setup>
import { onMounted, reactive, ref, computed, watch } from "vue";
import { useForm, usePoll } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import { useToast } from "@/Components/ui/toast/use-toast";
import { DateTime } from "luxon";
import { useFilter } from "reka-ui";

import {
  Combobox,
  ComboboxAnchor,
  ComboboxEmpty,
  ComboboxGroup,
  ComboboxInput,
  ComboboxItem,
  ComboboxList,
} from "@/Components/ui/combobox";
import {
  TagsInput,
  TagsInputInput,
  TagsInputItem,
  TagsInputItemDelete,
  TagsInputItemText,
} from "@/Components/ui/tags-input";

import {
  Loader2,
  ChevronRight,
  ClipboardList,
  MessagesSquare,
  ListCollapse,
  List,
  ListChecks,
  MessageSquareText,
  MessageSquareShare,
  Calendar,
  Paperclip,
  FileText,
  FileDown,
  EllipsisVertical,
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

const formatDate = (date) => {
  if (!date) return "------";

  let parsedDate;

  if (typeof date === "string") {
    if (date.includes("T")) {
      // Handle ISO format (2025-03-06T17:41:20.000000Z)
      parsedDate = DateTime.fromISO(date, { zone: "utc" });
    } else {
      // Handle non-ISO format (2025-03-06 23:10:06)
      parsedDate = DateTime.fromFormat(date, "yyyy-MM-dd HH:mm:ss", { zone: "utc" });
    }
  } else if (date instanceof Date) {
    parsedDate = DateTime.fromJSDate(date);
  } else {
    return "Invalid Date";
  }

  return parsedDate.isValid ? parsedDate.toFormat("MM/dd/yyyy") : "Invalid Date";
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
  service_status: "",
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
});

const closeWorkOrderForm = useForm({
  id: "",
});

// In case of a range picker, you'll receive [Date, Date]
const format = (date) => {
  const day = date.getDate();
  const month = date.getMonth() + 1;
  const year = date.getFullYear();
  return `${month}/${day}/${year}`;
};

const open = ref(false);
const searchTerm = ref("");

const { contains } = useFilter({ sensitivity: "base" });
const filteredVendors = computed(() => {
  const options = props.vendors.filter((i) => !workOrderForm.vendors.includes(i.name));
  return searchTerm.value
    ? options.filter((option) => contains(option.name, searchTerm.value))
    : options;
});

const badgeClass = computed(() => {
  if (task.status === "completed") return "bg-green-500 text-white";
  if (task.status === "pending") return "bg-yellow-500 text-black";
  if (task.status === "processing") return "bg-blue-500 text-white";
  return "bg-gray-300 text-black";
});

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
const activeTab = ref("details"); // Default tab

const handleSwitchTab = (tab) => {
  activeTab.value = tab;
  workOrderTasks.value = [];

  if (activeTab.value === "tasks") {
    fetchWorkOrderTask();
  }
};
const isLoading = ref(false);
const workOrderTasks = ref([]);

const fetchWorkOrderTask = async () => {
  try {
    isLoading.value = true;
    const response = await axios.get(route("work_order.tasks", workOrderForm.id));
    workOrderTasks.value = response.data.tasks;
  } catch (error) {
    console.error("Error fetching tasks:", error);
  } finally {
    isLoading.value = false;
  }
};

const handleTaskStatusChange = async (task_id, status) => {
  try {
    workOrderTasks.value = [];
    isLoading.value = true;
    const response = await axios.put(route("work_order.task.change", task_id), {
      status: status,
    });
    await fetchWorkOrderTask(); // Refetch the updated task list
  } catch (error) {
    console.error("Error updating task:", error);
  } finally {
    isLoading.value = false;
  }
};

const handleWorkOrder = (order) => {
  workOrderForm.reset();
  activeTab.value = "details";
  workOrderTasks.value = [];

  openWorkOrder.value = true;
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
  workOrderForm.is_emergency = order.is_emergency ? "Emergency" : "Non-emergency";
  workOrderForm.total_cost = order.total_cost ?? "0";
  workOrderForm.total_hour_work = order.total_hour_work ?? "0";
  workOrderForm.cost_estimate = order.cost_estimate ?? "0";
  workOrderForm.hour_estimate = order.hour_estimate ?? "0";
  workOrderForm.type = order.type;
  workOrderForm.closing_comments = order.closing_comments;
  workOrderForm.source = order.source;
  workOrderForm.service_status = order.service_status.name;
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

  closeWorkOrderForm.reset();
  closeWorkOrderForm.id = order.id;
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
    <div class="flex flex-row flex-nowrap space-x-2 overflow-x-auto scrollbar-hide">
      <div
        v-for="status in service_status"
        :key="status.id"
        class="overflow-hidden min-w-[240px]"
      >
        <div class="text-center font-semibol">
          <!-- Status Name -->
          <div
            class="h-16 flex items-center justify-center border p-3 text-sm uppercase font-semibold"
          >
            <p>{{ status.name }} ({{ status.work_orders.length }})</p>
          </div>

          <!-- Work Orders List -->
          <ScrollArea class="h-[600px] overflow-y-auto border-t pt-2 mb-5">
            <div
              @click="handleWorkOrder(work_order)"
              v-for="work_order in status.work_orders"
              :key="work_order.id"
              class="mb-2 rounded-lg p-4 text-white cursor-pointer shadow-md"
              :class="{
                'bg-destructive': work_order.is_emergency === 1,
                'bg-primary': work_order.is_emergency === 0,
                'bg-primary': work_order.is_emergency === 0,
              }"
            >
              <!-- Work Order Number & Date -->
              <div class="flex justify-between items-center border-b pb-2 mb-2">
                <h1 class="text-lg font-semibold">{{ work_order.work_order_no }}</h1>
                <p class="text-xs text-gray-200">
                  📅 {{ formatDate(work_order.created_date) }}
                </p>
              </div>
              <!-- Location -->
              <p class="text-sm text-gray-100">{{ work_order.location }}</p>

              <!-- Owner Info -->
              <div
                v-if="work_order.requested_by"
                class="flex justify-end mt-4 items-center gap-2"
              >
                <p class="text-sm text-gray-100">
                  {{ work_order.requested_by?.first_name }}
                  {{ work_order.requested_by?.last_name }}
                </p>
                <Avatar class="w-5 h-5">
                  <AvatarImage
                    :src="
                      work_order?.requested_by?.user?.profile_photo_url || 'default.jpg'
                    "
                  />
                  <AvatarFallback>
                    {{ work_order.requested_by?.first_name?.charAt(0)
                    }}{{ work_order.requested_by?.last_name?.charAt(0) }}
                  </AvatarFallback>
                </Avatar>
              </div>
            </div>

            <ScrollBar orientation="vertical" />
          </ScrollArea>
        </div>
      </div>
    </div>
    <ScrollBar orientation="horizontal" />
  </ScrollArea>

  <Dialog v-model:open="openWorkOrder">
    <DialogContent
      class="sm:max-w-[800px] grid-rows-[auto_minmax(0,1fr)_auto] p-0 max-h-[95dvh]"
    >
      <DialogHeader class="p-6 pb-0 text-left">
        <DialogTitle class="text-2xl text-primary"
          >#{{ workOrderForm.work_order_no }}</DialogTitle
        >
        <DialogDescription>
          <div class="flex gap-2 mb-2">
            <Badge
              :variant="workOrderForm.priority === 'High' ? 'destructive' : 'outline'"
              >Priority: {{ workOrderForm.priority }}</Badge
            >
            <Badge variant="outline">Status: {{ workOrderForm.status }}</Badge>
            <Badge
              v-if="workOrderForm.service_status !== 'New'"
              :variant="
                workOrderForm.is_emergency === 'Non-emergency' ? 'outline' : 'destructive'
              "
              >{{ workOrderForm.is_emergency }}</Badge
            >
          </div>
        </DialogDescription>
        <div class="flex justify-center gap-2 flex-wrap">
          <TooltipProvider>
            <Tooltip>
              <TooltipTrigger as-child>
                <Button
                  :variant="activeTab === 'details' ? '' : 'outline'"
                  size="icon"
                  @click="handleSwitchTab('details')"
                >
                  <ClipboardList class="w-4 h-4" />
                </Button>
              </TooltipTrigger>
              <TooltipContent>
                <p>Details</p>
              </TooltipContent>
            </Tooltip>
          </TooltipProvider>

          <TooltipProvider>
            <Tooltip>
              <TooltipTrigger as-child>
                <Button
                  :variant="activeTab === 'tasks' ? '' : 'outline'"
                  size="icon"
                  @click="handleSwitchTab('tasks')"
                >
                  <ListChecks class="w-4 h-4" />
                </Button>
              </TooltipTrigger>
              <TooltipContent>
                <p>Task List</p>
              </TooltipContent>
            </Tooltip>
          </TooltipProvider>

          <TooltipProvider>
            <Tooltip>
              <TooltipTrigger as-child>
                <Button
                  :variant="activeTab === 'conversation' ? '' : 'outline'"
                  size="icon"
                  @click="handleSwitchTab('conversation')"
                >
                  <MessagesSquare class="w-4 h-4" />
                </Button>
              </TooltipTrigger>
              <TooltipContent>
                <p>Conversation</p>
              </TooltipContent>
            </Tooltip>
          </TooltipProvider>

          <TooltipProvider>
            <Tooltip>
              <TooltipTrigger as-child>
                <Button
                  :variant="activeTab === 'owner_conversation' ? '' : 'outline'"
                  size="icon"
                  @click="handleSwitchTab('owner_conversation')"
                >
                  <MessageSquareText class="w-4 h-4" />
                </Button>
              </TooltipTrigger>
              <TooltipContent>
                <p>Onwer Conversation</p>
              </TooltipContent>
            </Tooltip>
          </TooltipProvider>

          <TooltipProvider>
            <Tooltip>
              <TooltipTrigger as-child>
                <Button
                  :variant="activeTab === 'tenant_conversation' ? '' : 'outline'"
                  size="icon"
                  @click="handleSwitchTab('tenant_conversation')"
                >
                  <MessageSquareShare class="w-4 h-4" />
                </Button>
              </TooltipTrigger>
              <TooltipContent>
                <p>Tenant Conversation</p>
              </TooltipContent>
            </Tooltip>
          </TooltipProvider>

          <TooltipProvider>
            <Tooltip>
              <TooltipTrigger as-child>
                <Button
                  :variant="activeTab === 'meetings' ? '' : 'outline'"
                  size="icon"
                  @click="handleSwitchTab('meetings')"
                >
                  <Calendar class="w-4 h-4" />
                </Button>
              </TooltipTrigger>
              <TooltipContent>
                <p>Meetings</p>
              </TooltipContent>
            </Tooltip>
          </TooltipProvider>

          <TooltipProvider>
            <Tooltip>
              <TooltipTrigger as-child>
                <Button
                  :variant="activeTab === 'attachements' ? '' : 'outline'"
                  size="icon"
                  @click="handleSwitchTab('attachments')"
                >
                  <Paperclip class="w-4 h-4" />
                </Button>
              </TooltipTrigger>
              <TooltipContent>
                <p>Attachments</p>
              </TooltipContent>
            </Tooltip>
          </TooltipProvider>

          <TooltipProvider>
            <Tooltip>
              <TooltipTrigger as-child>
                <Button
                  :variant="activeTab === 'invoices' ? '' : 'outline'"
                  size="icon"
                  @click="handleSwitchTab('invoices')"
                >
                  <FileText class="w-4 h-4" />
                </Button>
              </TooltipTrigger>
              <TooltipContent>
                <p>Generate Invoice</p>
              </TooltipContent>
            </Tooltip>
          </TooltipProvider>

          <TooltipProvider>
            <Tooltip>
              <TooltipTrigger as-child>
                <Button
                  :variant="activeTab === 'reports' ? '' : 'outline'"
                  size="icon"
                  @click="handleSwitchTab('reports')"
                >
                  <FileDown class="w-4 h-4" />
                </Button>
              </TooltipTrigger>
              <TooltipContent>
                <p>Reports</p>
              </TooltipContent>
            </Tooltip>
          </TooltipProvider>
        </div>
      </DialogHeader>
      <Separator />
      <div class="grid gap-3 overflow-y-auto px-6" v-if="activeTab === 'details'">
        <div class="grid grid-cols-2 gap-3">
          <div>
            <Label for="message">Vendors:</Label>
            <template v-if="workOrderForm.service_status !== 'New'">
              <p v-for="vendor in workOrderForm.vendors" :key="vendor.id">
                {{ vendor.name }}
              </p>
              <br />
            </template>

            <Combobox
              v-model="workOrderForm.vendors"
              v-model:open="open"
              :ignore-filter="true"
              v-if="workOrderForm.service_status === 'New'"
            >
              <ComboboxAnchor as-child>
                <TagsInput v-model="workOrderForm.vendors" class="px-2 py-2 gap-2 w-full">
                  <div class="flex gap-2 flex-wrap items-center">
                    <TagsInputItem
                      v-for="vendor in workOrderForm.vendors"
                      :key="vendor"
                      :value="vendor"
                    >
                      <TagsInputItemText />
                      <TagsInputItemDelete />
                    </TagsInputItem>
                  </div>

                  <ComboboxInput v-model="searchTerm" as-child>
                    <TagsInputInput
                      placeholder="Vendors..."
                      class="min-w-[200px] w-full p-0 border-none focus-visible:ring-0 h-auto"
                      @keydown.enter.prevent
                    />
                  </ComboboxInput>
                </TagsInput>

                <ComboboxList class="w-[--reka-popper-anchor-width] h-32">
                  <ComboboxEmpty />
                  <ComboboxGroup>
                    <ComboboxItem
                      v-for="vendor in vendors"
                      :key="vendor.id"
                      :value="vendor.name"
                      @select.prevent="
                        (ev) => {
                          if (typeof ev.detail.value === 'string') {
                            searchTerm = '';
                            workOrderForm.vendors.push(ev.detail.value);
                          }

                          if (filteredVendors.length === 0) {
                            open = false;
                          }
                        }
                      "
                    >
                      {{ vendor.name }}
                    </ComboboxItem>
                  </ComboboxGroup>
                </ComboboxList>
              </ComboboxAnchor>
            </Combobox>
          </div>
          <div v-if="workOrderForm.service_status === 'New'">
            <Label for="message">Emergency:</Label>
            <Select v-model="workOrderForm.is_emergency">
              <SelectTrigger class="w-full">
                <SelectValue placeholder="Select an emergency" />
              </SelectTrigger>
              <SelectContent>
                <SelectGroup>
                  <SelectItem value="Emergency"> Emergency </SelectItem>
                  <SelectItem value="Non-emergency"> Non-emergency </SelectItem>
                </SelectGroup>
              </SelectContent>
            </Select>
          </div>
          <div>
            <Label for="message">Category:</Label>
            <Select v-model="workOrderForm.category">
              <SelectTrigger class="w-full">
                <SelectValue placeholder="Select a category" />
              </SelectTrigger>
              <SelectContent>
                <SelectGroup>
                  <SelectItem
                    :value="category.category"
                    v-for="category in categories"
                    :key="category.category"
                  >
                    {{ category.category }}
                  </SelectItem>
                </SelectGroup>
              </SelectContent>
            </Select>
          </div>

          <div>
            <Label for="message">Manage by:</Label>
            <div class="flex gap-2 items-center">
              <Avatar class="w-5 h-5" v-if="workOrderForm?.managed_by">
                <AvatarImage
                  :src="
                    workOrderForm?.managed_by?.user?.profile_photo_url || 'default.jpg'
                  "
                />
                <AvatarFallback>
                  {{ workOrderForm.managed_by?.first_name?.charAt(0)
                  }}{{ workOrderForm.managed_by?.last_name?.charAt(0) }}
                </AvatarFallback>
              </Avatar>
              <p>
                {{ workOrderForm.managed_by?.first_name }}
                {{ workOrderForm.managed_by?.last_name }}
              </p>
            </div>
          </div>

          <div>
            <Label for="message">Location:</Label>

            <p>{{ workOrderForm.location }}</p>
          </div>

          <div>
            <Label for="message">Requested by:</Label>
            <div class="flex gap-2 items-center">
              <Avatar class="w-5 h-5" v-if="workOrderForm?.requested">
                <AvatarImage
                  :src="
                    workOrderForm?.requested?.user?.profile_photo_url || 'default.jpg'
                  "
                />
                <AvatarFallback>
                  {{ workOrderForm.requested?.first_name?.charAt(0)
                  }}{{ workOrderForm.requested?.last_name?.charAt(0) }}
                </AvatarFallback>
              </Avatar>
              <p>
                {{ workOrderForm.requested?.first_name }}
                {{ workOrderForm.requested?.last_name }}
              </p>
            </div>
          </div>
          <div>
            <Label for="message">Type:</Label>
            <p>{{ workOrderForm.type }}</p>
          </div>
          <div>
            <Label for="message">Service Status:</Label>
            <p>{{ workOrderForm.service_status }}</p>
          </div>
          <div>
            <Label for="message">Authorized to enter:</Label>
            <p>{{ workOrderForm.authorized_to_enter }}</p>
          </div>
          <div>
            <Label for="message">Source:</Label>
            <p>{{ workOrderForm.source }}</p>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <Label for="message">Total Cost:</Label>
            <p>${{ workOrderForm.total_cost }}</p>
          </div>
          <div>
            <Label for="message">Total Hour Worked:</Label>
            <p>{{ workOrderForm.total_hour_work }}</p>
          </div>

          <div>
            <Label for="message">Estimated cost:</Label>
            <Input type="number" class="mt-1" v-model="workOrderForm.cost_estimate" />
          </div>

          <div>
            <Label for="message">Estimated Time (Hrs) :</Label>
            <Input type="number" class="mt-1" v-model="workOrderForm.hour_estimate" />
          </div>
        </div>

        <div class="work_order_details">
          <div class="grid grid-cols-2 gap-4 items-center">
            <div>
              <Label for="message">Created Date:</Label>
              <p>{{ formatDate(workOrderForm.created_date) }}</p>
            </div>
            <div>
              <Label for="message">Scheduled End Date:</Label>
              <p>{{ formatDate(workOrderForm.updated_at) }}</p>
            </div>
            <div>
              <Label for="message">Zone:</Label>
              <Input class="mt-1" v-model="workOrderForm.zone" />
            </div>
            <div>
              <Label for="message">End Date:</Label>
              <VueDatePicker
                class="mt-1"
                v-model="workOrderForm.end_date"
                :format="format"
                position="left"
              />
            </div>
          </div>

          <div class="grid gap-1.5 mt-5">
            <Label for="message">Management Plan</Label>
            <Textarea
              placeholder="Type your message here."
              v-model="workOrderForm.management_plan"
            />
          </div>
          <div class="grid gap-1.5 mt-5">
            <Label for="message">Additional Work Needed </Label>
            <Textarea
              placeholder="Type your message here."
              rows="1"
              v-model="workOrderForm.additional_work_needed_reschedule"
            />
          </div>
          <div class="grid gap-1.5 mt-5">
            <Label for="message">Closing Comments</Label>
            <Textarea
              placeholder="Type your message here."
              v-model="workOrderForm.closing_comments"
              rows="1"
            />
          </div>
          <div class="grid gap-1.5 mt-5">
            <Label>Description:</Label>
            <div class="border mt-2" v-if="workOrderForm.description">
              <p class="text-sm p-2 rounded">
                {{ workOrderForm.description }}
              </p>
            </div>
          </div>

          <div class="grid gap-1.5 mt-5 mb-5">
            <Label for="message">Vendor Notes</Label>
            <Textarea
              placeholder="Type your message here."
              v-model="workOrderForm.vendor_notes"
            />
          </div>
        </div>
      </div>
      <DialogFooter class="p-6 pt-0" v-if="activeTab === 'details'">
        <Button
          type="submit"
          variant="destructive"
          :disabled="closeWorkOrderForm.processing"
          @click.prevent="handleCloseOrderSubmit"
        >
          <Loader2 v-if="closeWorkOrderForm.processing" class="w-4 h-4 animate-spin" />
          Close Work Order
        </Button>
        <Button
          type="submit"
          :disabled="workOrderForm.processing"
          @click.prevent="handleUpdateSubmit"
        >
          <Loader2 v-if="workOrderForm.processing" class="w-4 h-4 animate-spin" />
          Save changes
        </Button>
      </DialogFooter>

      <div
        class="overflow-y-auto px-6 mb-6 w-full min-h-[88px]"
        v-if="activeTab === 'tasks'"
      >
        <p class="font-semibold uppercase text-xs mb-3">Task Details</p>
        <div class="flex justify-center" v-if="isLoading">
          <Loader2 class="w-12 h-12 animate-spin text-primary" />
        </div>

        <div class="flex" v-if="!isLoading && workOrderTasks.length === 0">
          <p class="font-semibold">No tasks available</p>
        </div>

        <div v-else>
          <div
            class="w-full p-2 mb-2 rounded-lg shadow hover:bg-secondary"
            v-for="task in workOrderTasks"
            :key="task.id"
          >
            <div class="flex justify-between">
              <div class="flex flex-col gap-2">
                <div class="flex text-xs items-center gap-1">
                  <Badge
                    :variant="
                      task.status === 'pending'
                        ? 'secondary'
                        : task.status === 'completed'
                        ? 'destructive'
                        : '' /* Default */
                    "
                  >
                    {{ task.status }}</Badge
                  >
                  <p>{{ task.due_date ?? "No due date" }}</p>
                </div>
                <p class="text-sm">{{ task.task.name }}</p>
                <div class="flex flex-col text-xs gap-1">
                  <p>Assigned: {{ task.task.type }}</p>
                  <p class="flex gap-1 items-center">
                    <Avatar class="w-5 h-5">
                      <AvatarImage
                        :src="task.assigned_user?.profile_photo_url || 'default.jpg'"
                      />
                      <AvatarFallback></AvatarFallback>
                    </Avatar>
                    {{ task.assigned_user.name }}
                  </p>
                </div>
              </div>
              <div>
                <DropdownMenu>
                  <DropdownMenuTrigger as-child>
                    <Button aria-haspopup="true" size="icon" variant="ghost">
                      <EllipsisVertical class="w-3 h-3" />
                      <span class="sr-only">Toggle menu</span>
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="end">
                    <DropdownMenuLabel>Mark as</DropdownMenuLabel>
                    <DropdownMenuItem
                      class="cursor-pointer hover:bg-secondary"
                      @click="handleTaskStatusChange(task.id, 'pending')"
                      >Pending</DropdownMenuItem
                    >
                    <DropdownMenuItem
                      class="cursor-pointer hover:bg-secondary"
                      @click="handleTaskStatusChange(task.id, 'processing')"
                      >Processing</DropdownMenuItem
                    >
                    <DropdownMenuItem
                      class="cursor-pointer hover:bg-secondary"
                      @click="handleTaskStatusChange(task.id, 'completed')"
                      >Complete</DropdownMenuItem
                    >
                  </DropdownMenuContent>
                </DropdownMenu>
              </div>
            </div>
          </div>
        </div>
      </div>
    </DialogContent>
  </Dialog>
</template>
