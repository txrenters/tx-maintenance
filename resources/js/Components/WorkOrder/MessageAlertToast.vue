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

/** Two letters is enough to recognise a regular caller at a glance. */
const initialsFor = (name) =>
    String(name || "")
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join("") || "?";
</script>

<template>
    <!-- pointer-events-none on the stack so it never blocks the page behind it;
         each card turns them back on for itself. -->
    <div
        class="pointer-events-none fixed left-1/2 top-4 z-[110] flex w-full max-w-sm -translate-x-1/2 flex-col gap-2 px-4"
    >
        <TransitionGroup
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="-translate-y-3 scale-95 opacity-0"
            enter-to-class="translate-y-0 scale-100 opacity-100"
            leave-active-class="transition duration-200 ease-in absolute w-full"
            leave-from-class="translate-y-0 scale-100 opacity-100"
            leave-to-class="-translate-y-3 scale-95 opacity-0"
            move-class="transition duration-200"
        >
            <button
                v-for="banner in banners"
                :key="banner.key"
                type="button"
                class="bg-card text-card-foreground ring-destructive/30 hover:bg-accent pointer-events-auto relative flex w-full items-start gap-3 overflow-hidden rounded-lg border py-3 pl-4 pr-3 text-left shadow-xl ring-1 transition-colors"
                @click="emit('open', banner)"
            >
                <!-- Accent bar: reads as "message" before any text is parsed. -->
                <span class="bg-destructive absolute inset-y-0 left-0 w-1" />

                <span class="relative mt-0.5 shrink-0">
                    <span
                        class="bg-destructive/10 text-destructive flex h-9 w-9 items-center justify-center rounded-full text-xs font-semibold"
                    >
                        {{ banner.alert ? initialsFor(banner.alert.from_name) : "" }}
                        <MessagesSquare v-if="!banner.alert" class="h-4 w-4" />
                    </span>
                    <!-- Which conversation, badged onto the avatar. -->
                    <span
                        v-if="banner.alert"
                        class="bg-card ring-border absolute -bottom-0.5 -right-0.5 flex h-4 w-4 items-center justify-center rounded-full ring-1"
                    >
                        <component
                            :is="iconFor(banner)"
                            class="text-muted-foreground h-2.5 w-2.5"
                        />
                    </span>
                </span>

                <span class="min-w-0 flex-1">
                    <span
                        class="text-destructive block text-[10px] font-bold uppercase tracking-wider"
                    >
                        New message
                    </span>
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
