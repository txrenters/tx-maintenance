<script setup>
import { ref } from "vue";
import { useForm, usePage } from "@inertiajs/vue3";
import {
    Plus,
    SquarePen,
    Copy,
    Check,
    Mail,
    MailX,
    ExternalLink,
} from "lucide-vue-next";
import { useToast } from "@/Components/ui/toast/use-toast";
import { DateTime } from "luxon";

const { toast } = useToast();

const props = defineProps({
    isLoading: Boolean,
    workOrder: Object,
    workOrderVendorData: Object,
    vendorLinks: { type: Array, default: () => [] },
});

const page = usePage();
const isStaff = ["admin", "woc", "accounting"].some((role) =>
    (page.props.auth.user?.roles ?? []).includes(role)
);

const copied = ref(null);

const copyLink = async (link, kind) => {
    const url = kind === "dashboard" ? link.dashboard_url : link.url;
    if (!url) return;
    try {
        await navigator.clipboard.writeText(url);
        copied.value = `${link.vendor_id}:${kind}`;
        setTimeout(() => (copied.value = null), 2000);
        toast({
            title: "Link copied",
            description:
                kind === "dashboard"
                    ? `All-jobs link for ${link.name} copied.`
                    : `This work order's link for ${link.name} copied.`,
        });
    } catch (e) {
        toast({
            variant: "destructive",
            title: "Couldn't copy",
            description: "Please copy the link manually.",
        });
    }
};

const emit = defineEmits(["fetch-vendor"]);

const formatDate = (date) => {
    if (!date) return "------";

    let parsedDate;

    if (typeof date === "string") {
        if (date.includes("T")) {
            // Handle ISO format (e.g., 2025-03-06T17:41:20.000000Z)
            parsedDate = DateTime.fromISO(date, { zone: "utc" });
        } else if (date.includes(":")) {
            // Handle non-ISO format with time (e.g., 2025-03-06 23:10:06)
            parsedDate = DateTime.fromFormat(date, "yyyy-MM-dd HH:mm:ss", {
                zone: "utc",
            });
        } else {
            // Handle plain date format (e.g., 2025-03-24)
            parsedDate = DateTime.fromFormat(date, "yyyy-MM-dd", {
                zone: "utc",
            });
        }
    } else if (date instanceof Date) {
        // Handle JavaScript Date object
        parsedDate = DateTime.fromJSDate(date);
    } else if (typeof date === "number") {
        // Handle UNIX timestamp (e.g., 1672531200 or 1672531200000)
        parsedDate = DateTime.fromMillis(date > 1e12 ? date : date * 1000);
    } else {
        return "Invalid Date";
    }

    return parsedDate.isValid
        ? parsedDate.toFormat("MM/dd/yyyy")
        : "Invalid Date";
};

const openModal = ref(false);
const vendorForms = useForm({
    cost_estimate: "",
    time_estimate: "",
    scheduled_end_date: "",
    work_order_id: props.workOrder.id,
});

const handleFormSubmit = () => {
    if (!vendorForms.cost_estimate || !vendorForms.scheduled_end_date) {
        toast({
            variant: "destructive",
            title: "Uh oh! Something went wrong.",
            description:
                "There was a problem with your request. Please try again!",
        });
        return;
    }
    vendorForms.post(route("api.vendor_work_order_details.update"), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            toast({
                title: "Success",
                description:
                    "Work order details has been created successfully!",
            });
            openModal.value = false;
            vendorForms.reset();
            handleFetchVendor();
        },
        onError: () => {
            toast({
                variant: "destructive",
                title: "Uh oh! Something went wrong.",
                description:
                    "There was a problem with your request. Please try again!",
            });
        },
    });
};

const handleFetchVendor = () => {
    emit("fetch-vendor");
};
</script>

<template>
    <div class="overflow-y-auto px-6 w-full min-h-[300px] mb-10">
        <!-- Vendor portal magic links (staff only) -->
        <div v-if="isStaff && vendorLinks.length" class="mb-5">
            <p class="font-semibold uppercase text-xs mb-2">
                Vendor Portal Links
            </p>
            <p class="text-xs text-muted-foreground mb-3">
                Each vendor has a unique no-login link. Copy and send it to a
                vendor who has no email on file.
            </p>
            <div class="space-y-2">
                <div
                    v-for="link in vendorLinks"
                    :key="link.vendor_id"
                    class="flex items-center justify-between gap-2 p-2 border rounded-md"
                >
                    <div class="flex items-center gap-2 min-w-0">
                        <Mail
                            v-if="link.has_email"
                            class="w-4 h-4 text-green-600 shrink-0"
                        />
                        <MailX
                            v-else
                            class="w-4 h-4 text-amber-500 shrink-0"
                            title="No email on file"
                        />
                        <span class="text-sm truncate">{{ link.name }}</span>
                    </div>
                    <div class="flex gap-1 shrink-0">
                        <Button
                            v-if="link.url"
                            as="a"
                            :href="link.url"
                            target="_blank"
                            rel="noopener"
                            size="sm"
                            @click.stop
                        >
                            <ExternalLink class="w-3.5 h-3.5 mr-1" />
                            View Work Order
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="!link.url"
                            @click="copyLink(link, 'work_order')"
                        >
                            <Check
                                v-if="copied === `${link.vendor_id}:work_order`"
                                class="w-3.5 h-3.5 mr-1"
                            />
                            <Copy v-else class="w-3.5 h-3.5 mr-1" />
                            This job
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            :disabled="!link.dashboard_url"
                            @click="copyLink(link, 'dashboard')"
                        >
                            <Check
                                v-if="copied === `${link.vendor_id}:dashboard`"
                                class="w-3.5 h-3.5 mr-1"
                            />
                            <Copy v-else class="w-3.5 h-3.5 mr-1" />
                            All jobs
                        </Button>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex justify-between gap-2 items-center mb-3">
            <div>
                <p class="font-semibold uppercase text-xs mb-3">
                    Vendor Estimates
                </p>
            </div>
            <div
                class="flex gap-2"
                v-if="$page.props.auth.user.roles.includes('vendor')"
            >
                <Button
                    :disabled="isLoading"
                    size="icon"
                    @click="openModal = true"
                >
                    <SquarePen v-if="!isLoading" class="" />
                    <Loader2 v-else class="w-4 h-4 animate-spin" />
                </Button>
            </div>
        </div>
        <div class="work_order_details">
            <div
                v-for="vendor in workOrderVendorData"
                :key="vendor.id"
                class="p-2 border mb-2"
            >
                <p>Vendor: {{ vendor.name }}</p>

                <div class="grid grid-cols-3 gap-4 items-center">
                    <div>
                        <Label for="message"
                            >Estimated cost: $
                            {{ vendor.pivot.cost_estimate ?? "0.00" }}</Label
                        >
                    </div>
                    <div>
                        <Label for="message"
                            >Estimated Time (Hrs) :
                            {{ vendor.pivot.time_estimate }}</Label
                        >
                    </div>
                    <div>
                        <Label for="message"
                            >Scheduled End Date :
                            {{
                                formatDate(vendor.pivot.scheduled_end_date)
                            }}</Label
                        >
                    </div>
                </div>
            </div>
        </div>
    </div>
    <Dialog v-model:open="openModal">
        <DialogContent
            class="sm:max-w-[500px] grid-rows-[auto_minmax(0,1fr)_auto] p-0 max-h-[95dvh]"
        >
            <DialogHeader class="p-6 pb-0 text-left">
                <DialogTitle> Update Work Order Details </DialogTitle>
                <DialogDescription>
                    Fill out the input fields and then click
                    submit.</DialogDescription
                >
            </DialogHeader>
            <Separator />
            <div
                class="flex flex-col flex-nowrap overflow-x-auto scrollbar-hide px-6"
            >
                <div class="mb-3">
                    <Label>Cost Estimate</Label>
                    <Input
                        type="number"
                        placeholder="Enter file description"
                        class="mt-1"
                        v-model="vendorForms.cost_estimate"
                    />
                </div>
                <div class="mb-3">
                    <Label>Time Estimate</Label>
                    <Input
                        type="number"
                        placeholder="Enter file description"
                        class="mt-1"
                        v-model="vendorForms.time_estimate"
                    />
                </div>
                <div class="mb-3">
                    <Label>Scheduled End Date</Label>
                    <Input
                        type="date"
                        placeholder="Enter file description"
                        class="mt-1"
                        v-model="vendorForms.scheduled_end_date"
                    />
                </div>
            </div>
            <DialogFooter class="p-6 pt-0">
                <Button
                    type="submit"
                    :disabled="vendorForms.processing"
                    @click.prevent="handleFormSubmit"
                >
                    <Loader2
                        v-if="vendorForms.processing"
                        class="w-4 h-4 animate-spin"
                    />
                    Submit
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
