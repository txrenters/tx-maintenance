<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from "vue";
import { Link, usePage } from "@inertiajs/vue3";
import {
    LayoutDashboard,
    Wrench,
    ClipboardList,
    Files,
    CalendarDays,
} from "lucide-vue-next";

const page = usePage();

const isVendor = computed(() =>
    (page.props.auth.user?.roles || []).includes("vendor")
);

const items = computed(() => [
    {
        name: "Dashboard",
        icon: LayoutDashboard,
        url: route("dashboard"),
        active: page.url.startsWith("/dashboard"),
    },
    {
        name: "Work Orders",
        icon: Wrench,
        url: route("work_orders.vendor"),
        active: page.component === "WorkOrder/VendorWorkOrders",
    },
    {
        name: "Tasks",
        icon: ClipboardList,
        url: route("tasks.index"),
        active: page.url.startsWith("/tasks"),
    },
    {
        name: "Invoice",
        icon: Files,
        url: route("invoices.index"),
        active: page.url.startsWith("/work_order/invoices"),
    },
    {
        name: "Calendar",
        icon: CalendarDays,
        url: route("scheduled_service"),
        active: page.url.startsWith("/scheduled_service"),
    },
]);

// Auto-hide: slide the bar down while the user scrolls down the page, and bring
// it back on scroll up, when scrolling stops, or near the top of the page.
const hidden = ref(false);
let lastScrollY = 0;
let stopTimer = null;

const onScroll = () => {
    const y = window.scrollY;

    if (y < 40) {
        hidden.value = false;
    } else if (y > lastScrollY + 6) {
        hidden.value = true;
    } else if (y < lastScrollY - 6) {
        hidden.value = false;
    }

    lastScrollY = y;

    // Reveal again once scrolling settles.
    clearTimeout(stopTimer);
    stopTimer = setTimeout(() => {
        hidden.value = false;
    }, 250);
};

onMounted(() => {
    lastScrollY = window.scrollY;
    window.addEventListener("scroll", onScroll, { passive: true });
});

onBeforeUnmount(() => {
    window.removeEventListener("scroll", onScroll);
    clearTimeout(stopTimer);
});
</script>

<template>
    <nav
        v-if="isVendor"
        class="fixed inset-x-0 bottom-0 z-50 border-t border-border bg-background/95 shadow-[0_-2px_10px_rgba(0,0,0,0.08)] backdrop-blur transition-transform duration-300 md:hidden"
        :class="hidden ? 'translate-y-full' : 'translate-y-0'"
        style="padding-bottom: env(safe-area-inset-bottom)"
    >
        <div class="flex items-stretch justify-around">
            <Link
                v-for="item in items"
                :key="item.name"
                :href="item.url"
                class="relative flex flex-1 flex-col items-center gap-1 py-2 text-[10px] font-medium transition-colors"
                :class="
                    item.active
                        ? 'text-primary'
                        : 'text-muted-foreground hover:text-foreground'
                "
            >
                <!-- Active "peek": a top accent pill + a soft tint behind the icon. -->
                <span
                    v-if="item.active"
                    class="absolute inset-x-4 top-0 h-1 rounded-full bg-primary"
                />
                <span
                    class="flex h-8 w-12 items-center justify-center rounded-full transition-colors"
                    :class="item.active ? 'bg-primary/10' : ''"
                >
                    <component
                        :is="item.icon"
                        class="h-5 w-5"
                        :stroke-width="item.active ? 2.5 : 2"
                    />
                </span>
                <span class="leading-none">{{ item.name }}</span>
            </Link>
        </div>
    </nav>
</template>
