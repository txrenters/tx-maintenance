<script setup>
import { computed } from "vue";
import { Clock } from "lucide-vue-next";
import {
    hoursSince,
    isAwaitingReply,
    lastMessage,
    waitedLabel,
} from "@/utils/conversation";

/**
 * Flags a thread whose newest message came from the other side, so a
 * coordinator can see at a glance that someone is still waiting on them.
 */
const props = defineProps({
    messages: { type: Array, default: () => [] },
    /** Our number on this thread — used to tell inbound from outbound. */
    ourNumber: { type: String, default: "" },
    /** Who is waiting, e.g. "owner". Shown when the wait is on us. */
    party: { type: String, default: "" },
});

const awaiting = computed(() => isAwaitingReply(props.messages, props.ourNumber));

const waitingHours = computed(() =>
    hoursSince(lastMessage(props.messages)?.created_at),
);

const isOverdue = computed(() => waitingHours.value >= 24);
</script>

<template>
    <div
        v-if="awaiting"
        class="mb-2 flex items-center gap-2 rounded-md border px-3 py-2 text-xs"
        :class="
            isOverdue
                ? 'border-destructive/40 bg-destructive/10 text-destructive'
                : 'border-amber-500/40 bg-amber-500/10 text-amber-700 dark:text-amber-300'
        "
    >
        <Clock class="h-3.5 w-3.5 shrink-0" />
        <span>
            Awaiting your reply<template v-if="party">
                — the {{ party }} messaged last</template
            >, waiting {{ waitedLabel(waitingHours) }}
        </span>
    </div>
</template>
