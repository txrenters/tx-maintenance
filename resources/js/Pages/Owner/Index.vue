<script setup>
import { ref } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import TableData from "./Partials/TableData.vue";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    owners: Object,
    filter: Object,
});

const url = ref(route("owners.index"));
const search = ref(props.filter.search);
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
            <TableData :data="owners.data" />
        </CardContent>
        <CardFooter
            class="border-t px-6 py-4 flex flex-col sm:flex-row justify-between items-center sm:items-start gap-3"
        >
            <PaginationResultRange :data="owners" />
            <Pagination :pagination="owners.links" />
        </CardFooter>
    </Card>
</template>
