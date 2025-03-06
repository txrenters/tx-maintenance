<script setup>
import { ref } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import TableData from "./Partials/TableData.vue";
import { useForm, router } from "@inertiajs/vue3";
import { useToast } from "@/Components/ui/toast/use-toast";

const { toast } = useToast();

defineOptions({ layout: AppLayout });

const props = defineProps({
  title: String,
  vendors: Object,
  twilio_numbers: Object,
  filter: Object,
});

const url = ref(route("vendors.index"));
const search = ref(props.filter.search);

const isDialogOpen = ref(false);

const editForm = useForm({
  id: "",
  twilio_number: "",
});

const handleAssignTwilioSubmit = () => {
  editForm.put(route("vendors.update", editForm.id), {
    preserveState: true,
    preserveScroll: true,
    onSuccess: () => {
      editForm.reset();
      toast({
        title: "Success",
        description: "Vendor has been updated successfully!",
      });
      isDialogOpen.value = false;
    },
    onError: () => {
      toast({
        variant: "destructive",
        title: "Uh oh! Something went wrong.",
        description: "There was a problem with your request. Please try again!",
      });
    },
    only: ["vendors"],
  });
};

const setEditForm = (vendor) => {
  editForm.id = String(vendor.id);
  editForm.twilio_number = vendor.twilio_number;
};
const handleOpenDialog = (open, vendor) => {
  isDialogOpen.value = open;
  setEditForm(vendor);
};

const handleStatusChange = (checked, vendor) => {
  router.put(
    route("vendors.change_status", vendor),
    { status: checked },
    {
      preserveState: true,
      preserveScroll: true,
      onSuccess: () => {
        toast({
          title: "Success",
          description: `Vendor status updated to ${checked ? "Active" : "Inactive"}!`,
        });
      },
      onError: () => {
        toast({
          variant: "destructive",
          title: "Uh oh! Something went wrong.",
          description: "There was a problem with your request. Please try again!",
        });
      },
      only: ["vendors"],
    }
  );
};
</script>
<template>
  <Head :title="title" />
  <Card>
    <CardHeader>
      <SearchBar :url="url" v-model="search" />
      <!-- <CardTitle>{{ title }}</CardTitle>
          <CardDescription> Manage your users and view their roles. </CardDescription> -->
    </CardHeader>
    <CardContent>
      <TableData
        :data="vendors.data"
        @isDialogOpen="handleOpenDialog"
        @statusChanged="handleStatusChange"
      />
    </CardContent>
    <CardFooter
      class="border-t px-6 py-4 flex flex-col sm:flex-row justify-between items-center sm:items-start gap-3"
    >
      <PaginationResultRange :data="vendors" />
      <Pagination :pagination="vendors.links" />
    </CardFooter>
  </Card>

  <Dialog v-model:open="isDialogOpen">
    <DialogContent class="sm:max-w-[525px]">
      <DialogHeader>
        <DialogTitle>WOC Twillio Number </DialogTitle>
        <DialogDescription>
          Assign number here. Click assign when you're done.
        </DialogDescription>
      </DialogHeader>
      <form id="dialogForm" @submit="handleAssignTwilioSubmit($event, onSubmit)">
        <div class="mb-3 flex flex-col gap-4">
          <Label for="roles">Assign Number</Label>
          <Select class="mt-2" v-model="editForm.twilio_number">
            <SelectTrigger>
              <SelectValue placeholder="Select a number" />
            </SelectTrigger>
            <SelectContent>
              <SelectGroup>
                <SelectLabel>Numbers</SelectLabel>
                <SelectItem
                  v-for="twilio in twilio_numbers"
                  :value="String(twilio.phone_number)"
                  :key="String(twilio.id)"
                >
                  {{ twilio.name }} - {{ twilio.phone_number }}
                </SelectItem>
              </SelectGroup>
            </SelectContent>
          </Select>
          <Label class="mt-1 text-destructive text-xs">{{
            editForm.errors.twilio_number
          }}</Label>
        </div>
      </form>
      <DialogFooter class="flex gap-2">
        <Button type="button" variant="outline" @click="isDialogOpen = false">
          Cancel</Button
        >
        <Button
          type="submit"
          :disabled="editForm.processing"
          @click.prevent="handleAssignTwilioSubmit"
        >
          <Loader2 v-if="editForm.processing" class="w-4 h-4 animate-spin" />
          Assign</Button
        >
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
