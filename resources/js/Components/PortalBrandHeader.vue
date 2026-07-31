<script setup>
import { Sun, Moon } from "lucide-vue-next";

/**
 * The branded band that sits at the top of a portal's first card, matching the
 * header on every automated email so a recipient following a link lands on the
 * same brand. Renders as a band rather than its own card, so the parent must
 * supply the card border and `overflow-hidden`.
 *
 * The logo keeps a white plate in dark mode because the wordmark is dark navy
 * and would otherwise disappear.
 */
defineProps({
    isDark: { type: Boolean, default: false },
});

defineEmits(["toggle-theme"]);
</script>

<template>
    <div
        class="relative border-b-[3px] border-b-[#6cbf3f] px-4 py-4 sm:px-6 sm:py-5 text-center"
    >
        <span class="inline-flex rounded-md dark:bg-white dark:px-3 dark:py-2">
            <img
                src="/tx-logo.png"
                alt="TexasRenters.com"
                class="h-7 w-auto sm:h-9"
            />
        </span>
        <button
            type="button"
            :title="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
            class="absolute right-2 top-2 sm:right-3 sm:top-3 inline-flex items-center justify-center rounded-md border border-input bg-background p-2 text-foreground shadow-sm transition-colors hover:bg-accent"
            @click="$emit('toggle-theme')"
        >
            <Sun v-if="isDark" class="w-4 h-4" />
            <Moon v-else class="w-4 h-4" />
        </button>
    </div>
</template>
