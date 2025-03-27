<script setup>
import { ref, watch, onMounted, nextTick } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import { Loader2, EllipsisVertical, CalendarPlus } from "lucide-vue-next";
import { DateTime } from "luxon";
import { useToast } from "@/Components/ui/toast/use-toast";
const { toast } = useToast();

const props = defineProps({
  vendorServiceSchedules: Array,
  workOrderTenants: Array,
  workOrderVendors: Array,
  isLoading: Boolean,
  workOrder: Object,
});

const openService = ref(false);

const serviceScheduleForm = useForm({
  title: "",
  description: "",
  date: "",
  time: "",
  vendor_id: "",
  tenant_id: "",
  work_order_id: props.workOrder.id,
});
const emit = defineEmits(["fetch-schedule"]);

const formatDate = (date) => {
  if (!date) return "------";

  let parsedDate;

  if (typeof date === "string") {
    parsedDate = DateTime.fromISO(date, { zone: "utc" }).isValid
      ? DateTime.fromISO(date, { zone: "utc" })
      : DateTime.fromFormat(date, "yyyy-MM-dd HH:mm:ss", { zone: "utc" });
  } else if (date instanceof Date) {
    parsedDate = DateTime.fromJSDate(date);
  } else {
    return "Invalid Date";
  }

  return parsedDate.isValid
    ? parsedDate.toFormat("EEE, MMMM d, yyyy hh:mm a")
    : "Invalid Date";
};

const updateScheduleStatus = async (service_schedule_id, status) => {
  router.post(
    route("service_schedule.status.completed", service_schedule_id),
    { status: status },
    {
      preserveState: true,
      preserveScroll: true,
      onSuccess: () => {
        toast({
          title: "Success",
          description: "Service schedule has been set successfully!",
        });
        emit("fetch-schedule");
      },
      onError: () => {
        toast({
          variant: "destructive",
          title: "Uh oh! Something went wrong.",
          description: "There was a problem with your request. Please try again!",
        });
      },
    }
  );
};

const handleMeetingSubmit = () => {
  if (
    !serviceScheduleForm.title ||
    !serviceScheduleForm.date ||
    !serviceScheduleForm.time ||
    !serviceScheduleForm.work_order_id ||
    !serviceScheduleForm.vendor_id ||
    !serviceScheduleForm.tenant_id
  ) {
    toast({
      variant: "destructive",
      title: "Uh oh! Something went wrong.",
      description: "There was a problem with your request. Please try again!",
    });
    return;
  }
  serviceScheduleForm.post(route("work_order.service_schedule.create"), {
    preserveState: true,
    preserveScroll: true,
    onSuccess: () => {
      toast({
        title: "Success",
        description: "Service schedule has been set successfully!",
      });
      openService.value = false;
      serviceScheduleForm.reset();
      emit("fetch-schedule");
    },
    onError: () => {
      toast({
        variant: "destructive",
        title: "Uh oh! Something went wrong.",
        description: "There was a problem with your request. Please try again!",
      });
    },
  });
};
</script>

<template>
  <div class="overflow-y-auto px-6 w-full min-h-[300px]">
    <div class="flex justify-between items-center mb-3">
      <p class="font-semibold uppercase text-xs">Service Schedule - Vendor and Tenant</p>
      <Button
        :disabled="isLoading"
        size="icon"
        @click.prevent="openService = true"
        v-if="$page.props.auth.user.roles.includes('vendor')"
      >
        <CalendarPlus v-if="!isLoading" class="" />
        <Loader2 v-else class="w-4 h-4 animate-spin" />
      </Button>
    </div>
    <div class="flex" v-if="!isLoading && vendorServiceSchedules.length === 0">
      <p class="font-semibold">No scheduled service available</p>
    </div>
    <div class="mb-10" v-else>
      <Card
        class="w-full p-4 mb-2 bg-primary/95 text-white"
        v-for="schedule in vendorServiceSchedules"
        :key="schedule.id"
      >
        <div class="flex justify-between">
          <div class="flex flex-col w-full">
            <div class="flex text-xs items-center gap-1">
              <p>📅 Due {{ formatDate(schedule.scheduled_date) }}</p>
            </div>
            <p class="text-sm mt-2 font-semibold">{{ schedule.title }}</p>
            <p class="text-xs">{{ schedule.description }}</p>
          </div>
          <div
            v-if="
              schedule.status !== 'completed' ||
              $page.props.auth.user.roles.includes('vendor')
            "
          >
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
                  @click="() => updateScheduleStatus(schedule.id, 'completed')"
                >
                  Complete
                </DropdownMenuItem>
                <DropdownMenuItem
                  class="cursor-pointer hover:bg-secondary"
                  @click="() => updateScheduleStatus(schedule.id, 'cancelled')"
                >
                  Cancel
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        </div>
        <div class="flex justify-between mt-2 items-center">
          <div class="flex gap-3">
            <div class="flex flex-col text-xs gap-1">
              <p>Tenant</p>
              <p class="flex gap-1 items-center">
                <Avatar class="w-5 h-5">
                  <AvatarImage
                    :src="schedule.tenant.user?.profile_photo_url || 'default.jpg'"
                  />
                  <AvatarFallback></AvatarFallback>
                </Avatar>
                {{ schedule.tenant.first_name }} {{ schedule.tenant.last_name }}
              </p>
            </div>
            <div class="flex flex-col text-xs gap-1">
              <p>Vendor</p>
              <p class="flex gap-1 items-center">
                <Avatar class="w-5 h-5">
                  <AvatarImage
                    :src="schedule.vendor.user?.profile_photo_url || 'default.jpg'"
                  />
                  <AvatarFallback></AvatarFallback>
                </Avatar>
                {{ schedule.vendor.name }}
              </p>
            </div>
          </div>
          <div>
            <Badge
              :class="schedule.status === 'cancelled' ? 'bg-red-500' : 'bg-green-500'"
            >
              {{ schedule.status }}
            </Badge>
          </div>
        </div>
      </Card>
    </div>
  </div>

  <Dialog v-model:open="openService">
    <DialogContent
      class="sm:max-w-[500px] grid-rows-[auto_minmax(0,1fr)_auto] p-0 max-h-[95dvh]"
    >
      <DialogHeader class="p-6 pb-0 text-left">
        <DialogTitle> Service Schedule </DialogTitle>
        <DialogDescription>
          Fill-in the required fields and click submit.</DialogDescription
        >
      </DialogHeader>
      <Separator />
      <div
        class="flex flex-col flex-nowrap space-x-2 overflow-x-auto scrollbar-hide px-4"
      >
        <div class="flex gap-4 mb-4">
          <div class="w-full">
            <Label>Tenant</Label>
            <Select v-model="serviceScheduleForm.tenant_id" class="w-full">
              <SelectTrigger class="w-full">
                <SelectValue placeholder="Select a tenant" />
              </SelectTrigger>
              <SelectContent>
                <SelectGroup>
                  <template v-for="tenant in workOrderTenants" :key="tenant.id">
                    <SelectItem :value="String(tenant.id)">
                      {{ tenant.first_name }} {{ tenant.last_name }}
                    </SelectItem>
                  </template>
                </SelectGroup>
              </SelectContent>
            </Select>
          </div>
          <div class="w-full">
            <Label>Vendor</Label>

            <Select v-model="serviceScheduleForm.vendor_id">
              <SelectTrigger class="w-full">
                <SelectValue placeholder="Select a tenant" />
              </SelectTrigger>
              <SelectContent>
                <SelectGroup>
                  <template v-for="vendor in workOrderVendors" :key="vendor.id">
                    <SelectItem :value="String(vendor.id)" :selected="vendor.id">
                      {{ vendor.name }}
                    </SelectItem>
                  </template>
                </SelectGroup>
              </SelectContent>
            </Select>
          </div>
        </div>
        <div class="mb-4">
          <Label>Title</Label>
          <Input
            type="text"
            class="mt-1"
            placeholder="Title"
            v-model="serviceScheduleForm.title"
          />
        </div>
        <div class="flex gap-4 mb-4">
          <div>
            <Label>Select Date</Label>
            <Input
              type="date"
              class="mt-1"
              placeholder="Type the title"
              v-model="serviceScheduleForm.date"
            />
          </div>
          <div>
            <Label>Select Time</Label>
            <Input
              type="time"
              class="mt-1"
              placeholder="Type the title"
              v-model="serviceScheduleForm.time"
            />
          </div>
        </div>
        <div class="mb-4">
          <Label>Description</Label>
          <Textarea
            type="text"
            class="mt-1"
            placeholder="Type the purpose..."
            v-model="serviceScheduleForm.description"
          />
        </div>
      </div>
      <DialogFooter class="p-6 pt-0">
        <Button @click="openService = false" variant="destructive">Cancel</Button>

        <Button
          type="submit"
          :disabled="serviceScheduleForm.processing"
          @click.prevent="handleMeetingSubmit"
        >
          <Loader2 v-if="serviceScheduleForm.processing" class="w-4 h-4 animate-spin" />
          Submit
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
