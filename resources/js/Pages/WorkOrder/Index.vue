<script setup>
import { onMounted, reactive, ref, computed } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import { useForm } from "@inertiajs/vue3";
import { useToast } from "@/Components/ui/toast/use-toast";
import { ScrollArea, ScrollBar } from "@/Components/ui/scroll-area";
import { DateTime } from "luxon";

import {
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
} from "lucide-vue-next";
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from "@/components/ui/tooltip";
const { toast } = useToast();

// Reactive state
const value = ref([{ name: "Javascript", code: "js" }]);
const options = ref([
  { name: "Vue.js", code: "vu" },
  { name: "Javascript", code: "js" },
  { name: "Open Source", code: "os" },
]);

// Method to add a new tag
const addTag = (newTag) => {
  const tag = {
    name: newTag,
    code: newTag.substring(0, 2) + Math.floor(Math.random() * 10000000),
  };
  options.value.push(tag);
  value.value.push(tag);
};

defineOptions({ layout: AppLayout });

const props = defineProps({
  title: String,
  service_status: Object,
  vendors: Object,
  categories: Object,
  filter: Array,
});

const url = ref(route("work_orders.index"));
const search = ref(props.filter.search);

const openWorkOrder = ref(false);

const formatDate = (date) => {
  if (!date) return "Unknown Date";

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

const work_order = reactive({
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
  estimated_cost: "",
  estimated_time: "",
  additional_work_need: "",
  last_modified: "",
  closing_comments: "",
  service_status: "",
  zone: "",
  created_date: "",
  updated_at: "",
  end_date: "",
  management_plan: "",
  description: "",
  vendor_notes: "",
  is_emergency: "",
  vendors: Array,
  additional_work_need: "",
});

const handleWorkOrder = (order) => {
  openWorkOrder.value = true;
  work_order.work_order_no = order.work_order_no;
  work_order.description = order.description;
  work_order.location = order.location;
  work_order.managed_by = order.managed_by;
  work_order.requested = order.requested_by;
  work_order.vendors = order.vendors;
  work_order.priority = order.priority;
  work_order.status = order.status;
  work_order.is_emergency = order.is_emergency ? "Emergency" : "Non-emergency";
  work_order.total_cost = order.total_cost ?? "0";
  work_order.total_hour_work = order.total_hour_work ?? "0";
  work_order.estimated_cost = order.estimated_cost ?? "0";
  work_order.hour_estimate = order.hour_estimate ?? "0";
  work_order.type = order.type;
  work_order.source = order.source;
  work_order.service_status = order.service_status.name;
  work_order.category = order.category;
  work_order.additional_work_need = order.additional_work_need;
  work_order.created_date = order.created_date ? order.created_date : "";
  work_order.updated_at = order.updated_at ? order.updated_at : "";
  work_order.end_date = order.end_date ? new Date(order.end_date) : "";
  work_order.authorized_to_enter = order.authorized_to_enter;
  work_order.additional_work_need = order.additional_work_needed_reschedule;
  work_order.zone = order.zone;
};
// In case of a range picker, you'll receive [Date, Date]
const format = (date) => {
  const day = date.getDate();
  const month = date.getMonth() + 1;
  const year = date.getFullYear();
  return `${month}/${day}/${year}`;
};

console.log(format);
</script>
<template>
  <Head :title="title" />

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
          <div class="h-16 flex items-center justify-center border p-3 text-sm">
            <p>{{ status.name }}</p>
          </div>

          <!-- Work Orders List -->
          <ScrollArea class="h-[600px] overflow-y-auto border-t pt-2 mb-5">
            <div
              @click="handleWorkOrder(work_order)"
              v-for="work_order in status.work_orders"
              :key="work_order.id"
              class="mb-2 bg-primary rounded-lg p-4 text-white cursor-pointer shadow-md"
              :class="{
                'bg-red-500': work_order.is_emergency === '1',
                'bg-primary': work_order.is_emergency === '0',
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
      class="sm:max-w-[700px] grid-rows-[auto_minmax(0,1fr)_auto] p-0 max-h-[95dvh]"
    >
      <DialogHeader class="p-6 pb-0 text-left">
        <DialogTitle class="text-2xl text-primary"
          >#{{ work_order.work_order_no }}</DialogTitle
        >
        <DialogDescription>
          <div class="flex gap-2 mb-2">
            <Badge :variant="work_order.priority === 'High' ? 'destructive' : 'outline'"
              >Priority: {{ work_order.priority }}</Badge
            >
            <Badge variant="outline">Status: {{ work_order.status }}</Badge>
            <Badge
              v-if="work_order.service_status !== 'New'"
              :variant="
                work_order.is_emergency === 'Non-emergency' ? 'outline' : 'destructive'
              "
              >{{ work_order.is_emergency }}</Badge
            >
          </div>

          <div class="p-2 border rounded-lg">
            <Label>Description:</Label>
            <p class="font-semibold">
              {{ work_order.description }}
            </p>
          </div>
        </DialogDescription>
        <div class="flex justify-end gap-2">
          <TooltipProvider>
            <Tooltip>
              <TooltipTrigger as-child>
                <Button variant="outline" size="icon">
                  <ClipboardList class="w-4 h-4" />
                </Button>
              </TooltipTrigger>
              <TooltipContent>
                <p>Work Order Details</p>
              </TooltipContent>
            </Tooltip>
          </TooltipProvider>

          <TooltipProvider>
            <Tooltip>
              <TooltipTrigger as-child>
                <Button variant="outline" size="icon">
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
                <Button variant="outline" size="icon">
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
                <Button variant="outline" size="icon">
                  <MessageSquareText class="w-4 h-4" />
                </Button>
              </TooltipTrigger>
              <TooltipContent>
                <p>Vendor Conversation</p>
              </TooltipContent>
            </Tooltip>
          </TooltipProvider>

          <TooltipProvider>
            <Tooltip>
              <TooltipTrigger as-child>
                <Button variant="outline" size="icon">
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
                <Button variant="outline" size="icon">
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
                <Button variant="outline" size="icon">
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
                <Button variant="outline" size="icon">
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
                <Button variant="outline" size="icon">
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
      <div class="grid gap-3 overflow-y-auto px-6">
        <div class="grid grid-cols-2 gap-3">
          <div v-if="work_order.service_status === 'New'">
            <Label for="message">Emergency:</Label>
            <Select v-model="work_order.is_emergency">
              <SelectTrigger class="w-[180px]">
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
            <Select>
              <SelectTrigger class="w-[180px]">
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
            <Label for="message">Vendors:</Label>
            <p v-for="vendor in work_order.vendors" :key="vendor.id">
              {{ vendor.name }}
            </p>
            <Multiselect
              id="tagging"
              v-model="value"
              tag-placeholder="Add this as new tag"
              placeholder="Search or add a tag"
              label="name"
              track-by="code"
              :options="options"
              :multiple="true"
              :taggable="true"
              @tag="addTag"
            />
          </div>
          <div>
            <Label for="message">Manage by:</Label>
            <div class="flex gap-2 items-center">
              <Avatar class="w-5 h-5" v-if="work_order?.managed_by">
                <AvatarImage
                  :src="work_order?.managed_by?.user?.profile_photo_url || 'default.jpg'"
                />
                <AvatarFallback>
                  {{ work_order.managed_by?.first_name?.charAt(0)
                  }}{{ work_order.managed_by?.last_name?.charAt(0) }}
                </AvatarFallback>
              </Avatar>
              <p>
                {{ work_order.managed_by?.first_name }}
                {{ work_order.managed_by?.last_name }}
              </p>
            </div>
          </div>

          <div>
            <Label for="message">Location:</Label>

            <p>{{ work_order.location }}</p>
          </div>

          <div>
            <Label for="message">Requested by:</Label>
            <div class="flex gap-2 items-center">
              <Avatar class="w-5 h-5" v-if="work_order?.requested">
                <AvatarImage
                  :src="work_order?.requested?.user?.profile_photo_url || 'default.jpg'"
                />
                <AvatarFallback>
                  {{ work_order.requested?.first_name?.charAt(0)
                  }}{{ work_order.requested?.last_name?.charAt(0) }}
                </AvatarFallback>
              </Avatar>
              <p>
                {{ work_order.requested?.first_name }}
                {{ work_order.requested?.last_name }}
              </p>
            </div>
          </div>
          <div>
            <Label for="message">Type:</Label>
            <p>{{ work_order.type }}</p>
          </div>
          <div>
            <Label for="message">Service Status:</Label>
            <p>{{ work_order.service_status }}</p>
          </div>
          <div>
            <Label for="message">Authorized to enter:</Label>
            <p>{{ work_order.authorized_to_enter }}</p>
          </div>
          <div>
            <Label for="message">Source:</Label>
            <p>{{ work_order.source }}</p>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <Label for="message">Total Cost:</Label>
            <p>${{ work_order.total_cost }}</p>
          </div>
          <div>
            <Label for="message">Total Hour Worked:</Label>
            <p>{{ work_order.total_hour_work }}</p>
          </div>

          <div>
            <Label for="message">Estimated cost:</Label>
            <Input type="number" class="mt-1" v-model="work_order.estimated_cost" />
          </div>

          <div>
            <Label for="message">Estimated Time (Hrs) :</Label>
            <Input type="number" class="mt-1" v-model="work_order.estimated_time" />
          </div>
        </div>

        <div class="work_order_details">
          <div class="grid grid-cols-2 gap-4 items-center">
            <div>
              <Label for="message">Created Date:</Label>
              <p>{{ formatDate(work_order.created_date) }}</p>
            </div>
            <div>
              <Label for="message">Modified Date:</Label>
              <p>{{ formatDate(work_order.updated_at) }}</p>
            </div>
            <div>
              <Label for="message">Zone:</Label>
              <Input class="mt-1" v-model="work_order.zone" />
            </div>
            <div>
              <Label for="message">End Date:</Label>
              <VueDatePicker
                class="mt-1"
                v-model="work_order.end_date"
                :format="format"
                position="left"
              />
            </div>
          </div>

          <div class="grid gap-1.5 mt-5">
            <Label for="message">Management Plan</Label>
            <Textarea placeholder="Type your message here." />
          </div>
          <div class="grid gap-1.5 mt-5">
            <Label for="message">Additional Work Needed </Label>
            <Textarea
              placeholder="Type your message here."
              rows="1"
              v-model="work_order.additional_work_need"
            />
          </div>
          <div class="grid gap-1.5 mt-5">
            <Label for="message">Closing Comments</Label>
            <Textarea placeholder="Type your message here." rows="1" />
          </div>
          <div class="grid gap-1.5 mt-5 mb-5">
            <Label for="message">Vendor Notes</Label>
            <Textarea placeholder="Type your message here." />
          </div>
        </div>
      </div>
      <DialogFooter class="p-6 pt-0">
        <Button type="submit" variant="destructive"> Close Work Order </Button>
        <Button type="submit"> Save changes </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
