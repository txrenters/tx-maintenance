<script setup>
import { reactive, onMounted } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import { useToast } from "@/Components/ui/toast/use-toast";
import { useForm } from "@inertiajs/vue3";

const { toast } = useToast();

defineOptions({ layout: AppLayout });

const props = defineProps({
  title: String,
  template: Object,
  statuses: Object,
  autoCompleteTriggers: { type: Array, default: () => [] },
});

const triggerLabel = (value) =>
  props.autoCompleteTriggers.find((trigger) => trigger.value === value)?.label ??
  "Never";
</script>

<template>
  <Head :title="title" />
  <Card>
    <CardHeader>
      <CardTitle>{{ template.name }}</CardTitle>
      <CardDescription> {{ template.description }}</CardDescription>
    </CardHeader>
    <CardContent>
      <div class="flex">
        <div class="w-full">
          <Label>Current Service Status </Label>
          <p>{{ template.current_service_status.name }}</p>
        </div>
        <div class="w-full">
          <Label>Current Service Status type </Label>
          <p>
            {{
              template.is_current_service_status_emergency ? "Emergency" : "Non-emergency"
            }}
          </p>
        </div>
      </div>
      <div class="flex mt-3">
        <div class="w-full">
          <Label>Next Service Status </Label>
          <p>{{ template.next_service_status.name }}</p>
        </div>
        <div class="w-full">
          <Label>Next Service Status type </Label>
          <p>
            {{
              template.is_next_service_status_emergency ? "Emergency" : "Non-emergency"
            }}
          </p>
        </div>
      </div>
    </CardContent>
  </Card>

  <Card>
    <CardHeader>
      <CardTitle>Task List</CardTitle>
    </CardHeader>
    <CardContent>
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Task Name </TableHead>
            <TableHead class="hidden md:table-cell"> Mandatory </TableHead>
            <TableHead class="hidden md:table-cell"> Task For </TableHead>
            <TableHead class="hidden md:table-cell"> Due Date </TableHead>
            <TableHead class="hidden md:table-cell"> Auto-complete when </TableHead>
            <TableHead class="hidden md:table-cell"> Option </TableHead>
            <TableHead class="hidden md:table-cell"> Task Done Service Status </TableHead>
            <TableHead class="hidden md:table-cell"> Task Done Status type </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-for="task in template.tasks" :key="task.id">
            <TableCell class="font-medium">
              {{ task.name }}
            </TableCell>
            <TableCell class="hidden md:table-cell">
              {{ task.is_mandatory ? "Yes" : "No" }}
            </TableCell>
            <TableCell class="hidden md:table-cell">
              {{ task.type }}
            </TableCell>
            <TableCell class="hidden md:table-cell">
              {{ task.due_date }}
            </TableCell>
            <TableCell class="hidden md:table-cell">
              {{ triggerLabel(task.auto_complete_trigger) }}
            </TableCell>
            <TableCell class="hidden md:table-cell">
              <template v-if="task.is_optional">
                <p
                  v-for="detail in task.task_details"
                  :key="detail.id"
                  class="flex flex-col"
                >
                  {{ detail.task_for }}
                </p>
              </template>
              <p v-else>n/a</p>
            </TableCell>
            <TableCell class="hidden md:table-cell">
              <template v-if="task.is_optional">
                <p
                  v-for="detail in task.task_details"
                  :key="detail.id"
                  class="flex flex-col"
                >
                  {{ detail.task_service_status.name }}
                </p>
              </template>
              <p v-else>{{ task.next_service_status.name }}</p>
            </TableCell>
            <TableCell class="hidden md:table-cell">
              <template v-if="task.is_optional">
                <p
                  v-for="detail in task.task_details"
                  :key="detail.id"
                  class="flex flex-col"
                >
                  {{ detail.is_emergency ? "Emergency" : "Non-emergency" }}
                </p>
              </template>
              <p v-else>{{ task.is_emergency ? "Emergency" : "Non-emergency" }}</p>
            </TableCell>
          </TableRow>
          <TableRow v-if="template.tasks.length === 0">
            <TableCell colspan="4">No tasks found!</TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </CardContent>
  </Card>
</template>
