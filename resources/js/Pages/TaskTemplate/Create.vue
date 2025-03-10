<script setup>
import { ref } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import { useToast } from "@/Components/ui/toast/use-toast";
import { useForm } from "@inertiajs/inertia-vue3";

const { toast } = useToast();

defineOptions({ layout: AppLayout });

const props = defineProps({
  title: String,
  statuses: Object,
});

const form = useForm({
  name: "",
  description: "",
  current_status_id: "",
  is_current_status_emergency: "",
  next_status_id: "",
  is_next_status_emergency: "",
});

const submitForm = () => {
  form.post(route("task_templates.store"), {
    preserveState: true,
    preserveScroll: true,
    onSuccess: () => {
      form.reset();
      toast({
        title: "Success",
        description: "Task template has been created successfully!",
      });
      isCreateDialogOpen.value = false;
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
            <Select class="mt-2" v-model="form.current_status_id">
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
              form.errors.current_status_id
            }}</Label>
          </div>
          <div class="w-full flex flex-col gap-1">
            <Label for="roles" class="mb-2">Current Service Status Emergency</Label>
            <Select class="mt-2" v-model="form.is_current_status_emergency">
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
              form.errors.is_current_status_emergency
            }}</Label>
          </div>
        </div>

        <div class="flex flex-col sm:flex-row mt-5 gap-4">
          <div class="w-full flex flex-col gap-1">
            <Label for="roles" class="mb-2">Next Service Status</Label>
            <Select class="mt-2" v-model="form.next_status_id">
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
              form.errors.next_status_id
            }}</Label>
          </div>
          <div class="w-full flex flex-col gap-1">
            <Label for="roles" class="mb-2">Next Service Status Emergency</Label>
            <Select class="mt-2" v-model="form.is_next_status_emergency">
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
              form.errors.is_next_status_emergency
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

        <p class="my-5 font-semibold">Task List</p>
        <div class="flex flex-col">
          <Label for="task" class="mb-2">Task Name</Label>
          <Input id="task" type="text" v-model="form.task" />
          <Label class="mt-1 text-destructive text-xs">{{ form.errors.task }}</Label>
        </div>
        <div class="mt-4 flex flex-col sm:flex-row gap-4">
          <div class="mt-2 w-full flex flex-col gap-2">
            <Label for="task" class="mb-3">Option</Label>
            <div class="flex gap-2">
              <Label>No</Label>
              <Switch
                v-model="form.is_option"
                @update:checked="$event = $event ? 'Yes' : 'No'"
              />
              <Label>Yes</Label>
            </div>
          </div>
          <div class="mt-2 w-full flex flex-col gap-2">
            <Label for="task" class="mb-3">Mandatory</Label>
            <div class="flex gap-2">
              <Label>No</Label>
              <Switch
                v-model="form.is_mandatory"
                @update:checked="$event = $event ? 'Yes' : 'No'"
              />
              <Label>Yes</Label>
            </div>
          </div>
          <div class="mt-2 w-full flex flex-col gap-2">
            <Label for="task_for">Task For</Label>
            <Select v-model="form.task_for" class="mt-2">
              <SelectTrigger>
                <SelectValue placeholder="Select a task assign for" />
              </SelectTrigger>
              <SelectContent>
                <SelectGroup>
                  <SelectLabel>Task Assign For</SelectLabel>
                  <SelectItem value="WOC"> WOC </SelectItem>
                  <SelectItem value="Vendor"> Vendor </SelectItem>
                </SelectGroup>
              </SelectContent>
            </Select>
          </div>
          <div class="mt-2 w-full flex flex-col gap-2">
            <Label for="task_for">Due Dates</Label>
            <Select v-model="form.due_date" class="mt-2">
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
          </div>
        </div>
        <div class="flex flex-col sm:flex-row gap-4 mt-6">
          <div class="w-full flex flex-col">
            <Label for="" class="mb-2">Task Service Status</Label>
            <Select class="mt-2" v-model="form.task_service_status_id">
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
          </div>
          <div class="w-full flex flex-col">
            <Label for="" class="mb-2">Task Service Status Emergency</Label>
            <Select class="mt-2" v-model="form.is_task_service_status_emergency">
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
          </div>
        </div>
      </form>
    </CardContent>
    <CardFooter
      class="border-t px-6 py-4 flex flex-col sm:flex-row justify-end items-center sm:items-start gap-3"
    >
      <Button type="submit" :disabled="form.processing">
        <Loader2 v-if="form.processing" class="w-4 h-4 animate-spin" />
        Create</Button
      >
    </CardFooter>
  </Card>
</template>
