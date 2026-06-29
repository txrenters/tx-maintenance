<script setup>
import { reactive, ref } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import { useToast } from "@/Components/ui/toast/use-toast";
import { useForm } from "@inertiajs/vue3";

const { toast } = useToast();

defineOptions({ layout: AppLayout });

const props = defineProps({
  title: String,
  statuses: Object,
  assignableUsers: { type: Array, default: () => [] },
});

const form = useForm({
  name: "",
  description: "",
  current_service_status_id: "",
  is_current_service_status_emergency: "Non-emergency",
  next_service_status_id: "",
  is_next_service_status_emergency: "Non-emergency",
  tasks: [],
});

// Reactive tasks array (used for local state)
const tasks = reactive([
  {
    name: "",
    is_option: "No",
    is_mandatory: "Yes",
    task_for: "Woc",
    assigned_user_id: "",
    due_date: "same day",
    task_service_status_id: "18",
    is_task_service_status_emergency: "Non-emergency",
    task_details: [
      {
        task_for: "Yes",
        task_service_status_id: "",
        is_task_service_status_emergency: "",
      },
      {
        task_for: "No",
        task_service_status_id: "",
        is_task_service_status_emergency: "",
      },
    ],
  },
]);

// Function to add a new task
const addTask = () => {
  const newTask = {
    name: "",
    is_option: "No",
    is_mandatory: "Yes",
    task_for: "Woc",
    assigned_user_id: "",
    due_date: "same day",
    task_service_status_id: "18",
    is_task_service_status_emergency: "Non-emergency",
    task_details: [
      {
        task_for: "Yes",
        task_service_status_id: "",
        is_task_service_status_emergency: "Non-emergency",
      },
      {
        task_for: "No",
        task_service_status_id: "18",
        is_task_service_status_emergency: "Non-emergency",
      },
    ],
  };
  tasks.push(newTask);
  form.tasks = [...tasks]; // Ensure form.tasks stays in sync
};

const removeTask = (index) => {
  if (tasks.length > 1) {
    tasks.splice(index, 1);
  }
};

const submitForm = () => {
  form.tasks = tasks;
  form.post(route("task_templates.store"), {
    preserveState: true,
    preserveScroll: true,
    onSuccess: () => {
      form.reset();
      toast({
        title: "Success",
        description: "Task template has been created successfully!",
      });
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
  <Head :title="title" />
  <Card>
    <CardHeader> {{ title }} </CardHeader>
    <CardContent>
      <form @submit.prevent="submitForm">
        <div>
          <Label for="name" class="mb-2">Name</Label>
          <Input id="name" type="text" v-model="form.name" />
          <Label class="mt-1 text-destructive text-xs">{{ form.errors.name }}</Label>
        </div>
        <div class="flex gap-3 mt-5 flex-col sm:flex-row">
          <div class="w-full flex flex-col gap-1">
            <Label for="roles" class="mb-2">Current Service Status</Label>
            <Select class="mt-2" v-model="form.current_service_status_id">
              <SelectTrigger>
                <SelectValue placeholder="Select a service status" />
              </SelectTrigger>
              <SelectContent>
                <SelectGroup>
                  <SelectLabel>Current Service Status</SelectLabel>
                  <SelectItem
                    v-for="status in statuses"
                    :value="String(status.id)"
                    :key="status.id"
                  >
                    {{ status.name }}
                  </SelectItem>
                </SelectGroup>
              </SelectContent>
            </Select>
            <Label class="mt-1 text-destructive text-xs">{{
              form.errors.current_service_status_id
            }}</Label>
          </div>
          <div class="w-full flex flex-col gap-1">
            <Label for="roles" class="mb-2">Current Service Status Emergency</Label>
            <Select class="mt-2" v-model="form.is_current_service_status_emergency">
              <SelectTrigger>
                <SelectValue placeholder="Select a emergency status" />
              </SelectTrigger>
              <SelectContent>
                <SelectGroup>
                  <SelectLabel>Is Service Status Emergency</SelectLabel>
                  <SelectItem value="Emergency"> Emergency </SelectItem>
                  <SelectItem value="Non-emergency"> Non-emergency </SelectItem>
                </SelectGroup>
              </SelectContent>
            </Select>
            <Label class="mt-1 text-destructive text-xs">{{
              form.errors.is_current_service_status_emergency
            }}</Label>
          </div>
        </div>

        <div class="flex flex-col sm:flex-row mt-5 gap-4">
          <div class="w-full flex flex-col gap-1">
            <Label for="roles" class="mb-2">Next Service Status</Label>
            <Select class="mt-2" v-model="form.next_service_status_id">
              <SelectTrigger>
                <SelectValue placeholder="Select a service status" />
              </SelectTrigger>
              <SelectContent>
                <SelectGroup>
                  <SelectLabel>Next Service Status</SelectLabel>
                  <SelectItem
                    v-for="status in statuses"
                    :value="String(status.id)"
                    :key="status.id"
                  >
                    {{ status.name }}
                  </SelectItem>
                </SelectGroup>
              </SelectContent>
            </Select>
            <Label class="mt-1 text-destructive text-xs">{{
              form.errors.next_service_status_id
            }}</Label>
          </div>
          <div class="w-full flex flex-col gap-1">
            <Label for="roles" class="mb-2">Next Service Status Emergency</Label>
            <Select class="mt-2" v-model="form.is_next_service_status_emergency">
              <SelectTrigger>
                <SelectValue placeholder="Select a emergency status" />
              </SelectTrigger>
              <SelectContent>
                <SelectGroup>
                  <SelectLabel>Is Service Status Emergency</SelectLabel>
                  <SelectItem value="Emergency"> Emergency </SelectItem>
                  <SelectItem value="Non-emergency"> Non-emergency </SelectItem>
                </SelectGroup>
              </SelectContent>
            </Select>
            <Label class="mt-1 text-destructive text-xs">{{
              form.errors.is_next_service_status_emergency
            }}</Label>
          </div>
        </div>

        <div class="mt-3">
          <Label for="description" class="mb-2">Description</Label>
          <Textarea id="description" v-model="form.description" class="mt-2" />
          <Label class="mt-1 text-destructive text-xs">{{
            form.errors.description
          }}</Label>
        </div>

        <div class="flex justify-between my-5">
          <p class="font-semibold">Task List</p>
          <Button type="button" @click="addTask">
            <PlusCircle class="h-3.5 w-3.5" />
          </Button>
        </div>
        <div v-for="(task, index) in tasks" :key="index" class="border p-4 mt-2">
          <div class="flex justify-end">
            <Button
              type="button"
              v-if="index > 0"
              @click="removeTask(index)"
              variant="destructive"
            >
              <Delete />
            </Button>
          </div>
          <div class="flex flex-col">
            <Label for="task" class="mb-2">Task Name</Label>
            <Input id="task" type="text" v-model="task.name" />
            <Label class="mt-1 text-destructive text-xs">
              {{ form.errors[`tasks.${index}.name`] }}
            </Label>
          </div>
          <div class="mt-4 flex flex-col sm:flex-row gap-4">
            <div class="mt-2 w-full flex flex-col gap-2">
              <Label for="task" class="mb-3">Option</Label>
              <div class="flex gap-2">
                <Label>No</Label>
                <Switch
                  :checked="task.is_option === 'Yes'"
                  @update:checked="(val) => (task.is_option = val ? 'Yes' : 'No')"
                />
                <Label>Yes</Label>
              </div>
            </div>
            <div class="mt-2 w-full flex flex-col gap-2">
              <Label for="task" class="mb-3">Mandatory</Label>
              <div class="flex gap-2">
                <Label>No</Label>
                <Switch
                  v-model="task.is_mandatory"
                  :checked="task.is_mandatory === 'Yes'"
                  @update:checked="(val) => (task.is_mandatory = val ? 'Yes' : 'No')"
                />
                <Label>Yes</Label>
              </div>
            </div>
            <div class="mt-2 w-full flex flex-col gap-2">
              <Label for="task_for">Task For</Label>
              <Select v-model="task.task_for" class="mt-2">
                <SelectTrigger>
                  <SelectValue placeholder="Select a task assign for" />
                </SelectTrigger>
                <SelectContent>
                  <SelectGroup>
                    <SelectLabel>Task Assign For</SelectLabel>
                    <SelectItem value="Woc"> WOC </SelectItem>
                    <SelectItem value="Vendor"> Vendor </SelectItem>
                  </SelectGroup>
                </SelectContent>
              </Select>
              <Label class="mt-1 text-destructive text-xs">
                {{ form.errors[`tasks.${index}.task_for`] }}
              </Label>
            </div>
            <div
              v-if="task.task_for === 'Woc'"
              class="mt-2 w-full flex flex-col gap-2"
            >
              <Label>Assign to specific user</Label>
              <Select
                :modelValue="task.assigned_user_id || '__auto__'"
                @update:modelValue="
                  (v) => (task.assigned_user_id = v === '__auto__' ? '' : v)
                "
              >
                <SelectTrigger>
                  <SelectValue placeholder="Auto-assign (first WOC)" />
                </SelectTrigger>
                <SelectContent>
                  <SelectGroup>
                    <SelectLabel>WOC user</SelectLabel>
                    <SelectItem value="__auto__">
                      Auto-assign (first WOC)
                    </SelectItem>
                    <SelectItem
                      v-for="user in assignableUsers"
                      :key="user.id"
                      :value="String(user.id)"
                    >
                      {{ user.name }}
                    </SelectItem>
                  </SelectGroup>
                </SelectContent>
              </Select>
              <Label class="mt-1 text-destructive text-xs">
                {{ form.errors[`tasks.${index}.assigned_user_id`] }}
              </Label>
            </div>
            <div class="mt-2 w-full flex flex-col gap-2">
              <Label for="task_for">Due Dates</Label>
              <Select v-model="task.due_date" class="mt-2">
                <SelectTrigger>
                  <SelectValue placeholder="Select a due date" />
                </SelectTrigger>
                <SelectContent>
                  <SelectGroup>
                    <SelectLabel>Task due dates</SelectLabel>
                    <SelectItem value="same day">same day</SelectItem>
                    <SelectItem value="1 day">1 day</SelectItem>
                    <SelectItem value="2 days">2 days</SelectItem>
                    <SelectItem value="3 days">3 days</SelectItem>
                    <SelectItem value="4 days">4 days</SelectItem>
                    <SelectItem value="5 days">5 days</SelectItem>
                    <SelectItem value="6 days">6 days</SelectItem>
                    <SelectItem value="7 days">7 days</SelectItem>
                    <SelectItem value="8 days">8 days</SelectItem>
                    <SelectItem value="9 days">9 days</SelectItem>
                    <SelectItem value="10 days">10 days</SelectItem>
                    <SelectItem value="11 days">11 days</SelectItem>
                    <SelectItem value="12 days">12 days</SelectItem>
                    <SelectItem value="13 days">13 days</SelectItem>
                    <SelectItem value="14 days">14 days</SelectItem>
                    <SelectItem value="15 days">15 days</SelectItem>
                  </SelectGroup>
                </SelectContent>
              </Select>
              <Label class="mt-1 text-destructive text-xs">
                {{ form.errors[`tasks.${index}.due_date`] }}
              </Label>
            </div>
          </div>
          <div
            class="flex flex-col sm:flex-row gap-4 mt-6"
            v-if="task.is_option === 'No'"
          >
            <div class="w-full flex flex-col">
              <Label for="" class="mb-2">Task Service Status</Label>
              <Select class="mt-2" v-model="task.task_service_status_id">
                <SelectTrigger>
                  <SelectValue placeholder="Select a task status" />
                </SelectTrigger>
                <SelectContent>
                  <SelectGroup>
                    <SelectLabel>Task Service Status</SelectLabel>

                    <SelectItem
                      v-for="status in statuses"
                      :value="String(status.id)"
                      :key="status.id"
                    >
                      {{ status.name }}
                    </SelectItem>
                  </SelectGroup>
                </SelectContent>
              </Select>
              <Label class="mt-1 text-destructive text-xs">
                {{ form.errors[`tasks.${index}.task_service_status_id`] }}
              </Label>
            </div>
            <div class="w-full flex flex-col" v-if="task.is_option === 'No'">
              <Label for="" class="mb-2">Task Service Status Emergency</Label>
              <Select class="mt-2" v-model="task.is_task_service_status_emergency">
                <SelectTrigger>
                  <SelectValue placeholder="Select a task status" />
                </SelectTrigger>
                <SelectContent>
                  <SelectGroup>
                    <SelectLabel>Task Service Status Emergency</SelectLabel>
                    <SelectItem value="Emergency"> Emergency </SelectItem>
                    <SelectItem value="Non-emergency"> Non-emergency </SelectItem>
                  </SelectGroup>
                </SelectContent>
              </Select>
              <Label class="mt-1 text-destructive text-xs">
                {{ form.errors[`tasks.${index}.is_task_service_status_emergency`] }}
              </Label>
            </div>
          </div>
          <div
            class="flex flex-col sm:flex-row gap-4 mt-6"
            v-if="task.is_option === 'Yes'"
          >
            <div
              v-for="(detail, detail_index) in task.task_details"
              :key="detail_index"
              class="flex flex-wrap gap-4 sm:flex-row border p-4"
            >
              <div class="flex flex-col">
                <Label :for="`task-${detail_index}`" class="mb-2">For</Label>
                <Input
                  :id="`task-${detail_index}`"
                  type="text"
                  disabled
                  v-model="detail.task_for"
                />
                <Label class="mt-1 text-destructive text-xs">
                  {{
                    form.errors[`tasks.${index}.task_details.${detail_index}.task_for`]
                  }}
                </Label>
              </div>
              <div class="w-full flex flex-col">
                <Label for="" class="mb-2">Task Service Status</Label>
                <Select class="mt-2" v-model="detail.task_service_status_id">
                  <SelectTrigger>
                    <SelectValue placeholder="Select a task status" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectGroup>
                      <SelectLabel>Task Service Status</SelectLabel>

                      <SelectItem
                        v-for="status in statuses"
                        :value="String(status.id)"
                        :key="status.id"
                      >
                        {{ status.name }}
                      </SelectItem>
                    </SelectGroup>
                  </SelectContent>
                </Select>
                <Label class="mt-1 text-destructive text-xs">
                  {{
                    form.errors[
                      `tasks.${index}.task_details.${detail_index}.task_service_status_id`
                    ]
                  }}
                </Label>
              </div>
              <div class="w-full flex flex-col">
                <Label for="" class="mb-2">Task Service Status Emergency</Label>
                <Select class="mt-2" v-model="detail.is_task_service_status_emergency">
                  <SelectTrigger>
                    <SelectValue placeholder="Select a task status" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectGroup>
                      <SelectLabel>Task Service Status Emergency</SelectLabel>
                      <SelectItem value="Emergency"> Emergency </SelectItem>
                      <SelectItem value="Non-emergency"> Non-emergency </SelectItem>
                    </SelectGroup>
                  </SelectContent>
                </Select>
                <Label class="mt-1 text-destructive text-xs">
                  {{
                    form.errors[
                      `tasks.${index}.task_details.${detail_index}.is_task_service_status_emergency`
                    ]
                  }}
                </Label>
              </div>
            </div>
          </div>
        </div>
      </form>
    </CardContent>
    <CardFooter
      class="border-t px-6 py-4 flex flex-col sm:flex-row justify-end items-center sm:items-start gap-3"
    >
      <Button type="submit" :disabled="form.processing" @click="submitForm">
        <Loader2 v-if="form.processing" class="w-4 h-4 animate-spin" />
        Create Now</Button
      >
    </CardFooter>
  </Card>
</template>
