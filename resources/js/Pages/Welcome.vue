<script setup>
import { Button } from "@/Components/ui/button";
import { Card } from "@/Components/ui/card";
import { Head, Link, usePage } from "@inertiajs/vue3";
import {
    ArrowRight,
    BadgeCheck,
    Building2,
    CalendarDays,
    CircleCheckBig,
    ClipboardList,
    Files,
    MessageSquareText,
    Tag,
    Truck,
    UserRoundPen,
    Users2,
    Wrench,
} from "lucide-vue-next";
import { computed } from "vue";

const page = usePage();

const portalHref = computed(() =>
    page.props?.auth?.user ? route("dashboard") : route("login"),
);
const portalLabel = computed(() =>
    page.props?.auth?.user ? "Open Dashboard" : "Sign In",
);
const greetingName = computed(() => page.props?.auth?.user?.name || "Team");

const heroWorkOrders = [
    {
        createdDate: "12/12/2024",
        label: "Leaking kitchen sink",
        location: "Property A, Unit 101",
        category: "Plumbing",
        isApproved: true,
        requestedBy: "resident",
        vendors: ["plumbing vendor"],
        tasks: "2/4 tasks",
        priority: "High",
        bgClass: "bg-primary",
    },
    {
        createdDate: "12/12/205",
        label: "Make-ready walkthrough",
        location: "Property B, Unit 204",
        category: "Turnover",
        isApproved: false,
        requestedBy: "coordinator",
        vendors: ["make-ready vendor"],
        tasks: "1/3 tasks",
        priority: "Normal",
        bgClass: "bg-green-500",
    },
    {
        createdDate: "12/12/2026",
        label: "Gate access issue",
        location: "Property C, Unit 12",
        category: "Security",
        isApproved: true,
        requestedBy: "owner",
        vendors: ["security vendor"],
        tasks: "0/2 tasks",
        priority: "Urgent",
        bgClass: "bg-destructive",
    },
];

const features = [
    {
        title: "Work Orders",
        description:
            "Manage active, inspections, lawn service, turnovers, waiting on payment, paid, and completed queues.",
        icon: Wrench,
    },
    {
        title: "People",
        description:
            "Coordinate across work order coordinators, vendors, owners, and tenants with role-specific visibility.",
        icon: Users2,
    },
    {
        title: "Tasks",
        description:
            "Use task assignment and templates to standardize follow-up and operational execution.",
        icon: ClipboardList,
    },
    {
        title: "Calendar",
        description:
            "View scheduled service data tied back to work-order service schedules.",
        icon: CalendarDays,
    },
    {
        title: "Messages",
        description:
            "Track work-order communication and delivery visibility from the same operational system.",
        icon: MessageSquareText,
    },
    {
        title: "Invoices And Files",
        description:
            "Keep invoices, attachments, notes, and service updates attached to the same operational record.",
        icon: Files,
    },
];
</script>

<template>
    <Head title="TexasRenters.com Maintenance" />

    <div class="min-h-screen bg-background text-foreground">
        <div
            class="absolute inset-x-0 top-0 -z-10 h-[520px] bg-[radial-gradient(circle_at_top_left,hsl(var(--primary)/0.22),transparent_42%),radial-gradient(circle_at_top_right,hsl(var(--primary)/0.12),transparent_32%)]"
        ></div>

        <nav class="border-b border-border/70 bg-background/90 backdrop-blur">
            <div
                class="mx-auto flex max-w-7xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8"
            >
                <Link href="/" class="flex items-center gap-3">
                    <img
                        src="/tx-portal-logo.png"
                        alt="TexasRenters.com Maintenance"
                        class="h-12 object-contain sm:h-14"
                    />
                </Link>

                <div class="flex items-center gap-3">
                    <Link :href="portalHref">
                        <Button
                            variant="ghost"
                            class="text-foreground hover:text-primary"
                            >{{ portalLabel }}</Button
                        >
                    </Link>
                </div>
            </div>
        </nav>

        <main>
            <section
                class="border-b border-primary/10 bg-[linear-gradient(135deg,rgba(2,6,23,1)_0%,rgba(15,23,42,1)_52%,hsl(var(--primary)/0.92)_100%)]"
            >
                <div
                    class="mx-auto grid max-w-7xl gap-12 px-4 py-16 sm:px-6 lg:grid-cols-[1.05fr_0.95fr] lg:px-8 lg:py-24"
                >
                    <div class="max-w-3xl text-center sm:text-left">
                        <div
                            class="inline-flex items-center rounded-full border border-white/12 bg-white/8 px-3 py-1 text-sm font-medium text-white/90"
                        >
                            TexasRenters.com Maintenance
                        </div>

                        <h1
                            class="mt-6 text-4xl font-semibold tracking-tight text-white sm:text-5xl lg:text-6xl"
                        >
                            Work orders, schedules, messages, and people in one
                            system.
                        </h1>

                        <p
                            class="mt-6 max-w-2xl text-lg leading-8 text-white/75"
                        >
                            TexasRenters.com Maintenance centralizes the
                            operational surface used to manage work-order
                            queues, vendors, invoices, buildings, and role-based
                            communication.
                        </p>

                        <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                            <Link :href="portalHref">
                                <Button
                                    size="lg"
                                    class="w-full bg-white text-primary hover:bg-white/90 sm:w-auto"
                                >
                                    {{
                                        page.props?.auth?.user
                                            ? "Go To Dashboard"
                                            : "Open Portal"
                                    }}
                                </Button>
                            </Link>
                        </div>

                        <div
                            class="mt-10 grid gap-6 text-center sm:grid-cols-3 sm:text-left"
                        >
                            <div>
                                <p class="text-2xl font-semibold text-white">
                                    7 Queues
                                </p>
                                <p class="mt-1 text-sm text-white/70">
                                    Active through completed workflow views
                                </p>
                            </div>
                            <div>
                                <p class="text-2xl font-semibold text-white">
                                    6 Roles
                                </p>
                                <p class="mt-1 text-sm text-white/70">
                                    Role-scoped record visibility
                                </p>
                            </div>
                            <div>
                                <p class="text-2xl font-semibold text-white">
                                    Twilio Aware
                                </p>
                                <p class="mt-1 text-sm text-white/70">
                                    Communication visibility built in
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="relative flex justify-center lg:justify-end">
                        <div
                            class="absolute right-8 top-10 hidden h-36 w-36 rounded-full bg-primary/25 blur-3xl lg:block"
                        ></div>
                        <div
                            class="absolute bottom-8 left-4 hidden h-40 w-40 rounded-full bg-white/10 blur-3xl lg:block"
                        ></div>

                        <div class="relative w-full max-w-[560px] p-4 sm:p-6">
                            <div
                                class="mb-4 flex items-center justify-between rounded-3xl border border-white/15 bg-white/10 px-4 py-3 text-white shadow-lg backdrop-blur"
                            >
                                <div>
                                    <p
                                        class="text-xs font-semibold uppercase tracking-[0.2em] text-white/70"
                                    >
                                        work order preview
                                    </p>
                                    <p class="mt-1 text-sm text-white/90">
                                        Welcome back, {{ greetingName }}
                                    </p>
                                </div>
                                <div
                                    class="rounded-full bg-primary px-3 py-1 text-xs font-medium text-primary-foreground"
                                >
                                    Live UI
                                </div>
                            </div>

                            <div
                                class="rounded-[2rem] border border-white/10 bg-white/95 p-5 shadow-[0_28px_70px_rgba(15,23,42,0.35)]"
                            >
                                <div
                                    class="rounded-[1.5rem] bg-slate-950 p-5 text-white"
                                >
                                    <!-- <div
                                        class="flex items-start justify-between gap-4"
                                    >
                                        <div>
                                            <p
                                                class="text-xs uppercase tracking-[0.22em] text-white/55"
                                            >
                                                Existing work orders
                                            </p>
                                            <h2
                                                class="mt-2 text-2xl font-semibold"
                                            >
                                                WorkOrderCard preview
                                            </h2>
                                        </div>
                                        <div
                                            class="rounded-2xl border border-white/10 bg-white/8 px-3 py-2 text-right"
                                        >
                                            <p class="text-xs text-white/60">
                                                Landing page mode
                                            </p>
                                            <p
                                                class="text-sm font-semibold text-white/85"
                                            >
                                                Dummy data only
                                            </p>
                                        </div>
                                    </div> -->

                                    <div class="mt-6 space-y-3">
                                        <div
                                            v-for="workOrder in heroWorkOrders"
                                            :key="`${workOrder.label}-${workOrder.location}`"
                                            class="rounded-lg p-4 text-white shadow-none"
                                            :class="workOrder.bgClass"
                                        >
                                            <div
                                                class="mb-2 flex items-center justify-between border-b border-white/20 pb-2"
                                            >
                                                <h3
                                                    class="text-sm font-semibold uppercase tracking-[0.16em]"
                                                >
                                                    {{ workOrder.label }}
                                                </h3>
                                                <p
                                                    class="text-xs text-white/80"
                                                >
                                                    {{ workOrder.createdDate }}
                                                </p>
                                            </div>

                                            <p
                                                class="text-sm font-semibold text-white"
                                            >
                                                {{ workOrder.location }}
                                            </p>
                                            <div
                                                class="mt-1 flex gap-2 justify-center"
                                            >
                                                <p
                                                    class="flex items-center gap-1 text-xs text-white/90"
                                                >
                                                    <Tag class="h-3 w-3" />{{
                                                        workOrder.category
                                                    }}
                                                </p>
                                                <p
                                                    v-if="workOrder.isApproved"
                                                    class="flex items-center gap-1 text-xs text-white/90"
                                                >
                                                    <CircleCheckBig
                                                        class="h-3 w-3"
                                                    />Approved
                                                </p>
                                            </div>

                                            <div
                                                class="mt-2 flex gap-1 text-left"
                                            >
                                                <UserRoundPen
                                                    class="h-4 w-4 shrink-0"
                                                />
                                                <p
                                                    class="text-xs uppercase text-white/90"
                                                >
                                                    {{ workOrder.requestedBy }}
                                                </p>
                                            </div>

                                            <p
                                                v-for="vendor in workOrder.vendors"
                                                :key="vendor"
                                                class="text-xs text-white/90"
                                            >
                                                <span
                                                    class="flex gap-1 text-left uppercase"
                                                >
                                                    <Truck class="h-4 w-4" />{{
                                                        vendor
                                                    }}
                                                </span>
                                            </p>

                                            <div
                                                class="mt-2 flex items-center justify-between"
                                            >
                                                <p
                                                    class="text-xs text-white/90"
                                                >
                                                    {{ workOrder.tasks }}
                                                </p>
                                                <span
                                                    class="rounded border border-white/20 bg-white/10 px-2 py-0.5 text-[10px] uppercase"
                                                >
                                                    Priority:
                                                    {{ workOrder.priority }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section
                id="modules"
                class="bg-[linear-gradient(to_bottom,hsl(var(--background)),hsl(var(--primary)/0.04))]"
            >
                <div
                    class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8 lg:py-20"
                >
                    <div class="max-w-3xl">
                        <p class="text-sm font-medium text-primary">
                            Operational Modules
                        </p>
                        <h2
                            class="mt-3 text-3xl font-semibold tracking-tight text-foreground sm:text-4xl"
                        >
                            Core areas inside the maintenance system
                        </h2>
                        <p
                            class="mt-4 max-w-2xl text-base leading-7 text-muted-foreground sm:text-lg"
                        >
                            These cards mirror the actual modules already
                            exposed in the authenticated application.
                        </p>
                    </div>

                    <div class="mt-10 grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                        <Card
                            v-for="feature in features"
                            :key="feature.title"
                            class="rounded-3xl border border-primary/10 bg-card p-6 shadow-[0_18px_45px_rgba(15,23,42,0.06)]"
                        >
                            <div
                                class="flex h-12 w-12 items-center justify-center rounded-2xl bg-primary/10 text-primary"
                            >
                                <component :is="feature.icon" class="h-5 w-5" />
                            </div>
                            <h3
                                class="mt-5 text-lg font-semibold text-card-foreground"
                            >
                                {{ feature.title }}
                            </h3>
                            <p
                                class="mt-2 text-sm leading-7 text-muted-foreground"
                            >
                                {{ feature.description }}
                            </p>
                        </Card>
                    </div>
                </div>
            </section>

            <section class="bg-background">
                <div
                    class="mx-auto max-w-7xl px-4 pb-16 sm:px-6 lg:px-8 lg:pb-20 mt-12"
                >
                    <div
                        class="rounded-[2rem] border border-primary/10 bg-card p-6 shadow-[0_18px_45px_rgba(15,23,42,0.05)] sm:p-8"
                    >
                        <div
                            class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between"
                        >
                            <div class="max-w-2xl">
                                <p class="text-sm font-medium text-primary">
                                    Internal Portal
                                </p>
                                <h2
                                    class="mt-3 text-3xl font-semibold tracking-tight text-card-foreground sm:text-4xl"
                                >
                                    Built for existing maintenance workflows
                                </h2>
                                <p
                                    class="mt-4 text-base leading-7 text-muted-foreground"
                                >
                                    The landing page stays focused on portal
                                    access, real system modules, and a safe
                                    visual preview that uses dummy work-order
                                    content only.
                                </p>
                            </div>

                            <div class="flex flex-col gap-3 sm:flex-row">
                                <Link :href="portalHref">
                                    <Button
                                        size="lg"
                                        class="w-full justify-between sm:w-auto"
                                    >
                                        {{
                                            page.props?.auth?.user
                                                ? "Return To Dashboard"
                                                : "Sign In To Portal"
                                        }}
                                        <ArrowRight class="h-4 w-4" />
                                    </Button>
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <footer class="border-t border-border/70 bg-background">
                <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
                    <div
                        class="flex flex-col gap-8 text-center lg:flex-row lg:items-end lg:justify-between lg:text-left"
                    >
                        <div class="mx-auto max-w-xl lg:mx-0">
                            <Link href="/" class="inline-flex">
                                <img
                                    src="/tx-portal-logo.png"
                                    alt="TexasRenters.com Maintenance"
                                    class="h-14 object-contain sm:h-16"
                                />
                            </Link>
                            <p
                                class="mt-4 text-sm leading-7 text-muted-foreground"
                            >
                                A role-aware maintenance system for work orders,
                                schedules, messages, buildings, invoices, and
                                administrative setup.
                            </p>
                        </div>

                        <div
                            class="flex flex-col gap-3 text-sm text-muted-foreground lg:items-end"
                        >
                            <div
                                class="flex flex-wrap items-center justify-center gap-x-6 gap-y-3 lg:justify-end"
                            >
                                <Link
                                    :href="portalHref"
                                    class="transition hover:text-primary"
                                    >{{
                                        page.props?.auth?.user
                                            ? "Dashboard"
                                            : "Portal Login"
                                    }}</Link
                                >
                                <a
                                    href="#modules"
                                    class="transition hover:text-primary"
                                    >Modules</a
                                >
                            </div>
                        </div>
                    </div>

                    <div
                        class="mt-8 flex flex-col gap-2 border-t border-border pt-5 text-center text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between sm:text-left"
                    >
                        <p>
                            &copy;
                            {{ new Date().getFullYear() }}
                            TexasRenters.com Maintenance. All rights reserved.
                        </p>
                        <p>
                            Developed and maintained by TexasRenters.com IT
                            Department.
                        </p>
                    </div>
                </div>
            </footer>
        </main>
    </div>
</template>
