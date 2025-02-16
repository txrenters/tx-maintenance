<script setup>
import { ref } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import {
  CircleUser,
  File,
  Home,
  LineChart,
  ListFilter,
  MoreHorizontal,
  Package,
  Package2,
  PanelLeft,
  PlusCircle,
  Search,
  Settings,
  ShoppingCart,
  Users2,
} from "lucide-vue-next";
import TableData from "./Partials/TableData.vue";

defineOptions({ layout: AppLayout });

const props = defineProps({
  title: String,
  users: Object,
  roles: Object,
  filter: Object,
});

const url = ref(route("users.index"));
const search = ref(props.filter.search);
const per_page = ref(props.filter.per_page);
</script>
<template>
  <Head :title="title" />

  <Tabs default-value="all">
    <div class="flex items-center">
      <TabsList>
        <TabsTrigger value="all"> All </TabsTrigger>
        <TabsTrigger value="active"> Active </TabsTrigger>
        <TabsTrigger value="draft"> Draft </TabsTrigger>
        <TabsTrigger value="archived" class="hidden sm:flex"> Archived </TabsTrigger>
      </TabsList>
      <div class="ml-auto flex items-center gap-2">
        <DropdownMenu>
          <DropdownMenuTrigger as-child>
            <Button variant="outline" size="sm" class="h-7 gap-1">
              <ListFilter class="h-3.5 w-3.5" />
              <span class="sr-only sm:not-sr-only sm:whitespace-nowrap"> Filter </span>
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuLabel>Filter by</DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem checked> Active </DropdownMenuItem>
            <DropdownMenuItem>Draft</DropdownMenuItem>
            <DropdownMenuItem> Archived </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
        <Button size="sm" variant="outline" class="h-7 gap-1">
          <File class="h-3.5 w-3.5" />
          <span class="sr-only sm:not-sr-only sm:whitespace-nowrap"> Export </span>
        </Button>
        <Button size="sm" class="h-7 gap-1">
          <PlusCircle class="h-3.5 w-3.5" />
          <span class="sr-only sm:not-sr-only sm:whitespace-nowrap">
            Add {{ title }}
          </span>
        </Button>
      </div>
    </div>
    <TabsContent value="all">
      <Card>
        <CardHeader>
          <SearchBar :url="url" v-model="search" />
          <!-- <CardTitle>{{ title }}</CardTitle>
          <CardDescription> Manage your users and view their roles. </CardDescription> -->
        </CardHeader>
        <CardContent>
          <TableData :userData="users.data" />
        </CardContent>
        <CardFooter
          class="border-t px-6 py-4 flex flex-col sm:flex-row justify-between items-center sm:items-start gap-3"
        >
          <PaginationResultRange :data="users" />
          <Pagination :pagination="users.links" />
        </CardFooter>
      </Card>
    </TabsContent>
  </Tabs>
</template>
