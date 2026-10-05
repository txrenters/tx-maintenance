<script setup>
import AppLayout from "@/Layouts/AppLayout.vue";
import LawnCare from "./LawnCare.vue";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    service_status: Object,
    vendors: Object,
    categories: Object,
    types: Array,
    users: Object,
    filter: Object,
    // Whether this user gets the "new activity" counters (every staff login).
    // The server decides; false renders the board exactly as every other board.
    shows_new_activity: { type: Boolean, default: false },
    board_seen_at: { type: String, default: null },
    // Whether the automatic easy-fix texts are switched on, so a board full of
    // "Not texted" chips reads as "switched off" rather than as a fault.
    easy_fix_texts_enabled: { type: Boolean, default: false },
});
</script>

<template>
    <LawnCare
        v-bind="props"
        list-route-name="work_orders.easy_fix"
        activity-board="easy_fix"
        :new-activity="shows_new_activity"
        :board-seen-at="board_seen_at"
    >
        <template #board-actions>
            <span
                data-easy-fix-texts-state
                class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium"
                :class="
                    easy_fix_texts_enabled
                        ? 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200'
                        : 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200'
                "
                :title="
                    easy_fix_texts_enabled
                        ? 'Tenants matched to a handbook item are texted the how-to video automatically.'
                        : 'TENANT_EASY_FIX_SMS_ENABLED is off: nobody is texted automatically, so cards read Not texted.'
                "
            >
                <span
                    class="inline-block h-1.5 w-1.5 rounded-full bg-current"
                ></span>
                Automatic easy-fix texts: {{ easy_fix_texts_enabled ? "ON" : "OFF" }}
            </span>
        </template>
    </LawnCare>
</template>
