<script setup>
import { ref, watch } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import { useToast } from "@/Components/ui/toast/use-toast";
import SearchBar from "@/Components/SearchBar.vue";
import { DateTime } from "luxon";
import {
    Tag,
    Truck,
    User,
    Calendar,
    MapPin,
    DollarSign,
    Clock,
    MessageCircle,
    Eye,
    Search,
    ClipboardList,
    Send,
    Loader2,
    Paperclip,
    X,
    Check,
} from "lucide-vue-next";
import {
    Combobox,
    ComboboxAnchor,
    ComboboxEmpty,
    ComboboxGroup,
    ComboboxInput,
    ComboboxItem,
    ComboboxItemIndicator,
    ComboboxList,
} from "@/Components/ui/combobox";

import Navigation from "./partials/Navigation.vue";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/Components/ui/dialog";
import MessageCard from "@/Components/MessageCard.vue";
import debounce from "lodash.debounce";
import { Deferred } from "@inertiajs/vue3";
import { useEchoPublic } from "@laravel/echo-vue";

const { toast } = useToast();

const page = usePage();

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    jobsByStatus: Object,
    statistics: Object,
    access_token_exist: Boolean,
    filter: Object,
});

const url = route("inspections.index");
const search = ref(props.filter.search ?? "");

// Modal state management
const isModalOpen = ref(false);
const selectedJob = ref(null);
const activeTab = ref("details");

// Tab configuration
const tabButtons = [
    {
        name: "details",
        tooltip: "Details",
        icon: ClipboardList,
    },
    {
        name: "visits",
        tooltip: "Visits",
        icon: Calendar,
    },
    {
        name: "messages",
        tooltip: "Messages",
        icon: MessageCircle,
    },
];

// Function to open modal with job details
const openJobModal = async (job) => {
    const response = await axios.get(route("jobber.jobDetails", job.id));

    selectedJob.value = { ...job, ...response.data };
    isModalOpen.value = true;
    activeTab.value = "details"; // Always start with details view

    // Reset messaging state
    newMessage.value = "";
    selectedContact.value = "";
    jobMessages.value = response.data.text_messages || [];
    jobContacts.value = [];
    selectedImage.value = null;
    imagePreview.value = null;
    selectedClient.value = job.client;
    contactPhoneNumber.value = job.client?.phone ?? "";
};

// Function to close modal
const closeJobModal = () => {
    isModalOpen.value = false;
    selectedJob.value = null;
    activeTab.value = "details";
};

// Function to switch tabs
const switchTab = (tabName) => {
    activeTab.value = tabName;
    if (tabName === "messages" && selectedJob.value?.id) {
        // Fetch messages when switching to messages tab
        fetchJobMessages(selectedJob.value.id);
    }
};

const newMessage = ref("");
const selectedContact = ref("");
const contactPhoneNumber = ref("");
const senderPhoneNumber = ref(page.props.twilio_phone_number); // This should come from user's settings/config
const isLoadingMessages = ref(false);
const isSendingMessage = ref(false);
const jobMessages = ref([]);
const jobContacts = ref([]);

// Image attachment functionality
const selectedImage = ref(null);
const imagePreview = ref(null);
const fileInput = ref(null);

// Fetch job messages
const fetchJobMessages = async (jobId) => {
    try {
        isLoadingMessages.value = true;
        // Messages are already loaded with the job data, so just use what we have
        if (selectedJob.value?.text_messages) {
            jobMessages.value = selectedJob.value.text_messages;
        } else {
            jobMessages.value = [];
        }
        jobContacts.value = [];
    } catch (error) {
        console.error("Error fetching messages:", error);
        jobMessages.value = [];
        jobContacts.value = [];
    } finally {
        isLoadingMessages.value = false;
    }
};

// Handle file selection
const handleImageSelect = (event) => {
    const file = event.target.files[0];
    if (file) {
        // Validate file type
        if (!file.type.startsWith("image/")) {
            toast({
                variant: "destructive",
                title: "Invalid file type",
                description:
                    "Please select an image file (JPG, PNG, GIF, etc.)",
            });
            return;
        }

        // Validate file size (5MB limit)
        if (file.size > 5 * 1024 * 1024) {
            toast({
                variant: "destructive",
                title: "File too large",
                description: "Please select an image smaller than 5MB",
            });
            return;
        }

        selectedImage.value = file;

        // Create preview URL
        const reader = new FileReader();
        reader.onload = (e) => {
            imagePreview.value = e.target.result;
        };
        reader.readAsDataURL(file);
    }
};

// Remove selected image
const removeImage = () => {
    selectedImage.value = null;
    imagePreview.value = null;
    if (fileInput.value) {
        fileInput.value.value = "";
    }
};

// Trigger file input
const triggerFileInput = () => {
    fileInput.value?.click();
};

// Send message function
const sendMessage = () => {
    if (!contactPhoneNumber.value) {
        toast({
            variant: "destructive",
            title: "Error",
            description: "Please select a contact or enter a phone number",
        });
        return;
    }

    if (!newMessage.value.trim() && !selectedImage.value) {
        toast({
            variant: "destructive",
            title: "Error",
            description: "Please enter a message or select an image to send",
        });
        return;
    }

    isSendingMessage.value = true;

    // Create FormData for file upload
    const formData = new FormData();
    formData.append("messages", newMessage.value || "");
    formData.append("sender_number", senderPhoneNumber.value);
    formData.append("receiver_number", contactPhoneNumber.value);
    formData.append("jobber_id", selectedJob.value.id);

    // Add image if selected
    if (selectedImage.value) {
        formData.append("image", selectedImage.value);
    }

    router.post(route("jobber-text-messages.store"), formData, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: (page) => {
            toast({
                title: "Success",
                description: "Message sent successfully!",
            });
            newMessage.value = "";
            removeImage();
            // Refresh the page data to get updated messages
            router.reload({ only: ["jobsByStatus"] });
        },
        onError: (errors) => {
            toast({
                variant: "destructive",
                title: "Error",
                description: "Failed to send message. Please try again.",
            });
        },
        onFinish: () => {
            isSendingMessage.value = false;
        },
        only: ["jobsByStatus"],
    });
};

// Watch for contact selection changes
watch(selectedContact, (newContactId) => {
    if (newContactId) {
        const foundContact = jobContacts.value.find(
            (contact) => contact.id == newContactId
        );
        contactPhoneNumber.value = foundContact ? foundContact.phone : "";
    }
});

const formatStatus = (status) => {
    if (typeof status !== "string") return "";

    return status.replace(/_/g, " ").replace(/\b\w/g, (l) => l.toUpperCase());
};

const formatDate = (date) => {
    if (!date) return "------";

    let parsedDate;

    if (typeof date === "string") {
        if (date.includes("T")) {
            // Handle ISO format (2025-03-06T17:41:20.000000Z)
            parsedDate = DateTime.fromISO(date, { zone: "utc" });
        } else {
            // Handle non-ISO format (2025-03-06 23:10:06)
            parsedDate = DateTime.fromFormat(date, "yyyy-MM-dd HH:mm:ss", {
                zone: "utc",
            });
        }
    } else if (date instanceof Date) {
        parsedDate = DateTime.fromJSDate(date);
    } else {
        return "Invalid Date";
    }

    return parsedDate.isValid
        ? parsedDate.toFormat("MM/dd/yyyy")
        : "Invalid Date";
};

const formatUSD = (value) => {
    if (typeof value !== "number") return value;
    return new Intl.NumberFormat("en-US", {
        style: "currency",
        currency: "USD",
    }).format(value);
};

const clients = ref([]);
const isSearchingLoading = ref(false);
const isSavingLoading = ref(false);
const searchQuery = ref("");
const selectedClient = ref(null);
const fetchClients = async (query) => {
    if (!query) {
        clients.value = [];
        return;
    }

    isSearchingLoading.value = true;
    try {
        const response = await axios.get(
            route("jobber.searchClient", { search: query })
        );

        clients.value = response.data;
    } catch (e) {
        console.error("Error fetching clients", e);
    } finally {
        isSearchingLoading.value = false;
    }
};
const debouncedSearch = debounce(fetchClients, 500);
watch(searchQuery, (val) => {
    debouncedSearch(val);
});

const saveClient = async () => {
    if (!selectedClient.value) {
        return;
    }

    isSavingLoading.value = true;
    try {
        const response = await axios.post(
            route("jobber.saveClient", {
                jobber_id: selectedJob.value.id,
                client: selectedClient.value,
            })
        );

        const res = response.data;

        if (res.success) {
            toast({
                title: "Success",
                description: "Client has been saved successfully!",
            });

            contactPhoneNumber.value = selectedClient.value.phone;

            router.reload();
        } else {
            toast({
                title: "Error",
                description: res.error || "Something went wrong.",
            });
        }
    } catch (e) {
        console.error("Error saving clients", e);
    } finally {
        isSavingLoading.value = false;
    }
};

useEchoPublic("jobs", "JobUpdated", (e) => {
    const updatedJob = e.job;
    const statusGroups = props.jobsByStatus;

    let found = false;

    // Loop through each status group to find and update the job
    for (const [status, jobs] of Object.entries(statusGroups)) {
        const index = jobs.findIndex((job) => job.id === updatedJob.id);

        if (index !== -1) {
            // Update the job in the current group
            jobs[index] = {
                ...jobs[index],
                ...updatedJob,
            };

            // If status changed, move to new group
            if (status !== updatedJob.job_status) {
                jobs.splice(index, 1); // Remove from old group

                // Add to new group
                if (!statusGroups[updatedJob.job_status]) {
                    statusGroups[updatedJob.job_status] = [];
                }

                statusGroups[updatedJob.job_status].unshift(updatedJob);
            }

            found = true;
            break;
        }
    }

    // If not found (i.e., new job), add it to its correct status group
    if (!found) {
        if (!statusGroups[updatedJob.job_status]) {
            statusGroups[updatedJob.job_status] = [];
        }

        statusGroups[updatedJob.job_status].unshift(updatedJob);
    }
});

useEchoPublic("jobs", "JobDeleted", (e) => {
    const deletedJob = e.job;
    const statusGroups = props.jobsByStatus;

    // Loop through all status groups to find and remove the job
    for (const [status, jobs] of Object.entries(statusGroups)) {
        const index = jobs.findIndex((job) => job.id === deletedJob.id);

        if (index !== -1) {
            jobs.splice(index, 1); // Remove job from the list
            break;
        }
    }
});
</script>
<template>
    <Head :title="title" />
    <div class="flex gap-3 flex-col sm:flex-row items-center justify-between">
        <SearchBar :url="url" v-model="search" class="w-full" />
        <Navigation />
    </div>
    <ScrollArea
        class="w-[90vw] sm:w-[85vw] md:w-[75vw] lg:w-[70vw] xl:w-[75vw]"
    >
        <div
            class="flex flex-row flex-nowrap space-x-2 overflow-x-auto scrollbar-hide"
        >
            <Deferred data="jobsByStatus">
                <template #fallback>
                    <div class="flex items-center justify-center py-6 gap-3">
                        <Loader2 class="animate-spin" />
                        <span class="text-gray-700">Loading...</span>
                    </div>
                </template>

                <div
                    v-for="(collection, status) in jobsByStatus"
                    :key="status"
                    class="overflow-hidden min-w-[250px] max-w-[250px]"
                >
                    <div class="text-center font-semibol">
                        <div
                            class="h-16 flex items-center justify-center border p-3 text-sm uppercase font-semibold"
                        >
                            <p>
                                {{ formatStatus(status) }} ({{
                                    collection.length
                                }})
                            </p>
                        </div>
                    </div>
                    <ScrollArea
                        class="h-[70vh] overflow-y-auto border-t pt-2 mb-5"
                    >
                        <div
                            v-for="item in collection"
                            :key="item.id"
                            class="mb-2 rounded-lg p-4 cursor-pointer hover:shadow-lg transition-all border"
                            @click="openJobModal(item)"
                            :class="{
                                // Past/Late items - Red (matching calendar past events)
                                'bg-red-100 text-red-800 border-red-300 hover:bg-red-200':
                                    item.job_status === 'late' ||
                                    item.job_status ===
                                        'ending_within_30_days' ||
                                    item.job_status === 'unscheduled',
                                // Current/Today items - Blue (matching calendar today events)
                                'bg-blue-100 text-blue-800 border-blue-300 hover:bg-blue-200':
                                    item.job_status === 'active' ||
                                    item.job_status === 'today',
                                // Action Required/On Hold - Yellow (warning state)
                                'bg-yellow-100 text-yellow-800 border-yellow-300 hover:bg-yellow-200':
                                    item.job_status === 'requires_invoicing' ||
                                    item.job_status === 'action_required' ||
                                    item.job_status === 'on_hold',
                                // Future/Upcoming items - Green (matching calendar future events)
                                'bg-green-100 text-green-800 border-green-300 hover:bg-green-200':
                                    item.job_status === 'upcoming',
                            }"
                        >
                            <!-- Work Order Number & Date -->
                            <div
                                class="flex justify-between items-center border-b pb-2 mb-2"
                            >
                                <h1 class="text-lg font-semibold">
                                    {{ item.job_number }}
                                </h1>
                                <p class="text-lg font-semibold">
                                    {{ formatUSD(item.total) }}
                                </p>
                            </div>

                            <!-- Location -->
                            <p
                                class="text-sm text-center font-semibold text-wrap opacity-90"
                            >
                                {{ item.title }}
                            </p>
                            <div class="flex gap-2 justify-center">
                                <p
                                    class="text-xs flex items-center gap-1 justify-center opacity-80"
                                >
                                    <Tag class="w-3 h-3" />{{
                                        item.job_type === "ONE_OFF"
                                            ? "One-off Job"
                                            : "Recurring Job"
                                    }}
                                </p>
                            </div>
                            <div
                                v-if="item.client_name"
                                class="flex justify-start items-start mb-1 mt-2"
                            >
                                <User class="w-4 h-4 opacity-80" />
                                <p class="text-sm text-wrap opacity-90">
                                    {{ item.client_name }}
                                </p>
                            </div>
                            <div class="flex justify-between items-center mt-2">
                                <p class="text-xs flex gap-1 opacity-80">
                                    <Truck class="w-4 h-4" />
                                    Visits: {{ item.visits_count }}
                                </p>
                                <p class="text-xs opacity-80">
                                    📅 {{ formatDate(item.start_at) }}
                                </p>
                            </div>
                        </div>

                        <ScrollBar orientation="vertical" />
                    </ScrollArea>
                </div>
            </Deferred>
        </div>
        <ScrollBar orientation="horizontal" />
    </ScrollArea>

    <div class="">
        <span class="text-gray-600">Drag/swipe the scrollbar →</span>
    </div>

    <!-- Job Details Modal -->
    <Dialog :open="isModalOpen" @update:open="isModalOpen = $event">
        <DialogContent
            class="flex max-h-[90dvh] w-full !max-w-4xl grid-rows-[auto_minmax(0,1fr)_auto] flex-col p-0 md:max-w-2xl"
        >
            <DialogHeader class="p-6 pb-0 text-left">
                <DialogTitle class="text-2xl text-primary">
                    {{ selectedJob?.title || "Job Details" }}
                    - Job #{{ selectedJob?.job_number || "N/A" }}
                </DialogTitle>
                <DialogDescription>
                    <div class="flex gap-2 mb-2 flex-wrap" v-if="selectedJob">
                        <Badge
                            :class="[
                                'px-3 py-1 font-medium',
                                {
                                    'bg-red-100 text-red-800 border-red-200':
                                        selectedJob.job_status === 'late' ||
                                        selectedJob.job_status ===
                                            'ending_within_30_days',
                                    'bg-blue-100 text-blue-800 border-blue-200':
                                        selectedJob.job_status === 'active' ||
                                        selectedJob.job_status ===
                                            'unscheduled',
                                    'bg-yellow-100 text-yellow-800 border-yellow-200':
                                        selectedJob.job_status ===
                                            'requires_invoicing' ||
                                        selectedJob.job_status ===
                                            'action_required' ||
                                        selectedJob.job_status === 'on_hold',
                                    'bg-green-100 text-green-800 border-green-200':
                                        selectedJob.job_status === 'upcoming',
                                },
                            ]"
                        >
                            {{ formatStatus(selectedJob.job_status) }}
                        </Badge>
                        <Badge variant="outline" v-if="selectedJob.job_type">
                            {{
                                selectedJob.job_type === "ONE_OFF"
                                    ? "One-off Job"
                                    : "Recurring Job"
                            }}
                        </Badge>
                    </div>
                </DialogDescription>
                <div class="flex justify-center gap-2 flex-wrap">
                    <!-- Tab Buttons -->
                    <div class="flex gap-1 p-1 bg-muted rounded-lg">
                        <button
                            v-for="tab in tabButtons"
                            :key="tab.name"
                            @click="switchTab(tab.name)"
                            :class="[
                                'flex items-center gap-2 px-3 py-2 text-sm font-medium rounded-md transition-colors',
                                activeTab === tab.name
                                    ? 'bg-background text-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground hover:bg-background/50',
                            ]"
                        >
                            <component :is="tab.icon" class="h-4 w-4" />
                            {{ tab.tooltip }}
                            <Badge
                                variant="secondary"
                                class="text-xs ml-1"
                                v-if="
                                    (tab.name === 'messages' &&
                                        selectedJob?.text_messages_count) ||
                                    (tab.name === 'visits' &&
                                        selectedJob?.visits_count)
                                "
                            >
                                {{
                                    tab.name === "messages"
                                        ? selectedJob.text_messages_count
                                        : selectedJob.visits_count
                                }}
                            </Badge>
                        </button>
                    </div>
                </div>
            </DialogHeader>
            <Separator />

            <!-- Job Details View -->
            <div
                v-if="activeTab === 'details' && selectedJob"
                class="p-6 space-y-6 overflow-y-auto"
            >
                <!-- Job Overview -->
                <div class="bg-muted/30 p-4 rounded-lg">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div v-if="selectedJob.total" class="text-center">
                            <div
                                class="flex items-center justify-center gap-2 mb-1"
                            >
                                <DollarSign class="h-4 w-4 text-primary" />
                                <span class="font-medium text-sm"
                                    >Total Amount</span
                                >
                            </div>
                            <p class="text-lg font-bold text-primary">
                                {{ formatUSD(selectedJob.total) }}
                            </p>
                        </div>
                        <div
                            v-if="selectedJob.visits_count !== undefined"
                            class="text-center"
                        >
                            <div
                                class="flex items-center justify-center gap-2 mb-1"
                            >
                                <Calendar class="h-4 w-4 text-primary" />
                                <span class="font-medium text-sm"
                                    >Total Visits</span
                                >
                            </div>
                            <p class="text-lg font-bold text-primary">
                                {{ selectedJob.visits_count }}
                            </p>
                        </div>
                        <div v-if="selectedJob.job_type" class="text-center">
                            <div
                                class="flex items-center justify-center gap-2 mb-1"
                            >
                                <Tag class="h-4 w-4 text-primary" />
                                <span class="font-medium text-sm"
                                    >Job Type</span
                                >
                            </div>
                            <p class="text-lg font-bold text-primary">
                                {{
                                    selectedJob.job_type === "ONE_OFF"
                                        ? "One-off"
                                        : "Recurring"
                                }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Main Details Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Client & Job Information -->
                    <div class="space-y-4">
                        <h3
                            class="font-semibold text-lg border-b pb-2 flex items-center gap-2"
                        >
                            <User class="h-5 w-5 text-primary" />
                            Client & Job Information
                        </h3>

                        <div class="space-y-3">
                            <div
                                v-if="selectedJob.client_name"
                                class="space-y-1"
                            >
                                <span
                                    class="font-medium text-sm text-muted-foreground"
                                    >Client Name</span
                                >
                                <p class="text-sm">
                                    {{ selectedJob.client?.first_name }}
                                    {{ selectedJob.client?.last_name }} -
                                    {{ selectedJob.client?.phone }}
                                </p>
                            </div>

                            <div
                                v-if="selectedJob.client_company"
                                class="space-y-1"
                            >
                                <span
                                    class="font-medium text-sm text-muted-foreground"
                                    >Company</span
                                >
                                <p class="text-sm">
                                    {{ selectedJob.client_company }}
                                </p>
                            </div>

                            <div v-if="selectedJob.start_at" class="space-y-1">
                                <span
                                    class="font-medium text-sm text-muted-foreground"
                                    >Start Date</span
                                >
                                <p class="text-sm">
                                    {{ formatDate(selectedJob.start_at) }}
                                </p>
                            </div>

                            <div v-if="selectedJob.end_at" class="space-y-1">
                                <span
                                    class="font-medium text-sm text-muted-foreground"
                                    >End Date</span
                                >
                                <p class="text-sm">
                                    {{ formatDate(selectedJob.end_at) }}
                                </p>
                            </div>

                            <div
                                v-if="selectedJob.completed_at"
                                class="space-y-1"
                            >
                                <span
                                    class="font-medium text-sm text-muted-foreground"
                                    >Completed Date</span
                                >
                                <p class="text-sm">
                                    {{ formatDate(selectedJob.completed_at) }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Property & Additional Details -->
                    <div class="space-y-4">
                        <h3
                            class="font-semibold text-lg border-b pb-2 flex items-center gap-2"
                        >
                            <MapPin class="h-5 w-5 text-primary" />
                            Property & Details
                        </h3>

                        <div class="space-y-3">
                            <div
                                v-if="selectedJob.property_address"
                                class="space-y-1"
                            >
                                <span
                                    class="font-medium text-sm text-muted-foreground"
                                    >Property Address</span
                                >
                                <p class="text-sm">
                                    {{ selectedJob.property_address }}
                                </p>
                            </div>

                            <div
                                v-if="selectedJob.jobber_web_uri"
                                class="space-y-1"
                            >
                                <span
                                    class="font-medium text-sm text-muted-foreground"
                                    >Jobber Link</span
                                >
                                <a
                                    :href="selectedJob.jobber_web_uri"
                                    target="_blank"
                                    class="text-sm text-primary hover:underline flex items-center gap-1"
                                >
                                    View in Jobber
                                    <Eye class="h-3 w-3" />
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Instructions/Description -->
                <div v-if="selectedJob.instructions" class="space-y-3">
                    <h3
                        class="font-semibold text-lg border-b pb-2 flex items-center gap-2"
                    >
                        <ClipboardList class="h-5 w-5 text-primary" />
                        Instructions
                    </h3>
                    <div class="bg-muted/50 p-4 rounded-lg">
                        <p
                            class="text-sm leading-relaxed"
                            v-html="selectedJob.instructions"
                        ></p>
                    </div>
                </div>
            </div>

            <!-- Visits/Schedules View -->
            <div
                v-if="activeTab === 'visits' && selectedJob"
                class="p-6 space-y-6 overflow-y-auto"
            >
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-semibold text-lg flex items-center gap-2">
                        <Calendar class="h-5 w-5 text-primary" />
                        Job Visits & Schedules
                    </h3>
                    <Badge variant="outline" class="px-2 py-1">
                        {{ selectedJob.visits_count || 0 }} Total Visits
                    </Badge>
                </div>

                <div
                    v-if="selectedJob.visits && selectedJob.visits.length > 0"
                    class="space-y-4"
                >
                    <div
                        v-for="(visit, index) in selectedJob.visits"
                        :key="visit.id || index"
                        class="border rounded-lg p-4 hover:bg-muted/20 transition-colors"
                    >
                        <div class="flex items-start justify-between mb-3">
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex items-center justify-center w-8 h-8 rounded-full bg-primary/10 text-primary font-semibold text-sm"
                                >
                                    {{ index + 1 }}
                                </div>
                                <div>
                                    <h4 class="font-medium">
                                        {{
                                            visit.title || `Visit ${index + 1}`
                                        }}
                                    </h4>
                                    <p class="text-sm text-muted-foreground">
                                        {{
                                            visit.anytime
                                                ? "Anytime Visit"
                                                : "Scheduled Visit"
                                        }}
                                    </p>
                                </div>
                            </div>
                            <Badge
                                :variant="
                                    visit.completed ? 'default' : 'secondary'
                                "
                                :class="
                                    visit.completed
                                        ? 'bg-green-100 text-green-800'
                                        : ''
                                "
                            >
                                {{ visit.completed ? "Completed" : "Pending" }}
                            </Badge>
                        </div>
                        <div class="p-2 bg-secondary rounded my-2">
                            <p class="text-sm" v-html="visit.instructions"></p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="space-y-2">
                                <div
                                    v-if="visit.start_at"
                                    class="flex items-center gap-2 text-sm"
                                >
                                    <Calendar
                                        class="h-4 w-4 text-muted-foreground"
                                    />
                                    <span class="font-medium">Start:</span>
                                    <span>{{
                                        formatDate(visit.start_at)
                                    }}</span>
                                </div>

                                <div
                                    v-if="visit.end_at"
                                    class="flex items-center gap-2 text-sm"
                                >
                                    <Clock
                                        class="h-4 w-4 text-muted-foreground"
                                    />
                                    <span class="font-medium">End:</span>
                                    <span>{{ formatDate(visit.end_at) }}</span>
                                </div>

                                <div
                                    v-if="visit.duration"
                                    class="flex items-center gap-2 text-sm"
                                >
                                    <Clock
                                        class="h-4 w-4 text-muted-foreground"
                                    />
                                    <span class="font-medium">Duration:</span>
                                    <span>{{ visit.duration }} minutes</span>
                                </div>
                            </div>

                            <div class="space-y-2">
                                <div
                                    v-if="visit.assignee_name"
                                    class="flex items-center gap-2 text-sm"
                                >
                                    <User
                                        class="h-4 w-4 text-muted-foreground"
                                    />
                                    <span class="font-medium"
                                        >Assigned to:</span
                                    >
                                    <span>{{ visit.assignee_name }}</span>
                                </div>

                                <div
                                    v-if="visit.team_size"
                                    class="flex items-center gap-2 text-sm"
                                >
                                    <User
                                        class="h-4 w-4 text-muted-foreground"
                                    />
                                    <span class="font-medium">Team Size:</span>
                                    <span>{{ visit.team_size }} member(s)</span>
                                </div>
                            </div>
                        </div>

                        <!-- Visit Description/Notes -->
                        <div
                            v-if="visit.description || visit.notes"
                            class="mt-3 pt-3 border-t"
                        >
                            <div class="bg-muted/30 p-3 rounded-lg">
                                <p class="text-sm leading-relaxed">
                                    {{ visit.description || visit.notes }}
                                </p>
                            </div>
                        </div>

                        <!-- Visit Actions -->
                        <div
                            v-if="visit.jobber_web_uri || visit.edit_url"
                            class="flex gap-2 mt-3 pt-3 border-t"
                        >
                            <Button
                                v-if="visit.jobber_web_uri"
                                variant="outline"
                                size="sm"
                                @click="
                                    window.open(visit.jobber_web_uri, '_blank')
                                "
                            >
                                <Eye class="h-3 w-3 mr-1" />
                                View in Jobber
                            </Button>
                        </div>
                    </div>
                </div>

                <!-- Empty State for Visits -->
                <div v-else class="text-center py-12 text-muted-foreground">
                    <Calendar class="h-16 w-16 mx-auto mb-4 opacity-30" />
                    <h3 class="text-lg font-medium mb-2">
                        No Visits Scheduled
                    </h3>
                    <p class="text-sm">
                        No visits have been scheduled for this job yet
                    </p>
                </div>
            </div>

            <!-- Text Messages View -->
            <div
                v-if="activeTab === 'messages' && selectedJob"
                class="grid gap-3 overflow-y-auto px-6"
            >
                <p class="font-semibold uppercase text-xs mb-3">Job Messages</p>

                <!-- Contact Selection -->
                <div class="flex justify-between gap-2 mb-2">
                    <div class="grid flex-1 gap-2">
                        <Label for="link"> Client Name</Label>
                        <div class="flex gap-2">
                            <Combobox v-model="selectedClient" by="phone">
                                <ComboboxAnchor class="w-[300px]">
                                    <div
                                        class="relative flex w-full items-center border"
                                    >
                                        <span
                                            class="absolute inset-y-0 start-0 flex items-center justify-center px-2"
                                        >
                                            <Search
                                                class="text-muted-foreground size-4"
                                            />
                                        </span>
                                        <ComboboxInput
                                            class="w-[300px] pl-7"
                                            :display-value="
                                                (val) =>
                                                    val?.first_name
                                                        ? val?.first_name +
                                                          ' ' +
                                                          val?.last_name +
                                                          ' ' +
                                                          val?.phone
                                                        : ''
                                            "
                                            :model-value="searchQuery"
                                            @update:model-value="
                                                searchQuery = $event
                                            "
                                            placeholder="Search client..."
                                        />
                                    </div>
                                </ComboboxAnchor>

                                <ComboboxList class="w-[300px]">
                                    <ComboboxEmpty v-if="!isSearchingLoading"
                                        >No client found.</ComboboxEmpty
                                    >
                                    <div
                                        v-if="isSearchingLoading"
                                        class="text-muted-foreground p-2 text-sm"
                                    >
                                        Loading...
                                    </div>

                                    <ComboboxGroup class="w-[300px]">
                                        <ComboboxItem
                                            class="w-[300px]"
                                            v-for="client in clients"
                                            :key="
                                                client.id + '-' + client.phone
                                            "
                                            :value="client"
                                        >
                                            {{ client.first_name }}
                                            {{ client.last_name }} -
                                            {{ client.phone }}
                                        </ComboboxItem>
                                    </ComboboxGroup>
                                </ComboboxList>
                            </Combobox>
                            <Button
                                v-if="
                                    selectedClient || selectedJob.client?.phone
                                "
                                @click="saveClient"
                                size="sm"
                            >
                                <Loader2
                                    v-if="isSavingLoading"
                                    class="animate-spin"
                                />
                                {{
                                    isSavingLoading ? "Saving..." : "Save"
                                }}</Button
                            >
                        </div>
                        Client Phone: {{ selectedJob.client?.phone }}
                    </div>

                    <div class="flex flex-col text-left">
                        <div class="flex gap-2 items-center">
                            <Avatar class="w-5 h-5">
                                <AvatarImage
                                    :src="
                                        $page.props.auth.user
                                            ?.profile_photo_url || 'default.jpg'
                                    "
                                />
                                <AvatarFallback>
                                    {{ $page.props.auth.user.name?.charAt(0) }}
                                </AvatarFallback>
                            </Avatar>
                            {{ $page.props.auth.user.name }}
                        </div>
                        <span class="text-sm text-muted-foreground">{{
                            senderPhoneNumber || "No sender number configured"
                        }}</span>
                    </div>
                </div>

                <!-- Messages Display -->
                <div class="flex flex-col gap-4 overflow-y-auto">
                    <ScrollArea class="bg-secondary h-[520px] rounded-md p-3">
                        <div
                            class="flex justify-center"
                            v-if="isLoadingMessages"
                        >
                            <Loader2
                                class="w-12 h-12 animate-spin text-primary"
                            />
                        </div>
                        <MessageCard
                            v-else-if="jobMessages && jobMessages.length > 0"
                            :messages="jobMessages"
                            :sender="senderPhoneNumber"
                        />
                        <div
                            v-else
                            class="text-center py-8 text-muted-foreground"
                        >
                            <MessageCircle
                                class="h-12 w-12 mx-auto mb-3 opacity-50"
                            />
                            <p class="text-sm">No messages yet</p>
                        </div>
                    </ScrollArea>
                </div>

                <!-- Image Preview -->
                <div
                    v-if="imagePreview"
                    class="mb-4 p-3 border rounded-lg bg-muted/20"
                >
                    <div class="flex items-start gap-3">
                        <div class="relative">
                            <img
                                :src="imagePreview"
                                alt="Selected image"
                                class="w-20 h-20 object-cover rounded-lg border"
                            />
                            <Button
                                size="icon"
                                variant="destructive"
                                class="absolute -top-2 -right-2 h-6 w-6"
                                @click="removeImage"
                            >
                                <X class="h-3 w-3" />
                            </Button>
                        </div>
                        <div class="flex-1">
                            <p class="text-sm font-medium">
                                {{ selectedImage?.name }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ Math.round(selectedImage?.size / 1024) }}KB
                            </p>
                            <p class="text-xs text-muted-foreground mt-1">
                                Ready to send with your message
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Message Input -->
                <div class="relative w-full mt-4 mb-6">
                    <!-- Hidden file input -->
                    <input
                        ref="fileInput"
                        type="file"
                        accept="image/*"
                        @change="handleImageSelect"
                        class="hidden"
                    />

                    <Textarea
                        v-model="newMessage"
                        placeholder="Type your message..."
                        class="w-full resize-none rounded-2xl border py-3 pr-24"
                        rows="1"
                        :disabled="isSendingMessage"
                        @keydown.enter.prevent="sendMessage"
                    />

                    <div class="flex absolute top-1/2 right-2 -translate-y-1/2">
                        <!-- Attachment Button -->
                        <Button
                            size="icon"
                            variant="ghost"
                            @click="triggerFileInput"
                            :disabled="isSendingMessage"
                            title="Attach image"
                        >
                            <Paperclip class="h-4 w-4" />
                        </Button>

                        <!-- Send Button -->
                        <Button
                            size="icon"
                            variant="ghost"
                            @click.prevent="sendMessage"
                            :disabled="isSendingMessage || isLoadingMessages"
                        >
                            <Send v-if="!isSendingMessage" class="h-4 w-4" />
                            <Loader2 v-else class="w-4 h-4 animate-spin" />
                        </Button>
                    </div>
                </div>
            </div>
            <Separator />

            <DialogFooter
                class="flex gap-2 justify-end p-4"
                v-if="activeTab !== 'messages' && selectedJob"
            >
                <Button variant="outline" @click="closeJobModal">
                    Close
                </Button>
                <Button
                    v-if="selectedJob?.view_url || selectedJob?.id"
                    as-child
                >
                    <a :href="selectedJob.jobber_web_uri" target="_blank">
                        <Eye class="h-4 w-4" />
                        View Full Details
                    </a>
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
