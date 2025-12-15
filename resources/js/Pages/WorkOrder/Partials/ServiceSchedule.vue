<script setup>
import { ref, watch, onMounted, nextTick } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import { Loader2, EllipsisVertical, CalendarPlus } from "lucide-vue-next";
import { DateTime } from "luxon";
import { useToast } from "@/Components/ui/toast/use-toast";
const { toast } = useToast();

const props = defineProps({
    vendorServiceSchedules: Array,
    workOrderTenants: Array,
    workOrderVendors: Array,
    isLoading: Boolean,
    workOrder: Object,
});

const openService = ref(false);

const serviceScheduleForm = useForm({
    title: "",
    description: "",
    date: "",
    vendor_id: "",
    tenant_id: "",
    work_order_id: props.workOrder.id,
});
const emit = defineEmits(["fetch-schedule"]);

const formatDate = (date) => {
    if (!date) return "------";

    let parsedDate;

    if (typeof date === "string") {
        parsedDate = DateTime.fromISO(date, { zone: "utc" }).isValid
            ? DateTime.fromISO(date, { zone: "utc" })
            : DateTime.fromFormat(date, "yyyy-MM-dd", { zone: "utc" });
    } else if (date instanceof Date) {
        parsedDate = DateTime.fromJSDate(date);
    } else {
        return "Invalid Date";
    }

    return parsedDate.isValid
        ? parsedDate.toFormat("EEE, MMMM d, yyyy")
        : "Invalid Date";
};

const updateScheduleStatus = async (service_schedule_id, status) => {
    router.post(
        route("service_schedule.status.completed", service_schedule_id),
        { status: status },
        {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                toast({
                    title: "Success",
                    description: "Service schedule has been set successfully!",
                });
                emit("fetch-schedule");
            },
            onError: () => {
                toast({
                    variant: "destructive",
                    title: "Uh oh! Something went wrong.",
                    description:
                        "There was a problem with your request. Please try again!",
                });
            },
        }
    );
};

const handleMeetingSubmit = () => {
    if (
        !serviceScheduleForm.title ||
        !serviceScheduleForm.date ||
        !serviceScheduleForm.work_order_id ||
        !serviceScheduleForm.vendor_id
    ) {
        toast({
            variant: "destructive",
            title: "Uh oh! Something went wrong.",
            description:
                "Please fill in all required fields (Title, Date, Vendor).",
        });
        return;
    }
    serviceScheduleForm.post(route("work_order.service_schedule.create"), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            toast({
                title: "Success",
                description: "Service schedule has been set successfully!",
            });
            openService.value = false;
            serviceScheduleForm.reset();
            emit("fetch-schedule");
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
</script>

<template>
    <div class="overflow-y-auto px-6 w-full min-h-[300px]">
        <div class="flex justify-between items-center mb-3">
            <p class="font-semibold uppercase text-xs">
                Service Schedule - Vendor and Tenant
            </p>
            <Button
                :disabled="isLoading"
                size="icon"
                @click.prevent="openService = true"
                v-if="
                    $page.props.auth.user.roles.includes('vendor') ||
                    $page.props.auth.user.roles.includes('woc') ||
                    $page.props.auth.user.roles.includes('admin')
                "
            >
                <CalendarPlus v-if="!isLoading" class="" />
                <Loader2 v-else class="w-4 h-4 animate-spin" />
            </Button>
        </div>
        <div
            class="flex"
            v-if="!isLoading && vendorServiceSchedules.length === 0"
        >
            <p class="font-semibold">No scheduled service available</p>
        </div>
        <div class="mb-10" v-else>
            <Card
                class="w-full p-4 mb-2 bg-primary/95 text-white"
                v-for="schedule in vendorServiceSchedules"
                :key="schedule.id"
            >
                <div class="flex justify-between">
                    <div class="flex flex-col w-full">
                        <div class="flex text-xs items-center gap-1">
                            <p>
                                📅 Due {{ formatDate(schedule.scheduled_date) }}
                            </p>
                        </div>
                        <p class="text-sm mt-2 font-semibold">
                            {{ schedule.title }}
                        </p>
                        <p class="text-xs">{{ schedule.description }}</p>
                    </div>
                    <div
                        v-if="
                            schedule.status !== 'completed' ||
                            $page.props.auth.user.roles.includes('vendor')
                        "
                    >
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button
                                    aria-haspopup="true"
                                    size="icon"
                                    variant="ghost"
                                >
                                    <EllipsisVertical class="w-3 h-3" />
                                    <span class="sr-only">Toggle menu</span>
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuLabel>Mark as</DropdownMenuLabel>
                                <DropdownMenuItem
                                    class="cursor-pointer hover:bg-secondary"
                                    @click="
                                        () =>
                                            updateScheduleStatus(
                                                schedule.id,
                                                'completed'
                                            )
                                    "
                                >
                                    Complete
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    class="cursor-pointer hover:bg-secondary"
                                    @click="
                                        () =>
                                            updateScheduleStatus(
                                                schedule.id,
                                                'cancelled'
                                            )
                                    "
                                >
                                    Cancel
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>
                <div class="flex justify-between mt-2 items-center">
                    <div class="flex gap-3">
                        <div class="flex flex-col text-xs gap-1">
                            <p>Tenant</p>
                            <p class="flex gap-1 items-center">
                                <Avatar class="w-5 h-5">
                                    <AvatarImage
                                        :src="
                                            schedule.tenant.user
                                                ?.profile_photo_url ||
                                            'default.jpg'
                                        "
                                    />
                                    <AvatarFallback></AvatarFallback>
                                </Avatar>
                                {{ schedule.tenant.first_name }}
                                {{ schedule.tenant.last_name }}
                            </p>
                        </div>
                        <div class="flex flex-col text-xs gap-1">
                            <p>Vendor</p>
                            <p class="flex gap-1 items-center">
                                <Avatar class="w-5 h-5">
                                    <AvatarImage
                                        :src="
                                            schedule.vendor.user
                                                ?.profile_photo_url ||
                                            'default.jpg'
                                        "
                                    />
                                    <AvatarFallback></AvatarFallback>
                                </Avatar>
                                {{ schedule.vendor.name }}
                            </p>
                        </div>
                    </div>
                    <div>
                        <Badge
                            :class="
                                schedule.status === 'cancelled'
                                    ? 'bg-red-500'
                                    : 'bg-green-500'
                            "
                        >
                            {{ schedule.status }}
                        </Badge>
                    </div>
                </div>
            </Card>
        </div>
    </div>

    <Dialog v-model:open="openService">
        <DialogContent
            class="sm:max-w-[550px] grid-rows-[auto_minmax(0,1fr)_auto] p-0 max-h-[90dvh]"
        >
            <DialogHeader class="p-6 pb-4">
                <DialogTitle class="text-xl font-semibold">
                    Service Schedule
                </DialogTitle>
                <DialogDescription class="text-sm text-muted-foreground">
                    Schedule a service appointment for this work order.
                </DialogDescription>
            </DialogHeader>
            <Separator />
            <div class="px-6 py-4 overflow-y-auto">
                <div class="space-y-4">
                    <!-- Vendor and Tenant Row -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <Label class="text-sm font-medium">
                                Vendor
                                <span class="text-red-500 ml-0.5">*</span>
                            </Label>
                            <Select v-model="serviceScheduleForm.vendor_id">
                                <SelectTrigger class="w-full">
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
                                            </SelectItem>
                                        </template>
                                    </SelectGroup>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="space-y-2">
                            <Label class="text-sm font-medium">
                                Tenant
                                <span class="text-xs text-muted-foreground ml-1">
                                    (Optional)
                                </span>
                            </Label>
                            <Select v-model="serviceScheduleForm.tenant_id">
                                <SelectTrigger class="w-full">
                                    <SelectValue placeholder="Select a tenant" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        <template
                                            v-for="tenant in workOrderTenants"
                                            :key="tenant.id"
                                        >
                                            <SelectItem :value="String(tenant.id)">
                                                {{ tenant.first_name }}
                                                {{ tenant.last_name }}
                                            </SelectItem>
                                        </template>
                                    </SelectGroup>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <!-- Title -->
                    <div class="space-y-2">
                        <Label class="text-sm font-medium">
                            Title
                            <span class="text-red-500 ml-0.5">*</span>
                        </Label>
                        <Input
                            type="text"
                            placeholder="Enter schedule title"
                            v-model="serviceScheduleForm.title"
                        />
                    </div>

                    <!-- Scheduled Date -->
                    <div class="space-y-2">
                        <Label class="text-sm font-medium">
                            Scheduled Date
                            <span class="text-red-500 ml-0.5">*</span>
                        </Label>
                        <Input
                            type="date"
                            v-model="serviceScheduleForm.date"
                        />
                    </div>

                    <!-- Description -->
                    <div class="space-y-2">
                        <Label class="text-sm font-medium">
                            Description
                        </Label>
                        <Textarea
                            placeholder="Enter additional details about this service schedule..."
                            v-model="serviceScheduleForm.description"
                            rows="3"
                        />
                    </div>
                </div>
            </div>
            <Separator />
            <DialogFooter class="p-6 pt-4">
                <Button
                    @click="openService = false"
                    variant="outline"
                    type="button"
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    :disabled="serviceScheduleForm.processing"
                    @click.prevent="handleMeetingSubmit"
                >
                    <Loader2
                        v-if="serviceScheduleForm.processing"
                        class="w-4 h-4 mr-2 animate-spin"
                    />
                    {{ serviceScheduleForm.processing ? "Creating..." : "Create Schedule" }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
