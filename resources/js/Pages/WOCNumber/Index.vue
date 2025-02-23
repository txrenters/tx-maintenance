<script setup>
import { ref } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import TableData from "./Partials/TableData.vue";
import { useForm } from "@inertiajs/vue3";
import { useToast } from "@/Components/ui/toast/use-toast";

const { toast } = useToast();

defineOptions({ layout: AppLayout });

const props = defineProps({
  title: String,
  woc_numbers: Object,
  woc_users: Object,
  twilio_numbers: Object,
  filter: Object,
});

const url = ref(route("woc_numbers.index"));
const search = ref(props.filter.search);

const isCreateDialogOpen = ref(false);
const isEditDialogOpen = ref(false);
const isDeleteDialogOpen = ref(false);

const form = useForm({
  user_id: "",
  twilio_phone_number_id: "",
});

const editForm = useForm({
  id: "",
  user_id: "",
  twilio_phone_number_id: "",
});

const deleteForm = useForm({
  id: "",
});

const setEditForm = (woc_user) => {
  editForm.id = woc_user.id;
  editForm.user_id = String(woc_user.user_id);
  editForm.twilio_phone_number_id = String(woc_user.twilio_phone_number_id);
};

const setDeleteForm = (woc_user) => {
  deleteForm.id = woc_user.id;
};

const handleCreateSubmit = () => {
  form.post(route("woc_numbers.store"), {
    preserveState: true,
    preserveScroll: true,
    onSuccess: () => {
      form.reset();
      toast({
        title: "Success!",
        description: "WOC twilio number has been set successfully!",
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
    only: ["woc_numbers"],
  });
};

const handleUpdateSubmit = () => {
  editForm.put(route("woc_numbers.update", editForm.id), {
    preserveState: true,
    preserveScroll: true,
    onSuccess: () => {
      form.reset();
      toast({
        title: "Success!",
        description: "WOC twilio number has been updated successfully!",
      });
      isEditDialogOpen.value = false;
    },
    onError: () => {
      toast({
        variant: "destructive",
        title: "Uh oh! Something went wrong.",
        description: "There was a problem with your request. Please try again!",
      });
    },
    only: ["woc_numbers"],
  });
};

const handleDeleteSubmit = () => {
  deleteForm.delete(route("woc_numbers.destroy", deleteForm.id), {
    preserveState: true,
    preserveScroll: true,
    onSuccess: () => {
      deleteForm.reset();
      toast({
        title: "Success!",
        description: "WOC twilio number has been deleted successfully!",
      });
      isDeleteDialogOpen.value = false;
    },
    onError: () => {
      toast({
        variant: "destructive",
        title: "Uh oh! Something went wrong.",
        description: "There was a problem with your request. Please try again!",
      });
      isDeleteDialogOpen.value = false;
    },
    only: ["woc_numbers"],
  });
};

const handleEditDialog = (open, status) => {
  isEditDialogOpen.value = open;
  setEditForm(status);
};

const handleAlertDialog = (open, status) => {
  isDeleteDialogOpen.value = open;
  setDeleteForm(status);
};
</script>
<template>
  <Head :title="title" />

  <div class="flex items-center">
    <div class="ml-auto flex items-center gap-2">
      <Button size="sm" class="h-7 gap-1" @click="isCreateDialogOpen = true">
        <PlusCircle class="h-3.5 w-3.5" />
        <span class="sr-only sm:not-sr-only sm:whitespace-nowrap">
          Set WOC Twilio Number
        </span>
      </Button>
    </div>
  </div>
  <Card>
    <CardHeader>
      <SearchBar :url="url" v-model="search" />
    </CardHeader>
    <CardContent>
      <TableData
        :data="woc_numbers.data"
        @openEditDialog="handleEditDialog"
        @openDeleteDialog="handleAlertDialog"
      />
    </CardContent>
    <CardFooter
      class="border-t px-6 py-4 flex flex-col sm:flex-row justify-between items-center sm:items-start gap-3"
    >
      <PaginationResultRange :data="woc_numbers" />
      <Pagination :pagination="woc_numbers.links" />
    </CardFooter>
  </Card>

  <Dialog v-model:open="isCreateDialogOpen">
    <DialogContent class="sm:max-w-[525px]">
      <DialogHeader>
        <DialogTitle>Set Work Order Coordinator Twilio Number </DialogTitle>
        <DialogDescription>
          Set a twilio number for your work order coordinator here. Click set when you're
          done.
        </DialogDescription>
      </DialogHeader>
      <form id="dialogForm" @submit="handleSubmit($event, onSubmit)">
        <div class="mb-3">
          <Label for="roles" class="mb-2">Name</Label>
          <Select class="mt-2" v-model="form.user_id">
            <SelectTrigger>
              <SelectValue placeholder="Select a work order coordinator" />
            </SelectTrigger>
            <SelectContent>
              <SelectGroup>
                <SelectLabel>Work order coordinator</SelectLabel>
                <SelectItem
                  v-for="user in woc_users"
                  :value="String(user.id)"
                  :key="user.id"
                >
                  {{ user.name }}
                </SelectItem>
              </SelectGroup>
            </SelectContent>
          </Select>
          <Label class="mt-1 text-destructive text-xs">{{ form.errors.user_id }}</Label>
        </div>
        <div class="mb-3">
          <Label for="roles" class="mb-2">Twilio Phone Number</Label>
          <Select class="mt-2" v-model="form.twilio_phone_number_id">
            <SelectTrigger>
              <SelectValue placeholder="Select a twilio phone number" />
            </SelectTrigger>
            <SelectContent>
              <SelectGroup>
                <SelectLabel>Twilio phone number</SelectLabel>
                <SelectItem
                  v-for="twilio in twilio_numbers"
                  :value="String(twilio.id)"
                  :key="twilio.id"
                >
                  {{ twilio.name }} -
                  {{ twilio.phone_number }}
                </SelectItem>
              </SelectGroup>
            </SelectContent>
          </Select>
          <Label class="mt-1 text-destructive text-xs">{{
            form.errors.twilio_phone_number_id
          }}</Label>
        </div>
      </form>
      <DialogFooter class="flex gap-2">
        <Button type="button" variant="outline" @click="isCreateDialogOpen = false">
          Cancel</Button
        >
        <Button
          type="submit"
          :disabled="form.processing"
          @click.prevent="handleCreateSubmit"
        >
          <Loader2 v-if="form.processing" class="w-4 h-4 animate-spin" />
          Set</Button
        >
      </DialogFooter>
    </DialogContent>
  </Dialog>

  <Dialog v-model:open="isEditDialogOpen">
    <DialogContent class="sm:max-w-[525px]">
      <DialogHeader>
        <DialogTitle>Edit Work Order Coordinator Twilio Number </DialogTitle>
        <DialogDescription>
          Edit a twilio number for your work order coordinator here. Click save changes
          when you're done.
        </DialogDescription>
      </DialogHeader>
      <form id="dialogForm" @submit="handleSubmit($event, onSubmit)">
        <div class="mb-3">
          <Label for="roles" class="mb-2">Name</Label>
          <Select class="mt-2" v-model="editForm.user_id">
            <SelectTrigger>
              <SelectValue placeholder="Select a work order coordinator" />
            </SelectTrigger>
            <SelectContent>
              <SelectGroup>
                <SelectLabel>Work order coordinator</SelectLabel>
                <SelectItem
                  v-for="user in woc_users"
                  :value="String(user.id)"
                  :key="user.id"
                >
                  {{ user.name }}
                </SelectItem>
              </SelectGroup>
            </SelectContent>
          </Select>
          <Label class="mt-1 text-destructive text-xs">{{
            editForm.errors.user_id
          }}</Label>
        </div>
        <div class="mb-3">
          <Label for="roles" class="mb-2">Twilio Phone Number</Label>
          <Select class="mt-2" v-model="editForm.twilio_phone_number_id">
            <SelectTrigger>
              <SelectValue placeholder="Select a twilio phone number" />
            </SelectTrigger>
            <SelectContent>
              <SelectGroup>
                <SelectLabel>Twilio phone number</SelectLabel>
                <SelectItem
                  v-for="twilio in twilio_numbers"
                  :value="String(twilio.id)"
                  :key="twilio.id"
                >
                  {{ twilio.name }} -
                  {{ twilio.phone_number }}
                </SelectItem>
              </SelectGroup>
            </SelectContent>
          </Select>
          <Label class="mt-1 text-destructive text-xs">{{
            editForm.errors.twilio_phone_number_id
          }}</Label>
        </div>
      </form>
      <DialogFooter class="flex gap-2">
        <Button type="button" variant="outline" @click="isEditDialogOpen = false">
          Cancel</Button
        >
        <Button
          type="submit"
          :disabled="editForm.processing"
          @click.prevent="handleUpdateSubmit"
        >
          <Loader2 v-if="editForm.processing" class="w-4 h-4 animate-spin" />
          Save Changes</Button
        >
      </DialogFooter>
    </DialogContent>
  </Dialog>
  <AlertDialog v-model:open="isDeleteDialogOpen">
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>Are you absolutely sure?</AlertDialogTitle>
        <AlertDialogDescription>
          This action cannot be undone. This will permanently delete the data and removes
          all associated data from our servers.
        </AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel>Cancel</AlertDialogCancel>
        <AlertDialogAction
          class="destructive"
          :disabled="deleteForm.processing"
          @click.prevent="handleDeleteSubmit"
        >
          <Loader2 v-if="deleteForm.processing" class="w-4 h-4 animate-spin" />
          Continue
        </AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>
