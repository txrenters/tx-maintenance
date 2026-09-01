<script setup>
import { ref, watch, onMounted, computed } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import axios from "axios";
import { Send } from "lucide-vue-next";
import { useToast } from "@/Components/ui/toast/use-toast";
import { Button } from "@/Components/ui/button";
import MessageCard from "@/Components/MessageCard.vue";
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from "@/Components/ui/select";
import { Input } from "@/Components/ui/input";
import { Avatar, AvatarFallback, AvatarImage } from "@/Components/ui/avatar";
import { ScrollArea } from "@/Components/ui/scroll-area";
import { Skeleton } from "@/Components/ui/skeleton";
import AutomationToggle from "@/Components/WorkOrder/AutomationToggle.vue";
import AwaitingReplyBanner from "@/Components/WorkOrder/AwaitingReplyBanner.vue";
import MessageComposer from "@/Components/WorkOrder/MessageComposer.vue";
import { buildParticipants, phoneKey } from "@/utils/conversation";
import { useThreadScroll } from "@/composables/useThreadScroll";

const props = defineProps({
    vendorConversation: Array,
    workOrderTenants: Array,
    workOrderVendors: Array,
    // Vendors removed from the work order whose thread history remains
    // (staff only; the endpoint omits them for other roles).
    formerVendors: Array,
    isLoading: Boolean,
    workOrder: Object,
});

const messageBody = ref("");
const composer = ref(null);
const refSuffix = computed(() => {
    const workOrderNo = props.workOrder?.work_order_no;
    return workOrderNo ? ` (Ref: WO#${workOrderNo})` : "";
});
const selectedVendor = ref("");
const vendor_phone_number = ref("");
const { bottomAnchor, scrollToBottom } = useThreadScroll();

const emit = defineEmits(["update-vendor-convo"]);

const page = usePage();
const { toast } = useToast();

const woc = ref(props.workOrder.woc);
const woc_phone_number = ref(
    props.workOrder.woc?.woc_number?.twilio_phone_number?.phone_number
        ?? page.props.maintenance_twilio_phone_number
);

const loading = ref(false);

// Reduce a phone number to its final 10 digits, mirroring the backend match.
const lastTenDigits = (value) => phoneKey(value) || null;

const hasSingleVendor = computed(() => props.workOrderVendors?.length === 1);

const removedVendors = computed(() => props.formerVendors ?? []);

// Assigned vendors first, then removed ones, so lookups by id cover both.
const selectableVendors = computed(() => [
    ...(props.workOrderVendors ?? []),
    ...removedVendors.value,
]);

const selectedVendorIsRemoved = computed(() =>
    removedVendors.value.some(
        (vendor) => String(vendor.id) === String(selectedVendor.value)
    )
);

const participants = computed(() =>
    buildParticipants([
        ...selectableVendors.value.flatMap((vendor) =>
            [vendor.twilio_number, vendor.user?.phone].map((phone) => ({
                phone,
                name: vendor.name,
                role: "Vendor",
            }))
        ),
        {
            phone: woc_phone_number.value,
            name: woc.value?.name,
            role: "Coordinator",
            avatar: woc.value?.profile_photo_url,
        },
    ])
);

// Messages belonging to one vendor: tagged by vendor_id, plus legacy untagged
// messages matched by that vendor's number or on a single-vendor work order.
// The single-vendor fallback only applies to the assigned vendor — a removed
// vendor's thread must never absorb untagged messages it can't prove are its.
const messagesForVendor = (vendorId) => {
    const vendor = selectableVendors.value.find(
        (v) => String(v.id) === String(vendorId)
    );
    const isAssigned = (props.workOrderVendors ?? []).some(
        (v) => String(v.id) === String(vendorId)
    );
    const numbers = vendor
        ? [vendor.twilio_number, vendor.user?.phone]
              .map(lastTenDigits)
              .filter(Boolean)
        : [];

    return (props.vendorConversation ?? []).filter((m) => {
        if (String(m.vendor_id) === String(vendorId)) return true;
        if (m.vendor_id == null) {
            if (
                numbers.includes(lastTenDigits(m.sender_number)) ||
                numbers.includes(lastTenDigits(m.receiver_number))
            ) {
                return true;
            }
            if (hasSingleVendor.value && isAssigned) return true;
        }
        return false;
    });
};

// Default view: a single-vendor work order shows its thread; with multiple
// vendors the tab stays empty until the coordinator picks one.
const displayedMessages = computed(() =>
    selectedVendor.value ? messagesForVendor(selectedVendor.value) : []
);

const sendMessage = ({ text, files }) => {
    if (!selectedVendor.value && !vendor_phone_number.value) {
        toast({
            variant: "destructive",
            title: "Select a vendor",
            description:
                "Please select a vendor or enter a phone number. Vendors with no number will still see the message in their portal.",
        });

        return;
    }

    loading.value = true;

    const formData = new FormData();
    formData.append("text", text);
    formData.append("sender_phone_number", woc_phone_number.value);
    formData.append("receiver_phone_number", vendor_phone_number.value);
    formData.append("work_order_id", props.workOrder.id);
    formData.append("conversation_type", "vendor");
    if (selectedVendor.value) {
        formData.append("vendor_id", selectedVendor.value);
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
            scrollToBottom(true);
            emit("update-vendor-convo");
        },
        onError: (errors) => {
            const errorMessage =
                errors.message ||
                Object.values(errors)[0] ||
                "There was a problem with your request. Please try again!";
            toast({
                variant: "destructive",
                title: "Uh oh! Something went wrong.",
                description: errorMessage,
            });
        },
        onFinish: () => {
            loading.value = false;
        },
    });
};

watch(
    () => props.vendorConversation,
    () => {
        scrollToBottom();
    },
    { deep: true }
);
watch(selectedVendor, (newVendor) => {
    if (newVendor) {
        const foundVendor = selectableVendors.value.find(
            (vendor) => vendor.id == newVendor
        );
        vendor_phone_number.value = foundVendor ? foundVendor.user?.phone : "";
    }
    scrollToBottom(true);
});

// Preselect the only vendor so their thread shows by default; leave the tab
// empty when there are multiple vendors so threads never blur together. When
// every vendor has been removed but exactly one left history behind, show
// that history rather than an empty tab.
watch(
    () => [props.workOrderVendors, props.formerVendors],
    () => {
        if (selectedVendor.value) return;

        if (props.workOrderVendors?.length === 1) {
            selectedVendor.value = String(props.workOrderVendors[0].id);
        } else if (
            !props.workOrderVendors?.length &&
            removedVendors.value.length === 1
        ) {
            selectedVendor.value = String(removedVendors.value[0].id);
        }
    },
    { immediate: true }
);

// Scroll to the bottom when the component mounts or when the conversation updates
onMounted(() => {
    scrollToBottom(true);
});

const notifyingAssignment = ref(false);

// Manually (re)send the selected vendor their assignment email + text. The
// automated dispatch only fires when a vendor is attached through the app's
// own assign flow, so vendors assigned in PropertyWare — or whose contact
// info was fixed later — need this button.
const notifyAssignment = async () => {
    if (!selectedVendor.value || notifyingAssignment.value) return;

    notifyingAssignment.value = true;
    try {
        const { data } = await axios.post(
            `/work_orders/${props.workOrder.id}/vendors/${selectedVendor.value}/notify-assignment`,
        );
        const channels = [
            data.email ? "email" : null,
            data.text ? "text" : null,
        ]
            .filter(Boolean)
            .join(" + ");
        toast({
            title: "Vendor notified",
            description: `Assignment ${channels} queued for the vendor.`,
        });
        setTimeout(() => emit("update-vendor-convo"), 4000);
    } catch (error) {
        toast({
            variant: "destructive",
            title: "Could not notify vendor",
            description:
                error?.response?.data?.error ||
                error?.response?.data?.message ||
                "Please try again.",
        });
    } finally {
        notifyingAssignment.value = false;
    }
};
</script>

<template>
    <div>
        <div class="grid gap-3 px-6">
            <div class="mb-1 flex items-center justify-between gap-2">
                <p class="text-xs font-semibold uppercase tracking-wide">
                    Vendor ↔ Coordinator
                </p>
                <div class="flex items-center gap-2">
                    <Button
                        v-if="selectedVendor && !selectedVendorIsRemoved"
                        type="button"
                        size="sm"
                        variant="outline"
                        class="h-7 gap-1.5 text-xs"
                        :disabled="notifyingAssignment"
                        title="Send this vendor the assignment email and text (work order PDF + portal link)"
                        @click="notifyAssignment"
                    >
                        <Send class="h-3.5 w-3.5" />
                        {{
                            notifyingAssignment
                                ? "Sending..."
                                : "Send assignment info"
                        }}
                    </Button>
                    <AutomationToggle
                        v-if="workOrder?.id"
                        :work-order-id="workOrder.id"
                        channel="vendor"
                    />
                </div>
            </div>

            <div
                class="text-muted-foreground mb-1 flex flex-wrap items-center gap-2 text-xs"
            >
                <span class="shrink-0">To</span>
                <Select v-model="selectedVendor">
                    <SelectTrigger class="h-8 w-[190px] text-xs">
                        <SelectValue placeholder="Select a vendor" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            <template
                                v-for="vendor in workOrderVendors"
                                :key="vendor.id"
                            >
                                <SelectItem :value="String(vendor.id)">
                                    {{ vendor.name }}
                                    <template v-if="vendor?.user?.phone">
                                        - {{ vendor.user.phone }}
                                    </template>
                                </SelectItem>
                            </template>
                        </SelectGroup>
                        <SelectGroup v-if="removedVendors.length">
                            <SelectLabel class="text-xs font-normal">
                                No longer assigned
                            </SelectLabel>
                            <template
                                v-for="vendor in removedVendors"
                                :key="`removed-${vendor.id}`"
                            >
                                <SelectItem :value="String(vendor.id)">
                                    {{ vendor.name }} (removed)
                                </SelectItem>
                            </template>
                        </SelectGroup>
                    </SelectContent>
                </Select>
                <Input
                    v-model="vendor_phone_number"
                    placeholder="+1 214 555 0134"
                    title="Include the country code, e.g. +1"
                    class="h-8 w-[150px] text-xs"
                />
                <span class="ml-auto flex min-w-0 items-center gap-1.5 truncate">
                    <Avatar class="h-5 w-5">
                        <AvatarImage
                            v-if="woc?.profile_photo_url"
                            :src="woc.profile_photo_url"
                        />
                        <AvatarFallback>
                            {{ woc?.name?.charAt(0) }}
                        </AvatarFallback>
                    </Avatar>
                    <span class="truncate">
                        From {{ woc?.name }} · {{ woc_phone_number }}
                    </span>
                </span>
            </div>

            <p
                v-if="selectedVendorIsRemoved"
                class="text-muted-foreground -mt-1 text-xs"
            >
                This vendor was removed from the work order. Their conversation
                history is kept; new texts still go to their number.
            </p>

            <AwaitingReplyBanner
                v-if="selectedVendor && !selectedVendorIsRemoved"
                :messages="displayedMessages"
                :our-number="woc_phone_number"
                party="vendor"
            />

            <ScrollArea
                class="bg-secondary h-[50vh] max-h-[520px] min-h-[300px] rounded-md p-3"
            >
                <div v-if="isLoading" class="space-y-3">
                    <div
                        v-for="i in 5"
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
                <div
                    v-else-if="!selectedVendor"
                    class="text-muted-foreground flex h-full min-h-[200px] items-center justify-center px-6 text-center text-sm"
                >
                    Select a vendor to view their conversation.
                </div>
                <MessageCard
                    v-else
                    :messages="displayedMessages"
                    :sender="woc_phone_number"
                    :participants="participants"
                />
                <div ref="bottomAnchor" class="h-px" />
            </ScrollArea>

            <div class="mb-6 mt-2">
                <MessageComposer
                    ref="composer"
                    v-model="messageBody"
                    :ref-suffix="refSuffix"
                    :sending="loading"
                    @send="sendMessage"
                />
            </div>
        </div>
    </div>
</template>
