<script setup>
import { Truck, Tag, UserRoundPen, CircleCheckBig, MapPin, Repeat2, CalendarClock } from "lucide-vue-next";
import { DateTime } from "luxon";
import { usePage } from "@inertiajs/vue3";
import { computed, nextTick, onMounted, ref, watch } from "vue";
import { statusList } from "@/utils/serviceStatusList";

const emit = defineEmits(["showWorkOrder"]);

const props = defineProps({
    work_order: Object,
    service_status: Object,
    // Signal to scroll to and flash a work order's card — set by the parent
    // when the modal closes so staff are taken straight to the card they were
    // just handling, wherever it landed (e.g. it moved to the New column after
    // a status change). Shape: { id, token }; token changes so re-focusing the
    // same work order re-triggers.
    focusSignal: {
        type: Object,
        default: null,
    },
    // "all" | "red" | "blue" | "green" — narrows the board to cards of one
    // color, matching the same rules that paint the cards below.
    colorFilter: {
        type: String,
        default: "all",
    },
    // Client-side filters applied to the already-loaded board so filtering is
    // instant (no server round-trip). Each is a no-op when empty/"all".
    searchTerm: { type: String, default: "" },
    vendorFilter: { type: [String, Number], default: "" },
    categoryFilter: { type: String, default: "" },
    emergencyFilter: { type: String, default: "" },
    dateRange: { type: Object, default: null },
    // HOA board: render the HOA deadline + state pill on each card (data comes
    // from work_order.hoa, decorated server-side). No effect on other boards.
    hoa: { type: Boolean, default: false },
    // Crystal Creek Air board: a brand pill on each card, and the outside
    // customer's name and phone where a Texas Renters card shows the tenant.
    crystalCreek: { type: Boolean, default: false },
    // HVAC board: count and mark the work orders that moved since this user
    // last marked the board seen. Off (false) for every other board, which
    // renders exactly as it did before this existed.
    newActivity: { type: Boolean, default: false },
    // ISO-8601 UTC string; null means this user has never marked the board
    // seen, in which case nothing is called new rather than everything.
    boardSeenAt: { type: String, default: null },
    // Work order ids already dealt with from the "what moved" list. The board
    // payload cannot know about dismissals, so without these the chips and
    // pills would keep counting rows the badge has already dropped.
    dismissedIds: { type: Object, default: () => new Set() },
});

// Remembers each column's scroll offset for the whole SPA session, keyed by
// service status id. Module-scoped on purpose: updating a work order from the
// modal re-fetches the deferred `service_status` prop, which tears this board
// down to its skeleton and remounts it with every column back at the top —
// component state would be lost, module state survives.
const columnScrollPositions = new Map();

const boardRoot = ref(null);

// Scroll events don't bubble, so a capture-phase listener on the board root
// hears every column viewport without wiring a listener per column.
const rememberColumnScroll = (event) => {
    if (event.target === boardRoot.value) {
        columnScrollPositions.set("__board", event.target.scrollLeft);

        return;
    }

    const column = event.target?.closest?.("[data-scroll-column]");

    if (column) {
        columnScrollPositions.set(column.dataset.scrollColumn, event.target.scrollTop);
    }
};

const restoreColumnScroll = async () => {
    await nextTick();

    if (!boardRoot.value) return;

    for (const [columnId, scrollTop] of columnScrollPositions) {
        if (columnId === "__board") {
            boardRoot.value.scrollLeft = scrollTop;
            continue;
        }

        const column = boardRoot.value.querySelector(
            `[data-scroll-column="${columnId}"]`,
        );

        if (!column || !scrollTop) continue;

        // Only the reka-ui viewport may carry the offset. The overflow-hidden
        // wrapper is programmatically scrollable too (despite hiding its
        // scrollbars), but the mouse wheel can't move it — any offset left on
        // it shifts the column's content permanently out of view (clipped
        // cards at the top, blank band at the bottom).
        const viewport = column.querySelector("[data-reka-scroll-area-viewport]");
        (viewport ?? column).scrollTop = scrollTop;
        if (viewport) column.scrollTop = 0;
    }
};

onMounted(restoreColumnScroll);
watch(() => props.service_status, restoreColumnScroll, { flush: "post" });

// Scroll to and briefly flash the card the user was just working on, wherever
// it now lives on the board.
const flashId = ref(null);
let flashTimer = null;

// Walk up from an element to the nearest ancestor that actually scrolls on
// the given axis. The board's horizontal scroller may be this component's
// root or the reka ScrollArea viewport that wraps it depending on layout, so
// we find it at run time instead of assuming which element it is.
const scrollableAncestor = (el, axis) => {
    const overflowProp = axis === "x" ? "overflowX" : "overflowY";
    const scrollProp = axis === "x" ? "scrollWidth" : "scrollHeight";
    const clientProp = axis === "x" ? "clientWidth" : "clientHeight";

    let node = el?.parentElement;

    while (node) {
        const overflow = getComputedStyle(node)[overflowProp];

        if (/(auto|scroll)/.test(overflow) && node[scrollProp] > node[clientProp]) {
            return node;
        }

        node = node.parentElement;
    }

    return null;
};

// Bring the card fully into view along one axis by nudging its scroll
// container the minimum distance — never using scrollIntoView(), which would
// also scroll the overflow-hidden wrapper and the page, leaving offsets the
// mouse wheel can't undo.
const revealAlong = (scroller, cardRect, axis) => {
    if (!scroller) return;

    const box = scroller.getBoundingClientRect();
    const start = axis === "x" ? "left" : "top";
    const end = axis === "x" ? "right" : "bottom";

    if (cardRect[start] < box[start]) {
        scroller.scrollBy({
            [axis === "x" ? "left" : "top"]: cardRect[start] - box[start],
            behavior: "smooth",
        });
    } else if (cardRect[end] > box[end]) {
        scroller.scrollBy({
            [axis === "x" ? "left" : "top"]: cardRect[end] - box[end],
            behavior: "smooth",
        });
    }
};

const nextFrame = () => new Promise((resolve) => requestAnimationFrame(resolve));

const focusWorkOrder = async (id) => {
    if (!id) return;

    await nextTick();

    // A status change can move the card to another column, which reloads the
    // board (deferred service_status). That re-render may not be done when the
    // modal closes, so wait a few frames for the card to appear rather than
    // silently giving up on the first miss.
    let card = boardRoot.value?.querySelector(`[data-work-order-id="${id}"]`);

    for (let tries = 0; !card && tries < 30; tries++) {
        await nextFrame();
        card = boardRoot.value?.querySelector(`[data-work-order-id="${id}"]`);
    }

    if (!card) return;

    const column = card.closest("[data-scroll-column]");

    // Clear any stray wrapper offset before measuring.
    if (column) column.scrollTop = 0;

    const cardRect = card.getBoundingClientRect();

    // Vertical: the column's own scroll viewport. Horizontal: whichever
    // ancestor really scrolls sideways (board root or the reka viewport).
    revealAlong(scrollableAncestor(card, "y"), cardRect, "y");
    revealAlong(scrollableAncestor(card, "x"), cardRect, "x");

    flashId.value = id;

    if (flashTimer) clearTimeout(flashTimer);
    flashTimer = setTimeout(() => {
        if (flashId.value === id) flashId.value = null;
    }, 4100);
};

watch(
    () => props.focusSignal,
    (signal) => {
        if (signal?.id) focusWorkOrder(signal.id);
    },
    { flush: "post" },
);

const page = usePage();
const authUser = page.props.auth?.user;
const isVendor = (authUser?.roles ?? []).includes("vendor");
const myVendorId = authUser?.vendor?.id;

// A vendor must only see their own tag on a shared work order, never other vendors'.
const displayVendors = (workOrder) => {
    const vendors = workOrder?.vendors ?? [];
    if (isVendor && myVendorId) {
        return vendors.filter((v) => v.id === myVendorId);
    }
    return vendors;
};

const formatDate = (date) => {
    if (!date) return "------";

    let parsedDate;

    if (typeof date === "string") {
        if (date.includes("T")) {
            // Handle ISO format (2025-03-06T17:41:20.000000Z)
            parsedDate = DateTime.fromISO(date, { zone: "utc" });
        } else {
            // Handle non-ISO format (2025-03-06 23:10:06)
            parsedDate = DateTime.fromFormat(date, "yyyy-MM-dd HH:mm:ss", {
                zone: "utc",
            });
        }
    } else if (date instanceof Date) {
        parsedDate = DateTime.fromJSDate(date);
    } else {
        return "Invalid Date";
    }

    return parsedDate.isValid
        ? parsedDate.toFormat("MM/dd/yyyy")
        : "Invalid Date";
};

const handleWorkOrder = (work_order) => {
    emit("showWorkOrder", work_order); // Emit event to parent
};

const countCompletedTask = (tasks) => {
    const completedTasks = (tasks ?? []).filter(
        (task) => task.status === "completed"
    );
    return completedTasks.length;
};

const checkDueTask = (tasks, scheduled_end_date) => {
    const today = new Date().toISOString().split("T")[0];

    let bgColor = "green"; // Default color if all tasks are upcoming

    // Check scheduled_end_date first (highest priority)
    if (scheduled_end_date) {
        if (scheduled_end_date === today) return "blue"; // Due today
        if (scheduled_end_date < today) return "red"; // Overdue
        if (scheduled_end_date > today) return "green"; // Upcoming
    }

    const pendingTasks = (tasks ?? []).filter(
        (task) => task.status === "pending"
    );

    // Check pending tasks
    if (pendingTasks.some((task) => task.due_date < today)) {
        bgColor = "red"; // Any overdue task
    } else if (pendingTasks.some((task) => task.due_date === today)) {
        bgColor = "blue"; // Any task due today
    }

    return bgColor;
};

// The color a card is actually painted with: closed work orders render gray
// regardless of due dates, and emergencies render red on top of checkDueTask.
const cardColor = (work_order) => {
    if (work_order.status === "Closed") {
        return "gray";
    }

    if (work_order.is_emergency) {
        return "red";
    }

    return checkDueTask(work_order.tasks, work_order.scheduled_end_date);
};

// --- Client-side filters ---------------------------------------------------
// Everything below narrows the already-loaded board in the browser, so vendor,
// category, emergency, search, date, and color filtering are all instant.

const matchesSearch = (work_order) => {
    const term = (props.searchTerm || "").trim().toLowerCase();
    if (!term) return true;

    return [work_order.work_order_no, work_order.location, work_order.building?.name]
        .filter((value) => value != null)
        .some((value) => String(value).toLowerCase().includes(term));
};

// Vendors whose work orders the selected vendor's filter must not show: THMP
// is tagged onto Jimmie Gendke SFA's work orders only so the Jobber job gets
// created, so THMP's filter leaves them out. The map comes from the controller
// (vendor_filter_exclusions, THMP id => hidden vendor ids; [] when empty) and
// is read here, not captured at setup, so a later page visit is honoured.
const hiddenVendorIds = computed(
    () =>
        new Set(
            ((page.props.vendor_filter_exclusions ?? {})[String(props.vendorFilter)] ?? []).map(String)
        )
);

const matchesVendor = (work_order) => {
    if (!props.vendorFilter || props.vendorFilter === "all") return true;

    const vendors = work_order.vendors || [];

    if (vendors.some((vendor) => hiddenVendorIds.value.has(String(vendor.id)))) return false;

    return vendors.some((vendor) => String(vendor.id) === String(props.vendorFilter));
};

const matchesCategory = (work_order) => {
    if (!props.categoryFilter || props.categoryFilter === "all") return true;

    return work_order.category === props.categoryFilter;
};

const matchesEmergency = (work_order) => {
    const filter = props.emergencyFilter;
    if (!filter || filter === "all") return true;

    const isEmergency = work_order.is_emergency;
    if (filter === "emergency") return isEmergency === true || isEmergency === 1;
    if (filter === "non_emergency") return isEmergency === false || isEmergency === 0;
    if (filter === "needs_review") return isEmergency === null || isEmergency === undefined;

    return true;
};

const matchesColor = (work_order) => {
    if (!props.colorFilter || props.colorFilter === "all") return true;

    return cardColor(work_order) === props.colorFilter;
};

const matchesDate = (work_order) => {
    const start = props.dateRange?.start ? props.dateRange.start.toString() : null;
    const end = props.dateRange?.end ? props.dateRange.end.toString() : null;
    if (!start && !end) return true;

    // created_date is an ISO-ish datetime; its first 10 chars are the YYYY-MM-DD
    // date, which compares correctly against the calendar range as a string.
    const created = work_order.created_date
        ? String(work_order.created_date).slice(0, 10)
        : null;
    if (!created) return false;

    if (start && created < start) return false;
    if (end && created > end) return false;

    return true;
};

// Filtered cards per column, computed once per board change rather than per
// call: the template reads this for the column's v-if, its count, its badge and
// its card loop, and recomputing the whole filter chain four times per column on
// every render is work the board does not need to repeat.
const visibleByStatus = computed(() => {
    const map = new Map();

    // service_status arrives as an object keyed by index, not an array — the
    // template's v-for does not care, but for…of does.
    for (const status of statusList(props.service_status)) {
        map.set(
            status.id,
            (status.work_orders || []).filter(
                (work_order) =>
                    matchesSearch(work_order) &&
                    matchesVendor(work_order) &&
                    matchesCategory(work_order) &&
                    matchesEmergency(work_order) &&
                    matchesColor(work_order) &&
                    matchesDate(work_order)
            )
        );
    }

    return map;
});

const visibleWorkOrders = (status) => visibleByStatus.value.get(status.id) ?? [];

/**
 * Whether this work order moved since the user last marked the board seen.
 *
 * Both sides are ISO-8601 UTC strings as Laravel serializes them, so a plain
 * string comparison orders them correctly without parsing a date per card.
 */
const isNew = (work_order) => {
    if (!props.newActivity || !props.boardSeenAt || !work_order?.updated_at) {
        return false;
    }

    // Already dealt with from the "what moved" list, so the badge has dropped
    // it and the chips must agree.
    if (props.dismissedIds?.has?.(work_order.id)) {
        return false;
    }

    return work_order.updated_at > props.boardSeenAt;
};

/** How many of a column's *visible* cards are new, so it agrees with its count. */
const newCount = (status) => visibleWorkOrders(status).filter(isNew).length;
</script>

<template>
    <div
        ref="boardRoot"
        class="flex flex-row flex-nowrap space-x-2 overflow-x-auto scrollbar-hide"
        @scroll.capture="rememberColumnScroll"
    >
        <template v-for="status in service_status" :key="status.id">
            <div
                v-if="visibleWorkOrders(status).length !== 0"
                class="overflow-hidden min-w-[240px]"
            >
                <div class="text-center font-semibol">
                    <!-- Status Name -->
                    <div
                        class="h-16 flex items-center justify-center gap-1.5 border p-3 text-sm uppercase font-semibold"
                    >
                        <p>
                            {{ status.name }} ({{
                                visibleWorkOrders(status).length
                            }})
                        </p>
                        <!-- How many of this column's cards moved since the
                             user last marked the board seen. Carries the word
                             "new" because a bare number beside "(15)" reads as
                             another total. HVAC board only. -->
                        <span
                            v-if="newCount(status)"
                            :title="`${newCount(status)} updated since you last marked this board seen`"
                            class="inline-flex shrink-0 items-center gap-1 rounded-full bg-destructive px-2 py-0.5 text-[10px] font-semibold leading-none text-destructive-foreground normal-case"
                        >
                            <span
                                class="inline-block h-1.5 w-1.5 rounded-full bg-current"
                            ></span>
                            {{ newCount(status) }} new
                        </span>
                    </div>

                    <!-- Work Orders List -->
                    <ScrollArea
                        :data-scroll-column="status.id"
                        class="h-[70vh] overflow-y-auto border-t pt-2 mb-5"
                    >
                        <div
                            @click="handleWorkOrder(work_order)"
                            v-motion-slide-visible-once-right
                            v-for="work_order in visibleWorkOrders(status)"
                            :key="work_order.id"
                            :data-work-order-id="work_order.id"
                            class="mb-2 rounded-lg p-4 text-white cursor-pointer hover:shadow-lg transition-all"
                            :class="{
                                'wo-flash': work_order.id === flashId,
                                'bg-destructive':
                                    checkDueTask(
                                        work_order.tasks,
                                        work_order.scheduled_end_date
                                    ) == 'red' ||
                                    work_order.is_emergency ||
                                    (hoa && work_order.hoa?.overdue),
                                'bg-primary':
                                    checkDueTask(
                                        work_order.tasks,
                                        work_order.scheduled_end_date
                                    ) == 'blue',
                                'bg-green-500':
                                    checkDueTask(
                                        work_order.tasks,
                                        work_order.scheduled_end_date
                                    ) == 'green',
                                'bg-gray-600': work_order.status === 'Closed',
                            }"
                        >
                            <!-- Work Order Number & Date -->
                            <div
                                class="flex justify-between items-center border-b pb-2 mb-2"
                            >
                                <h1
                                    class="text-lg font-semibold flex items-center gap-1.5"
                                >
                                    <!-- This card moved since the board was
                                         last marked seen. A dot rather than a
                                         word: it has to catch the eye while
                                         scanning a column, not compete with the
                                         work order number for the headline.
                                         HVAC board only. -->
                                    <span
                                        v-if="isNew(work_order)"
                                        class="relative flex h-2.5 w-2.5 shrink-0"
                                        title="Updated since you last marked this board seen"
                                    >
                                        <span
                                            class="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-500 opacity-75"
                                        ></span>
                                        <span
                                            class="relative inline-flex h-2.5 w-2.5 rounded-full bg-red-600 ring-2 ring-white/70"
                                        ></span>
                                    </span>
                                    {{ work_order.work_order_no }}
                                </h1>
                                <p class="text-xs text-gray-200">
                                    📅 {{ formatDate(work_order.created_date) }}
                                </p>
                            </div>

                            <!-- Crystal Creek Air: an outside customer's job,
                                 not a Texas Renters property. A Texas Renters
                                 work order assigned to the Crystal Creek Air
                                 vendor shows its vendor line instead. -->
                            <div
                                v-if="work_order.source === 'Crystal Creek Air'"
                                class="mb-2 flex justify-center"
                            >
                                <span
                                    class="inline-flex items-center gap-1 rounded-full border border-white/50 bg-white/25 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                                    title="Crystal Creek Air customer, not a Texas Renters property"
                                >
                                    Crystal Creek Air
                                </span>
                            </div>

                            <!-- Repeat issue: this problem has come up before
                                 at this property -->
                            <div
                                v-if="work_order.is_repeat_issue"
                                class="mb-2 flex justify-center"
                            >
                                <span
                                    class="inline-flex items-center gap-1 rounded-full border border-white/50 bg-white/25 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                                    title="This issue has come up before at this property"
                                >
                                    <Repeat2 class="w-3 h-3" />
                                    Repeat<template
                                        v-if="Number(work_order.repeat_count) > 1"
                                    >
                                        · {{ work_order.repeat_count }}×</template
                                    >
                                </span>
                            </div>

                            <!-- Location -->
                            <p class="text-sm text-gray-100 font-semibold">
                                {{ work_order.location }}
                            </p>

                            <!-- Property name -->
                              <span class="text-center text-xs text-gray-200">
                                    <span
                                        v-if="work_order.building?.name"
                                        class="font-medium"
                                        >{{ work_order.building.name }}</span
                                    >
                                </span>
                                <p
                                    class="text-xs text-gray-100 flex items-center gap-1 justify-center"
                                >
                                    <Tag class="w-3 h-3" />{{
                                        work_order.category
                                    }}
                                </p>

                                <!-- HOA deadline + state (HOA board only) -->
                                <template v-if="hoa && work_order.hoa">
                                    <p
                                        v-if="work_order.hoa.deadline"
                                        class="text-xs text-gray-100 flex items-center gap-1 justify-center mt-1"
                                    >
                                        <CalendarClock class="w-3 h-3" />Due
                                        {{ work_order.hoa.deadline }}
                                    </p>
                                    <div class="mt-1 flex justify-center">
                                        <span
                                            class="inline-flex items-center rounded-full border border-white/50 bg-white/25 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                                        >
                                            {{ work_order.hoa.state }}
                                        </span>
                                    </div>
                                </template>

                                <!-- Tenant Easy Fix details (easy-fix board only;
                                     work_order.easy_fix is decorated server-side) -->
                                <div
                                    v-if="work_order.easy_fix"
                                    class="mt-1 flex flex-wrap justify-center gap-1"
                                    data-easy-fix-chips
                                >
                                    <a
                                        v-if="work_order.easy_fix.item && work_order.easy_fix.video_url"
                                        :href="work_order.easy_fix.video_url"
                                        target="_blank"
                                        rel="noopener"
                                        title="Open the handbook how-to video"
                                        class="inline-flex items-center rounded-full border border-white/50 bg-white/25 px-2 py-0.5 text-[10px] font-semibold underline"
                                        @click.stop
                                    >
                                        {{ work_order.easy_fix.item }}
                                    </a>
                                    <span
                                        v-else-if="work_order.easy_fix.item"
                                        class="inline-flex items-center rounded-full border border-white/50 bg-white/25 px-2 py-0.5 text-[10px] font-semibold"
                                    >
                                        {{ work_order.easy_fix.item }}
                                    </span>
                                    <span
                                        :title="[
                                            work_order.easy_fix.flagged && work_order.easy_fix.status_set
                                                ? 'Matched by the automation and put in Checking for Tenant Easy Fix'
                                                : work_order.easy_fix.flagged
                                                    ? 'Matched to a handbook item by the automation'
                                                    : work_order.easy_fix.hoa
                                                        ? 'HOA violation: the HOA intake puts every violation in Checking for Tenant Easy Fix'
                                                        : 'Put in Checking for Tenant Easy Fix by a coordinator',
                                            work_order.easy_fix.verdict_reason,
                                        ].filter(Boolean).join('\n')"
                                        class="inline-flex items-center rounded-full border border-white/50 bg-white/25 px-2 py-0.5 text-[10px] font-semibold"
                                        data-easy-fix-source
                                    >
                                        {{ work_order.easy_fix.flagged ? "Auto" : work_order.easy_fix.hoa ? "HOA" : "Set by WOC" }}
                                    </span>
                                    <span
                                        class="inline-flex items-center rounded-full border border-white/50 px-2 py-0.5 text-[10px] font-semibold"
                                        :class="work_order.easy_fix.texted ? 'bg-white/25' : 'bg-black/20'"
                                    >
                                        {{ work_order.easy_fix.texted ? "Texted" : "Not texted" }}
                                    </span>
                                    <span
                                        v-if="work_order.easy_fix.texted"
                                        :title="work_order.easy_fix.last_check_in_at ? `Last check-in ${work_order.easy_fix.last_check_in_at}` : 'No check-in sent yet'"
                                        class="inline-flex items-center rounded-full border border-white/50 bg-white/25 px-2 py-0.5 text-[10px] font-semibold"
                                    >
                                        Check-ins {{ work_order.easy_fix.check_ins }}/{{ work_order.easy_fix.check_ins_max }}
                                    </span>
                                    <span
                                        v-if="work_order.easy_fix.photo_uploaded"
                                        class="inline-flex items-center rounded-full border border-white/50 bg-white/25 px-2 py-0.5 text-[10px] font-semibold"
                                    >
                                        Photo in
                                    </span>
                                    <span
                                        v-if="work_order.easy_fix.tenant_replied"
                                        class="inline-flex items-center rounded-full border border-white/50 bg-white/25 px-2 py-0.5 text-[10px] font-semibold"
                                    >
                                        Tenant replied
                                    </span>
                                </div>
                                <p
                                    v-if="work_order.is_approved"
                                    class="text-xs text-gray-100 flex items-center gap-1 justify-center"
                                >
                                    <CircleCheckBig class="w-3 h-3" />Approved
                                </p>

                            <div
                                v-if="work_order.requested_by"
                                class="flex text-left gap-1 mb-1 mt-2"
                            >
                                <UserRoundPen class="w-4 h-4" />
                                <p class="text-xs text-gray-100 uppercase">
                                    {{ work_order.requested_by?.first_name }}
                                    {{ work_order.requested_by?.last_name }}
                                </p>
                            </div>
                            <div v-else-if="work_order.owners?.length" class="flex text-left mb-1 mt-2">
                                <UserRoundPen class="w-4 h-4" />
                                <p class="text-xs text-gray-100 uppercase">
                                    {{ work_order.owners[0]?.first_name }}
                                    {{ work_order.owners[0]?.last_name }}
                                </p>
                            </div>
                            <!-- Outside customer (Crystal Creek Air): no tenant
                                 or owner, so the caller is the contact -->
                            <div
                                v-else-if="work_order.service_request_contact_name"
                                class="flex text-left gap-1 mb-1 mt-2"
                            >
                                <UserRoundPen class="w-4 h-4 shrink-0" />
                                <p class="text-xs text-gray-100 uppercase">
                                    {{ work_order.service_request_contact_name }}
                                    <span
                                        v-if="work_order.service_request_contact_phone"
                                        class="block normal-case"
                                        >{{ work_order.service_request_contact_phone }}</span
                                    >
                                </p>
                            </div>
                            <p
                                class="text-xs text-gray-100"
                                v-for="vendor in displayVendors(work_order)"
                                :key="vendor.id"
                            >
                                <span class="flex gap-1 text-left uppercase">
                                    <Truck class="w-4 h-4" />{{
                                        vendor.name
                                    }}</span
                                >
                            </p>

                            <!-- Requested Info -->
                            <div class="flex justify-between items-center mt-1">
                                <div class="flex gap-1 items-center">
                                    <p
                                        class="text-xs"
                                        v-if="work_order.tasks?.length > 0"
                                    >
                                        {{
                                            countCompletedTask(
                                                work_order.tasks
                                            )
                                        }}/{{ work_order.tasks.length }}
                                        tasks
                                    </p>
                                </div>
                                <div>
                                    <span
                                        class="text-[10px] px-1 uppercase rounded border"
                                        :class="
                                            work_order.priority === 'High'
                                                ? 'bg-destructive'
                                                : 'bg-primary'
                                        "
                                        >Priority:
                                        {{ work_order.priority }}</span
                                    >
                                </div>
                            </div>
                        </div>

                        <ScrollBar orientation="vertical" />
                    </ScrollArea>
                </div>
            </div>
        </template>
    </div>
</template>

<style scoped>
/* Brief ring that pulses then fades on the card the user was just working on. */
@keyframes wo-flash {
    0% {
        box-shadow: 0 0 0 0 rgba(250, 204, 21, 0);
    }
    15% {
        box-shadow: 0 0 0 4px rgba(250, 204, 21, 0.95);
    }
    100% {
        box-shadow: 0 0 0 4px rgba(250, 204, 21, 0);
    }
}

.wo-flash {
    animation: wo-flash 4s ease-out;
}
</style>
