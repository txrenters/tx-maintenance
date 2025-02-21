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
  filter: Object,
});

const url = ref(route("vendors.index"));
const search = ref(props.filter.search);

const isCreateDialogOpen = ref(false);

const form = useForm({
  name: "",
  email: "",
  phone: "",
  company: "",
  website: "",
  address: "",
  role_id: "",
});

const handleCreateSubmit = () => {
  form.post(route("users.store_"), {
    preserveState: true,
    preserveScroll: true,
    onSuccess: () => {
      form.reset();
      toast({
        title: "Success",
        description: "User has been created successfully!",
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
    only: ["users"],
  });
};

const loader = ref(false);

const handleImportTwilioNumbers = () => {
  loader.value = true;
  router.get(
    route("import_twilio_numbers"),
    {},
    {
      preserveState: true,
      preserveScroll: true,
      onSuccess: () => {
        toast({
          title: "Success",
          description: "Twilio numbers has been imported successfully!",
        });
        loader.value = false;
      },
      onError: () => {
        toast({
          variant: "destructive",
          title: "Uh oh! Something went wrong.",
          description: "There was a problem with your request. Please try again!",
        });
        loader.value = false;
      },
      only: ["twilio_numbers"],
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
      <TableData :data="vendors.data" />
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
        <DialogTitle>WOC Twillio Number </DialogTitle>
        <DialogDescription>
          Assign number here. Click assign when you're done.
        </DialogDescription>
      </DialogHeader>
      <form id="dialogForm" @submit="handleSubmit($event, onSubmit)">
        <div class="mb-3">
          <Label for="name">Current Number </Label>
          <Input type="text" class="mt-2" v-model="form.name" />
          <Label class="mt-1 text-destructive text-xs">{{ form.errors.name }}</Label>
        </div>
        <div class="mb-3">
          <Label for="roles" class="mb-2">Assign Number</Label>
          <Select class="mt-2" v-model="form.name">
            <SelectTrigger>
              <SelectValue placeholder="Select a number" />
            </SelectTrigger>
            <SelectContent>
              <SelectGroup>
                <SelectLabel>Numbers</SelectLabel>
                <SelectItem v-for="twilio in twilios" :value="twilio.id" :key="twilio.id">
                  {{ twilio.name }} - {{ twilio.phone_number }}
                </SelectItem>
              </SelectGroup>
            </SelectContent>
          </Select>
          <Label class="mt-1 name-destructive text-xs">{{ form.errors.role_id }}</Label>
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
          Assign</Button
        >
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
