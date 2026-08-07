<script setup>
import { ExternalLink } from "lucide-vue-next";

// The header links every work order view shares: PropertyWare, plus Jobber for
// the in-house crew's jobs. Whether the Jobber link may be followed is decided
// server-side (THMP assigned, staff or THMP's own vendor account) — the URL is
// simply absent from the payload otherwise, so there is no role check here.
defineProps({
    propertywareId: { type: [String, Number], default: null },
    jobberWebUri: { type: String, default: null },
});
</script>

<template>
    <span class="inline-flex items-center gap-3">
        <a
            v-if="propertywareId"
            :href="`https://app.propertyware.com/pw/maintenance/work_order_detail.do?entityID=${propertywareId}`"
            target="_blank"
            rel="noopener"
            class="inline-flex items-center gap-1 rounded-md border border-input bg-background px-2.5 py-1 text-xs font-medium text-primary transition-colors hover:bg-muted"
        >
            <ExternalLink class="h-3.5 w-3.5" />
            PropertyWare
        </a>
        <a
            v-if="jobberWebUri"
            :href="jobberWebUri"
            target="_blank"
            rel="noopener"
            class="inline-flex items-center gap-1 rounded-md border border-input bg-background px-2.5 py-1 text-xs font-medium text-primary transition-colors hover:bg-muted"
        >
            <ExternalLink class="h-3.5 w-3.5" />
            Open in Jobber
        </a>
    </span>
</template>
