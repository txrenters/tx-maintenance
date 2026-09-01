<script setup>
import { ref, watch, onMounted, nextTick } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import axios from "axios";
import { Loader2, EllipsisVertical, CalendarPlus, Sparkles } from "lucide-vue-next";
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
const isEditing = ref(false);
const editingScheduleId = ref(null);

const serviceScheduleForm = useForm({
    title: "Service Schedule for " + props.workOrder.work_order_no,
    description: props.workOrder.description ?? "",
    date: "",
    end_date: "",
    vendor_id: "",
    tenant_id: "",
    technician_id: "",
    work_order_id: props.workOrder.id,
});

// "none" is the explicit "No technician" pick; either way null goes out.
serviceScheduleForm.transform((data) => ({
    ...data,
    technician_id:
        data.technician_id && data.technician_id !== "none"
            ? Number(data.technician_id)
            : null,
}));
const emit = defineEmits(["fetch-schedule"]);

// Parse dates coming from the API, which may be ISO ("2026-06-22T14:30:00"),
// MySQL datetime ("2026-06-22 14:30:00"), or date-only ("2026-06-22").
const parseScheduleDate = (date) => {
    const iso = DateTime.fromISO(date, { zone: "utc" });
    if (iso.isValid) return iso;

    const sql = DateTime.fromSQL(date, { zone: "utc" });
    if (sql.isValid) return sql;

    return DateTime.fromFormat(date, "yyyy-MM-dd", { zone: "utc" });
};

const formatDate = (date) => {
    if (!date) return "------";

    let parsedDate;

    if (typeof date === "string") {
        parsedDate = parseScheduleDate(date);
    } else if (date instanceof Date) {
        parsedDate = DateTime.fromJSDate(date);
    } else {
        return "Invalid Date";
    }

    return parsedDate.isValid
        ? parsedDate.toFormat("EEE, MMMM d, yyyy")
        : "Invalid Date";
};

// Set default dates when opening the service schedule dialog — unless the
// form was just pre-filled from an AI suggestion.
watch(openService, (newValue) => {
    if (newValue && !acceptingSuggestionId.value) {
        const today = DateTime.now().toFormat("yyyy-MM-dd");
        const tomorrow = DateTime.now().plus({ days: 1 }).toFormat("yyyy-MM-dd");

        serviceScheduleForm.date = today;
        serviceScheduleForm.end_date = tomorrow;
    }

    if (!newValue) {
        acceptingSuggestionId.value = null;
    }
});

// ---- Technician picker ----
// Naming who is going lets the tenant's appointment text carry that
// technician's photo. Staff-only endpoint — a 403 just hides the picker.
const technicianOptions = ref([]);

const fetchTechnicianOptions = async () => {
    try {
        const { data } = await axios.get(route("technicians.options"));
        technicianOptions.value = data.technicians ?? [];
    } catch {
        technicianOptions.value = [];
    }
};

onMounted(fetchTechnicianOptions);

// ---- AI schedule suggestions (extracted from tenant/vendor texts) ----
// Read-only proposals: "Use" only pre-fills the create form below; nothing
// is saved until the form is submitted. Staff-only endpoint — a 403 (e.g.
// vendors) just hides the card.
const scheduleSuggestions = ref([]);
const acceptingSuggestionId = ref(null);

const fetchSuggestions = async () => {
    try {
        const { data } = await axios.get(
            route("work_orders.schedule_suggestions", props.workOrder.id),
        );
        scheduleSuggestions.value = data.suggestions ?? [];
    } catch {
        scheduleSuggestions.value = [];
    }
};

onMounted(fetchSuggestions);

const formatSuggestionDate = (value) => {
    if (!value) return "";
    const parsed = DateTime.fromSQL(value);
    if (!parsed.isValid) return value;

    // 00:00 is the "date only" convention — no time was proposed.
    return parsed.hour === 0 && parsed.minute === 0
        ? parsed.toFormat("EEE, MMMM d, yyyy")
        : parsed.toFormat("EEE, MMMM d, yyyy h:mm a");
};

const resolveSuggestion = async (suggestionId, status) => {
    try {
        await axios.patch(route("ai-insights.status", suggestionId), { status });
    } catch {
        // Resolving is best-effort; the card hides either way.
    }
    scheduleSuggestions.value = scheduleSuggestions.value.filter(
        (s) => s.id !== suggestionId,
    );
};

const useSuggestion = (suggestion) => {
    acceptingSuggestionId.value = suggestion.id;
    openCreateMode();

    const start = DateTime.fromSQL(suggestion.start ?? "");
    serviceScheduleForm.date = start.isValid
        ? start.toFormat("yyyy-MM-dd")
        : serviceScheduleForm.date;

    const end = suggestion.end ? DateTime.fromSQL(suggestion.end) : null;
    serviceScheduleForm.end_date = end?.isValid
        ? end.toFormat("yyyy-MM-dd")
        : serviceScheduleForm.date;

    if ((props.workOrderVendors ?? []).length === 1) {
        serviceScheduleForm.vendor_id = String(props.workOrderVendors[0].id);
    }
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

const formatDateForInput = (date) => {
    if (!date) return "";

    let parsedDate;

    if (typeof date === "string") {
        parsedDate = parseScheduleDate(date);
    } else if (date instanceof Date) {
        parsedDate = DateTime.fromJSDate(date);
    } else {
        return "";
    }

    return parsedDate.isValid ? parsedDate.toFormat("yyyy-MM-dd") : "";
};

const openEditMode = (schedule) => {
    isEditing.value = true;
    editingScheduleId.value = schedule.id;

    serviceScheduleForm.title = schedule.title;
    serviceScheduleForm.description = schedule.description ?? "";
    serviceScheduleForm.date = formatDateForInput(schedule.scheduled_date);
    serviceScheduleForm.end_date = formatDateForInput(schedule.scheduled_end_date);
    serviceScheduleForm.vendor_id = String(schedule.vendor_id);
    serviceScheduleForm.tenant_id = schedule.tenant_id ? String(schedule.tenant_id) : "";
    serviceScheduleForm.technician_id = schedule.technician_id ? String(schedule.technician_id) : "";

    openService.value = true;
};

const openCreateMode = () => {
    isEditing.value = false;
    editingScheduleId.value = null;

    serviceScheduleForm.reset();
    serviceScheduleForm.title = "Service Schedule for " + props.workOrder.work_order_no;
    serviceScheduleForm.description = props.workOrder.description ?? "";
    serviceScheduleForm.work_order_id = props.workOrder.id;

    openService.value = true;
};

const handleMeetingSubmit = () => {
    if (
        !serviceScheduleForm.title ||
        !serviceScheduleForm.date ||
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

    if (isEditing.value && editingScheduleId.value) {
        // Update existing schedule
        serviceScheduleForm.put(route("work_order.service_schedule.update", editingScheduleId.value), {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                toast({
                    title: "Success",
                    description: "Service schedule has been updated successfully!",
                });
                openService.value = false;
                serviceScheduleForm.reset();
                isEditing.value = false;
                editingScheduleId.value = null;
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
    } else {
        // Create new schedule
        if (!serviceScheduleForm.work_order_id) {
            toast({
                variant: "destructive",
                title: "Uh oh! Something went wrong.",
                description:
                    "Work order ID is missing.",
            });
            return;
        }

        serviceScheduleForm.post(route("work_order.service_schedule.create"), {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                toast({
                    title: "Success",
                    description: "Service schedule has been created successfully!",
                });
                if (acceptingSuggestionId.value) {
                    resolveSuggestion(acceptingSuggestionId.value, "accepted");
                }
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
    }
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
                @click.prevent="openCreateMode"
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
        <Card
            v-if="scheduleSuggestions.length"
            class="w-full p-4 mb-3 border-amber-300 bg-amber-50"
        >
            <div
                class="mb-2 flex items-center gap-1 text-xs font-semibold uppercase text-amber-800"
            >
                <Sparkles class="h-3.5 w-3.5" /> Suggested from messages
            </div>
            <div
                v-for="suggestion in scheduleSuggestions"
                :key="suggestion.id"
                class="flex items-start justify-between gap-2 py-1 text-sm text-amber-900"
            >
                <div class="min-w-0">
                    <p class="font-medium">
                        📅 {{ formatSuggestionDate(suggestion.start) }}
                        <template v-if="suggestion.end">
                            – {{ formatSuggestionDate(suggestion.end) }}
                        </template>
                    </p>
                    <p class="text-xs">{{ suggestion.summary }}</p>
                    <p v-if="suggestion.quote" class="truncate text-xs italic">
                        "{{ suggestion.quote }}" — {{ suggestion.party }}
                    </p>
                </div>
                <div class="flex shrink-0 gap-1">
                    <Button
                        size="sm"
                        variant="outline"
                        @click.prevent="useSuggestion(suggestion)"
                    >
                        Use
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        @click.prevent="resolveSuggestion(suggestion.id, 'dismissed')"
                    >
                        Dismiss
                    </Button>
                </div>
            </div>
            <p class="mt-1 text-[11px] text-amber-700">
                "Use" only pre-fills the schedule form — nothing is saved until
                you submit it.
            </p>
        </Card>
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
                            <p v-if="schedule.scheduled_end_date">
                                📅 {{ formatDate(schedule.scheduled_date) }} - {{ formatDate(schedule.scheduled_end_date) }}
                            </p>
                            <p v-else>
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
                                <DropdownMenuLabel>Actions</DropdownMenuLabel>
                                <DropdownMenuItem
                                    class="cursor-pointer hover:bg-secondary"
                                    @click="() => openEditMode(schedule)"
                                >
                                    Edit
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
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
                                <DropdownMenuItem
                                    class="cursor-pointer hover:bg-secondary"
                                    @click="
                                        () =>
                                            updateScheduleStatus(
                                                schedule.id,
                                                'delete'
                                            )
                                    "
                                >
                                    Delete
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>
                <div class="flex justify-between mt-2 items-center">
                    <div class="flex gap-3">
                        <!-- Remove for easy creation of schedules -->
                        <!-- <div class="flex flex-col text-xs gap-1">
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
                        </div> -->
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
                        <div
                            v-if="schedule.technician"
                            class="flex flex-col text-xs gap-1"
                        >
                            <p>Technician</p>
                            <p class="flex gap-1 items-center">
                                {{ schedule.technician.name }}
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
                    {{ isEditing ? 'Edit Service Schedule' : 'Create Service Schedule' }}
                </DialogTitle>
                <DialogDescription class="text-sm text-muted-foreground">
                    {{ isEditing ? 'Update the service appointment details.' : 'Schedule a service appointment for this work order.' }}
                </DialogDescription>
            </DialogHeader>
            <Separator />
            <div class="px-6 py-4 overflow-y-auto">
                <div class="space-y-4">
                    <!-- Vendor and Tenant Row -->
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

                    <!-- Technician (optional) — the tenant's appointment text
                         attaches this technician's photo -->
                    <div v-if="technicianOptions.length" class="space-y-2">
                        <Label class="text-sm font-medium">Technician</Label>
                        <Select v-model="serviceScheduleForm.technician_id">
                            <SelectTrigger class="w-full">
                                <SelectValue placeholder="Select a technician (optional)" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    <SelectItem value="none">No technician</SelectItem>
                                    <template
                                        v-for="technician in technicianOptions"
                                        :key="technician.id"
                                    >
                                        <SelectItem :value="String(technician.id)">
                                            {{ technician.name }}{{ technician.has_photo ? "" : " (no photo on file)" }}
                                        </SelectItem>
                                    </template>
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                        <p class="text-xs text-muted-foreground">
                            The tenant's appointment text includes their name and
                            photo, so the tenant knows who to expect.
                        </p>
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
                            Scheduled Date (Start)
                            <span class="text-red-500 ml-0.5">*</span>
                        </Label>
                        <Input type="date" v-model="serviceScheduleForm.date" />
                    </div>

                    <!-- Scheduled End Date -->
                    <div class="space-y-2">
                        <Label class="text-sm font-medium">
                            Scheduled End Date
                        </Label>
                        <Input type="date" v-model="serviceScheduleForm.end_date" />
                    </div>

                    <!-- Description -->
                    <div class="space-y-2">
                        <Label class="text-sm font-medium"> Description </Label>
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
                    {{
                        serviceScheduleForm.processing
                            ? (isEditing ? "Updating..." : "Creating...")
                            : (isEditing ? "Update Schedule" : "Create Schedule")
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
