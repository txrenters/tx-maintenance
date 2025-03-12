<script setup>
import { ref } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import TableData from "./Partials/TableData.vue";
import { useToast } from "@/Components/ui/toast/use-toast";
import { useForm } from "@inertiajs/vue3";

const { toast } = useToast();

defineOptions({ layout: AppLayout });

const props = defineProps({
  title: String,
  templates: Object,
  filter: Object,
});

const url = ref(route("task_templates.index"));
const search = ref(props.filter.search);
const isDeleteDialogOpen = ref(false);

const deleteForm = useForm({
  id: "",
});

const setDeleteForm = (template) => {
  deleteForm.id = template.id;
};
const handleDeleteSubmit = () => {
  deleteForm.delete(route("task_templates.destroy", deleteForm.id), {
    preserveState: true,
    preserveScroll: true,
    onSuccess: () => {
      deleteForm.reset();
      toast({
        title: "Success",
        description: "Template has been deleted successfully!",
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
    only: ["templates"],
  });
};
const handleAlertDialog = (open, user) => {
  isDeleteDialogOpen.value = open;
  setDeleteForm(user);
};
</script>
<template>
  <Head :title="title" />
  <div class="flex items-center">
    <div class="ml-auto flex items-center gap-2">
      <Link :href="route('task_templates.create')" class="h-7 gap-1">
        <Button size="sm" class="h-7 gap-1">
          <PlusCircle class="h-3.5 w-3.5" />
          <span class="sr-only sm:not-sr-only sm:whitespace-nowrap">
            Add {{ title }}
          </span>
        </Button>
      </Link>
    </div>
  </div>
  <Card>
    <CardHeader>
      <SearchBar :url="url" v-model="search" />
    </CardHeader>
    <CardContent>
      <TableData :data="templates.data" @openDeleteDialog="handleAlertDialog" />
    </CardContent>
    <CardFooter
      class="border-t px-6 py-4 flex flex-col sm:flex-row justify-between items-center sm:items-start gap-3"
    >
      <PaginationResultRange :data="templates" />
      <Pagination :pagination="templates.links" />
    </CardFooter>
  </Card>

  <AlertDialog v-model:open="isDeleteDialogOpen">
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>Are you absolutely sure?</AlertDialogTitle>
        <AlertDialogDescription>
          This action cannot be undone. This will permanently delete templates and remove
          your data from our servers.
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
