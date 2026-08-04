<script setup>
import { Headset, House, KeyRound, MessagesSquare, Wrench, X } from "lucide-vue-next";

/**
 * Banners that fade in at the top of the page when a message arrives and fade
 * out on their own a few seconds later.
 *
 * Separate from the app's toaster, which sits bottom-right and reports the
 * result of something the user just did. This announces something that arrived
 * on its own, so it needs the eye-level position — and unlike a desktop
 * notification it needs no browser permission, so it always shows.
 */
defineProps({
    banners: { type: Array, default: () => [] },
});

const emit = defineEmits(["open", "dismiss"]);

const PARTY_ICON = {
    tenant: KeyRound,
    owner: House,
    vendor: Wrench,
    vendor_tenant: KeyRound,
    vendor_owner: House,
};

const iconFor = (banner) => {
    if (!banner.alert) return MessagesSquare;

    return PARTY_ICON[banner.alert.conversation_type] ?? Headset;
};
</script>

<template>
    <!-- pointer-events-none on the stack so it never blocks the page behind it;
         each card turns them back on for itself. -->
    <div
        class="pointer-events-none fixed left-1/2 top-4 z-[110] flex w-full max-w-sm -translate-x-1/2 flex-col gap-2 px-4"
    >
        <TransitionGroup
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="-translate-y-3 opacity-0"
            enter-to-class="translate-y-0 opacity-100"
            leave-active-class="transition duration-200 ease-in absolute w-full"
            leave-from-class="translate-y-0 opacity-100"
            leave-to-class="-translate-y-3 opacity-0"
            move-class="transition duration-200"
        >
            <button
                v-for="banner in banners"
                :key="banner.key"
                type="button"
                class="bg-card text-card-foreground hover:bg-accent pointer-events-auto flex w-full items-start gap-3 rounded-lg border p-3 text-left shadow-lg transition-colors"
                @click="emit('open', banner)"
            >
                <span
                    class="bg-destructive/10 text-destructive mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full"
                >
                    <component :is="iconFor(banner)" class="h-3.5 w-3.5" />
                </span>

                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-semibold">
                        {{ banner.title }}
                    </span>
                    <span
                        class="text-muted-foreground line-clamp-2 block text-xs"
                    >
                        {{ banner.body }}
                    </span>
                </span>

                <span
                    class="text-muted-foreground hover:text-foreground -mr-1 -mt-1 shrink-0 rounded p-1"
                    role="button"
                    aria-label="Dismiss"
                    @click.stop="emit('dismiss', banner.key)"
                >
                    <X class="h-3.5 w-3.5" />
                </span>
            </button>
        </TransitionGroup>
    </div>
</template>
