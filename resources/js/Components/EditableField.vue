<script setup>
import { ref } from "vue";
import { Input } from "@/Components/ui/input";
import { Textarea } from "@/Components/ui/textarea";
import { Button } from "@/Components/ui/button";
import { Label } from "@/Components/ui/label";
import { Pencil, Check } from "lucide-vue-next";

const props = defineProps({
    modelValue: { type: [String, Number], default: "" },
    label: { type: String, default: "" },
    // 'textarea' | 'input'
    type: { type: String, default: "textarea" },
    // When false, the field is read-only text with no edit affordance (e.g. vendors).
    editable: { type: Boolean, default: true },
    placeholder: { type: String, default: "Type your message here." },
    rows: { type: [String, Number], default: 3 },
    emptyText: { type: String, default: "—" },
});

defineEmits(["update:modelValue"]);

const editing = ref(false);
</script>

<template>
    <div class="grid gap-1.5">
        <div class="flex items-center justify-between">
            <Label>{{ label }}</Label>
            <Button
                v-if="editable"
                type="button"
                variant="ghost"
                size="icon"
                class="h-7 w-7 text-muted-foreground hover:text-foreground"
                :aria-label="editing ? 'Done editing' : 'Edit'"
                @click="editing = !editing"
            >
                <Check v-if="editing" class="h-4 w-4" />
                <Pencil v-else class="h-4 w-4" />
            </Button>
        </div>

        <!-- Read mode: clean, fully visible text -->
        <p
            v-if="!editable || !editing"
            class="whitespace-pre-wrap break-words text-sm leading-relaxed text-foreground"
        >
            {{ modelValue === null || modelValue === "" ? emptyText : modelValue }}
        </p>

        <!-- Edit mode -->
        <Textarea
            v-else-if="type === 'textarea'"
            :placeholder="placeholder"
            :rows="rows"
            :model-value="modelValue"
            @update:model-value="$emit('update:modelValue', $event)"
        />
        <Input
            v-else
            :placeholder="placeholder"
            :model-value="modelValue"
            @update:model-value="$emit('update:modelValue', $event)"
        />
    </div>
</template>
