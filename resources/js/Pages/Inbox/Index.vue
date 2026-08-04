<script setup>
import { computed, ref, watch } from "vue";
import { router } from "@inertiajs/vue3";
import axios from "axios";
import AppLayout from "@/Layouts/AppLayout.vue";
import { useToast } from "@/Components/ui/toast/use-toast";
import MessageCard from "@/Components/MessageCard.vue";
import MessageComposer from "@/Components/WorkOrder/MessageComposer.vue";
import AwaitingReplyBanner from "@/Components/WorkOrder/AwaitingReplyBanner.vue";
import AutomationToggle from "@/Components/WorkOrder/AutomationToggle.vue";
import { Button } from "@/Components/ui/button";
import { Input } from "@/Components/ui/input";
import { ScrollArea } from "@/Components/ui/scroll-area";
import { Skeleton } from "@/Components/ui/skeleton";
import { useThreadScroll } from "@/composables/useThreadScroll";
import { useWorkOrderModal } from "@/composables/useWorkOrderModal";
import { waitedLabel } from "@/utils/conversation";
import {
    ExternalLink,
    Headset,
    House,
    Inbox as InboxIcon,
    KeyRound,
    MessagesSquare,
    Search,
    Wrench,
} from "lucide-vue-next";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    threads: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    partyCounts: { type: Object, default: () => ({}) },
    stats: { type: Object, default: () => ({}) },
});

const { toast } = useToast();
const { bottomAnchor, scrollToBottom } = useThreadScroll();

// The modal itself is mounted once in AppLayout; this opens that same one, so
// a work order can be read without losing the thread you are in.
const { state: workOrderModal, open: openWorkOrder } = useWorkOrderModal();

const search = ref(props.filters.search ?? "");
const activeKey = ref(null);
const thread = ref(null);
const threadLoading = ref(false);
const sending = ref(false);
const messageBody = ref("");
const composer = ref(null);

const PARTY_ICON = {
    tenant: KeyRound,
    owner: House,
    vendor: Wrench,
    vendor_tenant: KeyRound,
    vendor_owner: House,
};

const iconFor = (conversationType) =>
    PARTY_ICON[conversationType] ?? Headset;

const STATUS_TABS = [
    { value: "all", label: "All" },
    { value: "awaiting", label: "Awaiting reply" },
    { value: "unanswered_24h", label: "Over 24h" },
];

/**
 * Who the thread is with. The first four always show; the vendor-brokered
 * threads are rare enough that they only earn a chip when they hold something
 * (or are the current filter, so the active one never disappears).
 */
const PARTY_TABS = [
    { value: "all", label: "Everyone", icon: MessagesSquare, always: true },
    { value: "tenant", label: "Tenant", icon: KeyRound, always: true },
    { value: "owner", label: "Owner", icon: House, always: true },
    { value: "vendor", label: "Vendor", icon: Wrench, always: true },
    { value: "vendor_tenant", label: "Vendor–Tenant", icon: KeyRound },
    { value: "vendor_owner", label: "Vendor–Owner", icon: House },
];

const countFor = (party) => props.partyCounts?.[party] ?? 0;

/**
 * Counts are computed before the search is applied, so showing them next to a
 * search result would be misleading.
 */
const showCounts = computed(() => !props.filters.search);

const partyTabs = computed(() =>
    PARTY_TABS.filter(
        (tab) =>
            tab.always || countFor(tab.value) > 0 || props.filters.party === tab.value
    )
);

const activeThread = computed(() =>
    props.threads.find((item) => item.key === activeKey.value) ?? null
);

const refSuffix = computed(() => {
    const workOrderNo = thread.value?.work_order?.work_order_no;
    return workOrderNo ? ` (Ref: WO#${workOrderNo})` : "";
});

/** The channel the per-work-order automation switch mutes for this thread. */
const automationChannel = computed(() => {
    const type = activeThread.value?.conversation_type;
    return ["tenant", "owner", "vendor"].includes(type) ? type : null;
});

const reload = (overrides = {}) => {
    router.get(
        route("inbox.index"),
        { ...props.filters, ...overrides },
        { preserveState: true, preserveScroll: true, replace: true }
    );
};

const setStatus = (status) => reload({ status });

const setParty = (party) => reload({ party });

let searchTimer = null;
watch(search, (value) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => reload({ search: value }), 350);
});

const openThread = async (item) => {
    activeKey.value = item.key;
    threadLoading.value = true;
    thread.value = null;

    try {
        const { data } = await axios.get(route("inbox.thread"), {
            params: {
                work_order_id: item.work_order_id,
                conversation_type: item.conversation_type,
                vendor_id: item.vendor_id,
                owner_id: item.owner_id,
            },
        });
        thread.value = data;
        scrollToBottom(true);
    } catch (error) {
        toast({
            variant: "destructive",
            title: "Could not open conversation",
            description:
                error?.response?.data?.message ??
                "Please try again, or open the work order directly.",
        });
        activeKey.value = null;
    } finally {
        threadLoading.value = false;
    }
};

const refreshThread = async () => {
    if (activeThread.value) {
        await openThread(activeThread.value);
    }
};

/**
 * The modal can send messages and change a work order, so the list behind it
 * would otherwise keep showing the old awaiting flag — the one signal this
 * page exists for. Only the thread that was actually opened is refetched.
 */
watch(
    () => workOrderModal.isOpen,
    (isOpen, wasOpen) => {
        if (!wasOpen || isOpen) return;

        reload();

        if (activeThread.value?.work_order_id === workOrderModal.workOrderId) {
            refreshThread();
        }
    }
);

const sendMessage = ({ text, files }) => {
    const recipient = thread.value?.recipient_number;

    if (!recipient) {
        toast({
            variant: "destructive",
            title: "No number to reply to",
            description:
                "This thread has no phone number on file. Open the work order to pick a recipient.",
        });

        return;
    }

    sending.value = true;

    const formData = new FormData();
    formData.append("text", text);
    formData.append("sender_phone_number", thread.value.woc_phone_number ?? "");
    formData.append("receiver_phone_number", recipient);
    formData.append("work_order_id", thread.value.work_order.id);
    formData.append("conversation_type", activeThread.value.conversation_type);
    if (activeThread.value.vendor_id) {
        formData.append("vendor_id", activeThread.value.vendor_id);
    }

    files.forEach((file) => {
        formData.append("images[]", file);
    });

    router.post(route("work_order.conversation.send"), formData, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            toast({
                title: "Success",
                description: "Message sent. Delivery may take a moment.",
            });
            composer.value?.reset();
            refreshThread();
        },
        onError: (errors) => {
            toast({
                variant: "destructive",
                title: "Uh oh! Something went wrong.",
                description:
                    errors.message ||
                    Object.values(errors)[0] ||
                    "There was a problem with your request. Please try again!",
            });
        },
        onFinish: () => {
            sending.value = false;
        },
    });
};
</script>

<template>
    <div class="flex h-[calc(100vh-9rem)] min-h-[520px] gap-4">
        <!-- Thread list -->
        <div
            class="bg-card flex w-full max-w-sm shrink-0 flex-col rounded-lg border shadow-sm"
            :class="activeKey ? 'hidden md:flex' : 'flex'"
        >
            <div class="space-y-3 border-b p-3">
                <div class="flex items-center justify-between gap-2">
                    <h1 class="flex items-center gap-2 text-sm font-semibold">
                        <InboxIcon class="h-4 w-4" />
                        Inbox
                    </h1>
                    <span
                        v-if="stats.awaiting"
                        class="bg-destructive text-destructive-foreground rounded-full px-2 py-0.5 text-[10px] font-semibold"
                    >
                        {{ stats.awaiting }} awaiting
                    </span>
                </div>

                <div class="relative">
                    <Search
                        class="text-muted-foreground absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2"
                    />
                    <Input
                        v-model="search"
                        placeholder="Search work order, name or message"
                        class="h-8 pl-8 text-xs"
                    />
                </div>

                <div class="flex flex-wrap gap-1">
                    <Button
                        v-for="tab in STATUS_TABS"
                        :key="tab.value"
                        size="sm"
                        :variant="filters.status === tab.value ? 'default' : 'outline'"
                        class="h-7 text-xs"
                        @click="setStatus(tab.value)"
                    >
                        {{ tab.label }}
                    </Button>
                </div>

                <div class="flex flex-wrap gap-1">
                    <Button
                        v-for="tab in partyTabs"
                        :key="tab.value"
                        size="sm"
                        :variant="filters.party === tab.value ? 'secondary' : 'ghost'"
                        class="h-7 gap-1.5 px-2 text-xs"
                        :class="
                            filters.party === tab.value
                                ? 'ring-primary/40 font-semibold ring-1'
                                : 'text-muted-foreground'
                        "
                        @click="setParty(tab.value)"
                    >
                        <component :is="tab.icon" class="h-3 w-3" />
                        {{ tab.label }}
                        <span
                            v-if="showCounts && countFor(tab.value)"
                            class="text-[10px] font-normal tabular-nums opacity-70"
                        >
                            {{ countFor(tab.value) }}
                        </span>
                    </Button>
                </div>
            </div>

            <ScrollArea class="min-h-0 flex-1">
                <p
                    v-if="!threads.length"
                    class="text-muted-foreground p-6 text-center text-sm"
                >
                    No conversations match these filters.
                </p>

                <button
                    v-for="item in threads"
                    :key="item.key"
                    type="button"
                    class="hover:bg-accent flex w-full items-start gap-3 border-b p-3 text-left transition-colors"
                    :class="activeKey === item.key ? 'bg-accent' : ''"
                    @click="openThread(item)"
                >
                    <component
                        :is="iconFor(item.conversation_type)"
                        class="text-muted-foreground mt-0.5 h-4 w-4 shrink-0"
                    />
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="truncate text-sm font-medium">
                                {{ item.counterparty }}
                            </span>
                            <span
                                class="shrink-0 text-[10px]"
                                :class="
                                    item.awaiting && item.waiting_hours >= 24
                                        ? 'text-destructive font-semibold'
                                        : 'text-muted-foreground'
                                "
                            >
                                {{ waitedLabel(item.waiting_hours) }}
                            </span>
                        </div>
                        <p class="text-muted-foreground truncate text-xs">
                            <!-- Nested inside the row button, so a span rather
                                 than a button — same pattern as the toast's
                                 dismiss control. -->
                            <span
                                role="button"
                                tabindex="0"
                                class="hover:text-foreground focus-visible:ring-ring rounded underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-1"
                                title="Open this work order"
                                @click.stop="openWorkOrder(item.work_order_id)"
                                @keydown.enter.stop.prevent="openWorkOrder(item.work_order_id)"
                                @keydown.space.stop.prevent="openWorkOrder(item.work_order_id)"
                            >
                                WO#{{ item.work_order_no }}
                            </span>
                            · {{ item.party }}
                        </p>
                        <p class="text-muted-foreground mt-0.5 truncate text-xs">
                            {{ item.preview || "No message body" }}
                        </p>
                    </div>
                    <span
                        v-if="item.awaiting"
                        class="bg-destructive mt-1.5 h-2 w-2 shrink-0 rounded-full"
                        title="Waiting on a reply"
                    />
                </button>
            </ScrollArea>
        </div>

        <!-- Conversation -->
        <div
            class="bg-card flex min-w-0 flex-1 flex-col rounded-lg border shadow-sm"
            :class="activeKey ? 'flex' : 'hidden md:flex'"
        >
            <div
                v-if="!activeKey"
                class="text-muted-foreground flex flex-1 flex-col items-center justify-center gap-2 p-6 text-center"
            >
                <MessagesSquare class="h-8 w-8 opacity-50" />
                <p class="text-sm">Pick a conversation to read and reply.</p>
            </div>

            <template v-else>
                <div class="flex items-center justify-between gap-2 border-b p-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold">
                            {{ activeThread?.counterparty }}
                            <span class="text-muted-foreground font-normal">
                                · {{ activeThread?.party }}
                            </span>
                        </p>
                        <p class="text-muted-foreground truncate text-xs">
                            <button
                                type="button"
                                class="hover:text-foreground focus-visible:ring-ring rounded font-medium underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-1"
                                title="Open this work order"
                                @click="openWorkOrder(activeThread.work_order_id)"
                            >
                                WO#{{ activeThread?.work_order_no }}
                            </button>
                            <span v-if="activeThread?.work_order_description">
                                — {{ activeThread.work_order_description }}
                            </span>
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <AutomationToggle
                            v-if="automationChannel && activeThread?.work_order_id"
                            :work-order-id="activeThread.work_order_id"
                            :channel="automationChannel"
                        />
                        <Button
                            variant="outline"
                            size="sm"
                            class="h-8 text-xs"
                            as="a"
                            :href="
                                route('work_orders.details', activeThread.work_order_id)
                            "
                        >
                            <ExternalLink class="mr-1 h-3.5 w-3.5" />
                            Work order
                        </Button>
                    </div>
                </div>

                <div class="flex min-h-0 flex-1 flex-col p-3">
                    <AwaitingReplyBanner
                        v-if="thread"
                        :messages="thread.messages"
                        :our-number="thread.woc_phone_number"
                        :party="activeThread?.party?.toLowerCase()"
                    />

                    <ScrollArea class="bg-secondary min-h-0 flex-1 rounded-md p-3">
                        <div v-if="threadLoading" class="space-y-3">
                            <div
                                v-for="i in 6"
                                :key="i"
                                class="flex"
                                :class="i % 2 ? 'justify-start' : 'justify-end'"
                            >
                                <Skeleton
                                    class="h-12 rounded-lg"
                                    :class="i % 2 ? 'w-2/5' : 'w-1/3'"
                                />
                            </div>
                        </div>
                        <MessageCard
                            v-else-if="thread"
                            :messages="thread.messages"
                            :sender="thread.woc_phone_number"
                            :participants="thread.participants"
                        />
                        <div ref="bottomAnchor" class="h-px" />
                    </ScrollArea>
                </div>

                <div class="border-t p-3">
                    <MessageComposer
                        ref="composer"
                        v-model="messageBody"
                        :ref-suffix="refSuffix"
                        :disabled="threadLoading || !thread"
                        :sending="sending"
                        @send="sendMessage"
                    />
                </div>
            </template>
        </div>
    </div>
</template>
