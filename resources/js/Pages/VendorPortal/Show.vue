<script setup>
import { ref, computed, watch, onMounted } from "vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import { useToast } from "@/Components/ui/toast/use-toast";
import BrandHeader from "@/Components/PortalBrandHeader.vue";
import BrandFooter from "@/Components/PortalBrandFooter.vue";
import { detectTapback, quotedExcerpt } from "@/utils/tapback.js";
import {
    Loader2,
    Camera,
    Send,
    Pencil,
    FileText,
    CalendarClock,
    MessageSquare,
    MapPin,
    CheckCircle2,
    Circle,
    AlertTriangle,
    ChevronDown,
    Home,
    Phone,
    Mail,
    X,
} from "lucide-vue-next";

const props = defineProps({
    token: String,
    dashboardUrl: String,
    vendorName: String,
    wocName: String,
    workOrder: Object,
    tasks: { type: Array, default: () => [] },
    estimate: { type: Object, default: () => ({}) },
    attachments: { type: Array, default: () => [] },
    invoices: { type: Array, default: () => [] },
    schedules: { type: Array, default: () => [] },
    messages: { type: Array, default: () => [] },
    tenantContacts: { type: Array, default: () => [] },
    unreadMessages: { type: Number, default: 0 },
});

const page = usePage();
const { toast } = useToast();

// A vendor's iPhone tapback ("Liked \"…\"") arrives as a text quoting the
// entire original message; render it as a compact reaction chip instead.
// Only the vendor's own messages can be tapbacks.
const tapbackOf = (m) => (m.is_from_vendor ? detectTapback(m.message) : null);

// Dark/light theme, shared with the dashboard via the same localStorage key so
// the vendor's choice carries across the whole portal. Defaults to dark.
const isDark = ref(true);

onMounted(() => {
    const saved = localStorage.getItem("vendorPortalTheme");
    if (saved) {
        isDark.value = saved === "dark";
    }
});

const toggleTheme = () => {
    isDark.value = !isDark.value;
    localStorage.setItem("vendorPortalTheme", isDark.value ? "dark" : "light");
};

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
        if (f.warning)
            toast({ title: "Heads up", description: f.warning, duration: 5000 });
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
const tabs = [
    { key: "estimate", label: "Estimate", icon: Pencil },
    { key: "schedule", label: "Schedule", icon: CalendarClock },
    { key: "photos", label: "Photos", icon: Camera },
    { key: "invoice", label: "Invoice", icon: FileText },
    { key: "message", label: "Message", icon: MessageSquare },
];
const activeTab = ref("estimate");
const localUnread = ref(props.unreadMessages);

const selectTab = (key) => {
    activeTab.value = key;
    // Opening the Message tab clears the "new message" badge.
    if (key === "message" && localUnread.value > 0) {
        localUnread.value = 0;
        router.post(
            route("vendor.portal.messages.read", props.token),
            {},
            { preserveScroll: true, preserveState: true }
        );
    }
};

// --- Estimate form ---
const estimateForm = ref({
    cost_estimate: props.estimate?.cost_estimate ?? "",
    time_estimate: props.estimate?.time_estimate ?? "",
    scheduled_end_date: props.estimate?.scheduled_end_date ?? "",
});
const savingEstimate = ref(false);

const saveEstimate = () => {
    savingEstimate.value = true;
    router.post(route("vendor.portal.estimate", props.token), estimateForm.value, {
        preserveScroll: true,
        onFinish: () => (savingEstimate.value = false),
    });
};

// --- Service schedule ---
const scheduleForm = ref({
    title: `Service Schedule for ${props.workOrder.work_order_no}`,
    date: "",
    end_date: "",
    description: props.workOrder.description ?? "",
});
const savingSchedule = ref(false);

const saveSchedule = () => {
    if (!scheduleForm.value.title.trim() || !scheduleForm.value.date) return;
    savingSchedule.value = true;
    router.post(route("vendor.portal.schedule", props.token), scheduleForm.value, {
        preserveScroll: true,
        onSuccess: () => {
            scheduleForm.value.date = "";
            scheduleForm.value.end_date = "";
        },
        onFinish: () => (savingSchedule.value = false),
    });
};

const scheduleStatusClass = (status) => {
    const s = (status || "").toLowerCase();
    if (s === "completed") return "bg-green-100 text-green-700";
    if (s === "cancelled") return "bg-destructive/10 text-destructive";
    return "bg-primary/10 text-primary";
};

const fmtDate = (d) => {
    if (!d) return "";
    // Accept ISO, MySQL "YYYY-MM-DD HH:MM:SS", and date-only strings. Build the
    // date from its parts so it never shows "Invalid Date" or shifts a day by TZ.
    const [y, m, day] = String(d).slice(0, 10).split("-").map(Number);
    if (!y || !m || !day) return "";
    return new Date(y, m - 1, day).toLocaleDateString(undefined, {
        month: "short",
        day: "numeric",
        year: "numeric",
    });
};

// --- Photo upload ---
const photoType = ref("after");
const photoTypes = [
    { value: "before", label: "Before work" },
    { value: "after", label: "After work" },
    { value: "attachment", label: "Other" },
];
const photoTitle = ref("");
const selectedFiles = ref([]);
const selectedPreviews = ref([]);
const photoInput = ref(null);
const uploading = ref(false);

const isImageFile = (file) =>
    file.type.startsWith("image/") || /\.(jpe?g|png|gif|webp|heic)$/i.test(file.name);

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
    // Reset so re-picking the same file still fires @change.
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
    data.append("title", photoTitle.value.trim());
    data.append("type", photoType.value);
    selectedFiles.value.forEach((file) => data.append("files[]", file));

    router.post(route("vendor.portal.attachments", props.token), data, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            selectedFiles.value = [];
            selectedPreviews.value = [];
            photoTitle.value = "";
            if (photoInput.value) photoInput.value.value = "";
        },
        onFinish: () => (uploading.value = false),
    });
};

// --- Invoice upload ---
const invoiceForm = ref({ title: "", amount: "" });
const invoiceFile = ref(null);
const invoiceInput = ref(null);
const uploadingInvoice = ref(false);

const onPickInvoice = (e) => {
    invoiceFile.value = (e.target.files && e.target.files[0]) || null;
};

const uploadInvoice = () => {
    if (!invoiceFile.value || !invoiceForm.value.amount) return;
    uploadingInvoice.value = true;
    const data = new FormData();
    data.append("title", invoiceForm.value.title || invoiceFile.value.name);
    data.append("amount", invoiceForm.value.amount);
    data.append("filename", invoiceFile.value);

    router.post(route("vendor.portal.invoice", props.token), data, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            invoiceForm.value = { title: "", amount: "" };
            invoiceFile.value = null;
            if (invoiceInput.value) invoiceInput.value.value = "";
        },
        onFinish: () => (uploadingInvoice.value = false),
    });
};

// --- Message coordinator ---
const messageText = ref("");
const messageImages = ref([]);
const messageInput = ref(null);
const sending = ref(false);

const onPickMessageImages = (e) => {
    Array.from(e.target.files || []).forEach((file) => {
        const isDuplicate = messageImages.value.some(
            (existing) => existing.name === file.name && existing.size === file.size,
        );
        if (!isDuplicate) {
            messageImages.value.push(file);
        }
    });
    // Reset so re-picking the same file still fires @change.
    e.target.value = "";
};

const removeMessageImage = (index) => {
    messageImages.value.splice(index, 1);
};

const sendMessage = () => {
    if (!messageText.value.trim() && messageImages.value.length === 0) return;
    sending.value = true;
    const data = new FormData();
    data.append("text", messageText.value);
    messageImages.value.forEach((file) => data.append("images[]", file));

    router.post(route("vendor.portal.message", props.token), data, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            messageText.value = "";
            messageImages.value = [];
            if (messageInput.value) messageInput.value.value = "";
        },
        onFinish: () => (sending.value = false),
    });
};

const priorityClass = computed(() => {
    const p = (props.workOrder?.priority || "").toLowerCase();
    if (p.includes("high") || p.includes("emergency"))
        return "bg-destructive/10 text-destructive";
    if (p.includes("medium")) return "bg-amber-100 text-amber-700";
    return "bg-muted text-muted-foreground";
});

const addressLine = computed(
    () =>
        props.workOrder?.building?.address ||
        props.workOrder?.location ||
        "Address unavailable"
);

const invoiceStatusClass = (status) => {
    const s = (status || "").toLowerCase();
    if (s === "approved") return "bg-green-100 text-green-700";
    if (s === "decline" || s === "declined") return "bg-destructive/10 text-destructive";
    return "bg-amber-100 text-amber-700";
};

// Group uploaded photos by type so each set gets a clear heading.
const photoGroups = computed(() => {
    const labels = {
        before: "Before Work",
        after: "After Work",
        attachment: "Other",
    };
    return ["before", "after", "attachment"]
        .map((type) => ({
            type,
            label: labels[type],
            items: props.attachments.filter((a) => a.type === type),
        }))
        .filter((group) => group.items.length > 0);
});

// Tenant phone rows, in the order a vendor would try them.
const phoneRows = (contact) =>
    [
        { label: "Mobile", value: contact.mobile_phone },
        { label: "Home", value: contact.home_phone },
        { label: "Work", value: contact.work_phone },
    ].filter((row) => row.value);

// Numbers are displayed formatted, but dialled as digits.
const telHref = (value) => `tel:${String(value || "").replace(/\D+/g, "")}`;

// Photo groups are collapsible on mobile (always shown on desktop via lg:grid).
const openGroups = ref({});
const toggleGroup = (type) => {
    openGroups.value[type] = !openGroups.value[type];
};

// --- Mark a task complete ---
const completingTask = ref(null);
// The task awaiting confirmation in the styled modal (null = modal closed).
const taskToComplete = ref(null);

const completeTask = (task) => {
    taskToComplete.value = task;
};

const confirmCompleteTask = () => {
    const task = taskToComplete.value;
    if (!task) return;

    taskToComplete.value = null;
    completingTask.value = task.id;
    router.post(
        route("vendor.portal.tasks.complete", {
            token: props.token,
            task: task.id,
        }),
        {},
        {
            preserveScroll: true,
            onFinish: () => (completingTask.value = null),
        }
    );
};
</script>

<template>
    <div :class="isDark ? 'dark' : ''">
    <Head :title="`Work Order #${workOrder.work_order_no}`" />

    <Toaster />

    <!-- Confirm task completion -->
    <div
        v-if="taskToComplete"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
        @click.self="taskToComplete = null"
    >
        <div class="w-full max-w-sm rounded-lg bg-card p-5 shadow-xl">
            <div class="flex items-start gap-3">
                <div
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-green-100"
                >
                    <CheckCircle2 class="h-5 w-5 text-green-600" />
                </div>
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-foreground">
                        Mark task complete?
                    </h3>
                    <p class="mt-1 text-sm text-muted-foreground">
                        “{{ taskToComplete.name }}” will be marked as completed
                        and your coordinator will be notified.
                    </p>
                </div>
            </div>
            <div class="mt-5 flex justify-end gap-2">
                <button
                    type="button"
                    class="rounded-md border border-input bg-background px-4 py-2 text-sm font-medium text-foreground transition-colors hover:bg-accent"
                    @click="taskToComplete = null"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-green-700"
                    @click="confirmCompleteTask"
                >
                    <CheckCircle2 class="h-4 w-4" />
                    Complete
                </button>
            </div>
        </div>
    </div>

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
            <a
                v-if="dashboardUrl"
                :href="dashboardUrl"
                class="inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground active:text-foreground"
            >
                <Home class="w-4 h-4" />
                All my work orders
            </a>

            <!-- Two columns on desktop, single stack on mobile -->
            <div
                class="lg:grid lg:grid-cols-2 lg:gap-5 lg:items-start space-y-4 lg:space-y-0"
            >
                <!-- Left column: branded header + job details -->
                <div class="rounded-lg border bg-card text-card-foreground shadow-sm overflow-hidden">
                    <BrandHeader :is-dark="isDark" @toggle-theme="toggleTheme" />

                    <div class="p-5">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-semibold text-muted-foreground">
                            Hi {{ vendorName }}
                        </span>
                        <span
                            v-if="workOrder.status"
                            class="text-xs font-medium rounded-full bg-primary/10 text-primary px-3 py-1"
                        >
                            {{ workOrder.status }}
                        </span>
                    </div>

                    <h1 class="text-xl font-bold text-foreground">
                        Work Order #{{ workOrder.work_order_no }}
                    </h1>

                    <div
                        class="mt-2 flex items-start gap-2 text-muted-foreground text-sm"
                    >
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

                    <p
                        v-if="workOrder.description"
                        class="mt-4 text-sm text-foreground whitespace-pre-line"
                    >
                        {{ workOrder.description }}
                    </p>

                    <!-- Who to contact about this job -->
                    <div v-if="tenantContacts.length" class="mt-4 border-t pt-3">
                        <button
                            type="button"
                            class="w-full flex items-center justify-between lg:pointer-events-none"
                            @click="toggleGroup('tenant')"
                        >
                            <span
                                class="text-xs font-semibold text-muted-foreground uppercase"
                            >
                                Tenant contact
                            </span>
                            <ChevronDown
                                class="w-4 h-4 text-muted-foreground lg:hidden transition-transform"
                                :class="{ 'rotate-180': openGroups.tenant }"
                            />
                        </button>
                        <div
                            class="space-y-3 mt-2 lg:block"
                            :class="{ hidden: !openGroups.tenant }"
                        >
                            <div
                                v-for="(contact, i) in tenantContacts"
                                :key="i"
                                class="rounded-lg border border-input px-3 py-2"
                            >
                                <div
                                    class="flex items-center gap-2 text-sm font-medium text-foreground"
                                >
                                    <span class="truncate">{{ contact.name }}</span>
                                    <span
                                        v-if="contact.is_primary"
                                        class="shrink-0 text-[10px] uppercase tracking-wide rounded-full bg-primary/10 text-primary px-2 py-0.5"
                                        >Requester</span
                                    >
                                </div>
                                <div class="mt-2 space-y-1">
                                    <a
                                        v-for="row in phoneRows(contact)"
                                        :key="row.label"
                                        :href="telHref(row.value)"
                                        class="flex items-center gap-2 text-sm text-primary active:opacity-70"
                                    >
                                        <Phone class="w-4 h-4 shrink-0" />
                                        <span>{{ row.value }}</span>
                                        <span class="text-xs text-muted-foreground"
                                            >{{ row.label }}</span
                                        >
                                    </a>
                                    <a
                                        v-if="contact.email"
                                        :href="`mailto:${contact.email}`"
                                        class="flex items-center gap-2 text-sm text-primary active:opacity-70"
                                    >
                                        <Mail class="w-4 h-4 shrink-0" />
                                        <span class="truncate">{{ contact.email }}</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tasks -->
                    <div v-if="tasks.length" class="mt-4 border-t pt-3">
                        <button
                            type="button"
                            class="w-full flex items-center justify-between lg:pointer-events-none"
                            @click="toggleGroup('tasks')"
                        >
                            <span
                                class="text-xs font-semibold text-muted-foreground uppercase"
                            >
                                What needs to be done ({{ tasks.length }})
                            </span>
                            <ChevronDown
                                class="w-4 h-4 text-muted-foreground lg:hidden transition-transform"
                                :class="{ 'rotate-180': openGroups.tasks }"
                            />
                        </button>
                        <div
                            class="space-y-2 mt-2 lg:block"
                            :class="{ hidden: !openGroups.tasks }"
                        >
                        <div
                            v-for="task in tasks"
                            :key="task.id"
                            class="flex items-center gap-2 text-sm text-foreground"
                        >
                            <CheckCircle2
                                v-if="task.status === 'completed'"
                                class="w-4 h-4 shrink-0 text-green-500"
                            />
                            <button
                                v-else
                                type="button"
                                class="shrink-0"
                                :disabled="completingTask === task.id"
                                title="Mark complete"
                                @click="completeTask(task)"
                            >
                                <Loader2
                                    v-if="completingTask === task.id"
                                    class="w-4 h-4 animate-spin text-primary"
                                />
                                <Circle
                                    v-else
                                    class="w-4 h-4 text-muted-foreground/40 hover:text-primary"
                                />
                            </button>
                            <span
                                :class="
                                    task.status === 'completed'
                                        ? 'line-through text-muted-foreground'
                                        : ''
                                "
                                >{{ task.name }}</span
                            >
                        </div>
                        </div>
                    </div>

                    <!-- Reference photos grouped by type -->
                    <div
                        v-if="attachments.length"
                        class="mt-4 border-t pt-3 space-y-3"
                    >
                        <div
                            v-for="group in photoGroups"
                            :key="group.type"
                        >
                            <button
                                type="button"
                                class="w-full flex items-center justify-between lg:pointer-events-none"
                                @click="toggleGroup(group.type)"
                            >
                                <span
                                    class="text-xs font-semibold text-muted-foreground uppercase"
                                >
                                    {{ group.label }} ({{ group.items.length }})
                                </span>
                                <ChevronDown
                                    class="w-4 h-4 text-muted-foreground lg:hidden transition-transform"
                                    :class="{ 'rotate-180': openGroups[group.type] }"
                                />
                            </button>
                            <div
                                class="grid grid-cols-3 gap-2 mt-2 lg:grid"
                                :class="{ hidden: !openGroups[group.type] }"
                            >
                                <template
                                    v-for="a in group.items"
                                    :key="a.id"
                                >
                                    <button
                                        v-if="a.is_image"
                                        type="button"
                                        class="block aspect-square rounded-lg overflow-hidden bg-muted"
                                        @click="lightbox = a.url"
                                    >
                                        <img
                                            :src="a.url"
                                            :alt="a.title"
                                            class="w-full h-full object-cover"
                                        />
                                    </button>
                                    <a
                                        v-else
                                        :href="a.url"
                                        target="_blank"
                                        class="flex flex-col items-center justify-center gap-1 aspect-square rounded-lg bg-muted p-2"
                                    >
                                        <FileText class="w-6 h-6 text-muted-foreground" />
                                        <span
                                            class="text-[10px] leading-tight text-muted-foreground text-center w-full truncate px-1"
                                            >{{ a.title }}</span
                                        >
                                    </a>
                                </template>
                            </div>
                        </div>
                    </div>

                    <!-- Invoices -->
                    <div v-if="invoices.length" class="mt-4 border-t pt-3">
                        <button
                            type="button"
                            class="w-full flex items-center justify-between lg:pointer-events-none"
                            @click="toggleGroup('invoices')"
                        >
                            <span
                                class="text-xs font-semibold text-muted-foreground uppercase"
                            >
                                Invoices ({{ invoices.length }})
                            </span>
                            <ChevronDown
                                class="w-4 h-4 text-muted-foreground lg:hidden transition-transform"
                                :class="{ 'rotate-180': openGroups.invoices }"
                            />
                        </button>
                        <div
                            class="space-y-2 mt-2 lg:block"
                            :class="{ hidden: !openGroups.invoices }"
                        >
                            <a
                                v-for="inv in invoices"
                                :key="inv.id"
                                :href="inv.url"
                                target="_blank"
                                class="flex items-center justify-between gap-2 rounded-lg border border-input px-3 py-2 active:bg-accent"
                            >
                                <span
                                    class="flex items-center gap-2 min-w-0 text-sm text-foreground"
                                >
                                    <FileText
                                        class="w-4 h-4 shrink-0 text-muted-foreground"
                                    />
                                    <span class="truncate">{{ inv.title }}</span>
                                </span>
                                <span class="flex items-center gap-2 shrink-0">
                                    <span class="text-sm font-medium"
                                        >${{ inv.amount }}</span
                                    >
                                    <span
                                        class="text-xs rounded-full px-2 py-0.5 capitalize"
                                        :class="invoiceStatusClass(inv.status)"
                                        >{{ inv.status }}</span
                                    >
                                </span>
                            </a>
                        </div>
                    </div>
                    </div>
                </div>

                <!-- Right column: tabbed actions -->
                <div class="rounded-lg border bg-card text-card-foreground shadow-sm overflow-hidden">
                    <!-- Tab bar -->
                    <div class="grid grid-cols-5 border-b">
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
                        <!-- Estimate -->
                        <div v-show="activeTab === 'estimate'" class="space-y-3">
                            <div>
                                <label
                                    class="text-xs font-medium text-muted-foreground"
                                    >Cost ($)</label
                                >
                                <input
                                    v-model="estimateForm.cost_estimate"
                                    type="number"
                                    inputmode="decimal"
                                    min="0"
                                    step="0.01"
                                    class="mt-1 w-full rounded-md border border-input bg-background text-foreground placeholder:text-muted-foreground px-4 py-3 text-base focus:border-ring focus:ring-0"
                                    placeholder="0.00"
                                />
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label
                                        class="text-xs font-medium text-muted-foreground"
                                        >Time (hrs)</label
                                    >
                                    <input
                                        v-model="estimateForm.time_estimate"
                                        type="number"
                                        inputmode="numeric"
                                        min="0"
                                        class="mt-1 w-full rounded-md border border-input bg-background text-foreground placeholder:text-muted-foreground px-4 py-3 text-base focus:border-ring focus:ring-0"
                                        placeholder="0"
                                    />
                                </div>
                                <div>
                                    <label
                                        class="text-xs font-medium text-muted-foreground"
                                        >Finish by</label
                                    >
                                    <input
                                        v-model="estimateForm.scheduled_end_date"
                                        type="date"
                                        class="mt-1 w-full rounded-md border border-input bg-background text-foreground placeholder:text-muted-foreground px-4 py-3 text-base focus:border-ring focus:ring-0"
                                    />
                                </div>
                            </div>
                            <button
                                type="button"
                                :disabled="savingEstimate"
                                class="w-full rounded-md bg-primary text-primary-foreground font-medium py-3 hover:bg-primary/90 disabled:opacity-60 flex items-center justify-center gap-2"
                                @click="saveEstimate"
                            >
                                <Loader2
                                    v-if="savingEstimate"
                                    class="w-4 h-4 animate-spin"
                                />
                                Save estimate
                            </button>
                        </div>

                        <!-- Schedule -->
                        <div v-show="activeTab === 'schedule'" class="space-y-3">
                            <!-- Existing schedules -->
                            <div v-if="schedules.length" class="space-y-2 mb-1">
                                <div
                                    v-for="s in schedules"
                                    :key="s.id"
                                    class="rounded-lg border border-input px-3 py-2"
                                >
                                    <div
                                        class="flex items-center justify-between gap-2"
                                    >
                                        <span
                                            class="text-sm font-medium text-foreground truncate"
                                            >{{ s.title }}</span
                                        >
                                        <span
                                            class="text-xs rounded-full px-2 py-0.5 capitalize shrink-0"
                                            :class="scheduleStatusClass(s.status)"
                                            >{{ s.status }}</span
                                        >
                                    </div>
                                    <p class="text-xs text-muted-foreground mt-0.5">
                                        {{ fmtDate(s.scheduled_date) }}
                                        <template v-if="s.scheduled_end_date">
                                            → {{ fmtDate(s.scheduled_end_date) }}
                                        </template>
                                    </p>
                                </div>
                            </div>

                            <div>
                                <label class="text-xs font-medium text-muted-foreground"
                                    >Title</label
                                >
                                <input
                                    v-model="scheduleForm.title"
                                    type="text"
                                    class="mt-1 w-full rounded-md border border-input bg-background text-foreground placeholder:text-muted-foreground px-4 py-3 text-base focus:border-ring focus:ring-0"
                                />
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label
                                        class="text-xs font-medium text-muted-foreground"
                                        >Start date</label
                                    >
                                    <input
                                        v-model="scheduleForm.date"
                                        type="date"
                                        class="mt-1 w-full rounded-md border border-input bg-background text-foreground placeholder:text-muted-foreground px-4 py-3 text-base focus:border-ring focus:ring-0"
                                    />
                                </div>
                                <div>
                                    <label
                                        class="text-xs font-medium text-muted-foreground"
                                        >End date</label
                                    >
                                    <input
                                        v-model="scheduleForm.end_date"
                                        type="date"
                                        class="mt-1 w-full rounded-md border border-input bg-background text-foreground placeholder:text-muted-foreground px-4 py-3 text-base focus:border-ring focus:ring-0"
                                    />
                                </div>
                            </div>
                            <div>
                                <label class="text-xs font-medium text-muted-foreground"
                                    >Description</label
                                >
                                <textarea
                                    v-model="scheduleForm.description"
                                    rows="5"
                                    class="mt-1 w-full rounded-md border border-input bg-background text-foreground placeholder:text-muted-foreground px-4 py-3 text-base resize-y min-h-[120px] focus:border-ring focus:ring-0"
                                ></textarea>
                            </div>
                            <button
                                type="button"
                                :disabled="
                                    savingSchedule ||
                                    !scheduleForm.title.trim() ||
                                    !scheduleForm.date
                                "
                                class="w-full rounded-md bg-primary text-primary-foreground font-medium py-3 hover:bg-primary/90 disabled:opacity-60 flex items-center justify-center gap-2"
                                @click="saveSchedule"
                            >
                                <Loader2
                                    v-if="savingSchedule"
                                    class="w-4 h-4 animate-spin"
                                />
                                Add schedule
                            </button>
                        </div>

                        <!-- Photos -->
                        <div v-show="activeTab === 'photos'" class="space-y-3">
                            <div>
                                <label
                                    class="text-xs font-medium text-muted-foreground"
                                    >Description (optional)</label
                                >
                                <input
                                    v-model="photoTitle"
                                    type="text"
                                    class="mt-1 w-full rounded-md border border-input bg-background text-foreground placeholder:text-muted-foreground px-4 py-3 text-base focus:border-ring focus:ring-0"
                                    placeholder="e.g. Kitchen leak - before repair"
                                />
                                <p class="mt-1 text-xs text-muted-foreground">
                                    This name is sent to PropertyWare and shown
                                    to the owner & tenant. Leave it blank and
                                    one is filled in for you.
                                </p>
                            </div>

                            <div class="flex gap-2">
                                <button
                                    v-for="opt in photoTypes"
                                    :key="opt.value"
                                    type="button"
                                    class="flex-1 rounded-lg border py-2 text-xs font-medium"
                                    :class="
                                        photoType === opt.value
                                            ? 'border-primary bg-primary/10 text-primary'
                                            : 'border-input text-muted-foreground'
                                    "
                                    @click="photoType = opt.value"
                                >
                                    {{ opt.label }}
                                </button>
                            </div>

                            <label
                                class="flex flex-col items-center justify-center gap-2 rounded-md border-2 border-dashed border-input py-7 text-muted-foreground cursor-pointer active:bg-accent"
                            >
                                <Camera class="w-7 h-7" />
                                <span class="text-sm font-medium"
                                    >Tap to add photos or files</span
                                >
                                <span class="text-[10px] text-muted-foreground"
                                    >Images, PDF, Word, Excel or text</span
                                >
                                <span class="text-[10px] text-muted-foreground"
                                    >Add as many as you like — pick more anytime and
                                    they'll stack up</span
                                >
                                <input
                                    ref="photoInput"
                                    type="file"
                                    accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt"
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
                                <Loader2
                                    v-if="uploading"
                                    class="w-4 h-4 animate-spin"
                                />
                                Upload {{ selectedFiles.length }} photo(s)
                            </button>
                        </div>

                        <!-- Invoice -->
                        <div v-show="activeTab === 'invoice'" class="space-y-3">
                            <!-- Already-submitted invoices -->
                            <div
                                v-if="invoices.length"
                                class="space-y-2 mb-1"
                            >
                                <a
                                    v-for="inv in invoices"
                                    :key="inv.id"
                                    :href="inv.url"
                                    target="_blank"
                                    class="flex items-center justify-between gap-2 rounded-lg border border-input px-3 py-2 active:bg-accent"
                                >
                                    <span
                                        class="flex items-center gap-2 min-w-0 text-sm text-foreground"
                                    >
                                        <FileText
                                            class="w-4 h-4 shrink-0 text-muted-foreground"
                                        />
                                        <span class="truncate">{{
                                            inv.title
                                        }}</span>
                                    </span>
                                    <span class="flex items-center gap-2 shrink-0">
                                        <span class="text-sm font-medium"
                                            >${{ inv.amount }}</span
                                        >
                                        <span
                                            class="text-xs rounded-full px-2 py-0.5 capitalize"
                                            :class="invoiceStatusClass(inv.status)"
                                            >{{ inv.status }}</span
                                        >
                                    </span>
                                </a>
                            </div>

                            <div>
                                <label
                                    class="text-xs font-medium text-muted-foreground"
                                    >Amount ($)</label
                                >
                                <input
                                    v-model="invoiceForm.amount"
                                    type="number"
                                    inputmode="decimal"
                                    min="0"
                                    step="0.01"
                                    class="mt-1 w-full rounded-md border border-input bg-background text-foreground placeholder:text-muted-foreground px-4 py-3 text-base focus:border-ring focus:ring-0"
                                    placeholder="0.00"
                                />
                            </div>
                            <div>
                                <label
                                    class="text-xs font-medium text-muted-foreground"
                                    >Description (optional)</label
                                >
                                <input
                                    v-model="invoiceForm.title"
                                    type="text"
                                    class="mt-1 w-full rounded-md border border-input bg-background text-foreground placeholder:text-muted-foreground px-4 py-3 text-base focus:border-ring focus:ring-0"
                                    placeholder="e.g. Labor + parts"
                                />
                            </div>

                            <label
                                class="flex flex-col items-center justify-center gap-2 rounded-md border-2 border-dashed border-input py-6 text-muted-foreground cursor-pointer active:bg-accent"
                            >
                                <FileText class="w-6 h-6" />
                                <span class="text-sm font-medium">{{
                                    invoiceFile
                                        ? invoiceFile.name
                                        : "Tap to attach invoice (PDF or image)"
                                }}</span>
                                <input
                                    ref="invoiceInput"
                                    type="file"
                                    accept="image/*,application/pdf"
                                    class="hidden"
                                    @change="onPickInvoice"
                                />
                            </label>

                            <button
                                type="button"
                                :disabled="
                                    uploadingInvoice ||
                                    !invoiceFile ||
                                    !invoiceForm.amount
                                "
                                class="w-full rounded-md bg-primary text-primary-foreground font-medium py-3 hover:bg-primary/90 disabled:opacity-60 flex items-center justify-center gap-2"
                                @click="uploadInvoice"
                            >
                                <Loader2
                                    v-if="uploadingInvoice"
                                    class="w-4 h-4 animate-spin"
                                />
                                Submit invoice
                            </button>
                        </div>

                        <!-- Message coordinator -->
                        <div v-show="activeTab === 'message'">
                            <p class="text-xs text-muted-foreground mb-3">
                                <template v-if="wocName"
                                    >{{ wocName }} ·</template
                                >
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
                                    :class="
                                        m.is_from_vendor
                                            ? 'justify-end'
                                            : 'justify-start'
                                    "
                                >
                                    <div
                                        class="min-w-0 max-w-[85%] rounded-lg px-3 py-2 text-sm"
                                        :class="
                                            m.is_from_vendor
                                                ? 'bg-primary text-primary-foreground rounded-br-sm'
                                                : 'bg-muted text-foreground rounded-bl-sm'
                                        "
                                    >
                                        <!-- Messages can carry portal URLs, which have no
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
                                v-if="messageImages.length"
                                class="flex flex-wrap gap-2 mb-2"
                            >
                                <div
                                    v-for="(img, i) in messageImages"
                                    :key="i"
                                    class="relative"
                                >
                                    <span
                                        class="block rounded-lg bg-muted text-muted-foreground text-xs px-2 py-1 pr-6"
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
                                    class="flex items-center justify-center rounded-md border border-input px-4 text-muted-foreground cursor-pointer active:bg-accent"
                                >
                                    <Camera class="w-5 h-5" />
                                    <input
                                        ref="messageInput"
                                        type="file"
                                        accept="image/*"
                                        multiple
                                        class="hidden"
                                        @change="onPickMessageImages"
                                    />
                                </label>
                                <button
                                    type="button"
                                    :disabled="sending"
                                    class="flex-1 rounded-md bg-primary text-primary-foreground font-medium py-3 hover:bg-primary/90 disabled:opacity-60 flex items-center justify-center gap-2"
                                    @click="sendMessage"
                                >
                                    <Loader2
                                        v-if="sending"
                                        class="w-4 h-4 animate-spin"
                                    />
                                    <Send v-else class="w-4 h-4" />
                                    Send
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <BrandFooter label="Vendor Portal" />
        </div>
    </div>
    </div>
</template>
