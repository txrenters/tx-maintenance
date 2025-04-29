<script setup>
import { ref, watch } from "vue";
import { router } from "@inertiajs/vue3";
import debounce from "lodash/debounce";
import { Search } from "lucide-vue-next";

// Props and emits
const props = defineProps({
    url: String,
    modelValue: String,
});
const emit = defineEmits(["update:modelValue"]);

// Reactive model
const model = ref(props.modelValue || "");

// Sync with parent
watch(
    () => props.modelValue,
    (newVal) => {
        model.value = newVal || "";
    }
);

// Sanitize input to avoid issues with special characters
function sanitizeInput(value) {
    return value.replace(/[#?&]/g, "").trim();
}

// Debounced search handler
const search = debounce((value) => {
    if (!props.url) return;

    const sanitized = sanitizeInput(value);
    const query = new URLSearchParams(window.location.search);

    if (sanitized) {
        query.set("search", sanitized);
    } else {
        query.delete("search");
    }

    router.visit(`${props.url}?${query.toString()}`, {
        method: "get",
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}, 500);

// Watch model changes
watch(model, (value) => {
    emit("update:modelValue", value);
    search(value);
});

// Optional paste handler (extra defensive)
function handlePaste(event) {
    const pasted = (event.clipboardData || window.clipboardData).getData(
        "text"
    );
    const sanitized = sanitizeInput(pasted);
    model.value = sanitized;
    event.preventDefault(); // prevent raw paste input
}
</script>

<template>
    <div class="relative flex items-center flex-1 md:grow-0">
        <Search
            class="absolute left-2.5 top-2.8 h-4 w-4 text-muted-foreground"
        />
        <Input
            type="text"
            placeholder="Search..."
            v-model="model"
            @keydown.enter.prevent
            @paste="handlePaste"
            class="w-full rounded-lg bg-background pl-8 md:w-[200px] lg:w-[320px]"
        />
    </div>
</template>
