<script setup>
import { ref, watch, onMounted, computed } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { useToast } from "@/Components/ui/toast/use-toast";
import MessageCard from "@/Components/MessageCard.vue";
import { Button } from "@/Components/ui/button";
import { Input } from "@/Components/ui/input";
import { ScrollArea } from "@/Components/ui/scroll-area";
import { Skeleton } from "@/Components/ui/skeleton";
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/Components/ui/select";
import { Avatar, AvatarFallback, AvatarImage } from "@/Components/ui/avatar";
import AutomationToggle from "@/Components/WorkOrder/AutomationToggle.vue";
import AwaitingReplyBanner from "@/Components/WorkOrder/AwaitingReplyBanner.vue";
import MessageComposer from "@/Components/WorkOrder/MessageComposer.vue";
import { buildParticipants } from "@/utils/conversation";
import { useThreadScroll } from "@/composables/useThreadScroll";

const { toast } = useToast();

const props = defineProps({
    tenantConversation: Array,
    workOrderTenants: Array,
    isLoading: Boolean,
    workOrder: Object,
});
const emit = defineEmits(["update-tenant-convo"]);

const messageBody = ref("");
const composer = ref(null);
const refSuffix = computed(() => {
    const workOrderNo = props.workOrder?.work_order_no;
    return workOrderNo ? ` (Ref: WO#${workOrderNo})` : "";
});
const selectedTenant = ref("");
const tenant_phone_number = ref(props.workOrder?.requested?.mobile_phone);

// THMP (in-house) jobs are excluded from the automated tenant assignment
// message — THMP staff message the tenant manually. This one-click button fills
// the composer with the standard THMP wording (staff still review + Send; it
// never sends on its own). Shown only when THMP is an assigned vendor.
const THMP_NAME = "Texas Home Maintenance Pros";
const hasThmpVendor = computed(
    () =>
        Array.isArray(props.workOrder?.vendors) &&
        props.workOrder.vendors.some(
            (vendor) =>
                (vendor?.name || "").trim().toLowerCase() ===
                THMP_NAME.toLowerCase()
        )
);
const thmpRecipientFirstName = computed(() => {
    const selected = props.workOrderTenants?.find(
        (tenant) => tenant.id == selectedTenant.value
    );
    return (
        selected?.first_name ||
        props.workOrder?.requested?.first_name ||
        props.workOrder?.requested_by?.first_name ||
        ""
    ).trim();
});
const thmpPropertyAddress = computed(
    () =>
        props.workOrder?.requested?.address ||
        props.workOrder?.requested_by?.address ||
        props.workOrder?.building?.address ||
        props.workOrder?.building?.name ||
        "your home"
);
const insertThmpTemplate = () => {
    const name = thmpRecipientFirstName.value;
    const greeting = name ? `Hi ${name},` : "Hi,";
    const ref = props.workOrder?.work_order_no ?? props.workOrder?.id;
    composer.value?.insert(
        `${greeting}\n\nWe've assigned Texas Home Maintenance Pros to handle the repairs at ` +
            `${thmpPropertyAddress.value} under Work Order #${ref}. We will let you know once we ` +
            `receive the schedule updates from them. Thank you!`
    );
};

const { bottomAnchor, scrollToBottom } = useThreadScroll();

const page = usePage();
const woc = ref(props.workOrder.woc);
const woc_phone_number = ref(
    props.workOrder.woc?.woc_number?.twilio_phone_number?.phone_number
        ?? page.props.maintenance_twilio_phone_number
);

const participants = computed(() =>
    buildParticipants([
        ...(props.workOrderTenants ?? []).map((tenant) => ({
            phone: tenant.mobile_phone,
            name: `${tenant.first_name ?? ""} ${tenant.last_name ?? ""}`.trim(),
            role: "Tenant",
        })),
        {
            phone: woc_phone_number.value,
            name: woc.value?.name,
            role: "Coordinator",
            avatar: woc.value?.profile_photo_url,
        },
    ])
);

const loading = ref(false);

watch(selectedTenant, (newTenant) => {
    if (newTenant) {
        const foundTenant = props.workOrderTenants.find(
            (tenant) => tenant.id == newTenant
        );
        tenant_phone_number.value = foundTenant ? foundTenant.mobile_phone : "";
    }
});

const sendMessage = ({ text, files }) => {
    if (!tenant_phone_number.value) {
        toast({
            variant: "destructive",
            title: "Uh oh! Something went wrong.",
            description:
                "There was a problem with your request. Please select a receiver!",
        });

        return;
    }

    loading.value = true;

    const formData = new FormData();
    formData.append("text", text);
    formData.append("sender_phone_number", woc_phone_number.value);
    formData.append("receiver_phone_number", tenant_phone_number.value);
    formData.append("work_order_id", props.workOrder.id);
    formData.append("conversation_type", "tenant");

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
            emit("update-tenant-convo");
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

// Scroll to the bottom when the component mounts or when the conversation updates
onMounted(() => {
    scrollToBottom(true);
});

watch(
    () => props.tenantConversation,
    () => {
        scrollToBottom();
    },
    { deep: true }
);
</script>

<template>
    <div>
        <div class="grid gap-3 px-6">
            <div class="mb-1 flex items-center justify-between gap-2">
                <p class="text-xs font-semibold uppercase tracking-wide">
                    Tenant ↔ Coordinator
                </p>
                <AutomationToggle
                    v-if="workOrder?.id"
                    :work-order-id="workOrder.id"
                    channel="tenant"
                />
            </div>

            <div
                class="text-muted-foreground mb-1 flex flex-wrap items-center gap-2 text-xs"
            >
                <span class="shrink-0">To</span>
                <Select v-model="selectedTenant">
                    <SelectTrigger class="h-8 w-[190px] text-xs">
                        <SelectValue placeholder="Select a tenant" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            <template
                                v-for="tenant in workOrderTenants"
                                :key="tenant.id"
                            >
                                <SelectItem
                                    :value="String(tenant.id)"
                                    :selected="
                                        tenant.mobile_phone ===
                                        workOrder.requested?.mobile_phone
                                    "
                                >
                                    {{ tenant.first_name }}
                                    {{ tenant.last_name }} -
                                    {{ tenant?.mobile_phone }}
                                </SelectItem>
                            </template>
                        </SelectGroup>
                    </SelectContent>
                </Select>
                <Input
                    v-model="tenant_phone_number"
                    placeholder="+1 214 555 0134"
                    title="Include the country code, e.g. +1"
                    class="h-8 w-[150px] text-xs"
                />
                <span
                    v-if="woc"
                    class="ml-auto flex min-w-0 items-center gap-1.5 truncate"
                >
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

            <AwaitingReplyBanner
                :messages="tenantConversation"
                :our-number="woc_phone_number"
                party="tenant"
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
                <MessageCard
                    v-else
                    :messages="tenantConversation"
                    :sender="woc_phone_number"
                    :participants="participants"
                />
                <div ref="bottomAnchor" class="h-px" />
            </ScrollArea>

            <!-- Quick-insert templates -->
            <div v-if="hasThmpVendor" class="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="text-xs"
                    @click="insertThmpTemplate"
                >
                    Insert THMP assignment message
                </Button>
            </div>

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
