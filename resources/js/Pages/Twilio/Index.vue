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
  twilio_numbers: Object,
  twilios: Object,
  filter: Object,
});

const url = ref(route("twilio_numbers.index"));
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

  <div class="flex items-center">
    <div class="ml-auto flex items-center gap-2">
      <Button
        size="sm"
        :disabled="loader"
        class="h-7 gap-1"
        @click="handleImportTwilioNumbers"
      >
        <CloudDownload v-if="!loader" class="h-3.5 w-3.5" />
        <Loader2 v-else class="w-4 h-4 animate-spin" />
        <span class="sr-only sm:not-sr-only sm:whitespace-nowrap">
          Sync {{ title }}
        </span>
      </Button>
    </div>
  </div>
  <Card>
    <CardHeader>
      <SearchBar :url="url" v-model="search" />
      <!-- <CardTitle>{{ title }}</CardTitle>
          <CardDescription> Manage your users and view their roles. </CardDescription> -->
    </CardHeader>
    <CardContent>
      <TableData :data="twilio_numbers.data" />
    </CardContent>
    <CardFooter
      class="border-t px-6 py-4 flex flex-col sm:flex-row justify-between items-center sm:items-start gap-3"
    >
      <PaginationResultRange :data="twilio_numbers" />
      <Pagination :pagination="twilio_numbers.links" />
    </CardFooter>
  </Card>
</template>
