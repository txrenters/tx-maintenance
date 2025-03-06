<script setup>
import { onMounted, reactive, ref } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import { useForm } from "@inertiajs/vue3";
import { useToast } from "@/Components/ui/toast/use-toast";
import { ScrollArea, ScrollBar } from "@/Components/ui/scroll-area";
import { AspectRatio } from "@/components/ui/aspect-ratio";

import {
  Sheet,
  SheetClose,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@/Components/ui/sheet";

const { toast } = useToast();

defineOptions({ layout: AppLayout });

const props = defineProps({
  title: String,
  service_status: Object,
  vendors: Object,
  filter: Array,
});

const url = ref(route("work_orders.index"));
const search = ref(props.filter.search);

const openWorkOrder = ref(false);

import { DateTime } from "luxon";

const formatDate = (date) => {
  return date
    ? DateTime.fromFormat(date, "yyyy-MM-dd HH:mm:ss").toFormat("MMM dd, yyyy hh:mm a")
    : "Unknown Date";
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
  authorization_to_enter: "",
  source: "",
  estimated_cost: "",
  estimated_time: "",
  additional_work_need: "",
  last_modified: "",
  closing_comments: "",
  service_status: "",
  zone: "",
  created_date: "",
  end_date: "",
  management_plan: "",
  description: "",
  vendor_notes: "",
});

const handleWorkOrder = (order) => {
  openWorkOrder.value = true;
  work_order.work_order_no = order.work_order_no;
  work_order.description = order.description;
  work_order.location = order.location;
  work_order.owner = order.owner_full_name;
  work_order.requested = order.requested_by;
  console.log(order.vendors);
};
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
        <div class="text-center font-semibold text-gray-700">
          <!-- Status Name -->
          <div class="h-16 flex items-center justify-center border p-3 text-sm uppercase">
            <p>{{ status.name }}</p>
          </div>

          <!-- Work Orders List -->
          <ScrollArea class="h-[600px] overflow-y-auto border-t pt-2 mb-5">
            <div
              @click="handleWorkOrder(work_order)"
              v-for="work_order in status.work_orders"
              :key="work_order.id"
              class="mb-2 rounded-lg p-4 text-white cursor-pointer shadow-md"
              :class="{
                'bg-red-500': work_order.priority === 'High',
                'bg-primary': work_order.priority === 'Medium',
                'bg-green-400': work_order.priority === 'Low',
                'bg-gray-400': work_order.service_status.name === 'Closed',
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
              <div class="flex justify-end mt-4 items-center gap-2">
                <p class="text-sm text-gray-100">
                  {{ work_order.owner?.first_name }} {{ work_order.owner?.last_name }}
                </p>
                <Avatar class="w-5 h-5">
                  <AvatarImage :src="work_order?.owner?.user?.profile_photo_url" />
                  <AvatarFallback>
                    {{ work_order.owner?.first_name?.charAt(0)
                    }}{{ work_order.owner?.last_name?.charAt(0) }}
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

  <!-- Work Order Detail Modal -->
  <Sheet v-model:open="openWorkOrder">
    <SheetContent class="sm:w-full xl:max-w-3xl p-0">
      <SheetHeader class="p-4">
        <SheetTitle class="text-3xl text-left"
          >#{{ work_order.work_order_no }}</SheetTitle
        >
        <SheetDescription class="text-left">
          <p>Description:</p>
          <p class="font-semibold">
            {{ work_order.description }}
          </p>
        </SheetDescription>
      </SheetHeader>

      <div class="p-1 flex flex-col justify-end items-end absolute text-right right-0">
        <Button variant="outline" size="icon">
          <ChevronRight class="w-4 h-4" />
        </Button>
        <Button variant="outline" size="icon">
          <ChevronRight class="w-4 h-4" />
        </Button>
        <Button variant="outline" size="icon">
          <ChevronRight class="w-4 h-4" />
        </Button>
        <Button variant="outline" size="icon">
          <ChevronRight class="w-4 h-4" />
        </Button>
        <Button variant="outline" size="icon">
          <ChevronRight class="w-4 h-4" />
        </Button>
      </div>
      <div class="pl-4 pr-10 text-gray text-sm text-muted-foreground text-left">
        <div class="grid grid-cols-2">
          <div class="w-full">
            <div class="mb-2">
              <p>Vendor:</p>
              <p class="font-semibold">
                {{ work_order.location }}
              </p>
            </div>
            <div class="mb-2">
              <p>Location:</p>
              <p class="font-semibold">
                {{ work_order.location }}
              </p>
            </div>
          </div>
          <div class="w-full">
            <div class="mb-2">
              <p>Owner:</p>
              <p class="font-semibold">
                {{ work_order.owner }}
              </p>
            </div>
            <div class="mb-2">
              <p>Requested:</p>
              <p class="font-semibold">
                {{ work_order.requested }}
              </p>
            </div>
          </div>
        </div>
      </div>
    </SheetContent>
  </Sheet>
</template>

<style scoped>
.sheet {
  color: red !important;
}
</style>
