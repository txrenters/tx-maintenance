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
  vendors: Object,
  twilio_numbers: Object,
  filter: Object,
});

const url = ref(route("vendors.index"));
const search = ref(props.filter.search);

const form = useForm({
  name: "",
  contact_name: "",
  twilio_number: "",
  email: "",
  phone: "",
  address: "",
});

const isDialogOpen = ref(false);
const isCreateDialogOpen = ref(false);

const editForm = useForm({
  id: "",
  twilio_number: "",
  name: "",
  contact_name: "",
  twilio_number: "",
  email: "",
  phone: "",
  address: "",
});

const setEditForm = (vendor) => {
  editForm.id = String(vendor.id);
  editForm.name = vendor.name;
  editForm.contact_name = vendor.contact_name;
  editForm.twilio_number = vendor.twilio_number;
  editForm.email = vendor.email;
  editForm.phone = vendor.phone;
  editForm.address = vendor.address;
};
const handleOpenDialog = (open, vendor) => {
  isDialogOpen.value = open;
  setEditForm(vendor);
};

const handleUpdate = () => {
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
const loader = ref(false);

const handleSubmit = () => {
  form.post(route("vendors.store"), {
    preserveState: true,
    preserveScroll: true,
    onSuccess: () => {
      toast({
        title: "Success",
        description: "Vendors created successfully!",
      });
      form.reset();
      isCreateDialogOpen.value = false;
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
</script>
<template>
  <Head :title="title" />
  <div class="ml-auto flex items-center gap-2">
    <Button
      size="sm"
      :disabled="loader"
      class="h-7 gap-1"
      @click="isCreateDialogOpen = true"
    >
      <UserPlus v-if="!loader" class="h-3.5 w-3.5" />
      <Loader2 v-else class="w-4 h-4 animate-spin" />
      <span class="sr-only sm:not-sr-only sm:whitespace-nowrap"> Add {{ title }} </span>
    </Button>
  </div>
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

  <Dialog v-model:open="isCreateDialogOpen">
    <DialogContent class="sm:max-w-[525px]">
      <DialogHeader>
        <DialogTitle>Create {{ title }}</DialogTitle>
        <DialogDescription>
          Add new vendor here. Click create when you're done.
        </DialogDescription>
      </DialogHeader>
      <form id="dialogForm" @submit.prevent="handleSubmit">
        <div class="mb-3">
          <Label for="name">Name </Label>
          <Input type="text" class="mt-2" v-model="form.name" />
          <Label class="mt-1 text-destructive text-xs">{{ form.errors.name }}</Label>
          <p class="text-xs mt-1">
            Note: The password will be set to match the email address.
          </p>
        </div>
        <div class="flex gap-3">
          <div class="mb-3 w-full">
            <Label for="code">Contact Name</Label>
            <Input type="text" class="" v-model="form.contact_name" />
            <Label class="mt-1 text-destructive text-xs">{{
              form.errors.contact_name
            }}</Label>
          </div>
          <div class="mb-3 w-full">
            <Label for="roles " class="mb-4">Assign Number</Label>
            <Select class="mt-2" v-model="form.twilio_number">
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
              form.errors.twilio_number
            }}</Label>
          </div>
        </div>

        <div class="flex gap-3">
          <div class="mb-3 w-full">
            <Label for="code">Email</Label>
            <Input type="email" class="mt-2" v-model="form.email" />
            <Label class="mt-1 text-destructive text-xs">{{ form.errors.email }}</Label>
          </div>
          <div class="mb-3 w-full">
            <Label for="code">Phone Number</Label>
            <Input type="text" class="mt-2" v-model="form.phone" />
            <Label class="mt-1 text-destructive text-xs">{{ form.errors.phone }}</Label>
          </div>
        </div>
        <div class="mb-3">
          <Label for="code">Address</Label>
          <Textarea v-model="form.address" />
          <Label class="mt-1 text-destructive text-xs">{{ form.errors.address }}</Label>
        </div>
      </form>
      <DialogFooter class="flex gap-2">
        <Button type="button" variant="outline" @click="isCreateDialogOpen = false">
          Cancel</Button
        >
        <Button type="submit" :disabled="form.processing" @click.prevent="handleSubmit">
          <Loader2 v-if="form.processing" class="w-4 h-4 animate-spin" />
          Create</Button
        >
      </DialogFooter>
    </DialogContent>
  </Dialog>

  <Dialog v-model:open="isDialogOpen">
    <DialogContent class="sm:max-w-[525px]">
      <DialogHeader>
        <DialogTitle>Edit {{ title }}</DialogTitle>
        <DialogDescription>
          Edit new vendor here. Click save when you're done.
        </DialogDescription>
      </DialogHeader>
      <form id="dialogForm" @submit.prevent="handleUpdate">
        <div class="mb-3">
          <Label for="name">Name </Label>
          <Input type="text" class="mt-2" v-model="editForm.name" />
          <Label class="mt-1 text-destructive text-xs">{{ editForm.errors.name }}</Label>
        </div>
        <div class="flex gap-3">
          <div class="mb-3 w-full">
            <Label for="code">Contact Name</Label>
            <Input type="text" class="" v-model="editForm.contact_name" />
            <Label class="mt-1 text-destructive text-xs">{{
              editForm.errors.contact_name
            }}</Label>
          </div>
          <div class="mb-3 w-full">
            <Label for="roles " class="mb-4">Assign Number</Label>
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
        </div>

        <div class="flex gap-3">
          <div class="mb-3 w-full">
            <Label for="code">Email</Label>
            <Input type="email" class="mt-2" v-model="editForm.email" />
            <Label class="mt-1 text-destructive text-xs">{{
              editForm.errors.email
            }}</Label>
          </div>
          <div class="mb-3 w-full">
            <Label for="code">Phone Number</Label>
            <Input type="text" class="mt-2" v-model="editForm.phone" />
            <Label class="mt-1 text-destructive text-xs">{{
              editForm.errors.phone
            }}</Label>
          </div>
        </div>
        <div class="mb-3">
          <Label for="code">Address</Label>
          <Textarea v-model="editForm.address" />
          <Label class="mt-1 text-destructive text-xs">{{
            editForm.errors.address
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
          @click.prevent="handleUpdate"
        >
          <Loader2 v-if="editForm.processing" class="w-4 h-4 animate-spin" />
          Save</Button
        >
      </DialogFooter>
    </DialogContent>
  </Dialog>

  <!-- <Dialog v-model:open="isDialogOpen">
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
  </Dialog> -->
</template>
