<script setup>
import { ref } from "vue";
import { router } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import TableData from "./Partials/TableData.vue";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    invoices: Object,
    filter: Object,
    sort: String,
    direction: String,
    canPost: Boolean,
});

const url = ref(route("invoices.index"));
const search = ref(props.filter.search);
const occupancy = ref(props.filter.occupancy ?? "all");

// Merge into the existing query string rather than replacing it, so sorting
// keeps the search and the occupancy filter keeps the sort.
const visitWith = (params) => {
    const query = new URLSearchParams(window.location.search);
    Object.entries(params).forEach(([key, value]) => {
        if (value) {
            query.set(key, value);
        } else {
            query.delete(key);
        }
    });
    // Any change to what is listed returns to the first page.
    query.delete("page");
    router.visit(`${url.value}?${query.toString()}`, {
        method: "get",
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

// Alphabetical and oldest-first on the first click, except for the upload time,
// where accounting wants the most recent invoices.
const ascendingFirst = ["name", "vendor", "address", "posted"];

const applySort = (column) => {
    let direction = ascendingFirst.includes(column) ? "asc" : "desc";
    if (props.sort === column) {
        direction = props.direction === "asc" ? "desc" : "asc";
    }
    visitWith({ sort: column, direction });
};

const applyOccupancy = (value) => {
    occupancy.value = value;
    visitWith({ occupancy: value === "all" ? "" : value });
};
</script>
<template>
    <Head :title="title" />
    <Card>
        <CardHeader>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <div class="flex-1">
                    <SearchBar :url="url" v-model="search" />
                </div>
                <Select
                    :modelValue="occupancy"
                    @update:modelValue="applyOccupancy"
                >
                    <SelectTrigger class="w-full sm:w-[190px]">
                        <SelectValue placeholder="All properties" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            <SelectItem value="all">All properties</SelectItem>
                            <SelectItem value="vacant">Vacant only</SelectItem>
                            <SelectItem value="occupied">
                                Occupied only
                            </SelectItem>
                        </SelectGroup>
                    </SelectContent>
                </Select>
            </div>
        </CardHeader>
        <CardContent>
            <TableData
                :data="invoices.data"
                :sort="sort"
                :direction="direction"
                :can-post="canPost"
                @sort="applySort"
            />
        </CardContent>
        <CardFooter
            class="border-t px-6 py-4 flex flex-col sm:flex-row justify-between items-center sm:items-start gap-3"
        >
            <PaginationResultRange :data="invoices" />
            <Pagination :pagination="invoices.links" />
        </CardFooter>
    </Card>
</template>