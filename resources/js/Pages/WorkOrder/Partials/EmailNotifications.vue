<script setup>
import { ref } from "vue";
import EmailViewerDialog from "@/Components/EmailViewerDialog.vue";

defineProps({
    emails: { type: Array, default: () => [] },
    loading: Boolean,
});

const selectedEmail = ref(null);
const formatDate = (value) => value ? new Date(value).toLocaleString() : "—";
</script>

<template>
    <div class="flex min-h-[32rem] flex-col gap-3 p-2">
        <p v-if="loading" class="py-10 text-center text-sm text-muted-foreground">Loading email notifications…</p>
        <p v-else-if="emails.length === 0" class="py-10 text-center text-sm text-muted-foreground">No email notifications found for this work order.</p>
        <button
            v-for="email in emails"
            v-else
            :key="email.id"
            type="button"
            class="rounded-lg border bg-card p-4 text-left transition-colors hover:bg-muted/50"
            @click="selectedEmail = email"
        >
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge variant="secondary">{{ email.recipient_type }}</Badge>
                        <span class="font-medium">{{ email.recipient_name }}</span>
                        <span v-if="email.scope" class="text-xs text-muted-foreground">{{ email.scope }}</span>
                    </div>
                    <p class="mt-2 font-medium">{{ email.subject || "No subject" }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">{{ email.from_email }} → {{ email.to_email }}</p>
                    <p class="mt-2 line-clamp-1 text-sm text-muted-foreground">{{ email.body_text || "No message preview available." }}</p>
                </div>
                <time class="shrink-0 text-xs text-muted-foreground">{{ formatDate(email.sent_at) }}</time>
            </div>
        </button>

        <EmailViewerDialog v-model="selectedEmail" />
    </div>
</template>
