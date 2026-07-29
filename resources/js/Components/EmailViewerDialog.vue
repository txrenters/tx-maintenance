<script setup>
import { computed } from "vue";

const props = defineProps({
    modelValue: { type: Object, default: null },
});

const emit = defineEmits(["update:modelValue"]);

const email = computed(() => props.modelValue);
const open = computed(() => props.modelValue !== null);
const close = () => emit("update:modelValue", null);

const sentAt = computed(() => email.value?.sent_at ?? email.value?.emailed_at ?? null);
const formatDate = (value) => (value ? new Date(value).toLocaleString() : "—");
</script>

<template>
    <Dialog :open="open" @update:open="!$event && close()">
        <DialogContent class="flex max-h-[90vh] flex-col gap-0 overflow-hidden p-0 sm:max-w-3xl [&>button]:text-muted-foreground [&>button]:hover:text-foreground">
            <div class="px-6 pb-4 pr-12 pt-5">
                <img src="/tx-portal-logo.png" alt="Texas Renters Maintenance" class="mb-3 h-8 w-auto" />
                <DialogHeader class="space-y-2 text-left">
                    <div class="flex items-start gap-2">
                        <Badge v-if="email?.recipient_type" variant="secondary" class="mt-0.5 shrink-0 capitalize">{{ email.recipient_type }}</Badge>
                        <DialogTitle class="text-base font-semibold leading-snug text-[#1c1a8c] dark:text-neutral-100">{{ email?.subject || "Email message" }}</DialogTitle>
                    </div>
                    <DialogDescription as-child>
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <span class="rounded-full bg-[#1c1a8c]/10 px-2.5 py-1 font-medium text-[#1c1a8c] dark:bg-white/10 dark:text-neutral-200">{{ email?.from_email }}</span>
                            <span class="font-bold text-[#6cb33f]" aria-hidden="true">→</span>
                            <span class="rounded-full bg-[#6cb33f]/15 px-2.5 py-1 font-medium text-[#4f8a2a] dark:bg-[#6cb33f]/20 dark:text-[#9fdc6f]">{{ email?.to_email }}</span>
                            <time class="ml-auto text-muted-foreground">{{ formatDate(sentAt) }}</time>
                        </div>
                    </DialogDescription>
                    <div v-if="email?.cc?.length" class="text-xs text-muted-foreground">
                        <span class="font-semibold text-[#1c1a8c] dark:text-neutral-200">CC:</span> {{ email.cc.join(", ") }}
                    </div>
                </DialogHeader>
            </div>
            <div class="h-1 shrink-0 bg-gradient-to-r from-[#1c1a8c] via-[#2b4c9b] to-[#6cb33f]" />
            <div class="min-h-0 flex-1 overflow-y-auto bg-muted/20 px-4 py-5 sm:px-6">
                <div class="mx-auto max-w-[65ch] rounded-lg border bg-white px-6 py-6 shadow-sm sm:px-8">
                    <div
                        v-if="email?.body_html"
                        class="prose prose-sm max-w-none text-neutral-800"
                        v-html="email.body_html"
                    />
                    <p v-else class="whitespace-pre-wrap text-[13.5px] leading-relaxed text-neutral-800">{{ email?.body_text || "No message content." }}</p>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
