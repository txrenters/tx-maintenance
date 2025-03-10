<script setup>
import { ref } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import TableData from "./Partials/TableData.vue";
import { useToast } from "@/Components/ui/toast/use-toast";

const { toast } = useToast();

defineOptions({ layout: AppLayout });

const props = defineProps({
  title: String,
  templates: Object,
  filter: Object,
});

const url = ref(route("task_templates.index"));
const search = ref(props.filter.search);
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
      <TableData :data="templates.data" />
    </CardContent>
    <CardFooter
      class="border-t px-6 py-4 flex flex-col sm:flex-row justify-between items-center sm:items-start gap-3"
    >
      <PaginationResultRange :data="templates" />
      <Pagination :pagination="templates.links" />
    </CardFooter>
  </Card>
</template>
