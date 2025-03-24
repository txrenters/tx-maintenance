<script setup>
import { ref, watchEffect, watch } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import { Loader2, EllipsisVertical, Ellipsis } from "lucide-vue-next";
import { Avatar, AvatarImage, AvatarFallback } from "@/Components/ui/avatar";
import { DateTime } from "luxon";
import { useToast } from "@/Components/ui/toast/use-toast";
import TaskCard from "@/Components/TaskCard.vue";

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
        service_status_id.value = String(newValue);
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

const handleEmits = () => {
  emit("update-task-status");
};
</script>

<template>
  <div class="overflow-y-auto px-6 mb-6 w-full min-h-[300px]">
    <div class="flex justify-between items-center my-3">
      <p class="font-semibold uppercase text-xs mb-3">Task Details</p>
      <div class="" v-if="$page.props.auth.user.roles.includes('admin')">
        <Select
          :modelValue="String(service_status_id)"
          @update:modelValue="handleServiceStatusChange"
          :disabled="isLoading"
        >
          <SelectTrigger class="w-full">
            <SelectValue placeholder="Select an emergency" />
          </SelectTrigger>
          <SelectContent>
            <SelectGroup>
              <template v-for="status in service_status" :key="status.id">
                <SelectItem :value="String(status.id)"> {{ status.name }} </SelectItem>
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
      <TaskCard :tasks="workOrderTasks" @update-task-status="handleEmits" />
    </div>
  </div>
</template>
