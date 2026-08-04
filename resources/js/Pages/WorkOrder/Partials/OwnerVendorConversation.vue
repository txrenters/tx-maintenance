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
import MessageComposer from "@/Components/WorkOrder/MessageComposer.vue";
import { buildParticipants } from "@/utils/conversation";
import { useThreadScroll } from "@/composables/useThreadScroll";

const props = defineProps({
    ownerVendorConversation: Array,
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

const emit = defineEmits(["update-owner-vendor-convo"]);

const page = usePage();
const { toast } = useToast();

const loading = ref(false);

const selectedVendor = ref("");
const vendor_phone_number = ref("");

const owner_phone_number = page.props.auth.user.phone;

const participants = computed(() =>
    buildParticipants(
        (props.workOrderVendors ?? []).map((vendor) => ({
            phone: vendor.twilio_number,
            name: vendor.name,
            role: "Vendor",
        }))
    )
);

const sendMessage = ({ text, files }) => {
    loading.value = true;

    const formData = new FormData();
    formData.append("text", text);
    formData.append("sender_phone_number", owner_phone_number);
    formData.append("receiver_phone_number", vendor_phone_number.value);
    formData.append("work_order_id", props.workOrder.id);
    formData.append("conversation_type", "vendor_owner");

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
            emit("update-owner-vendor-convo");
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
    () => props.ownerVendorConversation,
    () => {
        scrollToBottom();
    },
    { deep: true }
);
watch(selectedVendor, (newVendor) => {
    if (newVendor) {
        const foundVendor = props.workOrderVendors.find(
            (vendor) => vendor.id == newVendor
        );
        vendor_phone_number.value = foundVendor
            ? foundVendor.twilio_number
            : "";
    }
});
onMounted(() => {
    scrollToBottom(true);
});
</script>

<template>
    <div>
        <div class="grid gap-3 px-6">
            <p class="mb-1 text-xs font-semibold uppercase tracking-wide">
                Vendor
            </p>

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
                                <SelectItem
                                    :value="String(vendor.id)"
                                    :selected="vendor.twilio_number"
                                >
                                    {{ vendor.name }} -
                                    {{ vendor?.twilio_number }}
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
                    :messages="ownerVendorConversation"
                    :sender="owner_phone_number"
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
