<script setup>
import { Input } from "@/Components/ui/input";
import { Textarea } from "@/Components/ui/textarea";
import { Label } from "@/Components/ui/label";

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
</script>

<template>
    <div class="grid gap-1.5">
        <Label>{{ label }}</Label>

        <!-- Read-only text (e.g. vendors, tenants, owners) -->
        <p
            v-if="!editable"
            class="whitespace-pre-wrap break-words text-sm leading-relaxed text-foreground"
        >
            {{ modelValue === null || modelValue === "" ? emptyText : modelValue }}
        </p>

        <!-- Editable: input/textarea shown directly -->
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
