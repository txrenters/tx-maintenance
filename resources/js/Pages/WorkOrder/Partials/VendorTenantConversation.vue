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
import MessageComposer from "@/Components/WorkOrder/MessageComposer.vue";
import { buildParticipants } from "@/utils/conversation";
import { useThreadScroll } from "@/composables/useThreadScroll";

const props = defineProps({
    vendorTenantConversations: Array,
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
const selectedTenant = ref("");

const emit = defineEmits(["update-vendor-tenant-convo"]);

const page = usePage();
const { toast } = useToast();

const vendor_phone_number = page.props.auth.user.phone;
const tenant_phone_number = ref(props.workOrder?.requested?.mobile_phone);

const participants = computed(() =>
    buildParticipants(
        (props.workOrderTenants ?? []).map((tenant) => ({
            phone: tenant.mobile_phone,
            name: `${tenant.first_name ?? ""} ${tenant.last_name ?? ""}`.trim(),
            role: "Tenant",
        }))
    )
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
    formData.append("sender_phone_number", vendor_phone_number);
    formData.append("receiver_phone_number", tenant_phone_number.value);
    formData.append("work_order_id", props.workOrder.id);
    formData.append("conversation_type", "vendor_tenant");

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
            emit("update-vendor-tenant-convo");
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
    () => props.vendorTenantConversations,
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
                Tenant
            </p>

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
                <span class="ml-auto flex min-w-0 items-center gap-1.5 truncate">
                    <Avatar class="h-5 w-5">
                        <AvatarImage
                            v-if="page.props.auth.user?.profile_photo_url"
                            :src="page.props.auth.user.profile_photo_url"
                        />
                        <AvatarFallback>
                            {{ page.props.auth.user?.name?.charAt(0) }}
                        </AvatarFallback>
                    </Avatar>
                    <span class="truncate">
                        From {{ page.props.auth.user?.name }} ·
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
                    :messages="vendorTenantConversations"
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
