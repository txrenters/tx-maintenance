<script setup>
import { ref, computed, watch, onMounted } from "vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import { useToast } from "@/Components/ui/toast/use-toast";
import {
    Toast,
    ToastClose,
    ToastDescription,
    ToastProvider,
    ToastTitle,
    ToastViewport,
} from "@/Components/ui/toast";
import GalleryTile from "@/Components/PortalGalleryTile.vue";
import BrandHeader from "@/Components/PortalBrandHeader.vue";
import BrandFooter from "@/Components/PortalBrandFooter.vue";
import { detectTapback, quotedExcerpt } from "@/utils/tapback.js";
import {
    Loader2,
    Camera,
    Send,
    FileText,
    MessageSquare,
    MapPin,
    CalendarClock,
    AlertTriangle,
    ChevronRight,
    ThumbsUp,
    ThumbsDown,
    X,
} from "lucide-vue-next";

const props = defineProps({
    token: String,
    ownerName: String,
    wocName: String,
    workOrder: Object,
    approval: { type: Object, default: () => ({}) },
    messages: { type: Array, default: () => [] },
    attachments: { type: Array, default: () => [] },
    unreadMessages: { type: Number, default: 0 },
});

const page = usePage();
const { toast, toasts } = useToast();

// Dark/light theme, kept under its own key so an owner's choice is independent
// of the vendor portal's. Defaults to dark, matching the vendor portal.
const isDark = ref(true);

onMounted(() => {
    const saved = localStorage.getItem("ownerPortalTheme");
    if (saved) {
        isDark.value = saved === "dark";
    }
});

const toggleTheme = () => {
    isDark.value = !isDark.value;
    localStorage.setItem("ownerPortalTheme", isDark.value ? "dark" : "light");
};

// An owner's iPhone tapback ("Liked \"…\"") arrives as a text quoting the
// entire original message; render it as a compact reaction chip instead.
// Only the owner's own messages can be tapbacks.
const tapbackOf = (m) => (m.from_owner ? detectTapback(m.message) : null);

// Classify a message attachment so it renders as a video player, PDF link, or
// image. Prefers content_type; falls back to the file extension for older rows.
const mediaKind = (media) => {
    const type = (media?.content_type || "").toLowerCase();
    const name = (media?.file_name || "").toLowerCase();

    if (type.startsWith("video/") || /\.(mp4|mov|m4v|3gp|3gpp|webm)$/.test(name)) {
        return "video";
    }
    if (type === "application/pdf" || name.endsWith(".pdf")) {
        return "pdf";
    }
    return "image";
};

// Image lightbox (modal) for viewing photos in-page.
const lightbox = ref(null);

// Surface server flash + validation messages as auto-dismissing toasts (5s).
watch(
    () => page.props.flash,
    (f) => {
        if (!f) return;
        if (f.success)
            toast({ title: "Success", description: f.success, duration: 5000 });
        if (f.error)
            toast({
                variant: "destructive",
                title: "Something went wrong",
                description: f.error,
                duration: 5000,
            });
    },
    { deep: true, immediate: true }
);

watch(
    () => page.props.errors,
    (errors) => {
        const first = errors && Object.values(errors)[0];
        if (first)
            toast({
                variant: "destructive",
                title: "Please check your entry",
                description: first,
                duration: 5000,
            });
    },
    { deep: true }
);

// --- Tabs ---
// Messages and photos only: the owner has no estimate, schedule or invoice.
const tabs = [
    { key: "message", label: "Messages", icon: MessageSquare },
    { key: "photos", label: "Photos", icon: Camera },
];
const activeTab = ref("message");
const localUnread = ref(props.unreadMessages);

const markRead = () => {
    if (localUnread.value === 0) return;
    localUnread.value = 0;
    router.post(
        route("owner.portal.messages.read", props.token),
        {},
        { preserveScroll: true, preserveState: true }
    );
};

const selectTab = (key) => {
    activeTab.value = key;
    if (key === "message") markRead();
};

// The Messages tab is open on arrival, so clear the badge straight away.
onMounted(markRead);

// Shared by the message composer and the photo upload below: what counts as a
// picture we can show a thumbnail of.
const isImageFile = (file) =>
    file.type.startsWith("image/") || /\.(jpe?g|png|gif|webp|heic)$/i.test(file.name);

// --- Message coordinator ---
const messageText = ref("");
const messageImages = ref([]);
const messageInput = ref(null);
const sending = ref(false);

// What each picked file looks like before it is sent: a thumbnail of the
// picture itself rather than its file name, which tells the owner nothing.
// Object URLs are revoked as soon as the thumbnail goes, so picking and
// removing photos repeatedly cannot leak them.
const messagePreviews = ref([]);

const onPickMessageImages = (e) => {
    Array.from(e.target.files || []).forEach((file) => {
        const isDuplicate = messageImages.value.some(
            (existing) => existing.name === file.name && existing.size === file.size,
        );
        if (!isDuplicate) {
            messageImages.value.push(file);
            const image = isImageFile(file);
            messagePreviews.value.push({
                name: file.name,
                isImage: image,
                url: image ? URL.createObjectURL(file) : null,
            });
        }
    });
    // Reset so re-picking the same file still fires @change.
    e.target.value = "";
};

const removeMessageImage = (index) => {
    const [removed] = messagePreviews.value.splice(index, 1);
    if (removed?.url) {
        URL.revokeObjectURL(removed.url);
    }
    messageImages.value.splice(index, 1);
};

const clearMessagePreviews = () => {
    messagePreviews.value.forEach((preview) => {
        if (preview.url) {
            URL.revokeObjectURL(preview.url);
        }
    });
    messagePreviews.value = [];
};

// Where the wordmark sits behind the thread. Scattered by hand rather than
// randomised: a fixed set cannot reshuffle on re-render, and these positions
// are spread so no two marks overlap into a dark patch. Percentages, so the
// spread holds at any width.
const wallpaperMarks = [
    { id: 1, top: "-4%", left: "-6%", rotate: "-14deg" },
    { id: 2, top: "12%", left: "58%", rotate: "9deg" },
    { id: 3, top: "34%", left: "14%", rotate: "-5deg" },
    { id: 4, top: "52%", left: "70%", rotate: "17deg" },
    { id: 5, top: "70%", left: "-2%", rotate: "7deg" },
    { id: 6, top: "86%", left: "44%", rotate: "-11deg" },
].map((mark) => ({
    id: mark.id,
    style: {
        top: mark.top,
        left: mark.left,
        transform: `rotate(${mark.rotate})`,
    },
}));

/**
 * The thread as a chat app lays it out: each message tagged with the day it
 * belongs to, so a date chip can be dropped in whenever the day changes and
 * the bubbles themselves only need to carry a clock time.
 */
const messageGroups = computed(() => {
    const groups = [];

    props.messages.forEach((message) => {
        const day = String(message.created_at ?? "").slice(0, 10);
        const last = groups[groups.length - 1];

        if (last && last.day === day) {
            last.messages.push(message);

            return;
        }

        groups.push({ day, label: fmtDate(message.created_at), messages: [message] });
    });

    return groups;
});

const canSendMessage = computed(
    () => messageText.value.trim().length > 0 || messageImages.value.length > 0
);

const sendMessage = () => {
    if (!canSendMessage.value) return;
    sending.value = true;
    const data = new FormData();
    data.append("text", messageText.value);
    messageImages.value.forEach((file) => data.append("images[]", file));

    router.post(route("owner.portal.message", props.token), data, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            messageText.value = "";
            messageImages.value = [];
            clearMessagePreviews();
            if (messageInput.value) messageInput.value.value = "";
        },
        onFinish: () => (sending.value = false),
    });
};

// --- Photo upload ---
const selectedFiles = ref([]);
const selectedPreviews = ref([]);
const photoInput = ref(null);
const uploading = ref(false);

const onPickFiles = (e) => {
    const picked = Array.from(e.target.files || []);
    picked.forEach((file) => {
        const isDuplicate = selectedFiles.value.some(
            (existing) => existing.name === file.name && existing.size === file.size,
        );
        if (isDuplicate) {
            return;
        }
        selectedFiles.value.push(file);
        const image = isImageFile(file);
        selectedPreviews.value.push({
            name: file.name,
            isImage: image,
            url: image ? URL.createObjectURL(file) : null,
        });
    });
    e.target.value = "";
};

const removeSelectedFile = (index) => {
    const [removed] = selectedPreviews.value.splice(index, 1);
    if (removed?.url) {
        URL.revokeObjectURL(removed.url);
    }
    selectedFiles.value.splice(index, 1);
};

const uploadPhotos = () => {
    if (selectedFiles.value.length === 0) return;
    uploading.value = true;
    const data = new FormData();
    selectedFiles.value.forEach((file) => data.append("files[]", file));

    router.post(route("owner.portal.attachments", props.token), data, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            selectedFiles.value = [];
            selectedPreviews.value = [];
            if (photoInput.value) photoInput.value.value = "";
        },
        onFinish: () => (uploading.value = false),
    });
};

// --- Owner approval ---
// Buttons show only while the work order is waiting on this owner's approval
// and they haven't already answered; after that the recorded decision shows.
const approvalPending = computed(
    () => props.approval?.requested && !props.approval?.decision
);
const coordinatorLabel = computed(
    () => props.wocName || "your work order coordinator"
);
const approvalSubmitting = ref(null);

const submitApproval = (decision) => {
    if (approvalSubmitting.value) return;
    approvalSubmitting.value = decision;
    router.post(
        route("owner.portal.approval", props.token),
        { decision },
        {
            preserveScroll: true,
            onFinish: () => (approvalSubmitting.value = null),
        }
    );
};

const priorityClass = computed(() => {
    const p = (props.workOrder?.priority || "").toLowerCase();
    if (p.includes("high") || p.includes("emergency"))
        return "bg-destructive/10 text-destructive";
    if (p.includes("medium")) return "bg-amber-100 text-amber-700";
    return "bg-muted text-muted-foreground";
});

const addressLine = computed(
    () => props.workOrder?.address || props.workOrder?.property_name || "Address unavailable"
);

// Accepts ISO, MySQL "YYYY-MM-DD HH:MM:SS", and date-only strings. Built from
// the parts so it never shows "Invalid Date" or shifts a day by timezone.
const fmtDate = (d) => {
    if (!d) return "";
    const [y, m, day] = String(d).slice(0, 10).split("-").map(Number);
    if (!y || !m || !day) return "";
    return new Date(y, m - 1, day).toLocaleDateString(undefined, {
        month: "short",
        day: "numeric",
        year: "numeric",
    });
};

// Midnight means the vendor set a date with no specific time.
const fmtDateTime = (d) => {
    if (!d) return "";
    const time = String(d).slice(11, 16);
    if (!time || time === "00:00") return fmtDate(d);

    const [hour, minute] = time.split(":").map(Number);
    const suffix = hour >= 12 ? "PM" : "AM";
    const hour12 = hour % 12 === 0 ? 12 : hour % 12;

    return `${fmtDate(d)} at ${hour12}:${String(minute).padStart(2, "0")} ${suffix}`;
};

/**
 * Just the clock time, for the corner of a bubble — the full date would not
 * fit there and is carried by the day separator above the group instead.
 */
const fmtClock = (d) => {
    if (!d) return "";
    const time = String(d).slice(11, 16);
    if (!time) return "";

    const [hour, minute] = time.split(":").map(Number);
    if (Number.isNaN(hour) || Number.isNaN(minute)) return "";

    const suffix = hour >= 12 ? "PM" : "AM";
    const hour12 = hour % 12 === 0 ? 12 : hour % 12;

    return `${hour12}:${String(minute).padStart(2, "0")} ${suffix}`;
};

const appointmentLine = computed(() => {
    const appointment = props.workOrder?.appointment;
    if (!appointment?.scheduled_date) return "";

    const start = fmtDateTime(appointment.scheduled_date);
    const end = appointment.scheduled_end_date
        ? fmtDate(appointment.scheduled_end_date)
        : "";

    return end && end !== fmtDate(appointment.scheduled_date)
        ? `${start} → ${end}`
        : start;
});

// The work order's own details, skipping anything we don't have on file.
const details = computed(() =>
    [
        // The address is already under the heading with its pin; repeating it
        // here just pads the list.
        { label: "Area", value: props.workOrder?.location },
        { label: "Category", value: props.workOrder?.category },
        { label: "Type", value: props.workOrder?.type },
        { label: "Submitted", value: fmtDate(props.workOrder?.created_date) },
        { label: "Coordinator", value: props.workOrder?.coordinator },
    ].filter((d) => d.value)
);

// The gallery is filtered by source through its own tab strip. Order is fixed
// so the tabs don't reshuffle as new photos arrive, and a source with nothing
// in it never gets a tab.
const photoFilters = computed(() => {
    const order = [
        "From tenant",
        "From work order coordinator",
        "From you",
    ];

    const available = order
        .filter((label) => props.attachments.some((a) => a.source === label))
        .map((label) => ({
            label,
            count: props.attachments.filter((a) => a.source === label).length,
        }));

    return available.length > 1
        ? [{ label: "All", count: props.attachments.length }, ...available]
        : available;
});

const activePhotoFilter = ref("All");

const visiblePhotos = computed(() =>
    activePhotoFilter.value === "All"
        ? props.attachments
        : props.attachments.filter((a) => a.source === activePhotoFilter.value)
);

// Keep the selection valid when the available sources change.
watch(photoFilters, (filters) => {
    if (!filters.some((f) => f.label === activePhotoFilter.value)) {
        activePhotoFilter.value = filters[0]?.label ?? "All";
    }
});
</script>

<template>
    <div :class="isDark ? 'dark' : ''">
    <Head :title="`Work Order #${workOrder.work_order_no}`" />

    <!-- The shared Toaster drops its messages into the bottom-right corner
         from sm: up. This is the owner's own page, read mostly on a phone and
         short enough that a corner far from what they just tapped is easy to
         miss, so the same toasts are rendered here pinned to the top. The
         shared component is left alone: every other page still uses it. -->
    <ToastProvider>
        <Toast v-for="t in toasts" :key="t.id" v-bind="t">
            <div class="grid gap-1">
                <ToastTitle v-if="t.title">{{ t.title }}</ToastTitle>
                <ToastDescription v-if="t.description">
                    {{ t.description }}
                </ToastDescription>
                <ToastClose />
            </div>
            <component :is="t.action" />
        </Toast>
        <ToastViewport
            class="top-0 bottom-auto right-0 flex-col sm:bottom-auto sm:top-0 sm:flex-col"
        />
    </ToastProvider>

    <!-- Photo lightbox -->
    <div
        v-if="lightbox"
        class="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4"
        @click="lightbox = null"
    >
        <button
            type="button"
            class="absolute top-4 right-4 text-primary-foreground/80 hover:text-primary-foreground"
            @click="lightbox = null"
        >
            <X class="w-8 h-8" />
        </button>
        <img
            :src="lightbox"
            class="max-h-full max-w-full rounded-lg object-contain"
            @click.stop
        />
    </div>

    <div class="min-h-screen bg-muted dark:bg-neutral-950">
        <div class="mx-auto w-full max-w-md lg:max-w-5xl px-4 py-5 space-y-4">
            <!-- Two columns on desktop, single stack on mobile -->
            <div
                class="lg:grid lg:grid-cols-2 lg:gap-5 lg:items-start space-y-4 lg:space-y-0"
            >
                <!-- Left column: branded header + request details -->
                <div class="rounded-lg border bg-card text-card-foreground shadow-sm overflow-hidden">
                    <BrandHeader :is-dark="isDark" @toggle-theme="toggleTheme" />

                    <!-- Tighter gutters on a small phone, roomier once there
                         is width for it. -->
                    <div class="p-4 sm:p-5">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-semibold text-muted-foreground">
                            Hi {{ ownerName }}
                        </span>
                        <!-- Where the work order stands: the one piece of state
                             the owner is here to check, so it carries a little
                             more weight than the greeting opposite it. -->
                        <span
                            v-if="workOrder.status"
                            class="shrink-0 rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold text-primary ring-1 ring-inset ring-primary/20"
                        >
                            {{ workOrder.status }}
                        </span>
                    </div>

                    <h1 class="text-xl font-bold text-foreground">
                        Work Order #{{ workOrder.work_order_no }}
                    </h1>

                    <div class="mt-2 flex items-start gap-2 text-muted-foreground text-sm">
                        <MapPin class="w-4 h-4 mt-0.5 shrink-0" />
                        <span>{{ addressLine }}</span>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <span
                            v-if="workOrder.priority"
                            class="text-xs font-medium rounded-full px-3 py-1"
                            :class="priorityClass"
                        >
                            {{ workOrder.priority }}
                        </span>
                        <span
                            v-if="workOrder.is_emergency"
                            class="inline-flex items-center gap-1 text-xs font-medium rounded-full bg-destructive/10 text-destructive px-3 py-1"
                        >
                            <AlertTriangle class="w-3 h-3" /> Emergency
                        </span>
                    </div>

                    <!-- What the owner is being asked to approve, so it reads
                         before the buttons rather than below them. A long
                         request scrolls inside its own box: the card keeps its
                         height on a phone and the buttons stay reachable.
                         break-words so an unbroken string cannot widen the
                         page sideways. -->
                    <div
                        v-if="workOrder.description"
                        class="mt-4 rounded-lg bg-muted/50 px-3 py-2.5"
                    >
                        <p class="text-xs font-medium text-muted-foreground">
                            Description
                        </p>
                        <p
                            class="mt-1 max-h-48 overflow-y-auto overscroll-contain whitespace-pre-line break-words text-sm font-medium leading-relaxed text-foreground sm:max-h-64 sm:text-base"
                        >
                            {{ workOrder.description }}
                        </p>
                    </div>

                    <!-- Approve / disapprove, while the work order waits on
                         this owner's decision. Deliberately understated: an
                         alarm-styled callout makes a routine repair read as a
                         crisis, and a greyed-out one makes it look skippable.
                         A plain bordered block, like the rest of the card. -->
                    <div
                        v-if="approvalPending"
                        class="mt-4 rounded-lg border bg-card p-3"
                    >
                        <p class="text-sm text-foreground">
                            Let us know if you'd like us to go ahead with this
                            one.
                        </p>
                        <!-- Stacked on a narrow phone, side by side once there
                             is room: "Don't approve" cannot shrink to two
                             clipped lines beside Approve. min-h-[44px] keeps
                             both at a comfortable touch size. -->
                        <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <button
                                type="button"
                                :disabled="!!approvalSubmitting"
                                class="flex min-h-[44px] items-center justify-center gap-2 rounded-md bg-emerald-600/90 px-3 py-3 text-sm font-medium text-white transition-colors hover:bg-emerald-600 disabled:opacity-60"
                                @click="submitApproval('approved')"
                            >
                                <Loader2
                                    v-if="approvalSubmitting === 'approved'"
                                    class="w-4 h-4 shrink-0 animate-spin"
                                />
                                <ThumbsUp v-else class="w-4 h-4 shrink-0" />
                                Approve
                            </button>
                            <button
                                type="button"
                                :disabled="!!approvalSubmitting"
                                class="flex min-h-[44px] items-center justify-center gap-2 rounded-md border border-input px-3 py-3 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground disabled:opacity-60"
                                @click="submitApproval('disapproved')"
                            >
                                <Loader2
                                    v-if="approvalSubmitting === 'disapproved'"
                                    class="w-4 h-4 shrink-0 animate-spin"
                                />
                                <ThumbsDown v-else class="w-4 h-4 shrink-0" />
                                <span class="whitespace-nowrap">Don't approve</span>
                            </button>
                        </div>
                        <p class="mt-2 text-xs text-muted-foreground">
                            Either way, {{ coordinatorLabel }} will see your
                            answer.
                        </p>
                    </div>

                    <!-- The decision already on file, once the owner has
                         answered -->
                    <div
                        v-else-if="approval?.decision"
                        class="mt-4 rounded-lg border bg-card px-3 py-2"
                    >
                        <p class="flex items-center gap-1.5 text-sm text-foreground">
                            <component
                                :is="
                                    approval.decision === 'approved'
                                        ? ThumbsUp
                                        : ThumbsDown
                                "
                                class="w-4 h-4 shrink-0"
                                :class="
                                    approval.decision === 'approved'
                                        ? 'text-emerald-600 dark:text-emerald-500'
                                        : 'text-muted-foreground'
                                "
                            />
                            {{
                                approval.decision === "approved"
                                    ? "You approved this work order"
                                    : "You did not approve this work order"
                            }}<template v-if="fmtDate(approval.decided_at)">
                                on {{ fmtDate(approval.decided_at) }}</template
                            >.
                        </p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            We've let {{ coordinatorLabel }} know. Changed your
                            mind? Just send us a message.
                        </p>
                    </div>

                    <!-- Appointment, when a vendor has set one -->
                    <div
                        v-if="appointmentLine"
                        class="mt-4 flex items-start gap-2 rounded-lg bg-primary/10 px-3 py-2"
                    >
                        <CalendarClock class="w-4 h-4 mt-0.5 shrink-0 text-primary" />
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase text-primary">
                                Appointment
                            </p>
                            <p class="text-sm text-foreground">{{ appointmentLine }}</p>
                        </div>
                    </div>

                    <!-- Request details -->
                    <dl v-if="details.length" class="mt-4 divide-y border-t">
                        <!-- Stacks on narrow phones so a long address is never
                             squeezed into a sliver beside its label. A hairline
                             between rows keeps label and value paired up when
                             several values wrap. -->
                        <div
                            v-for="d in details"
                            :key="d.label"
                            class="py-2 text-sm sm:flex sm:items-start sm:justify-between sm:gap-3"
                        >
                            <dt class="text-muted-foreground sm:shrink-0">{{ d.label }}</dt>
                            <dd class="min-w-0 break-words font-medium text-foreground sm:text-right">
                                {{ d.value }}
                            </dd>
                        </div>
                    </dl>

                    <!-- Photos live in their own tab; link across rather than
                         rendering the whole gallery twice. -->
                    <button
                        v-if="attachments.length"
                        type="button"
                        class="mt-4 flex w-full items-center justify-between gap-2 border-t pt-3 text-xs font-semibold uppercase text-muted-foreground hover:text-foreground"
                        @click="selectTab('photos')"
                    >
                        <span class="truncate">Photos ({{ attachments.length }})</span>
                        <span class="inline-flex shrink-0 items-center gap-1 text-primary normal-case">
                            View all <ChevronRight class="w-3.5 h-3.5" />
                        </span>
                    </button>
                    </div>
                </div>

                <!-- Right column: tabbed actions -->
                <div class="rounded-lg border bg-card text-card-foreground shadow-sm overflow-hidden">
                    <!-- Tab bar -->
                    <div class="grid grid-cols-2 border-b">
                        <button
                            v-for="t in tabs"
                            :key="t.key"
                            type="button"
                            class="flex flex-col items-center gap-1 border-b-2 py-3 text-xs font-medium transition-colors"
                            :class="
                                activeTab === t.key
                                    ? 'border-primary bg-primary/5 text-primary'
                                    : 'border-transparent text-muted-foreground hover:bg-accent/50 hover:text-foreground'
                            "
                            @click="selectTab(t.key)"
                        >
                            <span class="relative">
                                <component :is="t.icon" class="w-5 h-5" />
                                <span
                                    v-if="t.key === 'message' && localUnread > 0"
                                    class="absolute -top-1.5 -right-2.5 min-w-[16px] h-4 px-1 rounded-full bg-destructive text-destructive-foreground text-[10px] font-bold flex items-center justify-center"
                                >
                                    {{ localUnread }}
                                </span>
                            </span>
                            {{ t.label }}
                        </button>
                    </div>

                    <div class="p-5">
                        <!-- Message coordinator -->
                        <div v-show="activeTab === 'message'">
                            <p class="text-xs text-muted-foreground mb-3">
                                <template v-if="wocName">{{ wocName }} ·</template>
                                We'll get back to you here.
                            </p>

                            <!-- Branded backdrop: the wordmark scattered behind
                                 the thread, blurred a touch so it reads as
                                 wallpaper and never competes with the words on
                                 top of it. aria-hidden and pointer-events-none
                                 - it is decoration, not content. The bubbles
                                 sit in their own stacking context above it. -->
                            <div
                                v-if="messages.length"
                                class="relative mb-4 overflow-hidden rounded-lg bg-muted/30"
                            >
                                <!-- Outside the scroller, so the wallpaper
                                     stays put while the thread moves over it. -->
                                <div
                                    aria-hidden="true"
                                    class="pointer-events-none absolute inset-0 overflow-hidden"
                                >
                                    <img
                                        v-for="mark in wallpaperMarks"
                                        :key="mark.id"
                                        src="/tx-logo.png"
                                        alt=""
                                        class="absolute w-28 max-w-none select-none opacity-[0.07] blur-[1.5px] dark:opacity-[0.10] dark:invert"
                                        :style="mark.style"
                                    />
                                </div>
                                <div
                                    class="relative max-h-72 space-y-1.5 overflow-y-auto overscroll-contain p-3"
                                >
                                <template
                                    v-for="group in messageGroups"
                                    :key="group.day"
                                >
                                <!-- One date chip per day, so each bubble only
                                     has to carry its clock time. -->
                                <div v-if="group.label" class="flex justify-center py-1">
                                    <span
                                        class="rounded-full bg-background/80 px-2.5 py-0.5 text-[10px] font-medium text-muted-foreground shadow-sm ring-1 ring-border"
                                    >
                                        {{ group.label }}
                                    </span>
                                </div>
                                <div
                                    v-for="m in group.messages"
                                    :key="m.id"
                                    class="flex"
                                    :class="m.from_owner ? 'justify-end' : 'justify-start'"
                                >
                                    <!-- Telegram-style: a generous 18px radius
                                         with one squared-off corner on the side
                                         the message came from, so the tail
                                         points at its sender. Outgoing keeps
                                         the brand colour; incoming sits on the
                                         card so it reads against the wallpaper. -->
                                    <div
                                        class="min-w-0 max-w-[85%] rounded-2xl px-3 py-1.5 text-sm shadow-sm"
                                        :class="
                                            m.from_owner
                                                ? 'rounded-br-md bg-primary text-primary-foreground'
                                                : 'rounded-bl-md bg-card text-foreground ring-1 ring-border'
                                        "
                                    >
                                        <!-- Messages carry the portal URL, which has no
                                             spaces to wrap on: break anywhere so a narrow
                                             phone never gets one character per line. -->
                                        <p
                                            v-if="m.message && !tapbackOf(m)"
                                            class="whitespace-pre-line break-words [overflow-wrap:anywhere]"
                                        >
                                            {{ m.message }}
                                        </p>
                                        <p
                                            v-else-if="tapbackOf(m)"
                                            class="flex items-center gap-1.5 break-words [overflow-wrap:anywhere]"
                                            :class="tapbackOf(m).removal ? 'opacity-60' : ''"
                                        >
                                            <span class="text-base leading-none">{{ tapbackOf(m).emoji }}</span>
                                            <span>{{ tapbackOf(m).label }}</span>
                                            <span
                                                v-if="quotedExcerpt(tapbackOf(m))"
                                                class="text-xs italic opacity-60"
                                                >“{{ quotedExcerpt(tapbackOf(m)) }}”</span
                                            >
                                        </p>
                                        <div
                                            v-if="m.media && m.media.length"
                                            class="mt-1 grid grid-cols-2 gap-1"
                                        >
                                            <template
                                                v-for="(media, i) in m.media"
                                                :key="i"
                                            >
                                                <video
                                                    v-if="mediaKind(media) === 'video'"
                                                    :src="media.url"
                                                    controls
                                                    playsinline
                                                    class="rounded-lg w-full h-20 object-cover bg-black"
                                                />
                                                <a
                                                    v-else-if="mediaKind(media) === 'pdf'"
                                                    :href="media.url"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    class="flex items-center gap-1 rounded-lg border px-2 py-3 text-xs truncate"
                                                >
                                                    <FileText class="h-4 w-4 shrink-0" />
                                                    <span class="truncate">{{ media.file_name || "Document.pdf" }}</span>
                                                </a>
                                                <a
                                                    v-else
                                                    :href="media.url"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                >
                                                    <img
                                                        :src="media.url"
                                                        class="rounded-lg w-full h-20 object-cover"
                                                    />
                                                </a>
                                            </template>
                                        </div>
                                        <!-- Tucked to the bottom-right inside
                                             the bubble, the way a chat app
                                             does it, so it costs no line of
                                             its own. -->
                                        <p
                                            v-if="fmtClock(m.created_at)"
                                            class="-mt-0.5 text-right text-[10px] leading-tight opacity-60"
                                        >
                                            {{ fmtClock(m.created_at) }}
                                        </p>
                                    </div>
                                </div>
                                </template>
                                </div>
                            </div>
                            <div
                                v-else
                                class="mb-4 rounded-lg border border-dashed py-6 text-center"
                            >
                                <MessageSquare
                                    class="mx-auto h-6 w-6 text-muted-foreground/60"
                                />
                                <p class="mt-2 text-sm text-muted-foreground">
                                    No messages yet
                                </p>
                                <p class="text-xs text-muted-foreground/80">
                                    Ask us anything about this work order.
                                </p>
                            </div>

                            <!-- The pictures themselves, not their file names:
                                 an owner recognises the photo they just took,
                                 never "IMG_4821.png". A video or anything else
                                 with no thumbnail still falls back to a name. -->
                            <div
                                v-if="messagePreviews.length"
                                class="mb-2 flex flex-wrap gap-2"
                            >
                                <div
                                    v-for="(preview, i) in messagePreviews"
                                    :key="i"
                                    class="relative"
                                >
                                    <img
                                        v-if="preview.isImage"
                                        :src="preview.url"
                                        :alt="preview.name"
                                        class="h-16 w-16 rounded-lg border border-input object-cover"
                                    />
                                    <span
                                        v-else
                                        class="flex h-16 w-16 flex-col items-center justify-center gap-1 rounded-lg border border-input bg-muted px-1"
                                    >
                                        <FileText
                                            class="h-5 w-5 text-muted-foreground"
                                        />
                                        <span
                                            class="w-full truncate text-center text-[10px] leading-tight text-muted-foreground"
                                            >{{ preview.name }}</span
                                        >
                                    </span>
                                    <button
                                        type="button"
                                        aria-label="Remove photo"
                                        class="absolute -right-1.5 -top-1.5 rounded-full bg-muted-foreground p-1 text-primary-foreground shadow"
                                        @click="removeMessageImage(i)"
                                    >
                                        <X class="h-3 w-3" />
                                    </button>
                                </div>
                            </div>

                            <textarea
                                v-model="messageText"
                                rows="3"
                                placeholder="Type a message..."
                                class="w-full rounded-md border border-input bg-background text-foreground placeholder:text-muted-foreground px-4 py-3 text-base resize-none focus:border-ring focus:ring-0"
                            ></textarea>

                            <div class="flex gap-2 mt-2">
                                <label
                                    class="flex shrink-0 items-center justify-center rounded-md border border-input px-4 py-3 text-muted-foreground cursor-pointer active:bg-accent"
                                >
                                    <Camera class="w-5 h-5" />
                                    <input
                                        ref="messageInput"
                                        type="file"
                                        accept="image/*,video/*"
                                        multiple
                                        class="hidden"
                                        @change="onPickMessageImages"
                                    />
                                </label>
                                <!-- Nothing to send is a disabled button, not a
                                     tap that silently does nothing. -->
                                <button
                                    type="button"
                                    :disabled="sending || !canSendMessage"
                                    class="min-w-0 flex-1 rounded-md bg-primary px-2 py-3 font-medium text-primary-foreground hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-50 flex items-center justify-center gap-2 transition-colors"
                                    @click="sendMessage"
                                >
                                    <Loader2 v-if="sending" class="w-4 h-4 animate-spin" />
                                    <Send v-else class="w-4 h-4" />
                                    Send
                                </button>
                            </div>
                        </div>

                        <!-- Photos -->
                        <div v-show="activeTab === 'photos'" class="space-y-3">
                            <label
                                class="flex flex-col items-center justify-center gap-2 rounded-md border-2 border-dashed border-input py-7 text-muted-foreground cursor-pointer active:bg-accent"
                            >
                                <Camera class="w-7 h-7" />
                                <span class="text-sm font-medium"
                                    >Tap to add photos or files</span
                                >
                                <span class="text-[10px] text-muted-foreground"
                                    >Images, videos or PDF</span
                                >
                                <span class="text-[10px] text-muted-foreground"
                                    >Add as many as you like — pick more anytime and
                                    they'll stack up</span
                                >
                                <input
                                    ref="photoInput"
                                    type="file"
                                    accept="image/*,video/*,.pdf"
                                    multiple
                                    class="hidden"
                                    @change="onPickFiles"
                                />
                            </label>

                            <!-- Selected file previews -->
                            <div
                                v-if="selectedPreviews.length"
                                class="grid grid-cols-3 gap-2"
                            >
                                <div
                                    v-for="(p, i) in selectedPreviews"
                                    :key="i"
                                    class="relative aspect-square rounded-lg overflow-hidden bg-muted border flex items-center justify-center"
                                >
                                    <img
                                        v-if="p.isImage"
                                        :src="p.url"
                                        class="w-full h-full object-cover"
                                    />
                                    <div
                                        v-else
                                        class="flex flex-col items-center justify-center gap-1 w-full px-1"
                                    >
                                        <FileText class="w-6 h-6 text-muted-foreground" />
                                        <span
                                            class="text-[10px] leading-tight text-muted-foreground text-center w-full truncate"
                                            >{{ p.name }}</span
                                        >
                                    </div>
                                    <button
                                        type="button"
                                        class="absolute top-1 right-1 bg-foreground/70 text-background rounded-full p-0.5"
                                        @click="removeSelectedFile(i)"
                                    >
                                        <X class="w-3 h-3" />
                                    </button>
                                </div>
                            </div>

                            <button
                                v-if="selectedFiles.length"
                                type="button"
                                :disabled="uploading"
                                class="w-full rounded-md bg-primary text-primary-foreground font-medium py-3 hover:bg-primary/90 disabled:opacity-60 flex items-center justify-center gap-2"
                                @click="uploadPhotos"
                            >
                                <Loader2 v-if="uploading" class="w-4 h-4 animate-spin" />
                                Upload {{ selectedFiles.length }} photo(s)
                            </button>

                            <!-- Filter by where each photo came from -->
                            <div
                                v-if="photoFilters.length"
                                class="flex flex-wrap gap-2 pt-1"
                            >
                                <button
                                    v-for="f in photoFilters"
                                    :key="f.label"
                                    type="button"
                                    class="rounded-lg border px-3 py-2 text-xs font-medium transition-colors"
                                    :class="
                                        activePhotoFilter === f.label
                                            ? 'border-primary bg-primary/10 text-primary'
                                            : 'border-input text-muted-foreground hover:bg-accent'
                                    "
                                    @click="activePhotoFilter = f.label"
                                >
                                    {{ f.label }} ({{ f.count }})
                                </button>
                            </div>

                            <div v-if="visiblePhotos.length" class="grid grid-cols-3 gap-2">
                                <GalleryTile
                                    v-for="a in visiblePhotos"
                                    :key="a.id"
                                    :item="a"
                                    @open="lightbox = $event"
                                />
                            </div>
                            <p
                                v-else
                                class="rounded-lg border border-dashed py-6 text-center text-sm text-muted-foreground"
                            >
                                No photos yet.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <BrandFooter label="Owner Portal" />
        </div>
    </div>
    </div>
</template>
