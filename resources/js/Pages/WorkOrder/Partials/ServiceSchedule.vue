<script setup>
import { ref, watch, onMounted, nextTick, computed } from "vue";
import { router, useForm, usePage } from "@inertiajs/vue3";
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
    technician_ids: [],
    work_order_id: props.workOrder.id,
});

// Admins and coordinators decide whether the tenant is texted; a vendor
// setting a schedule keeps the automatic text.
const page = usePage();
const isStaff = computed(() =>
    ["admin", "woc"].some((role) => page.props.auth.user?.roles?.includes(role)),
);

// The answer to "Text the tenant now?": true, false, or null when the
// question was not asked (the server then texts as it always has). Kept out
// of useForm so reset() never touches it.
const notifyTenant = ref(null);

// Ticked technicians go out as ids; an empty list means nobody.
serviceScheduleForm.transform((data) => ({
    ...data,
    technician_ids: (data.technician_ids ?? []).map(Number),
    ...(notifyTenant.value === null ? {} : { notify_tenant: notifyTenant.value }),
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
// form was just pre-filled from an AI suggestion, or Edit just filled in the
// schedule's own dates (this runs after openEditMode and used to overwrite
// them with today's).
watch(openService, (newValue) => {
    if (newValue && !acceptingSuggestionId.value && !isEditing.value) {
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
// Naming who is going lets the tenant's appointment text carry their
// photos. THMP sometimes sends two on one visit, so any number can be
// ticked. Staff-only endpoint — a 403 just hides the picker.
const technicianOptions = ref([]);

const isTechnicianChosen = (technicianId) =>
    serviceScheduleForm.technician_ids.includes(String(technicianId));

const toggleTechnician = (technicianId, checked) => {
    const id = String(technicianId);
    const chosen = serviceScheduleForm.technician_ids.filter((t) => t !== id);

    serviceScheduleForm.technician_ids = checked ? [...chosen, id] : chosen;
};

const technicianNames = (schedule) =>
    (schedule.technicians ?? []).map((t) => t.name).join(", ");

const chosenTechnicianNames = computed(() =>
    technicianOptions.value
        .filter((t) => serviceScheduleForm.technician_ids.includes(String(t.id)))
        .map((t) => t.name),
);

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

// The date the schedule had when Edit was opened, so a save can tell a real
// reschedule (which texts the tenant) from a title or description edit.
const originalScheduledDate = ref("");

const openEditMode = (schedule) => {
    isEditing.value = true;
    editingScheduleId.value = schedule.id;

    serviceScheduleForm.title = schedule.title;
    serviceScheduleForm.description = schedule.description ?? "";
    serviceScheduleForm.date = formatDateForInput(schedule.scheduled_date);
    originalScheduledDate.value = serviceScheduleForm.date;
    serviceScheduleForm.end_date = formatDateForInput(schedule.scheduled_end_date);
    serviceScheduleForm.vendor_id = String(schedule.vendor_id);
    serviceScheduleForm.tenant_id = schedule.tenant_id ? String(schedule.tenant_id) : "";
    serviceScheduleForm.technician_ids = (schedule.technicians ?? []).map((t) => String(t.id));

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

// ---- "Text the tenant now?" ----
// Saving a schedule texts the tenant the appointment (the THMP Technician
// Visit Reminder when technicians are ticked). Staff are asked first: Yes
// saves and texts, No saves only and the card then offers "Send tenant
// text". A vendor is not asked. An edit only asks when the date actually
// moved, since that is the only edit that texts.
const confirmTenantTextOpen = ref(false);

const dateChanged = computed(
    () => serviceScheduleForm.date !== originalScheduledDate.value,
);

const asksAboutTenantText = computed(
    () => isStaff.value && (!isEditing.value || dateChanged.value),
);

const answerTenantText = (sendNow) => {
    notifyTenant.value = sendNow;
    confirmTenantTextOpen.value = false;
    submitSchedule();
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

    if (!isEditing.value && !serviceScheduleForm.work_order_id) {
        toast({
            variant: "destructive",
            title: "Uh oh! Something went wrong.",
            description:
                "Work order ID is missing.",
        });
        return;
    }

    if (asksAboutTenantText.value) {
        confirmTenantTextOpen.value = true;
        return;
    }

    notifyTenant.value = null;
    submitSchedule();
};

const savedDescription = (verb) => {
    if (notifyTenant.value === true) {
        return "Schedule saved. Tenant text queued.";
    }

    if (notifyTenant.value === false) {
        return "Schedule saved. The tenant was not texted.";
    }

    return `Service schedule has been ${verb} successfully!`;
};

const submitSchedule = () => {
    const onError = () => {
        notifyTenant.value = null;
        toast({
            variant: "destructive",
            title: "Uh oh! Something went wrong.",
            description:
                "There was a problem with your request. Please try again!",
        });
    };

    if (isEditing.value && editingScheduleId.value) {
        // Update existing schedule
        serviceScheduleForm.put(route("work_order.service_schedule.update", editingScheduleId.value), {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                toast({
                    title: "Success",
                    description: savedDescription("updated"),
                });
                notifyTenant.value = null;
                openService.value = false;
                serviceScheduleForm.reset();
                isEditing.value = false;
                editingScheduleId.value = null;
                emit("fetch-schedule");
            },
            onError,
        });
    } else {
        // Create new schedule
        serviceScheduleForm.post(route("work_order.service_schedule.create"), {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                toast({
                    title: "Success",
                    description: savedDescription("created"),
                });
                notifyTenant.value = null;
                if (acceptingSuggestionId.value) {
                    resolveSuggestion(acceptingSuggestionId.value, "accepted");
                }
                openService.value = false;
                serviceScheduleForm.reset();
                emit("fetch-schedule");
            },
            onError,
        });
    }
};

// ---- Send tenant text (later) ----
// A schedule saved with "No" shows "Not sent yet" on its card and this menu
// action sends the same appointment text when the coordinator is ready. The
// server keeps the usual gates and says why when one is in the way.
const sendingTenantTextId = ref(null);

const scheduleIsPast = (schedule) => {
    const ends = schedule.scheduled_end_date || schedule.scheduled_date;
    if (!ends) return false;

    const parsed = parseScheduleDate(String(ends));

    return parsed.isValid && parsed.endOf("day") < DateTime.now();
};

const tenantTextPending = (schedule) =>
    isStaff.value &&
    !schedule.tenant_notified_at &&
    !["completed", "cancelled"].includes(schedule.status) &&
    !scheduleIsPast(schedule);

const formatTenantTextedDate = (value) => {
    const parsed = parseScheduleDate(String(value));

    return parsed.isValid ? parsed.toLocal().toFormat("MMM d") : "";
};

const sendTenantText = async (schedule) => {
    sendingTenantTextId.value = schedule.id;

    try {
        await axios.post(route("service_schedule.tenant_notice.send", schedule.id));
        toast({
            title: "Tenant text sent",
            description: "The tenant has been texted about this appointment.",
        });
    } catch (error) {
        // A refusal carries its reason; anything else (a 403 page, a network
        // error) gets the generic line.
        const reason = error?.response?.data?.error;
        toast({
            variant: "destructive",
            title: "Tenant text not sent",
            description: typeof reason === "string" ? reason : "Could not send the tenant text.",
        });
    } finally {
        sendingTenantTextId.value = null;
        emit("fetch-schedule");
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
                        <!-- Non-modal: a modal menu locks the page (pointer
                             events, scroll) and, when Edit opens the dialog
                             while the menu is still closing, that lock was
                             never released, leaving the page dead until a
                             reload. -->
                        <DropdownMenu :modal="false">
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
                                <DropdownMenuItem
                                    v-if="tenantTextPending(schedule)"
                                    class="cursor-pointer hover:bg-secondary"
                                    :disabled="sendingTenantTextId === schedule.id"
                                    @click="() => sendTenantText(schedule)"
                                >
                                    Send tenant text
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
                            v-if="schedule.technicians?.length"
                            class="flex flex-col text-xs gap-1"
                        >
                            <p>{{ schedule.technicians.length > 1 ? "Technicians" : "Technician" }}</p>
                            <p class="flex gap-1 items-center">
                                {{ technicianNames(schedule) }}
                            </p>
                        </div>
                        <!-- Whether the tenant has had the appointment text;
                             "Send tenant text" in the menu covers "Not sent yet" -->
                        <div v-if="isStaff" class="flex flex-col text-xs gap-1">
                            <p>Tenant text</p>
                            <p class="flex gap-1 items-center">
                                {{
                                    schedule.tenant_notified_at
                                        ? `Sent ${formatTenantTextedDate(schedule.tenant_notified_at)}`
                                        : "Not sent yet"
                                }}
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

                    <!-- Technicians (optional) — tick everyone going; the
                         tenant's appointment text names each and attaches
                         their photos -->
                    <div v-if="technicianOptions.length" class="space-y-2">
                        <Label class="text-sm font-medium">Technicians</Label>
                        <div class="max-h-40 overflow-y-auto rounded-md border p-2 space-y-1">
                            <label
                                v-for="technician in technicianOptions"
                                :key="technician.id"
                                :for="`schedule-technician-${technician.id}`"
                                class="flex cursor-pointer items-center gap-2 rounded px-1 py-1 text-sm hover:bg-secondary"
                            >
                                <Checkbox
                                    :id="`schedule-technician-${technician.id}`"
                                    :checked="isTechnicianChosen(technician.id)"
                                    @update:checked="(checked) => toggleTechnician(technician.id, checked)"
                                />
                                <span>
                                    {{ technician.name }}<span v-if="!technician.has_photo" class="text-muted-foreground"> (no photo on file)</span>
                                </span>
                            </label>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Optional. The tenant's appointment text includes their
                            names and photos, so the tenant knows who to expect.
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

    <!-- Asked before a staff save that would text the tenant. Go back keeps
         the form open; either answer saves the schedule. -->
    <AlertDialog v-model:open="confirmTenantTextOpen">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>
                    {{ isEditing ? "Text the tenant about the new date?" : "Text the tenant about this appointment?" }}
                </AlertDialogTitle>
                <AlertDialogDescription>
                    <template v-if="chosenTechnicianNames.length">
                        Yes sends the tenant the THMP Technician Visit Reminder
                        naming {{ chosenTechnicianNames.join(", ") }}, with each
                        photo on file attached.
                    </template>
                    <template v-else>
                        Yes sends the tenant the standard appointment text.
                    </template>
                    The schedule is saved either way. If you choose No, you can
                    send the text later from the schedule's menu.
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel>Go back</AlertDialogCancel>
                <AlertDialogCancel
                    :disabled="serviceScheduleForm.processing"
                    @click="answerTenantText(false)"
                >
                    No, save only
                </AlertDialogCancel>
                <AlertDialogAction
                    :disabled="serviceScheduleForm.processing"
                    @click="answerTenantText(true)"
                >
                    Yes, save and text
                </AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
