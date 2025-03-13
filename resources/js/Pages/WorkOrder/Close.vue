<script setup></script>
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
  work_orders: Object,
  filter: Object,
});

const url = ref(route("work_orders.closed_work_orders"));
const search = ref(props.filter.search);

const isDialogOpen = ref(false);

const workOderForm = useForm({
  id: "",
});

const setWorkOrder = () => {};

const handleWorkOrderOpen = (checked, work_order) => {
  workOderForm.id = work_order;
  workOderForm.put(route("work_orders.open", workOderForm.id), {
    preserveState: true,
    preserveScroll: true,
    onSuccess: () => {
      toast({
        title: "Success",
        description: "Work order has been re-open successfully!",
      });
    },
    onError: () => {
      toast({
        variant: "destructive",
        title: "Uh oh! Something went wrong.",
        description: "There was a problem with your request. Please try again!",
      });
    },
    only: ["work_orders"],
  });
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
      <TableData :data="work_orders.data" @statusChanged="handleWorkOrderOpen" />
    </CardContent>
    <CardFooter
      class="border-t px-6 py-4 flex flex-col sm:flex-row justify-between items-center sm:items-start gap-3"
    >
      <PaginationResultRange :data="work_orders" />
      <Pagination :pagination="work_orders.links" />
    </CardFooter>
  </Card>
</template>
