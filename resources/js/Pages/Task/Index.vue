<script setup>
import { computed } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import TaskCard from "@/Components/TaskCard.vue";
import { usePoll } from "@inertiajs/vue3";

defineOptions({ layout: AppLayout });

const props = defineProps({
  title: String,
  tasks: Object,
});

const tasksDueToday = computed(() => {
  const today = new Date().toISOString().split("T")[0];

  return props.tasks.filter(
    (task) => task.status !== "completed" && task.due_date === today
  );
});

const taskPastDue = computed(() => {
  const today = new Date().toISOString().split("T")[0];

  return props.tasks.filter(
    (task) => task.status !== "completed" && task.due_date < today
  );
});

const taskNotDue = computed(() => {
  const today = new Date().toISOString().split("T")[0];

  return props.tasks.filter(
    (task) => task.status !== "completed" && task.due_date > today
  );
});

const completedTasks = computed(() => {
  return props.tasks.filter((task) => task.status === "completed");
});

usePoll(10000);
</script>

<template>
  <Head :title="title" />
  <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
    <div class="flex-1">
      <h2 class="text-xl font-bold mb-2">Past Due ({{ taskPastDue.length }})</h2>
      <ScrollArea class="h-auto md:h-[85vh] border-t pt-2 mb-5">
        <TaskCard :tasks="taskPastDue" />
        <ScrollBar orientation="vertical" />
      </ScrollArea>
    </div>
    <div class="flex-1">
      <h2 class="text-xl font-bold mb-2">Due Today ({{ tasksDueToday.length }})</h2>
      <ScrollArea class="h-auto md:h-[85vh] border-t pt-2 mb-5">
        <TaskCard :tasks="tasksDueToday" />
        <ScrollBar orientation="vertical" />
      </ScrollArea>
    </div>
    <div class="flex-1">
      <h2 class="text-xl font-bold mb-2">Pending ({{ taskNotDue.length }})</h2>
      <ScrollArea class="h-auto md:h-[85vh] border-t pt-2 mb-5">
        <TaskCard :tasks="taskNotDue" />
        <ScrollBar orientation="vertical" />
      </ScrollArea>
    </div>
    <div class="flex-1">
      <h2 class="text-xl font-bold mb-2">Completed ({{ completedTasks.length }})</h2>
      <ScrollArea class="h-auto md:h-[85vh] border-t pt-2 mb-5">
        <TaskCard :tasks="completedTasks" />
        <ScrollBar orientation="vertical" />
      </ScrollArea>
    </div>
  </div>
</template>
