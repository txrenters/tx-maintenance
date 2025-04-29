<script setup>
import { ref, watch } from "vue";
import { router } from "@inertiajs/vue3";
import debounce from "lodash/debounce";
import { Search } from "lucide-vue-next";

const props = defineProps({
    url: String,
    modelValue: String, // for v-model
});

const emit = defineEmits(["update:modelValue"]);

// Local reactive copy of modelValue
const model = ref(props.modelValue);

// Keep local model in sync with parent
watch(
    () => props.modelValue,
    (newVal) => {
        model.value = newVal;
    }
);

// Debounced search trigger
const search = debounce((value) => {
    const query = new URLSearchParams(window.location.search);
    query.set("search", value || "");

    router.visit(`${props.url}?${query.toString()}`, {
        method: "get",
        preserveState: true,
        replace: true,
        preserveScroll: true,
    });
}, 500);

// Watch for input changes
watch(model, (value) => {
    emit("update:modelValue", value);
    search(value);
});
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
