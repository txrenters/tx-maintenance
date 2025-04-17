<script setup>
import { ref } from "vue";
import { useForm } from "@inertiajs/vue3";
import { Plus, SquarePen } from "lucide-vue-next";
import { useToast } from "@/Components/ui/toast/use-toast";
import { DateTime } from "luxon";

const { toast } = useToast();

const props = defineProps({
    isLoading: Boolean,
    workOrder: Object,
    workOrderVendorData: Object,
});

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
