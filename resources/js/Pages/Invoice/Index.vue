<script setup>
import { ref } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import TableData from "./Partials/TableData.vue";
import { useToast } from "@/Components/ui/toast/use-toast";

const { toast } = useToast();

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    invoices: Object,
    filter: Object,
});

const url = ref(route("invoices.index"));
const search = ref(props.filter.search);
</script>
<template>
    <Head :title="title" />
    <Card>
        <CardHeader>
            <SearchBar :url="url" v-model="search" />
        </CardHeader>
        <CardContent>
            <TableData :data="invoices.data" />
        </CardContent>
        <CardFooter
            class="border-t px-6 py-4 flex flex-col sm:flex-row justify-between items-center sm:items-start gap-3"
        >
            <PaginationResultRange :data="invoices" />
            <Pagination :pagination="invoices.links" />
        </CardFooter>
    </Card>
</template>
