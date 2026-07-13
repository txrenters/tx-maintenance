<script setup>
import { computed, ref } from "vue";
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from "@/Components/ui/popover";
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from "@/Components/ui/command";
import { ChevronDown, X, Check } from "lucide-vue-next";

const props = defineProps({
    label: { type: String, required: true },
    modelValue: { type: [String, Number], default: "" },
    // [{ value, label, dot? }] — `dot` is a bg-* class for a colored indicator.
    options: { type: Array, default: () => [] },
    searchable: { type: Boolean, default: false },
    searchPlaceholder: { type: String, default: "Search…" },
    icon: { type: [Object, Function], default: null },
});

const emit = defineEmits(["update:modelValue"]);

const open = ref(false);

// A chip is "active" (shows a value + clear) when it holds a real filter — not
// empty and not the "all" sentinel used by the fixed-option chips.
const isActive = computed(() => {
    const v = props.modelValue;
    return v !== "" && v !== null && v !== undefined && v !== "all";
});

const selectedOption = computed(() =>
    props.options.find((o) => String(o.value) === String(props.modelValue))
);

const select = (value) => {
    emit("update:modelValue", String(value));
    open.value = false;
};

const clear = () => {
    const hasAll = props.options.some((o) => String(o.value) === "all");
    emit("update:modelValue", hasAll ? "all" : "");
    open.value = false;
};
</script>

<template>
    <!-- One chip = a trigger button + (when active) a separate clear button,
         wrapped in a single bordered container so the ✕ is clickable on its own
         and never opens the dropdown. -->
    <div
        class="inline-flex h-9 shrink-0 items-center whitespace-nowrap rounded-md border text-sm transition-colors"
        :class="
            isActive
                ? 'border-primary bg-primary/10 text-primary'
                : 'border-dashed text-muted-foreground hover:bg-accent'
        "
    >
        <Popover v-model:open="open">
            <PopoverTrigger as-child>
                <button
                    type="button"
                    class="flex h-full items-center gap-1.5 rounded-l-md pl-3 focus:outline-none"
                    :class="isActive ? 'pr-1.5' : 'pr-3'"
                >
                    <component
                        :is="icon"
                        v-if="icon"
                        class="h-3.5 w-3.5 shrink-0"
                    />
                    <span
                        v-if="selectedOption?.dot"
                        class="h-2.5 w-2.5 shrink-0 rounded-full"
                        :class="selectedOption.dot"
                    ></span>
                    <span class="max-w-[160px] truncate font-medium">
                        <template v-if="isActive && selectedOption?.dot">{{
                            selectedOption.label
                        }}</template>
                        <template v-else
                            >{{ label
                            }}<template v-if="isActive && selectedOption"
                                >: {{ selectedOption.label }}</template
                            ></template
                        >
                    </span>
                    <ChevronDown
                        v-if="!isActive"
                        class="h-3.5 w-3.5 shrink-0 opacity-60"
                    />
                </button>
            </PopoverTrigger>
            <PopoverContent class="w-56 p-0" align="start">
                <Command>
                    <CommandInput
                        v-if="searchable"
                        :placeholder="searchPlaceholder"
                    />
                    <CommandList>
                        <CommandEmpty>No results.</CommandEmpty>
                        <CommandGroup>
                            <CommandItem
                                v-for="option in options"
                                :key="String(option.value)"
                                :value="option.label"
                                class="gap-2"
                                @select="select(option.value)"
                            >
                                <span
                                    v-if="option.dot"
                                    class="h-2.5 w-2.5 shrink-0 rounded-full"
                                    :class="option.dot"
                                ></span>
                                <span class="truncate">{{ option.label }}</span>
                                <Check
                                    v-if="
                                        String(option.value) ===
                                        String(modelValue)
                                    "
                                    class="ml-auto h-4 w-4 shrink-0"
                                />
                            </CommandItem>
                        </CommandGroup>
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>

        <button
            v-if="isActive"
            type="button"
            title="Clear filter"
            class="flex h-full items-center rounded-r-md pl-1 pr-2 hover:text-primary/70 focus:outline-none"
            @click="clear"
        >
            <X class="h-3.5 w-3.5 shrink-0" />
        </button>
    </div>
</template>
