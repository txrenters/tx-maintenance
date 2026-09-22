<script setup>
import { ref, computed, watch, onMounted } from "vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import { useToast } from "@/Components/ui/toast/use-toast";
import GalleryTile from "@/Components/PortalGalleryTile.vue";
import BrandHeader from "@/Components/PortalBrandHeader.vue";
import BrandFooter from "@/Components/PortalBrandFooter.vue";
import { usePickedFiles } from "@/composables/usePickedFiles";
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
    CheckCircle2,
    ChevronRight,
    Plus,
    X,
} from "lucide-vue-next";

const props = defineProps({
    title: String,
    token: String,
    tenantName: String,
    wocName: String,
    completed: Boolean,
    isHoa: Boolean,
    canCreateRequest: Boolean,
    deadline: String,
    // {label, video_url, tip} when the request was judged a tenant easy fix
    easyFix: { type: Object, default: null },
    workOrder: Object,
    messages: { type: Array, default: () => [] },
    attachments: { type: Array, default: () => [] },
    unreadMessages: { type: Number, default: 0 },
});

const page = usePage();
const { toast } = useToast();

// Dark/light theme, kept under its own key so a tenant's choice is independent
// of the owner and vendor portals. Defaults to dark, matching them.
const isDark = ref(true);

onMounted(() => {
    const saved = localStorage.getItem("tenantPortalTheme");
    if (saved) {
        isDark.value = saved === "dark";
    }
});

const toggleTheme = () => {
    isDark.value = !isDark.value;
    localStorage.setItem("tenantPortalTheme", isDark.value ? "dark" : "light");
};

// A tenant's iPhone tapback ("Liked \"…\"") arrives as a text quoting the
// entire original message; render it as a compact reaction chip instead.
// Only the tenant's own messages can be tapbacks.
const tapbackOf = (m) => (m.from_tenant ? detectTapback(m.message) : null);

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
// Messages, photos, and opening a new request: the tenant has no estimate,
// schedule or invoice.
const tabs = computed(() =>
    [
        { key: "message", label: "Messages", icon: MessageSquare },
        { key: "photos", label: "Photos", icon: Camera },
        props.canCreateRequest
            ? { key: "new_request", label: "New request", icon: Plus }
            : null,
    ].filter(Boolean)
);
// An HOA notice is a request for photos, so open that tab first.
const activeTab = ref(props.isHoa ? "photos" : "message");
const localUnread = ref(props.unreadMessages);

const markRead = () => {
    if (localUnread.value === 0) return;
    localUnread.value = 0;
    router.post(
        route("tenant.portal.messages.read", props.token),
        {},
        { preserveScroll: true, preserveState: true }
    );
};

const selectTab = (key) => {
    activeTab.value = key;
    if (key === "message") markRead();
};

// Clear the badge straight away when Messages is the tab we opened on.
onMounted(() => {
    if (activeTab.value === "message") markRead();
});

// --- Message coordinator ---
const messageText = ref("");
const {
    files: messageImages,
    previews: messagePreviews,
    input: messageInput,
    onPick: onPickMessageImages,
    remove: removeMessageImage,
    reset: resetMessageImages,
} = usePickedFiles();
const sending = ref(false);

const sendMessage = () => {
    if (!messageText.value.trim() && messageImages.value.length === 0) return;
    sending.value = true;
    const data = new FormData();
    data.append("text", messageText.value);
    messageImages.value.forEach((file) => data.append("images[]", file));

    router.post(route("tenant.portal.message", props.token), data, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            messageText.value = "";
            resetMessageImages();
        },
        onFinish: () => (sending.value = false),
    });
};

// --- Photo upload ---
const {
    files: selectedFiles,
    previews: selectedPreviews,
    input: photoInput,
    onPick: onPickFiles,
    remove: removeSelectedFile,
    reset: resetSelectedFiles,
} = usePickedFiles();
const uploading = ref(false);

const uploadPhotos = () => {
    if (selectedFiles.value.length === 0) return;
    uploading.value = true;
    const data = new FormData();
    selectedFiles.value.forEach((file) => data.append("files[]", file));

    router.post(route("tenant.portal.attachments", props.token), data, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => resetSelectedFiles(),
        onFinish: () => (uploading.value = false),
    });
};

// --- Report a new issue ---
// Description + photos only: everything else about the request (its category,
// whether it is an emergency) is worked out on our side, so the tenant is never
// asked to classify their own problem.
const actionsCard = ref(null);
const newRequestText = ref("");
const {
    files: newRequestFiles,
    previews: newRequestPreviews,
    input: newRequestInput,
    onPick: onPickNewRequestFiles,
    remove: removeNewRequestFile,
} = usePickedFiles();
const submittingRequest = ref(false);

const openNewRequest = () => {
    activeTab.value = "new_request";
    // The right column stacks below the details on a phone, so scrolling is
    // what makes the button feel like a button.
    actionsCard.value?.scrollIntoView({ behavior: "smooth", block: "start" });
};

const submitNewRequest = () => {
    if (newRequestText.value.trim().length < 10 || submittingRequest.value) return;
    submittingRequest.value = true;
    const data = new FormData();
    data.append("description", newRequestText.value.trim());
    newRequestFiles.value.forEach((file) => data.append("photos[]", file));

    // No preserveState: a successful submit navigates to the new request's own
    // portal page.
    router.post(route("tenant.portal.request.store", props.token), data, {
        forceFormData: true,
        onFinish: () => (submittingRequest.value = false),
    });
};

const markingDone = ref(false);

const markDone = () => {
    markingDone.value = true;
    router.post(
        route("tenant.portal.complete", props.token),
        {},
        { preserveScroll: true, onFinish: () => (markingDone.value = false) }
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

// Midnight means the appointment was set with no specific time.
const fmtDateTime = (d) => {
    if (!d) return "";
    const time = String(d).slice(11, 16);
    if (!time || time === "00:00") return fmtDate(d);

    const [hour, minute] = time.split(":").map(Number);
    const suffix = hour >= 12 ? "PM" : "AM";
    const hour12 = hour % 12 === 0 ? 12 : hour % 12;

    return `${fmtDate(d)} at ${hour12}:${String(minute).padStart(2, "0")} ${suffix}`;
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
        { label: "Property", value: props.workOrder?.address },
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
    const order = ["From you", "From your coordinator"];

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
    <Head :title="title" />

    <Toaster />

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

                    <div class="p-5">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-semibold text-muted-foreground">
                            Hi {{ tenantName }}
                        </span>
                        <span
                            v-if="workOrder.status"
                            class="text-xs font-medium rounded-full bg-primary/10 text-primary px-3 py-1"
                        >
                            {{ workOrder.status }}
                        </span>
                    </div>

                    <h1 class="text-xl font-bold text-foreground">
                        <template v-if="isHoa">HOA Violation — #{{ workOrder.work_order_no }}</template>
                        <template v-else>Service Request #{{ workOrder.work_order_no }}</template>
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

                    <!-- HOA notices carry their own deadline and instructions -->
                    <p v-if="isHoa" class="mt-4 text-sm text-muted-foreground">
                        The HOA has issued a violation notice for your property.
                        Please correct the items listed below and upload proof
                        photos so we can confirm the violation is resolved.
                    </p>

                    <div
                        v-if="isHoa && deadline"
                        class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200"
                    >
                        <span class="font-semibold">Please complete by {{ deadline }}.</span>
                        If the items are not corrected by then, a vendor may be
                        sent to complete the work.
                    </div>

                    <!-- Tenant easy fix: the handbook's how-to video -->
                    <div
                        v-if="easyFix && easyFix.video_url"
                        class="mt-4 rounded-lg border border-sky-200 bg-sky-50 px-3 py-3 text-sm text-sky-900 dark:border-sky-900/50 dark:bg-sky-950/40 dark:text-sky-100"
                    >
                        <p class="text-xs font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-300">
                            Quick fix you can try
                        </p>
                        <p class="mt-1">
                            A {{ easyFix.label }} issue is usually something you can
                            take care of yourself in a few minutes.
                        </p>
                        <p v-if="easyFix.tip" class="mt-2">{{ easyFix.tip }}</p>
                        <a
                            :href="easyFix.video_url"
                            target="_blank"
                            rel="noopener"
                            class="mt-3 inline-flex items-center gap-2 rounded-md bg-sky-600 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-700"
                        >
                            Watch the how-to video
                            <ChevronRight class="h-4 w-4" />
                        </a>
                        <p class="mt-2 text-xs text-sky-800/80 dark:text-sky-200/80">
                            Still not working after you try it? Send us a message
                            or a photo below and we will take it from there.
                        </p>
                    </div>

                    <!-- Appointment, when one has been set -->
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

                    <p
                        v-if="workOrder.description"
                        class="mt-4 text-sm text-foreground whitespace-pre-line"
                    >
                        {{ workOrder.description }}
                    </p>

                    <!-- Request details -->
                    <dl v-if="details.length" class="mt-4 border-t pt-3 space-y-2">
                        <!-- Stacks on narrow phones so a long address is never
                             squeezed into a sliver beside its label. -->
                        <div
                            v-for="d in details"
                            :key="d.label"
                            class="text-sm sm:flex sm:items-start sm:justify-between sm:gap-3"
                        >
                            <dt class="text-muted-foreground sm:shrink-0">{{ d.label }}</dt>
                            <dd class="min-w-0 break-words text-foreground sm:text-right">
                                {{ d.value }}
                            </dd>
                        </div>
                    </dl>

                    <!-- Something else broken? It becomes its own request
                         rather than a message someone has to re-key. Outlined
                         so it never competes with Send and Upload. -->
                    <button
                        v-if="canCreateRequest"
                        type="button"
                        class="mt-4 flex w-full flex-col items-center gap-0.5 rounded-md border border-input py-3 text-sm font-medium text-foreground hover:bg-accent active:bg-accent"
                        @click="openNewRequest"
                    >
                        <span class="flex items-center gap-2">
                            <Plus class="w-4 h-4" />
                            Report a new issue
                        </span>
                        <span class="text-[11px] font-normal text-muted-foreground">
                            Something else broken? Tell us here.
                        </span>
                    </button>

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
                <div
                    ref="actionsCard"
                    class="rounded-lg border bg-card text-card-foreground shadow-sm overflow-hidden scroll-mt-4"
                >
                    <!-- Tab bar -->
                    <div
                        class="grid border-b"
                        :style="{
                            gridTemplateColumns: `repeat(${tabs.length}, minmax(0, 1fr))`,
                        }"
                    >
                        <button
                            v-for="t in tabs"
                            :key="t.key"
                            type="button"
                            class="flex flex-col items-center gap-1 py-3 text-xs font-medium border-b-2 transition-colors"
                            :class="
                                activeTab === t.key
                                    ? 'border-primary text-primary'
                                    : 'border-transparent text-muted-foreground'
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

                            <div
                                v-if="messages.length"
                                class="space-y-2 mb-4 max-h-72 overflow-y-auto"
                            >
                                <div
                                    v-for="m in messages"
                                    :key="m.id"
                                    class="flex"
                                    :class="m.from_tenant ? 'justify-end' : 'justify-start'"
                                >
                                    <div
                                        class="min-w-0 max-w-[85%] rounded-lg px-3 py-2 text-sm"
                                        :class="
                                            m.from_tenant
                                                ? 'bg-primary text-primary-foreground rounded-br-sm'
                                                : 'bg-muted text-foreground rounded-bl-sm'
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
                                    </div>
                                </div>
                            </div>
                            <p v-else class="text-sm text-muted-foreground mb-4">
                                No messages yet.
                            </p>

                            <div
                                v-if="messagePreviews.length"
                                class="flex flex-wrap gap-2 mb-2"
                            >
                                <div
                                    v-for="(img, i) in messagePreviews"
                                    :key="i"
                                    class="relative"
                                >
                                    <span
                                        class="block max-w-[200px] truncate rounded-lg bg-muted px-2 py-1 pr-6 text-xs text-muted-foreground"
                                        >{{ img.name }}</span
                                    >
                                    <button
                                        type="button"
                                        class="absolute -top-1 -right-1 bg-muted-foreground text-primary-foreground rounded-full p-0.5"
                                        @click="removeMessageImage(i)"
                                    >
                                        <X class="w-3 h-3" />
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
                                <button
                                    type="button"
                                    :disabled="sending"
                                    class="min-w-0 flex-1 rounded-md bg-primary px-2 py-3 font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-60 flex items-center justify-center gap-2"
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
                            <p v-else class="text-sm text-muted-foreground">
                                No photos yet.
                            </p>

                            <!-- "I'm done" stops the reminder texts. Lives with the
                                 photos because that is what the reminders ask for. -->
                            <div class="border-t pt-3">
                                <button
                                    v-if="!completed"
                                    type="button"
                                    :disabled="markingDone"
                                    class="flex w-full items-center justify-center gap-2 rounded-md border border-input py-3 text-sm font-medium text-foreground hover:bg-accent disabled:opacity-60"
                                    @click="markDone"
                                >
                                    <Loader2 v-if="markingDone" class="w-4 h-4 animate-spin" />
                                    <CheckCircle2 v-else class="w-4 h-4" />
                                    I'm done — notify the team
                                </button>
                                <p
                                    v-else
                                    class="flex items-center justify-center gap-2 text-sm font-medium text-primary"
                                >
                                    <CheckCircle2 class="w-4 h-4" />
                                    All set — our team has been notified.
                                </p>
                            </div>
                        </div>

                        <!-- Report a new issue -->
                        <div
                            v-if="canCreateRequest"
                            v-show="activeTab === 'new_request'"
                            class="space-y-3"
                        >
                            <p class="text-xs text-muted-foreground">
                                Tell us what's wrong and we'll open a new service
                                request.
                                <template v-if="workOrder.work_order_no">
                                    This won't change request #{{
                                        workOrder.work_order_no
                                    }}.
                                </template>
                            </p>

                            <div>
                                <textarea
                                    v-model="newRequestText"
                                    rows="5"
                                    maxlength="2000"
                                    placeholder="Describe the problem — where it is, when it started, and anything that helps us send the right person."
                                    class="w-full rounded-md border border-input bg-background text-foreground placeholder:text-muted-foreground px-4 py-3 text-base resize-none focus:border-ring focus:ring-0"
                                ></textarea>
                                <p class="mt-1 text-right text-[10px] text-muted-foreground">
                                    {{ newRequestText.length }}/2000
                                </p>
                            </div>

                            <label
                                class="flex flex-col items-center justify-center gap-2 rounded-md border-2 border-dashed border-input py-7 text-muted-foreground cursor-pointer active:bg-accent"
                            >
                                <Camera class="w-7 h-7" />
                                <span class="text-sm font-medium">Tap to add photos</span>
                                <span class="text-[10px] text-muted-foreground">
                                    Photos help us send the right person the first
                                    time (optional)
                                </span>
                                <input
                                    ref="newRequestInput"
                                    type="file"
                                    accept="image/*,video/*,.pdf"
                                    multiple
                                    class="hidden"
                                    @change="onPickNewRequestFiles"
                                />
                            </label>

                            <div
                                v-if="newRequestPreviews.length"
                                class="grid grid-cols-3 gap-2"
                            >
                                <div
                                    v-for="(p, i) in newRequestPreviews"
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
                                        @click="removeNewRequestFile(i)"
                                    >
                                        <X class="w-3 h-3" />
                                    </button>
                                </div>
                            </div>

                            <button
                                type="button"
                                :disabled="
                                    submittingRequest || newRequestText.trim().length < 10
                                "
                                class="w-full rounded-md bg-primary text-primary-foreground font-medium py-3 hover:bg-primary/90 disabled:opacity-60 flex items-center justify-center gap-2"
                                @click="submitNewRequest"
                            >
                                <Loader2
                                    v-if="submittingRequest"
                                    class="w-4 h-4 animate-spin"
                                />
                                <Plus v-else class="w-4 h-4" />
                                {{
                                    submittingRequest
                                        ? "Opening your request…"
                                        : "Send this request"
                                }}
                            </button>
                            <p
                                v-if="submittingRequest"
                                class="text-center text-[11px] text-muted-foreground"
                            >
                                This can take a few seconds — please don't tap twice.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <BrandFooter label="Tenant Portal" />
        </div>
    </div>
    </div>
</template>
