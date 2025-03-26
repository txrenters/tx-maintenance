<script setup>
import { ref, computed, watch, watchEffect } from "vue";
import { router, useForm } from "@inertiajs/vue3";
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

const updateTaskStatus = async (taskId, status, option) => {
  router.post(
    route("api.work_order.task.change", taskId),
    { status: status, option: option },
    {
      preserveState: true,
      preserveScroll: true,
      onSuccess: () => {
        toast({
          title: "Success",
          description: "Task completed successfully!",
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

const openEditModal = ref(false);

const editTaskForm = useForm({
  id: "",
  description: "",
  due_date: "",
  status: "",
});

const handleEditForm = (task) => {
  if (task.status === "completed") {
    return;
  }
  openEditModal.value = true;
  editTaskForm.id = task.id;
  editTaskForm.description = task.description;
  editTaskForm.due_date = task.due_date;
};

const EditFormSubmit = () => {
  editTaskForm.patch(route("tasks.update", editTaskForm.id), {
    preserveState: true,
    preserveScroll: true,
    onSuccess: () => {
      toast({
        title: "Success",
        description: "Task has been changed successfully!",
      });
      emit("update-task-status"); // use this to notify parent component that I need the new task to be fetch
      openEditModal.value = false;
    },
    onError: () => {
      toast({
        variant: "destructive",
        title: "Uh oh! Something went wrong.",
        description: "There was a problem with your request. Please try again!",
      });
      openEditModal.value = false;
    },
  });
};

const checkDueTask = (task) => {
  const today = new Date().toISOString().split("T")[0];

  let bgColor = "green"; // Default color if all tasks are upcoming

  if (task.due_date === today) {
    bgColor = "blue";
  } else if (task.due_date < today) {
    bgColor = "red";
  }

  if (task.status === "completed") {
    bgColor = "completed";
  }

  return bgColor;
};
</script>

<template>
  <div v-for="task in tasks" :key="task.id" class="hover:bg-opacity-50">
    <Card
      class="w-full p-2 mb-2 hover:shadow-lg transition-all"
      :class="{
        'bg-secondary': checkDueTask(task) === 'completed',
        'bg-red-500 text-white': checkDueTask(task) === 'red',
        'bg-primary text-white': checkDueTask(task) === 'blue',
        'bg-green-500 text-white': checkDueTask(task) === 'green',
      }"
    >
      <div class="flex justify-between">
        <div class="flex flex-col gap-2 w-full" @click="handleEditForm(task)">
          <div class="flex text-xs items-center gap-1">
            <Badge
              :class="
                task.status === 'pending' ? 'bg-secondary text-black' : 'bg-green-500'
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
          <p class="text-sm">{{ task.description }}</p>
        </div>
        <div v-if="task.status !== 'completed'" class="mr-2">
          <Checkbox
            class="bg-white"
            @click="() => updateTaskStatus(task.id, 'completed', task.option)"
          />
        </div>
      </div>
      <div v-if="!task.task?.type">
        <div class="flex flex-col text-xs gap-1" @click="handleEditForm(task)">
          <p>Assigned:</p>
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
      <div class="flex justify-between mt-2 items-end mr-2" v-else>
        <div class="flex flex-col text-xs gap-1" @click="handleEditForm(task)">
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
        <div class="flex flex-col text-xs gap-1 justify-end" v-if="task.task.is_optional">
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
        <div v-else>
          <div class="flex justify-end">
            <p class="text-xs">
              Done:
              {{ task.task.next_service_status.name }}
            </p>
          </div>
        </div>
      </div>
    </Card>
  </div>
  <Dialog v-model:open="openEditModal">
    <DialogContent
      class="sm:max-w-[500px] grid-rows-[auto_minmax(0,1fr)_auto] p-0 max-h-[95dvh]"
    >
      <DialogHeader class="p-6 pb-0 text-left">
        <DialogTitle> Edit Task </DialogTitle>
        <DialogDescription>
          Edit task details and then click save if done.</DialogDescription
        >
      </DialogHeader>
      <Separator />
      <div class="flex flex-col flex-nowrap overflow-x-auto scrollbar-hide px-6">
        <div class="mb-3">
          <Label>Due Date</Label>
          <Input
            type="date"
            placeholder="Enter invoice amount"
            v-model="editTaskForm.due_date"
          />
        </div>
        <div class="mb-3">
          <Label>Description</Label>
          <Textarea class="mt-1" v-model="editTaskForm.description" />
        </div>
      </div>
      <DialogFooter class="p-6 pt-0">
        <Button
          type="submit"
          :disabled="editTaskForm.processing"
          @click.prevent="EditFormSubmit"
        >
          <Loader2 v-if="editTaskForm.processing" class="w-4 h-4 animate-spin" />
          Save Changes
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
