<script setup>
import { ref, computed, watch, watchEffect } from "vue";
import { router } from "@inertiajs/vue3";
import { Loader2, EllipsisVertical, Ellipsis } from "lucide-vue-next";
import { DateTime } from "luxon";
import { useToast } from "@/Components/ui/toast/use-toast";
const { toast } = useToast();

const props = defineProps({
  tasks: Object,
});

const emit = defineEmits(["update-task-status"]);

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

const option = ref("");

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

props.tasks?.forEach((task) => {
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
</script>

<template>
  <Card
    class="w-full p-2 mb-2"
    :class="
      task.status === 'completed'
        ? 'bg-secondary'
        : task.status === 'pending'
        ? 'bg-orange-300'
        : 'bg-primary text-white'
    "
    v-for="task in tasks"
    :key="task.id"
  >
    <div class="flex justify-between">
      <div class="flex flex-col gap-2 w-full">
        <div class="flex text-xs items-center gap-1">
          <Badge
            :class="
              task.status === 'pending'
                ? 'bg-red-500'
                : task.status === 'completed'
                ? 'bg-primary'
                : 'bg-white text-black'
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
            <AvatarImage :src="task.assigned_user?.profile_photo_url || 'default.jpg'" />
            <AvatarFallback></AvatarFallback>
          </Avatar>
          {{ task.assigned_user.name }}
        </p>
      </div>
      <div class="flex flex-col text-xs gap-1 justify-end" v-if="task.task.is_optional">
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
</template>
