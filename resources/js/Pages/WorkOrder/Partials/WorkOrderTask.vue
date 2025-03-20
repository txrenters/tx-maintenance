<script setup>
import { ref, watchEffect, watch } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import { Loader2, EllipsisVertical, Ellipsis } from "lucide-vue-next";
import { Avatar, AvatarImage, AvatarFallback } from "@/Components/ui/avatar";
import { DateTime } from "luxon";
import { useToast } from "@/Components/ui/toast/use-toast";

const props = defineProps({
  workOrderTasks: Array,
  service_status: Array,
  isEmergency: String,
  workOrder: Object,
  isLoading: Boolean,
  handleTaskStatusChange: Function,
});
const { toast } = useToast();

const option = ref("");

const emit = defineEmits(["update-task-status"]);

const service_status_id = ref(props.workOrder.service_status_id);

const handleServiceStatusChange = async (newValue) => {
  props.isLoading = true;
  router.post(
    route("api.work_order.service_status_change", props.workOrder),
    { is_emergency: props.isEmergency, service_status_id: newValue },
    {
      preserveState: true,
      preserveScroll: true,
      onSuccess: () => {
        toast({
          title: "Success",
          description: "Task has been changed successfully!",
        });

        emit("update-task-status"); // use this to notify parent component that I need the new task to be fetch
        service_status_id.value = newValue;
        props.isLoading = false;
      },
      onError: () => {
        toast({
          variant: "destructive",
          title: "Uh oh! Something went wrong.",
          description: "There was a problem with your request. Please try again!",
        });
        props.isLoading = false;
      },
    }
  );
};

const updateTaskStatus = async (taskId, status) => {
  router.post(
    route("api.work_order.task.change", taskId),
    { status: status, option: option.value },
    {
      preserveState: true,
      preserveScroll: true,
      onSuccess: () => {
        toast({
          title: "Success",
          description: "Task has been changed successfully!",
        });

        emit("update-task-status"); // use this to notify parent component that I need the new task to be fetch
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
// Ensure every task has an 'option' property to avoid undefined errors
props.workOrderTasks?.forEach((task) => {
  if (!("option" in task)) {
    task.option = "No"; // Set default value
  }
});

// Watch for changes in task.option and emit updates dynamically
watchEffect(() => {
  props.workOrderTasks?.forEach((task) => {
    watch(
      () => task.option,
      (newValue) => {
        option.value = newValue;
      },
      { deep: true }
    );
  });
});
const formatDate = (date) => {
  if (!date) return "------";

  let parsedDate;

  if (typeof date === "string") {
    if (date.includes("T")) {
      // Handle ISO format (2025-03-06T17:41:20.000000Z)
      parsedDate = DateTime.fromISO(date, { zone: "utc" });
    } else {
      // Handle non-ISO format (2025-03-06 23:10:06)
      parsedDate = DateTime.fromFormat(date, "yyyy-MM-dd", { zone: "utc" });
    }
  } else if (date instanceof Date) {
    parsedDate = DateTime.fromJSDate(date);
  } else {
    return "Invalid Date";
  }

  return parsedDate.isValid ? parsedDate.toFormat("MM/dd/yyyy") : "Invalid Date";
};
</script>

<template>
  <div class="overflow-y-auto px-6 mb-6 w-full min-h-[300px]">
    <div class="flex justify-between items-center my-3">
      <p class="font-semibold uppercase text-xs mb-3">Task Details</p>
      <div class="">
        <Select
          :modelValue="service_status_id"
          @update:modelValue="handleServiceStatusChange"
          :disabled="isLoading"
        >
          <SelectTrigger class="w-full">
            <SelectValue placeholder="Select an emergency" />
          </SelectTrigger>
          <SelectContent>
            <SelectGroup>
              <template v-for="status in service_status" :key="status.id">
                <SelectItem :value="status.id"> {{ status.name }} </SelectItem>
              </template>
            </SelectGroup>
          </SelectContent>
        </Select>
      </div>
    </div>
    <div class="flex justify-center" v-if="isLoading">
      <Loader2 class="w-12 h-12 animate-spin text-primary" />
    </div>
    <div class="flex" v-if="!isLoading && workOrderTasks.length === 0">
      <p class="font-semibold">No tasks available</p>
    </div>
    <div v-else>
      <Card
        class="w-full p-2 mb-2 hover:bg-secondary"
        :class="task.status === 'completed' ? 'bg-secondary' : ''"
        v-for="task in workOrderTasks"
        :key="task.id"
      >
        <div class="flex justify-between">
          <div class="flex flex-col gap-2 w-full">
            <div class="flex text-xs items-center gap-1">
              <Badge
                :variant="
                  task.status === 'pending'
                    ? 'secondary'
                    : task.status === 'completed'
                    ? 'destructive'
                    : ''
                "
              >
                {{ task.status }}
              </Badge>
              <p>
                {{
                  task.status === "completed"
                    ? formatDate(task.updated_at)
                    : formatDate(task.due_date) ?? "No due date"
                }}
              </p>
            </div>
            <p class="text-sm">{{ task.task.name }}</p>
          </div>
          <div v-if="task.status !== 'completed'">
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
                  @click="() => updateTaskStatus(task.id, 'pending')"
                >
                  Pending
                </DropdownMenuItem>
                <DropdownMenuItem
                  class="cursor-pointer hover:bg-secondary"
                  @click="() => updateTaskStatus(task.id, 'processing')"
                >
                  Processing
                </DropdownMenuItem>
                <DropdownMenuItem
                  class="cursor-pointer hover:bg-secondary"
                  @click="() => updateTaskStatus(task.id, 'completed')"
                >
                  Complete
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        </div>
        <div class="flex justify-between mt-2">
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
          <div
            class="flex flex-col text-xs gap-1 justify-end"
            v-if="task.task.is_optional"
          >
            <!-- {{ task.task.task_details.task_service_status }} -->

            <div class="flex justify-end">
              <p class="">
                Selected:
                <span v-if="task.status === 'completed'">{{ task.option ?? "No" }}</span>
              </p>
              <select v-model="task.option" v-if="task.status !== 'completed'">
                <option selected value="Yes">Yes</option>
                <option value="No">No</option>
              </select>
            </div>
            <p v-if="task.option">
              Done:
              <span v-for="detail in task.task.task_details" :key="detail.id">
                <span v-if="detail.task_for === task.option">{{
                  detail.task_service_status.name
                }}</span>
              </span>
            </p>
          </div>
        </div>
      </Card>
    </div>
  </div>
</template>
