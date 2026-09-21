<script setup>
import { ref, watch, onMounted, computed, onBeforeUnmount, defineAsyncComponent } from "vue";
import { router, useForm, usePoll, usePage, Deferred } from "@inertiajs/vue3";
import axios from "axios";
import AppLayout from "@/Layouts/AppLayout.vue";
import { useToast } from "@/Components/ui/toast/use-toast";
import WorkOrderCard from "./Partials/WorkOrderCard.vue";
import TabSwitcher from "./Partials/TabSwitcher.vue";
import { useUnseenAttachments } from "@/composables/useUnseenAttachments";
import { statusList } from "@/utils/serviceStatusList";
import { agoLabel } from "@/utils/conversation.js";
const WorkOrderDetails = defineAsyncComponent(() => import("./Partials/WorkOrderDetails.vue"));
const WorkOrderTask = defineAsyncComponent(() => import("./Partials/WorkOrderTask.vue"));
const VendorWocConversation = defineAsyncComponent(() => import("./Partials/VendorWocConversation.vue"));
const VendorOwnerConversation = defineAsyncComponent(() => import("./Partials/VendorOwnerConversation.vue"));
const VendorConversation = defineAsyncComponent(() => import("./Partials/VendorConversation.vue"));
const TenantConversation = defineAsyncComponent(() => import("./Partials/TenantConversation.vue"));
const OwnerConversation = defineAsyncComponent(() => import("./Partials/OwnerConversation.vue"));
const OwnerWocConversation = defineAsyncComponent(() => import("./Partials/OwnerWocConversation.vue"));
const Conversation = defineAsyncComponent(() => import("./Partials/Conversation.vue"));
const ServiceSchedule = defineAsyncComponent(() => import("./Partials/ServiceSchedule.vue"));
const Attachments = defineAsyncComponent(() => import("./Partials/Attachments.vue"));
const Invoices = defineAsyncComponent(() => import("./Partials/Invoices.vue"));
const Notes = defineAsyncComponent(() => import("./Partials/Notes.vue"));
const VendorEdit = defineAsyncComponent(() => import("./Partials/VendorEdit.vue"));
const Recommendation = defineAsyncComponent(() => import("./Partials/Recommendation.vue"));
import debounce from "lodash/debounce";

import {
    ClipboardList,
    ListChecks,
    Calendar,
    Paperclip,
    FileText,
    NotebookPen,
    Notebook,
    MessagesSquare,
    CalendarIcon,
    Download,
    RefreshCw,
    ScanSearch,
    Search,
    Loader2Icon,
    Loader2,
    Sparkles,
    CircleCheckBig,
    ChevronDown,
    Bell,
    BellOff,
    MessageSquare,
    StickyNote,
    ImageIcon,
    Receipt,
    Truck,
    ArrowRightLeft,
    DollarSign,
    CalendarClock,
    TriangleAlert,
    Pencil,
} from "lucide-vue-next";
import WorkOrderExternalLinks from "@/Components/WorkOrder/WorkOrderExternalLinks.vue";

const { toast } = useToast();
defineOptions({ layout: AppLayout });

import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from "@/Components/ui/popover";
import { RangeCalendar } from "@/Components/ui/range-calendar";
import { DateFormatter, getLocalTimeZone } from "@internationalized/date";
import { useEchoPublic } from "@laravel/echo-vue";
import SearchBar from "@/Components/SearchBar.vue";
import WorkOrderFilterBar from "@/Components/WorkOrderFilterBar.vue";
const VendorTenantConversation = defineAsyncComponent(() => import("./Partials/VendorTenantConversation.vue"));
const OwnerVendorConversation = defineAsyncComponent(() => import("./Partials/OwnerVendorConversation.vue"));
import { Skeleton } from "@/Components/ui/skeleton";

const props = defineProps({
    title: String,
    service_status: Object,
    vendors: Object,
    categories: Object,
    types: Array,
    users: Object,
    filter: Object,
    listRouteName: {
        type: String,
        default: "work_orders.lawn_service",
    },
    // HOA board: show the HOA deadline/state on each card and expose the
    // #board-actions slot for the "Upload HOA Notice" button. Harmless (false)
    // for every other board that reuses this page.
    hoa: {
        type: Boolean,
        default: false,
    },
    // HVAC board: count the work orders that moved since this user last marked
    // the board seen, mark them on the cards, and poll so the counts keep up
    // without a manual refresh. Harmless (false) for every other board that
    // reuses this page.
    newActivity: {
        type: Boolean,
        default: false,
    },
    // ISO-8601 UTC string, or null when this user has never marked it seen.
    boardSeenAt: {
        type: String,
        default: null,
    },
});

const page = usePage();

const url = ref(route(props.listRouteName));
const search = ref(props.filter.search ?? "");
const filter_vendor = ref(props.filter.vendor ?? "");
const filter_category = ref(props.filter.category ?? "");
const filter_emergency = ref(props.filter.emergency ?? "");
// Card color filter (red = overdue/emergency, blue = due today, green =
// upcoming). Colors are computed client-side per card, so this filter is
// applied on the board itself rather than via a server query.
const filter_color = ref("all");

// How many work orders moved since this user last marked the board seen,
// counted off the already-loaded payload — no extra request. It cannot see
// per-row dismissals, which is what serverNewCount below is for.
const payloadNewCount = computed(() => {
    if (!props.newActivity || !props.boardSeenAt) return 0;

    // service_status arrives as an object keyed by index, not an array — the
    // template's v-for does not care, but Array methods do.
    return statusList(props.service_status).reduce(
        (total, status) =>
            total +
            (status.work_orders || []).filter(
                (work_order) =>
                    work_order.updated_at &&
                    work_order.updated_at > props.boardSeenAt
            ).length,
        0
    );
});

// The authoritative count once a row has been dismissed: the server applies the
// dismissals, the payload cannot. Null until a dismissal returns one.
const serverNewCount = ref(null);

// Rows dismissed in this session but not yet confirmed by the server, so the
// badge drops the instant a row is clicked.
const dismissedCount = ref(0);

// Bumped on every dismissal. A refresh that started before the newest dismissal
// is stale by the time it answers, and applying its count would snap the badge
// back up over a row already cleared.
const dismissSeq = ref(0);

// Work orders dealt with in this session. The board payload cannot know about
// dismissals, so without this the column chips and card pills keep counting
// rows the badge has already dropped — the board contradicting itself in plain
// sight. Refreshed from the server's list, which applies the dismissals.
const dismissedIds = ref(new Set());

const rememberDismissedFrom = (updates) => {
    if (!Array.isArray(updates)) return;

    // Anything the board still thinks is new, but the server left out of the
    // list, has been dealt with.
    const stillNew = new Set(updates.map((row) => row.id));
    const dismissed = new Set();

    for (const status of statusList(props.service_status)) {
        for (const workOrder of status.work_orders || []) {
            const movedSinceSeen =
                workOrder.updated_at &&
                props.boardSeenAt &&
                workOrder.updated_at > props.boardSeenAt;

            if (movedSinceSeen && !stillNew.has(workOrder.id)) {
                dismissed.add(workOrder.id);
            }
        }
    }

    dismissedIds.value = dismissed;
};

const boardNewCount = computed(() => {
    if (serverNewCount.value !== null) return serverNewCount.value;

    return Math.max(0, payloadNewCount.value - dismissedCount.value);
});

// The dropdown behind the badge: which work orders moved and what happened to
// them. Fetched only when it is opened, never on board load or on the poll —
// the badge count that took production down on 2026-09-11 was one that ran on
// every request.
const activityOpen = ref(false);
const activityUpdates = ref([]);
const activityLoading = ref(false);
const activityError = ref(false);

// An icon per kind of change, so a column of rows can be scanned by shape
// instead of read word by word. Keyed by the phrases the server sends
// (HvacBoardActivityFeed::SOURCES and WorkOrder::CHANGE_LABELS); anything
// unrecognised, including the old generic "updated", falls back to the pencil.
const CHANGE_ICONS = {
    "new message": MessageSquare,
    "note added": StickyNote,
    "photo or file added": ImageIcon,
    "invoice added": Receipt,
    "vendor assigned": Truck,
    "cost updated": DollarSign,
    "estimate updated": DollarSign,
    "schedule changed": CalendarClock,
    "emergency flag changed": TriangleAlert,
    "priority changed": TriangleAlert,
};

const changeIcon = (change) => {
    if (!change) return Pencil;
    // Status moves are phrased "moved to <status name>", so they cannot be
    // matched by an exact key.
    if (change.startsWith("moved to")) return ArrowRightLeft;

    return CHANGE_ICONS[change] ?? Pencil;
};

const loadActivity = async () => {
    activityLoading.value = true;
    activityError.value = false;

    const seq = dismissSeq.value;

    try {
        const { data } = await axios.get(route("work_orders.hvac.activity"));

        // A dismissal landed while this was in flight; its answer is stale.
        if (seq !== dismissSeq.value) return;

        activityUpdates.value = data.updates ?? [];
        rememberDismissedFrom(data.updates);

        // The server applies dismissals; the payload count cannot. Once it has
        // told us the real number, trust it over the local tally.
        if (typeof data.new_count === "number") {
            serverNewCount.value = data.new_count;
            dismissedCount.value = 0;
        }
    } catch {
        // The count itself comes from the board payload and is still correct;
        // only the breakdown is missing, so say so rather than blanking it.
        activityError.value = true;
    } finally {
        activityLoading.value = false;
    }
};

watch(activityOpen, (open) => {
    if (open) loadActivity();
});

// Clicking a row is the deliberate act that clears it, the way opening a
// message does — unlike merely opening the board, which still clears nothing.
// The server records WHEN it was dismissed, so if this work order moves again
// it comes straight back.
const openUpdate = async (update) => {
    activityOpen.value = false;
    // Land on the tab the change happened in; "updated" has no specific tab to
    // blame, so it opens on Details as before.
    handleWorkOrder(update.id, update.tab);

    // Drop it from the list straight away rather than waiting on the request;
    // the board is already navigating and the server is the source of truth for
    // the count that comes back.
    activityUpdates.value = activityUpdates.value.filter(
        (row) => row.id !== update.id,
    );
    dismissedCount.value += 1;
    // Invalidate any refresh already in flight: its count predates this click.
    dismissSeq.value += 1;
    // Drop its column chip and card pill in the same breath as the badge.
    dismissedIds.value = new Set(dismissedIds.value).add(update.id);

    try {
        const { data } = await axios.post(
            route("work_orders.hvac.dismiss", update.id),
        );

        if (typeof data.new_count === "number") {
            serverNewCount.value = data.new_count;
            dismissedCount.value = 0;

            // The sidebar number is a shared Inertia prop, so it would otherwise
            // sit stale until the next navigation and disagree with the board.
            if (page.props) page.props.hvac_board_new_count = data.new_count;
        }
    } catch {
        // The dismissal did not stick. Put the optimistic decrement back so the
        // badge keeps telling the truth rather than quietly under-counting.
        dismissedCount.value -= 1;
    }
};

const markingSeen = ref(false);

// Clearing the counters is deliberate, never automatic: opening the board must
// not wipe the list of what moved before it has been dealt with.
const markBoardSeen = () => {
    if (markingSeen.value) return;

    markingSeen.value = true;

    router.post(
        route("work_orders.hvac.seen"),
        {},
        {
            preserveScroll: true,
            preserveState: false,
            onFinish: () => {
                markingSeen.value = false;
            },
        }
    );
};

// A reloaded board carries a fresh payload that knows nothing about per-row
// dismissals, so recounting it locally would resurrect everything already dealt
// with — which is exactly what the 60-second poll used to do, snapping the
// badge back up a few seconds after a row was cleared. Re-ask the server, which
// is the only thing that applies dismissals, and leave the displayed number
// alone until it answers.
watch(
    () => props.service_status,
    async () => {
        if (!props.newActivity) return;

        const seq = dismissSeq.value;

        try {
            const { data } = await axios.get(route("work_orders.hvac.activity"));

            // A dismissal happened while this was in flight, so its answer
            // predates that click and would put the row back.
            if (seq !== dismissSeq.value) return;

            if (typeof data.new_count === "number") {
                activityUpdates.value = data.updates ?? activityUpdates.value;
                rememberDismissedFrom(data.updates);
                serverNewCount.value = data.new_count;
                dismissedCount.value = 0;
            }
        } catch {
            // Keep the last known-good number rather than jumping to a count
            // that ignores dismissals.
        }
    }
);

// Keep the counters current while the board is open. Guarded so only the board
// that shows counters pays for it; every other board stays as it was.
if (props.newActivity) {
    usePoll(60000, { only: ["service_status"] });

    // Ask once on arrival so the column chips and card pills already agree with
    // the badge, rather than counting dismissed rows until the dropdown is
    // opened for the first time.
    onMounted(loadActivity);
}

const openWorkOrder = ref(false);

const workOrderForm = useForm({
    id: "",
    work_order_no: "",
    category: "",
    type: "",
    priority: "",
    location: "",
    status: "",
    total_cost: "",
    total_hour_work: "",
    authorized_to_enter: "",
    source: "",
    cost_estimate: "",
    hour_estimate: "",
    additional_work_needed_reschedule: "",
    last_modified: "",
    closing_comments: "",
    latest_update_comments: "",
    service_status: "",
    service_status_id: "",
    zone: "",
    created_date: "",
    scheduled_end_date: "",
    end_date: "",
    management_plan: "",
    description: "",
    vendor_notes: "",
    is_emergency: "",
    is_approved: "",
    vendor_id: "",
    vendors: [],
    managed_by: "",
    requested: "",
    owners: [],
    local_status: "",
    woc: "",
    building: null,
    propertyware_id: "",
    jobber_web_uri: "",
});

const closeWorkOrderForm = useForm({
    id: "",
});

const activeTab = ref("details");
const tabButtons = [
    {
        name: "recommendation",
        tooltip: "Recommendation",
        icon: Sparkles,
        requires: ["admin", "woc", "vendor", "owner", "tenant"],
    },
    {
        name: "details",
        tooltip: "Details",
        icon: ClipboardList,
        requires: ["admin", "woc", "vendor", "owner", "tenant"],
    },
    {
        name: "tasks",
        tooltip: "Tasks",
        icon: ListChecks,
        requires: ["admin", "woc", "vendor"],
    },
    {
        name: "notes",
        tooltip: "Notes",
        icon: Notebook,
        requires: ["admin", "woc", "vendor", "owner", "tenant"],
    },
    {
        name: "vendor_edit",
        tooltip: "Vendor Edit",
        icon: NotebookPen,
        requires: ["admin", "woc", "vendor"],
    },
    {
        name: "vendor_woc_conversation",
        tooltip: "WOC Conversation",
        icon: "W",
        requires: ["vendor"],
    },
    {
        name: "vendor_owner_conversation",
        tooltip: "Owner Conversation",
        icon: "O",
        requires: ["vendor"],
    },
    {
        name: "vendor_tenant_conversation",
        tooltip: "Tenant Conversation",
        icon: "T",
        requires: ["vendor"],
    },
    {
        name: "vendor_conversation",
        tooltip: "Vendor Conversation",
        icon: "V",
        requires: ["admin", "woc"],
    },
    {
        name: "owner_conversation",
        tooltip: "Owner Conversation",
        icon: "O",
        requires: ["admin", "woc"],
    },
    {
        name: "tenant_conversation",
        tooltip: "Tenant Conversation",
        icon: "T",
        requires: ["admin", "woc"],
    },
    {
        name: "owner_woc_conversation",
        tooltip: "Work Order Coordinator Conversation",
        icon: "W",
        requires: ["owner"],
    },
    {
        name: "owner_vendor_conversation",
        tooltip: "Vendor Conversation",
        icon: "V",
        requires: ["owner"],
    },
    {
        name: "tenant_woc_conversation",
        tooltip: "Work Order Coordinator Conversation",
        icon: "W",
        requires: ["tenant"],
    },
    {
        name: "tenant_vendor_conversation",
        tooltip: "Vendor Conversation",
        icon: "V",
        requires: ["tenant"],
    },
    {
        name: "service_schedule",
        tooltip: "Service Schedule",
        icon: Calendar,
        requires: ["admin", "woc", "vendor"],
    },
    {
        name: "attachments",
        tooltip: "Attachments",
        icon: Paperclip,
        requires: ["admin", "woc", "vendor", "owner", "tenant"],
    },
    {
        name: "invoices",
        tooltip: "Invoice",
        icon: FileText,
        requires: ["admin", "woc", "vendor"],
    },
    {
        name: "conversation",
        tooltip: "Conversation",
        icon: MessagesSquare,
        requires: ["admin", "woc"],
    },
];

const switchTab = (tabName) => {
    activeTab.value = tabName;
    workOrderTasks.value = [];

    if (activeTab.value === "recommendation" && workOrderForm.id) {
        fetchRecommendation(workOrderForm.id);
    }

    if (activeTab.value === "tasks" && workOrderForm.id) {
        fetchWorkOrderTask(workOrderForm.id);
    }

    if (activeTab.value === "details" && workOrderForm.id) {
        handleWorkOrder(workOrderForm.id); // Fetch latest data when switching to "Details"
    }

    if (activeTab.value === "vendor_tenant_conversation" && workOrderForm.id) {
        fetchVendorTenantConversation(workOrderForm.id);
    }

    if (
        (activeTab.value === "vendor_owner_conversation" && workOrderForm.id) ||
        (activeTab.value === "owner_vendor_conversation" && workOrderForm.id)
    ) {
        fetchVendorOwnerConversation(workOrderForm.id);
    }

    if (
        (activeTab.value === "vendor_conversation" && workOrderForm.id) ||
        (activeTab.value === "vendor_woc_conversation" && workOrderForm.id)
    ) {
        fetchVendorConversation(workOrderForm.id);
    }

    if (activeTab.value === "tenant_conversation" && workOrderForm.id) {
        fetchTenantConversation(workOrderForm.id);
    }

    if (activeTab.value === "owner_conversation" && workOrderForm.id) {
        fetchOwnerConversation(workOrderForm.id);
    }

    if (activeTab.value === "owner_woc_conversation" && workOrderForm.id) {
        fetchOwnerConversation(workOrderForm.id);
    }

    if (activeTab.value === "conversation" && workOrderForm.id) {
        fetchVendorTenantConversation(workOrderForm.id);
        fetchVendorConversation(workOrderForm.id);
        fetchTenantConversation(workOrderForm.id);
        fetchOwnerConversation(workOrderForm.id);
    }

    if (activeTab.value === "service_schedule" && workOrderForm.id) {
        fetchVendorServiceSchedules(workOrderForm.id);
    }

    if (activeTab.value === "attachments" && workOrderForm.id) {
        fetchAttachments(workOrderForm.id);
    }

    if (activeTab.value === "invoices" && workOrderForm.id) {
        fetchInvoices(workOrderForm.id);
    }

    if (activeTab.value === "notes" && workOrderForm.id) {
        fetchNotes(workOrderForm.id);
    }

    if (activeTab.value === "vendor_edit" && workOrderForm.id) {
        fetchVendors(workOrderForm.id);
    }
};

const isLoading = ref(false);

const ownerConversation = ref([]);
const workOrderOwners = ref([]);
const workOrderTenants = ref([]);
const workOrderVendors = ref([]);
// Vendors removed from the work order whose thread history remains (staff only).
const formerVendors = ref([]);

const fetchOwnerConversation = async (workOrderId) => {
    try {
        isLoading.value = true;
        const response = await axios.get(
            route("work_order.owner_conversation", workOrderId)
        );

        ownerConversation.value = response.data.owner_conversation;
        workOrderOwners.value = response.data.owners;
    } catch (error) {
        console.error("Error fetching tasks:", error);
    } finally {
        isLoading.value = false;
    }
};

const tenantConversation = ref([]);

const fetchTenantConversation = async (workOrderId) => {
    try {
        isLoading.value = true;
        const response = await axios.get(
            route("work_order.tenant_conversation", workOrderId)
        );

        tenantConversation.value = response.data.tenant_conversation;
        workOrderTenants.value = response.data.tenants;

        console.log(response.data);
    } catch (error) {
        console.error("Error fetching tasks:", error);
    } finally {
        isLoading.value = false;
    }
};

const vendorTenantConversation = ref([]);

const fetchVendorTenantConversation = async (workOrderId) => {
    try {
        isLoading.value = true;
        const response = await axios.get(
            route("work_order.vendor_tenant_conversation", workOrderId)
        );

        vendorTenantConversation.value =
            response.data.vendor_tenant_conversation;
        workOrderTenants.value = response.data.tenants;
    } catch (error) {
        console.error("Error fetching tasks:", error);
    } finally {
        isLoading.value = false;
    }
};

const vendorConversation = ref([]);

const fetchVendorConversation = async (workOrderId) => {
    try {
        isLoading.value = true;
        const response = await axios.get(
            route("work_order.vendor_conversation", workOrderId)
        );

        vendorConversation.value = response.data.vendor_conversation;
        workOrderVendors.value = response.data.vendors;
        formerVendors.value = response.data.former_vendors ?? [];
    } catch (error) {
        console.error("Error fetching tasks:", error);
    } finally {
        isLoading.value = false;
    }
};

const vendorOwnerConversation = ref([]);

const fetchVendorOwnerConversation = async (workOrderId) => {
    try {
        isLoading.value = true;
        const response = await axios.get(
            route("work_order.vendor_owner_conversation", workOrderId)
        );

        vendorOwnerConversation.value = response.data.vendor_owner_conversation;
        workOrderOwners.value = response.data.owners;
        workOrderVendors.value = response.data.vendors;
    } catch (error) {
        console.error("Error fetching tasks:", error);
    } finally {
        isLoading.value = false;
    }
};

const workOrderTasks = ref([]);
const fetchWorkOrderTask = async (workOrderId) => {
    try {
        isLoading.value = true;
        const response = await axios.get(
            route("api.work_order.tasks", workOrderId)
        );
        workOrderTasks.value = response.data.tasks;
    } catch (error) {
        console.error("Error fetching tasks:", error);
    } finally {
        isLoading.value = false;
    }
};

const vendorServiceSchedules = ref([]);
const fetchVendorServiceSchedules = async (workOrderId) => {
    try {
        isLoading.value = true;
        const response = await axios.get(
            route("work_order.service_schedules", workOrderId)
        );
        vendorServiceSchedules.value = response.data.service_schedules;
        workOrderVendors.value = response.data.vendors;
        workOrderTenants.value = response.data.tenants;
    } catch (error) {
        console.error("Error fetching tasks:", error);
    } finally {
        isLoading.value = false;
    }
};

const workOrderAttachments = ref([]);
const workOrderDocuments = ref([]);
const { unseenAttachments, buttonsWithAttachmentBadge } =
    useUnseenAttachments(tabButtons);

// Per-tab counts for the open work order: once the board says a work order
// moved, these say which tab it moved in. Layered on top of the attachments
// badge rather than replacing it, so that tab keeps its own unseen count.
const hvacTabCounts = ref({});

const tabButtonsWithBadges = computed(() => {
    const counts = hvacTabCounts.value;

    if (!props.newActivity || !Object.keys(counts).length) {
        return buttonsWithAttachmentBadge.value;
    }

    return buttonsWithAttachmentBadge.value.map((button) => {
        const count = counts[button.name];

        // Never overwrite a badge the tab already owns.
        if (!count || button.count) return button;

        return { ...button, count, countVariant: "alert" };
    });
});
const fetchAttachments = async (workOrderId) => {
    try {
        isLoading.value = true;
        const response = await axios.get(
            route("api.attachments.show", workOrderId)
        );

        workOrderAttachments.value = response.data.attachments;
        workOrderDocuments.value = response.data.documents ?? [];
        // The server marks everything viewed when staff load the tab.
        unseenAttachments.value = 0;
    } catch (error) {
        console.error("Error fetching tasks:", error);
    } finally {
        isLoading.value = false;
    }
};

const workOrderInvoices = ref([]);
const fetchInvoices = async (workOrderId) => {
    try {
        isLoading.value = true;
        const response = await axios.get(
            route("api.invoices.index", workOrderId)
        );
        workOrderInvoices.value = response.data.invoices;
    } catch (error) {
        console.error("Error fetching tasks:", error);
    } finally {
        isLoading.value = false;
    }
};
const workOrderNotes = ref([]);
const jobberNotes = ref([]);
const fetchNotes = async (workOrderId) => {
    try {
        isLoading.value = true;
        const response = await axios.get(
            route("api.work_order_notes.show", workOrderId)
        );

        workOrderNotes.value = response.data.notes;
        jobberNotes.value = response.data.jobber_notes ?? [];
    } catch (error) {
        console.error("Error fetching tasks:", error);
    } finally {
        isLoading.value = false;
    }
};

const workOrderVendorData = ref([]);
// Copyable vendor magic links, staff-only (the endpoint decides).
const vendorLinks = ref([]);
const fetchVendors = async (workOrderId) => {
    try {
        isLoading.value = true;
        const response = await axios.get(
            route("api.work_order_notes.show", workOrderId)
        );
        workOrderVendorData.value = response.data.vendors;
        vendorLinks.value = response.data.vendor_links ?? [];

        console.log(workOrderId);
    } catch (error) {
        console.error("Error fetching tasks:", error);
    } finally {
        isLoading.value = false;
    }
};

const recommendation = ref(null);
const isGeneratingRecommendation = ref(false);

const fetchRecommendation = async (workOrderId) => {
    try {
        const response = await axios.get(
            route("work_orders.recommendation.show", workOrderId)
        );
        recommendation.value = response.data.recommendation;
    } catch (error) {
        console.error("Error fetching recommendation:", error);
    }
};

const generateRecommendation = async () => {
    try {
        isGeneratingRecommendation.value = true;
        const response = await axios.post(
            route("work_orders.recommendation.generate", workOrderForm.id)
        );
        recommendation.value = response.data.recommendation;
        toast({
            title: "Success",
            description: "Recommendation generated successfully!",
        });
    } catch (error) {
        console.error("Error generating recommendation:", error);
        toast({
            variant: "destructive",
            title: "Error",
            description: "Failed to generate recommendation.",
        });
    } finally {
        isGeneratingRecommendation.value = false;
    }
};

const assignRecommendedVendor = (vendor) => {
    if (!vendor?.name) {
        return;
    }

    router.put(
        route("work_orders.vendor.change", workOrderForm.id),
        { vendors: [vendor.name] },
        {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                workOrderVendors.value = [vendor];
                toast({
                    title: "Success",
                    description: "Recommended vendor assigned successfully!",
                });
            },
            onError: () => {
                toast({
                    variant: "destructive",
                    title: "Uh oh! Something went wrong.",
                    description: "Failed to assign recommended vendor.",
                });
            },
        }
    );
};

const handleUpdateSubmit = () => {
    workOrderForm.put(route("work_orders.update", workOrderForm.id), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            toast({
                title: "Success",
                description: "Work order has been updated successfully!",
            });
        },
        onError: () => {
            toast({
                variant: "destructive",
                title: "Uh oh! Something went wrong.",
                description:
                    "There was a problem with your request. Please try again!",
            });
        },
        only: ["service_status"],
    });
};

const handleCloseOrderSubmit = () => {
    closeWorkOrderForm.put(route("work_orders.close", workOrderForm.id), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            toast({
                title: "Success",
                description: "Work order has been closed successfully!",
            });
            openWorkOrder.value = false;
        },
        onError: () => {
            toast({
                variant: "destructive",
                title: "Uh oh! Something went wrong.",
                description:
                    "There was a problem with your request. Please try again!",
            });
        },
        only: ["service_status"],
    });
};

// `openOnTab` lets the HVAC "what moved" list land on the tab that actually
// changed. Every other caller passes an id alone and still opens on Details.
const handleWorkOrder = async (orderId, openOnTab = null) => {
    workOrderForm.reset();
    activeTab.value = openOnTab || "details";
    workOrderTasks.value = [];
    recommendation.value = null;
    isGeneratingRecommendation.value = false;
    openWorkOrder.value = true;
    isLoading.value = true;

    try {
        const response = await axios.get(route("work_orders.data", orderId));
        const order = response.data; // Assuming the API returns the work order details

        unseenAttachments.value = order.unseen_attachments_count ?? 0;
        hvacTabCounts.value = order.hvac_tab_counts ?? {};

        workOrderForm.id = order.id;
        workOrderForm.work_order_no = order.work_order_no;
        workOrderForm.description = order.description;
        workOrderForm.location = order.location;
        workOrderForm.managed_by = order.managed_by;
        workOrderForm.requested = order.requested_by;
        workOrderVendors.value = order.vendors ?? [];
        workOrderForm.vendors = order.vendors ?? [];
        workOrderForm.is_approved = order.is_approved;
        workOrderForm.approved_date = order.approved_date;
        workOrderForm.approval_comments = order.approval_comments;
        workOrderForm.owners = order.owners;
        workOrderForm.management_plan = order.management_plan;
        workOrderForm.priority = order.priority;
        workOrderForm.status = order.status;
        workOrderForm.is_emergency =
            order.is_emergency === null
                ? null
                : order.is_emergency
                ? "Emergency"
                : "Non-emergency";

        workOrderForm.local_status = order.local_status;

        workOrderForm.total_cost = order.total_cost ?? "0";
        workOrderForm.total_hour_work = order.total_hour_work ?? "0";
        workOrderForm.cost_estimate = order.cost_estimate ?? "0";
        workOrderForm.hour_estimate = order.hour_estimate ?? "0";
        workOrderForm.type = order.type;
        workOrderForm.closing_comments = order.closing_comments;
        workOrderForm.latest_update_comments = order.latest_update_comments;
        workOrderForm.source = order.source;
        workOrderForm.service_status = order.service_status.name;
        workOrderForm.service_status_id = order.service_status.id;
        workOrderForm.category = order.category;
        workOrderForm.created_date = order.created_date
            ? order.created_date
            : "";
        workOrderForm.scheduled_end_date = order.scheduled_end_date
            ? order.scheduled_end_date
            : "";
        workOrderForm.end_date = order.end_date ? new Date(order.end_date) : "";
        workOrderForm.authorized_to_enter = order.authorized_to_enter;
        workOrderForm.additional_work_needed_reschedule =
            order.additional_work_needed_reschedule;
        workOrderForm.zone = order.zone;
        workOrderForm.vendor_notes = order.vendor_notes;

        workOrderForm.woc = order.woc;
        workOrderForm.building = order.building ?? null;
        workOrderForm.propertyware_id = order.propertyware_id;
        workOrderForm.jobber_web_uri = order.jobber_web_uri ?? "";

        // Reset close form
        closeWorkOrderForm.reset();
        closeWorkOrderForm.id = order.id;
    } catch (error) {
        console.error("Failed to fetch work order:", error);
    }
    isLoading.value = false;

    // A coordinator can click the Recommendation tab while this data is still
    // loading. switchTab skips its fetch then (the id is not back yet), and a
    // tab that never fetched looks like a work order with no recommendation,
    // so it would auto-generate: a paid AI call that also overwrites the
    // stored one. Fetch it now instead.
    if (activeTab.value === "recommendation" && workOrderForm.id) {
        fetchRecommendation(workOrderForm.id);
    }
};

const openImportWorkOrder = ref(false);
const importWorkOrderForm = useForm({
    work_order_no: "",
});

const handleImportWorkOrder = () => {
    importWorkOrderForm.post(route("work_orders.import"), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            toast({
                title: "Success",
                description: "Work order has been imported successfully!",
            });
            openImportWorkOrder.value = false;
            importWorkOrderForm.reset(); // Reset the form
        },
        onError: (errors) => {
            toast({
                variant: "destructive",
                title: "Import failed",
                description:
                    errors.work_order_no ??
                    "There was a problem with your request. Please try again!",
            });
        },
        only: ["service_status"],
    });
};

const df = new DateFormatter("en-US", {
    dateStyle: "medium",
});

const date_range = ref({
    start: "",
    end: "",
});

// Vendor, category, search and date filtering are applied client-side on the
// already-loaded board (see WorkOrderCard) — no server round-trips on change.

</script>
<template>
    <Head :title="title" />

    <WorkOrderFilterBar
        v-model:search="search"
        v-model:vendor="filter_vendor"
        v-model:category="filter_category"
        v-model:emergency="filter_emergency"
        v-model:color="filter_color"
        :vendors="vendors"
        :categories="categories"
    >
        <template #actions>
            <div class="flex gap-2 shrink-0 justify-end w-full sm:w-auto sm:ml-auto">
            <!-- Page-specific primary action (e.g. HOA "Upload Notice"). -->
            <slot name="board-actions" />
            <!-- How much moved since this board was last marked seen, and the
                 only way to clear it. Hidden entirely when nothing is new. -->
            <Popover v-if="newActivity && boardNewCount" v-model:open="activityOpen">
                <PopoverTrigger as-child>
                    <Button
                        variant="outline"
                        class="relative shrink-0 gap-1.5 border-destructive text-destructive hover:bg-destructive hover:text-destructive-foreground"
                        :title="`${boardNewCount} work order${boardNewCount === 1 ? '' : 's'} updated since you last marked this board seen`"
                    >
                        <span class="relative flex">
                            <Bell class="h-4 w-4" />
                            <!-- A live ping, so movement is noticed without
                                 the board being watched. -->
                            <span
                                class="absolute -right-0.5 -top-0.5 flex h-1.5 w-1.5"
                            >
                                <span
                                    class="absolute inline-flex h-full w-full animate-ping rounded-full bg-current opacity-75"
                                ></span>
                                <span
                                    class="relative inline-flex h-1.5 w-1.5 rounded-full bg-current"
                                ></span>
                            </span>
                        </span>
                        <span class="font-semibold">{{ boardNewCount }}</span>
                        new
                        <ChevronDown class="h-4 w-4 opacity-70" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent align="end" class="w-[24rem] p-0">
                    <div
                        class="flex items-center justify-between gap-2 border-b bg-muted/40 px-3 py-2.5"
                    >
                        <div class="min-w-0">
                            <p class="text-sm font-semibold leading-tight">
                                What moved
                            </p>
                            <p
                                class="text-[11px] leading-tight text-muted-foreground"
                            >
                                Since you last marked this board seen
                            </p>
                        </div>
                        <Button
                            variant="ghost"
                            size="sm"
                            :disabled="markingSeen"
                            class="h-7 shrink-0 gap-1 text-xs"
                            @click="markBoardSeen"
                        >
                            <BellOff class="h-3.5 w-3.5" />
                            Mark all seen
                        </Button>
                    </div>

                    <div
                        v-if="activityLoading"
                        class="px-3 py-8 text-center text-sm text-muted-foreground"
                    >
                        <Loader2 class="mx-auto mb-1 h-4 w-4 animate-spin" />
                        Loading…
                    </div>
                    <p
                        v-else-if="activityError"
                        class="px-3 py-8 text-center text-sm text-muted-foreground"
                    >
                        Could not load the list. The count above is still right.
                    </p>
                    <!-- Everything queued has been clicked through, but the
                         board has not been marked seen yet. -->
                    <div
                        v-else-if="!activityUpdates.length"
                        class="px-3 py-8 text-center text-sm text-muted-foreground"
                    >
                        <CircleCheckBig class="mx-auto mb-1 h-5 w-5 opacity-60" />
                        You are all caught up.
                    </div>
                    <ScrollArea v-else class="max-h-[24rem]">
                        <button
                            v-for="update in activityUpdates"
                            :key="update.id"
                            type="button"
                            class="group flex w-full items-start gap-2.5 border-b px-3 py-2.5 text-left transition-colors last:border-b-0 hover:bg-muted focus-visible:bg-muted focus-visible:outline-none"
                            @click="openUpdate(update)"
                        >
                            <!-- The kind of change, as a shape. Lets a column of
                                 rows be scanned without reading each one. -->
                            <span
                                class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-destructive text-destructive-foreground"
                            >
                                <component
                                    :is="changeIcon(update.change)"
                                    class="h-3.5 w-3.5"
                                />
                            </span>

                            <span class="min-w-0 flex-1">
                                <span
                                    class="flex items-baseline justify-between gap-2"
                                >
                                    <!-- What happened leads: it is the reason
                                         this row is in the list at all. -->
                                    <span
                                        class="truncate text-sm font-semibold capitalize"
                                        >{{ update.change }}</span
                                    >
                                    <span
                                        class="shrink-0 text-[10px] text-muted-foreground"
                                        >{{ agoLabel(update.at) }}</span
                                    >
                                </span>
                                <span
                                    class="mt-0.5 flex items-baseline gap-1.5 text-xs text-muted-foreground"
                                >
                                    <span class="shrink-0 font-medium"
                                        >#{{ update.work_order_no }}</span
                                    >
                                    <span v-if="update.location" class="truncate">{{
                                        update.location
                                    }}</span>
                                </span>
                                <span
                                    v-if="update.status"
                                    class="mt-1 inline-flex max-w-full truncate rounded bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground group-hover:bg-background"
                                    >{{ update.status }}</span
                                >
                            </span>
                        </button>
                    </ScrollArea>
                </PopoverContent>
            </Popover>
            <Popover>
                <PopoverTrigger as-child>
                    <Button
                        variant="outline"
                        :class="[
                            'w-full justify-start text-left text-xs font-normal sm:w-auto sm:min-w-[150px]',
                            !date_range.start ? 'text-muted-foreground' : '',
                        ]"
                    >
                        <CalendarIcon class="mr-2 h-4 w-4" />
                        <template v-if="date_range.start">
                            <template v-if="date_range.end">
                                {{
                                    df.format(
                                        date_range.start.toDate(
                                            getLocalTimeZone()
                                        )
                                    )
                                }}
                                -
                                {{
                                    df.format(
                                        date_range.end.toDate(
                                            getLocalTimeZone()
                                        )
                                    )
                                }}
                            </template>
                            <template v-else>
                                {{
                                    df.format(
                                        date_range.start.toDate(
                                            getLocalTimeZone()
                                        )
                                    )
                                }}
                            </template>
                        </template>
                        <template v-else> Pick a date </template>
                    </Button>
                </PopoverTrigger>
                <PopoverContent class="w-auto p-0">
                    <RangeCalendar
                        v-model="date_range"
                        initial-focus
                        :number-of-months="2"
                        @update:start-value="
                            (startDate) => (date_range.start = startDate)
                        "
                        @update:end-value="
                            (endDate) => (date_range.end = endDate)
                        "
                    />
                </PopoverContent>
            </Popover>
            <a
                :href="
                    route('work_orders.export', {
                        vendor: filter_vendor,
                        search: search,
                        start_date: date_range?.start?.toString(),
                        end_date: date_range?.end?.toString(),
                    })
                "
                class="bg-primary px-3 py-3 rounded text-white hover:bg-primary/80"
                title="Download work orders"
            >
                <Download class="w-4 h-4" />
            </a>
            <Button
                v-if="
                    $page.props.auth.user.roles.includes('admin') ||
                    $page.props.auth.user.roles.includes('woc')
                "
                class="bg-primary px-3 py-3 rounded text-white hover:bg-primary/80"
                size="icon"
                title="Import Work Order"
                @click="openImportWorkOrder = true"
                ><ScanSearch class="w-4 h-4" />
            </Button>
            <Link
                class="bg-primary px-3 py-3 rounded text-white hover:bg-primary/80"
                size="icon"
                title="Refresh"
                :href="url"
                preserve-scroll
                :only="['service_status']"
            >
                <RefreshCw class="w-4 h-4" />
            </Link>
            </div>
        </template>
    </WorkOrderFilterBar>

    <ScrollArea
        class="w-[90vw] sm:w-[85vw] md:w-[75vw] lg:w-[70vw] xl:w-[75vw]"
    >
        <Deferred data="service_status">
            <template #fallback>
                <div class="mb-5">
                    <div class="flex gap-3 mb-3">
                        <Skeleton
                            v-for="value in 6"
                            :key="value"
                            class="h-[80px] w-[240px]"
                        />
                    </div>
                    <div
                        class="flex gap-3 mb-3"
                        v-for="value in 3"
                        :key="value"
                    >
                        <Skeleton
                            v-for="value in 6"
                            :key="value"
                            class="h-[180px] w-[240px] rounded-lg"
                        />
                    </div>
                </div>
            </template>
            <WorkOrderCard
                :service_status="service_status"
                :hoa="hoa"
                :new-activity="newActivity"
                :board-seen-at="boardSeenAt"
                :dismissed-ids="dismissedIds"
                :color-filter="filter_color"
                :search-term="search"
                :vendor-filter="filter_vendor"
                :category-filter="filter_category"
                :emergency-filter="filter_emergency"
                :date-range="date_range"
                @showWorkOrder="handleWorkOrder"
            />
        </Deferred>
        <ScrollBar orientation="horizontal" />
    </ScrollArea>

    <div class="">
        <span class="text-gray-600">Drag/swipe the scrollbar →</span>
    </div>

    <Dialog v-model:open="openWorkOrder">
        <DialogScrollContent
            class="flex w-full !max-w-4xl grid-rows-[auto_minmax(0,1fr)_auto] flex-col p-0 md:max-w-2xl"
        >
            <DialogHeader class="p-6 pb-0 text-left">
                <DialogTitle class="text-2xl text-primary">
                    <div v-if="!isLoading" class="flex items-center gap-3">
                        <p>#{{ workOrderForm.work_order_no }}</p>
                        <WorkOrderExternalLinks
                            :propertyware-id="workOrderForm.propertyware_id"
                            :jobber-web-uri="workOrderForm.jobber_web_uri"
                        />
                    </div>
                </DialogTitle>
                <DialogDescription>
                    <div class="flex gap-2 mb-2 flex-wrap" v-if="!isLoading">
                        <Badge
                            :variant="
                                workOrderForm.priority === 'High'
                                    ? 'destructive'
                                    : 'outline'
                            "
                            >Priority: {{ workOrderForm.priority }}</Badge
                        >
                        <Badge variant="outline"
                            >Status: {{ workOrderForm.status }}</Badge
                        >
                        <Badge
                            v-if="workOrderForm.is_emergency !== null"
                            :variant="
                                workOrderForm.is_emergency === 'Non-emergency'
                                    ? 'outline'
                                    : 'destructive'
                            "
                            >{{ workOrderForm.is_emergency }}</Badge
                        >
                        <Badge
                            v-if="workOrderForm.is_approved"
                            variant="outline"
                            >Approved</Badge
                        >
                    </div>
                </DialogDescription>
                <div class="flex justify-center gap-2 flex-wrap">
                    <TabSwitcher
                        :buttons="tabButtonsWithBadges"
                        :activeTab="activeTab"
                        @switchTab="switchTab"
                    />
                </div>
            </DialogHeader>
            <Separator />

            <Recommendation
                :recommendation="recommendation"
                :isLoading="isLoading"
                :isGenerating="isGeneratingRecommendation"
                @generate="generateRecommendation"
                @assign="assignRecommendedVendor"
                v-if="activeTab === 'recommendation'"
            />

            <WorkOrderDetails
                :workOrder="workOrderForm"
                :categories="categories"
                :types="types ?? []"
                :vendors="vendors"
                :closeWorkOrderForm="closeWorkOrderForm"
                :isLoading="isLoading"
                @save="handleUpdateSubmit"
                @close="handleCloseOrderSubmit"
                @delete="openWorkOrder = false"
                @update-workOrder="handleWorkOrder(workOrderForm.id)"
                v-if="activeTab === 'details'"
            />

            <WorkOrderTask
                :workOrderTasks="workOrderTasks"
                :service_status="service_status"
                :isEmergency="workOrderForm.is_emergency"
                :workOrder="workOrderForm"
                :users="users"
                :isLoading="isLoading"
                @update-task-status="fetchWorkOrderTask(workOrderForm.id)"
                v-if="activeTab === 'tasks'"
            />

            <Conversation
                :workOrder="workOrderForm"
                :vendorConversation="vendorConversation"
                :ownerConversation="ownerConversation"
                :tenantConversation="tenantConversation"
                :vendorTenantConversation="vendorTenantConversation"
                :vendorWocConversation="vendorConversation"
                :isLoading="isLoading"
                v-if="activeTab === 'conversation'"
            />

            <VendorWocConversation
                :vendorWocConversation="vendorConversation"
                :workOrderVendors="workOrderVendors"
                @update-vendor-woc-convo="
                    fetchVendorConversation(workOrderForm.id)
                "
                :workOrder="workOrderForm"
                :isLoading="isLoading"
                v-if="activeTab === 'vendor_woc_conversation'"
            />

            <vendorOwnerConversation
                :vendorOwnerConversations="vendorOwnerConversation"
                :workOrderOwners="workOrderOwners"
                @update-vendor-owner-convo="
                    fetchVendorOwnerConversation(workOrderForm.id)
                "
                :workOrder="workOrderForm"
                :isLoading="isLoading"
                v-if="activeTab === 'vendor_owner_conversation'"
            />

            <VendorTenantConversation
                :vendorTenantConversations="vendorTenantConversation"
                :workOrderTenants="workOrderTenants"
                @update-vendor-tenant-convo="
                    fetchVendorTenantConversation(workOrderForm.id)
                "
                :workOrder="workOrderForm"
                :isLoading="isLoading"
                v-if="activeTab === 'vendor_tenant_conversation'"
            />

            <VendorConversation
                :vendorConversation="vendorConversation"
                :workOrderVendors="workOrderVendors"
                :formerVendors="formerVendors"
                @update-vendor-convo="fetchVendorConversation(workOrderForm.id)"
                :workOrder="workOrderForm"
                :isLoading="isLoading"
                v-if="activeTab === 'vendor_conversation'"
            />

            <OwnerConversation
                :ownerConversation="ownerConversation"
                :workOrderOwners="workOrderOwners"
                :workOrder="workOrderForm"
                @update-owner-convo="fetchOwnerConversation(workOrderForm.id)"
                :isLoading="isLoading"
                v-if="activeTab === 'owner_conversation'"
            />

            <OwnerWocConversation
                :ownerConversation="ownerConversation"
                :workOrder="workOrderForm"
                @update-owner-convo="fetchOwnerConversation(workOrderForm.id)"
                :isLoading="isLoading"
                v-if="activeTab === 'owner_woc_conversation'"
            />

            <OwnerVendorConversation
                :ownerVendorConversation="vendorOwnerConversation"
                :workOrder="workOrderForm"
                :workOrderVendors="workOrderVendors"
                @update-owner-vendor-convo="
                    fetchVendorOwnerConversation(workOrderForm.id)
                "
                :isLoading="isLoading"
                v-if="activeTab === 'owner_vendor_conversation'"
            />

            <TenantConversation
                :tenantConversation="tenantConversation"
                :workOrderTenants="workOrderTenants"
                :workOrder="workOrderForm"
                @update-tenant-convo="fetchTenantConversation(workOrderForm.id)"
                :isLoading="isLoading"
                v-if="activeTab === 'tenant_conversation'"
            />

            <ServiceSchedule
                :vendorServiceSchedules="vendorServiceSchedules"
                :workOrderVendors="workOrderVendors"
                :workOrderTenants="workOrderTenants"
                :workOrder="workOrderForm"
                @fetch-schedule="fetchVendorServiceSchedules(workOrderForm.id)"
                :isLoading="isLoading"
                v-if="activeTab === 'service_schedule'"
            />

            <Attachments
                :workOrderAttachments="workOrderAttachments"
                :workOrderDocuments="workOrderDocuments"
                :workOrder="workOrderForm"
                :isLoading="isLoading"
                @fetch-attachments="fetchAttachments(workOrderForm.id)"
                v-if="activeTab === 'attachments'"
            />

            <Invoices
                :workOrderInvoices="workOrderInvoices"
                :workOrder="workOrderForm"
                :assignedVendors="workOrderVendors"
                :isLoading="isLoading"
                @fetch-invoices="fetchInvoices(workOrderForm.id)"
                v-if="activeTab === 'invoices'"
            />

            <Notes
                :workOrderNotes="workOrderNotes"
                :jobberNotes="jobberNotes"
                :workOrder="workOrderForm"
                :isLoading="isLoading"
                @fetch-notes="fetchNotes(workOrderForm.id)"
                v-if="activeTab === 'notes'"
            />

            <VendorEdit
                :workOrderVendorData="workOrderVendorData"
                :vendorLinks="vendorLinks"
                :workOrder="workOrderForm"
                :isLoading="isLoading"
                @fetch-vendor="fetchVendors(workOrderForm.id)"
                v-if="activeTab === 'vendor_edit'"
            />
        </DialogScrollContent>
    </Dialog>

    <Dialog v-model:open="openImportWorkOrder">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Import Work Order</DialogTitle>
                <DialogDescription>
                    Enter work order number and click import to save the data.
                </DialogDescription>
            </DialogHeader>

            <div class="my-2">
                <Input
                    v-model="importWorkOrderForm.work_order_no"
                    type="number"
                    placeholder="Enter work order no"
                    class="mt-2"
                    :class="{
                        'border-destructive':
                            importWorkOrderForm.errors.work_order_no,
                    }"
                />
                <span class="text-xs text-destructive">{{
                    importWorkOrderForm.errors.work_order_no
                }}</span>

                <div class="text-xs text-muted-foreground mt-2">
                    <p>
                        This process may take some time depending on the work
                        orders.
                        <span v-if="importWorkOrderForm.processing">
                            Please don't close...
                        </span>
                    </p>
                </div>
            </div>

            <DialogFooter>
                <Button
                    type="submit"
                    :disabled="importWorkOrderForm.processing"
                    @click.prevent="handleImportWorkOrder"
                >
                    <Loader2
                        v-if="importWorkOrderForm.processing"
                        class="w-4 h-4 animate-spin"
                    />
                    <div>
                        <span v-if="importWorkOrderForm.processing">
                            Importing...
                        </span>
                        <span v-else>Import</span>
                    </div>
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
