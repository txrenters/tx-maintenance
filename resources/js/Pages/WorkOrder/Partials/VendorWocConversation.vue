<script setup>
import { ref, watch, onMounted, computed } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { useToast } from "@/Components/ui/toast/use-toast";
import MessageCard from "@/Components/MessageCard.vue";
import { ScrollArea } from "@/Components/ui/scroll-area";
import { Skeleton } from "@/Components/ui/skeleton";
import { Avatar, AvatarFallback, AvatarImage } from "@/Components/ui/avatar";
import MessageComposer from "@/Components/WorkOrder/MessageComposer.vue";
import { buildParticipants } from "@/utils/conversation";
import { useThreadScroll } from "@/composables/useThreadScroll";

const props = defineProps({
    vendorWocConversation: Array,
    workOrderTenants: Array,
    workOrderVendors: Array,
    isLoading: Boolean,
    workOrder: Object,
});

const messageBody = ref("");
const composer = ref(null);
const refSuffix = computed(() => {
    const workOrderNo = props.workOrder?.work_order_no;
    return workOrderNo ? ` (Ref: WO#${workOrderNo})` : "";
});
const { bottomAnchor, scrollToBottom } = useThreadScroll();

const emit = defineEmits(["update-vendor-woc-convo"]);

const page = usePage();
const { toast } = useToast();

const vendor_phone_number = page.props.auth.user.vendor.twilio_number;

const woc_phone_number = ref(
    props.workOrder.woc?.woc_number?.twilio_phone_number?.phone_number
        ?? page.props.maintenance_twilio_phone_number
);

const participants = computed(() =>
    buildParticipants([
        {
            phone: woc_phone_number.value,
            name: props.workOrder.woc?.name || "Work Order Coordinator",
            role: "Coordinator",
            avatar: props.workOrder.woc?.profile_photo_url,
        },
    ])
);

const loading = ref(false);

const sendMessage = ({ text, files }) => {
    if (!woc_phone_number.value) {
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
    formData.append("sender_phone_number", vendor_phone_number);
    formData.append("receiver_phone_number", woc_phone_number.value);
    formData.append("work_order_id", props.workOrder.id);
    formData.append("conversation_type", "vendor");
    formData.append("vendor_id", page.props.auth.user.vendor.id);

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
            emit("update-vendor-woc-convo");
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
    () => props.vendorWocConversation,
    () => {
        scrollToBottom();
    },
    { deep: true }
);

onMounted(() => {
    scrollToBottom(true);
});
</script>

<template>
    <div>
        <div class="grid gap-3 px-6">
            <p class="mb-1 text-xs font-semibold uppercase tracking-wide">
                Work Order Coordinator
            </p>

            <div
                class="text-muted-foreground mb-1 flex flex-wrap items-center gap-3 text-xs"
            >
                <span class="flex min-w-0 items-center gap-1.5 truncate">
                    <Avatar class="h-5 w-5">
                        <AvatarImage
                            v-if="props.workOrder.woc?.profile_photo_url"
                            :src="props.workOrder.woc.profile_photo_url"
                        />
                        <AvatarFallback>
                            {{ props.workOrder.woc?.name?.charAt(0) }}
                        </AvatarFallback>
                    </Avatar>
                    <span class="truncate">
                        To {{ props.workOrder.woc?.name }} ·
                        {{ woc_phone_number }}
                    </span>
                </span>
                <span class="ml-auto flex min-w-0 items-center gap-1.5 truncate">
                    <Avatar class="h-5 w-5">
                        <AvatarImage
                            v-if="page.props.auth.user?.profile_photo_url"
                            :src="page.props.auth.user.profile_photo_url"
                        />
                        <AvatarFallback>
                            {{ page.props.auth.user.name?.charAt(0) }}
                        </AvatarFallback>
                    </Avatar>
                    <span class="truncate">
                        From {{ page.props.auth.user.name }} ·
                        {{ vendor_phone_number }}
                    </span>
                </span>
            </div>

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
                    :messages="vendorWocConversation"
                    :sender="vendor_phone_number"
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
