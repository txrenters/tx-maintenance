<script setup>
import { ref, watch, onMounted, computed } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { useToast } from "@/Components/ui/toast/use-toast";
import MessageCard from "@/Components/MessageCard.vue";
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

const props = defineProps({
    ownerConversation: Array,
    workOrderOwners: Array,
    isLoading: Boolean,
    workOrder: Object,
});

const emit = defineEmits(["update-owner-convo"]);
const { toast } = useToast();

const messageBody = ref("");
const composer = ref(null);
const refSuffix = computed(() => {
    const workOrderNo = props.workOrder?.work_order_no;
    return workOrderNo ? ` (Ref: WO#${workOrderNo})` : "";
});
const selectedOwner = ref("");
const owner_phone_number = ref("");
const { bottomAnchor, scrollToBottom } = useThreadScroll();

const page = usePage();
const woc = ref(props.workOrder.woc);
const woc_phone_number = ref(
    props.workOrder.woc?.woc_number?.twilio_phone_number?.phone_number
        ?? page.props.maintenance_twilio_phone_number
);

// Name the bubbles instead of printing raw numbers on every message.
const participants = computed(() =>
    buildParticipants([
        ...(props.workOrderOwners ?? []).map((owner) => ({
            phone: owner.phone,
            name: `${owner.first_name ?? ""} ${owner.last_name ?? ""}`.trim(),
            role: "Owner",
        })),
        {
            phone: woc_phone_number.value,
            name: woc.value?.name,
            role: "Coordinator",
            avatar: woc.value?.profile_photo_url,
        },
    ])
);

watch(selectedOwner, (newOwner) => {
    if (newOwner) {
        const foundOwner = props.workOrderOwners.find(
            (owner) => owner.id == newOwner
        );
        owner_phone_number.value = foundOwner ? foundOwner.phone : "";
    }
});

const loading = ref(false);

const sendMessage = ({ text, files }) => {
    if (!owner_phone_number.value) {
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
    formData.append("receiver_phone_number", owner_phone_number.value);
    formData.append("work_order_id", props.workOrder.id);
    formData.append("conversation_type", "owner");

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
            emit("update-owner-convo");
            scrollToBottom(true);
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

onMounted(() => {
    scrollToBottom(true);
});

watch(
    () => props.ownerConversation,
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
                    Owner ↔ Coordinator
                </p>
                <AutomationToggle
                    v-if="workOrder?.id"
                    :work-order-id="workOrder.id"
                    channel="owner"
                />
            </div>

            <div
                class="text-muted-foreground mb-1 flex flex-wrap items-center gap-2 text-xs"
            >
                <span class="shrink-0">To</span>
                <Select v-model="selectedOwner">
                    <SelectTrigger class="h-8 w-[190px] text-xs">
                        <SelectValue placeholder="Select an owner" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            <template
                                v-for="owner in workOrderOwners"
                                :key="owner.id"
                            >
                                <SelectItem
                                    :value="String(owner.id)"
                                    :selected="owner.phone"
                                >
                                    {{ owner.first_name }}
                                    {{ owner.last_name }} -
                                    {{ owner?.phone }}
                                </SelectItem>
                            </template>
                        </SelectGroup>
                    </SelectContent>
                </Select>
                <Input
                    v-model="owner_phone_number"
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
                :messages="ownerConversation"
                :our-number="woc_phone_number"
                party="owner"
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
                    :messages="ownerConversation"
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
