<script setup>
import { ref } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import TableData from "./Partials/TableData.vue";
import { useForm, router } from "@inertiajs/vue3";
import { useToast } from "@/Components/ui/toast/use-toast";
import { CloudDownload, UserPlus } from "lucide-vue-next";

const { toast } = useToast();

defineOptions({ layout: AppLayout });

const props = defineProps({
  title: String,
  service_status: Object,
  filter: Object,
});

const url = ref(route("service_status.index"));
const search = ref(props.filter.search);

const isCreateDialogOpen = ref(false);
const isEditDialogOpen = ref(false);
const isDeleteDialogOpen = ref(false);

const form = useForm({
  name: "",
  description: "",
});

const editForm = useForm({
  id: "",
  name: "",
  description: "",
});

const deleteForm = useForm({
  id: "",
});

const setEditForm = (status) => {
  editForm.id = status.id;
  editForm.name = status.name;
  editForm.description = status.description;
};

const setDeleteForm = (status) => {
  deleteForm.id = status.id;
};

const handleCreateSubmit = () => {
  form.post(route("service_status.store"), {
    preserveState: true,
    preserveScroll: true,
    onSuccess: () => {
      form.reset();
      toast({
        title: "Success",
        description: "Service status has been created successfully!",
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
    only: ["service_status"],
  });
};

const handleUpdateSubmit = () => {
  editForm.put(route("service_status.update", editForm.id), {
    preserveState: true,
    preserveScroll: true,
    onSuccess: () => {
      form.reset();
      toast({
        title: "Success",
        description: "Service status has been updated successfully!",
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
    only: ["service_status"],
  });
};

const handleDeleteSubmit = () => {
  deleteForm.delete(route("service_status.destroy", deleteForm.id), {
    preserveState: true,
    preserveScroll: true,
    onSuccess: () => {
      deleteForm.reset();
      toast({
        title: "Success",
        description: "Service status has been deleted successfully!",
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
    only: ["service_status"],
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
          Create Service Status
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
        :data="service_status.data"
        @openEditDialog="handleEditDialog"
        @openDeleteDialog="handleAlertDialog"
      />
    </CardContent>
    <CardFooter
      class="border-t px-6 py-4 flex flex-col sm:flex-row justify-between items-center sm:items-start gap-3"
    >
      <PaginationResultRange :data="service_status" />
      <Pagination :pagination="service_status.links" />
    </CardFooter>
  </Card>

  <Dialog v-model:open="isCreateDialogOpen">
    <DialogContent class="sm:max-w-[525px]">
      <DialogHeader>
        <DialogTitle>Add New Service Status </DialogTitle>
        <DialogDescription>
          Create a new service status here. Click create when you're done.
        </DialogDescription>
      </DialogHeader>
      <form id="dialogForm" @submit="handleSubmit($event, onSubmit)">
        <div class="mb-3">
          <Label for="name">Name </Label>
          <Input type="text" class="mt-2" v-model="form.name" />
          <Label class="mt-1 text-destructive text-xs">{{ form.errors.name }}</Label>
        </div>
        <div class="mb-3">
          <Label for="description">Description </Label>
          <Textarea v-model="form.description" class="mt-2"></Textarea>
          <Label class="mt-1 text-destructive text-xs">{{
            form.errors.description
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
          Create</Button
        >
      </DialogFooter>
    </DialogContent>
  </Dialog>

  <Dialog v-model:open="isEditDialogOpen">
    <DialogContent class="sm:max-w-[525px]">
      <DialogHeader>
        <DialogTitle>Edit Service Status </DialogTitle>
        <DialogDescription>
          Edit a service status here. Click save changes when you're done.
        </DialogDescription>
      </DialogHeader>
      <form id="dialogForm" @submit="handleSubmit($event, onSubmit)">
        <div class="mb-3">
          <Label for="name">Name </Label>
          <Input type="text" class="mt-2" v-model="editForm.name" />
          <Label class="mt-1 text-destructive text-xs">{{ editForm.errors.name }}</Label>
        </div>
        <div class="mb-3">
          <Label for="description">Description </Label>
          <Textarea v-model="editForm.description" class="mt-2"></Textarea>
          <Label class="mt-1 text-destructive text-xs">{{
            editForm.errors.description
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
