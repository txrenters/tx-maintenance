<script setup>
import { watch } from "vue";
import { router } from "@inertiajs/vue3";
import debounce from "lodash/debounce";
import { Search } from "lucide-vue-next";

const props = defineProps({
    url: String,
});

const model = defineModel({
    type: String,
});

watch(
    model,
    debounce(function (value) {
        // const newQuery = { ...route().params, search: value }; //maintain url params
        const query = new URLSearchParams(window.location.search);

        query.set("search", value || ""); // set search param (empty if no value)

        // router.visit(props.url, {
        //     method: "get",
        //     data: newQuery,
        //     preserveState: true,
        //     replace: true,
        //     preserveScroll: true,
        // });

        router.visit(`${props.url}?${query.toString()}`, {
            method: "get",
        });
    }, 500)
);
</script>

<template>
    <div class="relative flex items-center flex-1 md:grow-0">
        <Search
            class="absolute left-2.5 top-2.8 h-4 w-4 text-muted-foreground"
        />
        <Input
            type="search"
            placeholder="Search..."
            v-model="model"
            class="w-full rounded-lg bg-background pl-8 md:w-[200px] lg:w-[320px]"
        />
    </div>
</template>
