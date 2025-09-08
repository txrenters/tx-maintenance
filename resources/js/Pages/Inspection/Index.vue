<script setup>
import {
    ref,
    watch,
    computed,
    shallowRef,
    nextTick,
    onMounted,
    onUnmounted,
} from "vue";
import { router, usePage, usePoll } from "@inertiajs/vue3";
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
    Calendar1,
    Plus,
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
import MessageCard from "@/Components/MessageCard.vue";
import debounce from "lodash.debounce";
import { Deferred, Head } from "@inertiajs/vue3";
import { useEchoPublic } from "@laravel/echo-vue";
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from "@/Components/ui/popover";
import { RangeCalendar } from "@/Components/ui/range-calendar";
import { DateFormatter, getLocalTimeZone } from "@internationalized/date";
import axios from "axios";
import { Button } from "@/Components/ui/button";
import { Badge } from "@/Components/ui/badge";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogFooter,
} from "@/Components/ui/dialog";
import { ScrollArea, ScrollBar } from "@/Components/ui/scroll-area";
import { Separator } from "@/Components/ui/separator";
import { Avatar, AvatarFallback, AvatarImage } from "@/Components/ui/avatar";
import { Label } from "@/Components/ui/label";
import { Textarea } from "@/Components/ui/textarea";

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

// Performance: Use shallowRef for large objects
const dateFormatCache = new Map();
const currencyFormatCache = new Map();

// Watch for prop changes and update reactive data
const jobsByStatusData = computed(() => props.jobsByStatus || {});

const url = route("inspections.index");
const search = ref(props.filter.search ?? "");

// Modal state management
const isModalOpen = ref(false);
const selectedJob = ref(null);
const activeTab = ref("details");

// Remove virtual scrolling for now to fix data loading

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

// Optimized job modal opening with lazy loading
let jobDetailsController = null;
const openJobModal = async (job) => {
    // Cancel previous request if still pending
    if (jobDetailsController) {
        jobDetailsController.abort();
    }

    jobDetailsController = new AbortController();

    try {
        const response = await axios.get(route("jobber.jobDetails", job.id), {
            signal: jobDetailsController.signal,
        });

        selectedJob.value = { ...job, ...response.data };
        isModalOpen.value = true;
        activeTab.value = "details";

        // Defer state reset to next tick
        await nextTick();

        // Reset messaging state
        newMessage.value = "";
        selectedContact.value = "";
        selectedRecipients.value = [];
        jobMessages.value = response.data.text_messages?.slice(0, 50) || []; // Limit initial messages
        jobContacts.value = [];
        selectedImage.value = null;
        imagePreview.value = null;
        selectedClient.value = job.client;
        contactPhoneNumber.value = job.client?.phone ?? "";

        // Load saved contacts asynchronously
        loadSavedContacts(job.id);
    } catch (error) {
        if (error.name !== "AbortError") {
            console.error("Error loading job details:", error);
            toast({
                variant: "destructive",
                title: "Error",
                description: "Failed to load job details",
            });
        }
    } finally {
        jobDetailsController = null;
    }
};

// Optimized modal closing
const closeJobModal = () => {
    isModalOpen.value = false;

    // Cancel any pending requests
    if (jobDetailsController) {
        jobDetailsController.abort();
    }

    // Defer cleanup to avoid blocking UI
    requestAnimationFrame(() => {
        selectedJob.value = null;
        activeTab.value = "details";
        jobMessages.value = [];
        selectedRecipients.value = [];
        newMessage.value = "";
        if (imagePreview.value) {
            URL.revokeObjectURL(imagePreview.value);
            imagePreview.value = null;
        }
        selectedImage.value = null;
    });
};

const deleteJob = (jobId) => {
    router.delete(route("inspections.destroy", $jobId), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: (page) => {
            toast({
                title: "Success",
                description: "Job has been deleted successfully!",
            });
            isModalOpen.value = false;
        },
        onError: (error) => {
            toast({
                title: "Error",
                description: "Error deleting job.",
            });
        },
    });
};

// Function to switch tabs
const switchTab = (tabName) => {
    activeTab.value = tabName;
    if (tabName === "messages" && selectedJob.value?.id) {
        fetchJobMessages(selectedJob.value.id);
    }
};

const newMessage = ref("");
const selectedContact = ref("");
const contactPhoneNumber = ref("");
const selectedRecipients = ref([]);
const senderPhoneNumber = ref(page.props.twilio_phone_number);
const isLoadingMessages = ref(false);
const isSendingMessage = ref(false);
const jobMessages = ref([]);
const jobContacts = ref([]);
const savedContacts = ref([]);
const isLoadingContacts = ref(false);

// Image attachment functionality
const selectedImage = ref(null);
const imagePreview = ref(null);
const fileInput = ref(null);

// Optimized message fetching
let messageController = null;
const fetchJobMessages = async (jobId) => {
    // Cancel previous request if still pending
    if (messageController) {
        messageController.abort();
    }

    messageController = new AbortController();

    try {
        isLoadingMessages.value = true;
        // Messages are already loaded with the job data
        if (selectedJob.value?.text_messages) {
            // Limit messages for performance
            jobMessages.value = selectedJob.value.text_messages.slice(0, 50);
        } else {
            jobMessages.value = [];
        }
        jobContacts.value = [];
    } catch (error) {
        if (error.name !== "AbortError") {
            console.error("Error fetching messages:", error);
            jobMessages.value = [];
            jobContacts.value = [];
        }
    } finally {
        isLoadingMessages.value = false;
        messageController = null;
    }
};

// Optimized image handling
const handleImageSelect = async (event) => {
    const file = event.target.files[0];
    if (!file) return;

    // Validate file type
    if (!file.type.startsWith("image/")) {
        toast({
            variant: "destructive",
            title: "Invalid file type",
            description: "Please select an image file (JPG, PNG, GIF, etc.)",
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

    // Use createObjectURL for better performance
    if (imagePreview.value) {
        URL.revokeObjectURL(imagePreview.value);
    }
    imagePreview.value = URL.createObjectURL(file);
};

// Remove selected image with cleanup
const removeImage = () => {
    if (imagePreview.value) {
        URL.revokeObjectURL(imagePreview.value);
    }
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

// Optimized contact loading
let contactsController = null;
const loadSavedContacts = async (jobId) => {
    // Cancel previous request if still pending
    if (contactsController) {
        contactsController.abort();
    }

    contactsController = new AbortController();

    try {
        isLoadingContacts.value = true;
        const response = await axios.get(
            route("client-contacts.index", { jobber: jobId }),
            { signal: contactsController.signal }
        );
        savedContacts.value = response.data || [];

        // Pre-populate recipients with saved contacts
        if (savedContacts.value.length > 0) {
            selectedRecipients.value = savedContacts.value.map((contact) => ({
                name: contact.name,
                phone: contact.phone,
            }));
        }
    } catch (error) {
        if (error.name !== "AbortError") {
            console.error("Error loading contacts:", error);
            savedContacts.value = [];
        }
    } finally {
        isLoadingContacts.value = false;
        contactsController = null;
    }
};

// Save contacts for future use
const saveContactsForJob = async () => {
    if (!selectedJob.value || selectedRecipients.value.length === 0) return;

    try {
        const response = await axios.post(
            route("client-contacts.store", { jobber: selectedJob.value.id }),
            {
                contacts: selectedRecipients.value.map((recipient) => ({
                    name: recipient.name || recipient.phone,
                    phone: recipient.phone,
                })),
            }
        );

        if (response.data.success) {
            savedContacts.value = response.data.contacts;
        }
    } catch (error) {
        console.error("Error saving contacts:", error);
    }
};

// Add recipient from client search
const addRecipientFromClient = () => {
    if (selectedClient.value && selectedClient.value.phone) {
        const recipient = {
            name: `${selectedClient.value.first_name} ${selectedClient.value.last_name}`,
            phone: selectedClient.value.phone,
        };

        // Check if recipient already exists
        const exists = selectedRecipients.value.some(
            (r) => r.phone === recipient.phone
        );
        if (!exists) {
            selectedRecipients.value.push(recipient);
        }

        // Clear selection
        selectedClient.value = null;
        searchQuery.value = "";
    }
};
const customePhoneNumber = ref("");
// Optimized send message function
const sendMessage = () => {
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

    // Add all recipient numbers
    selectedRecipients.value.forEach((recipient, index) => {
        formData.append(`receiver_numbers[${index}]`, recipient.phone);
    });

    if (customePhoneNumber.value.trim() !== "") {
        formData.append(
            `receiver_numbers[${selectedRecipients.value.length}]`,
            customePhoneNumber.value.trim()
        );
    }

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
                description: "Messages sent successfully!",
            });

            // Batch update messages for better performance
            requestAnimationFrame(() => {
                const timestamp = new Date().toISOString();
                const newMessages = selectedRecipients.value.map(
                    (recipient) => ({
                        id: Date.now() + Math.random(),
                        message: newMessage.value || "",
                        sender_number: senderPhoneNumber.value,
                        receiver_number: recipient.phone,
                        image: imagePreview.value,
                        created_at: timestamp,
                        jobber_id: selectedJob.value.id,
                    })
                );

                // Batch insert at the beginning
                jobMessages.value = [
                    ...newMessages,
                    ...jobMessages.value,
                ].slice(0, 50);

                // Update job count in the main list
                updateJobMessageCount(
                    selectedJob.value.id,
                    selectedRecipients.value.length
                );
            });

            newMessage.value = "";
            removeImage();

            // Save contacts asynchronously
            nextTick(() => saveContactsForJob());
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

// Update job message count
const updateJobMessageCount = (jobId, addCount) => {
    Object.keys(props.jobsByStatus).forEach((status) => {
        const jobIndex = props.jobsByStatus[status].findIndex(
            (job) => job.id === jobId
        );
        if (jobIndex !== -1) {
            props.jobsByStatus[status][jobIndex].text_messages_count =
                (props.jobsByStatus[status][jobIndex].text_messages_count ||
                    0) + addCount;
        }
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

// Memoized date formatting
const formatDate = (date) => {
    if (!date) return "------";

    // Check cache first
    if (dateFormatCache.has(date)) {
        return dateFormatCache.get(date);
    }

    let parsedDate;

    if (typeof date === "string") {
        if (date.includes("T")) {
            parsedDate = DateTime.fromISO(date, { zone: "utc" });
        } else {
            parsedDate = DateTime.fromFormat(date, "yyyy-MM-dd HH:mm:ss", {
                zone: "utc",
            });
        }
    } else if (date instanceof Date) {
        parsedDate = DateTime.fromJSDate(date);
    } else {
        return "Invalid Date";
    }

    const formatted = parsedDate.isValid
        ? parsedDate.toFormat("MM/dd/yyyy")
        : "Invalid Date";

    // Cache the result
    dateFormatCache.set(date, formatted);
    return formatted;
};

// Memoized currency formatting
const formatUSD = (value) => {
    if (typeof value !== "number") return value;

    // Check cache first
    if (currencyFormatCache.has(value)) {
        return currencyFormatCache.get(value);
    }

    const formatted = new Intl.NumberFormat("en-US", {
        style: "currency",
        currency: "USD",
    }).format(value);

    // Cache the result
    currencyFormatCache.set(value, formatted);
    return formatted;
};

const clients = ref([]);
const isSearchingLoading = ref(false);
const isSavingLoading = ref(false);
const searchQuery = ref("");
const selectedClient = ref(null);

// Optimized client fetching with increased debounce
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

const debouncedSearch = debounce(fetchClients, 700); // Increased debounce time
watch(searchQuery, (val) => {
    debouncedSearch(val);
});

const df = new DateFormatter("en-US", {
    dateStyle: "medium",
});

const date_range = ref({
    start: "",
    end: "",
});

// Optimized filtering with increased debounce
const fetchFilteredData = debounce(() => {
    const newQuery = {
        start_date: date_range.value.start
            ? date_range.value.start.toString()
            : "",
        end_date: date_range.value.end ? date_range.value.end.toString() : "",
    };

    router.visit(url, {
        method: "get",
        data: newQuery,
        preserveState: true,
        preserveScroll: true,
    });
}, 2500); // Increased debounce time

watch(date_range, fetchFilteredData, { deep: true });

// Virtual scrolling removed for now to fix data display

// Cleanup on unmount
onUnmounted(() => {
    // Clean up any object URLs
    if (imagePreview.value) {
        URL.revokeObjectURL(imagePreview.value);
    }

    // Cancel any pending requests
    if (jobDetailsController) {
        jobDetailsController.abort();
    }
    if (messageController) {
        messageController.abort();
    }
    if (contactsController) {
        contactsController.abort();
    }

    // Clear caches
    dateFormatCache.clear();
    currencyFormatCache.clear();
});

usePoll(15000, {
    // Increased poll interval
    only: ["jobsByStatus"],
});
</script>
<template>
    <Head :title="title" />
    <div class="flex gap-3 flex-col sm:flex-row items-center justify-between">
        <SearchBar :url="url" v-model="search" class="w-full" />
        <div class="flex gap-2">
            <Popover>
                <PopoverTrigger as-child>
                    <Button
                        variant="outline"
                        :class="[
                            'w-full justify-start text-left text-xs font-normal sm:w-[220px]',
                            !date_range.start ? 'text-muted-foreground' : '',
                        ]"
                    >
                        <Calendar1 class="mr-2 h-4 w-4" />
                        <template v-if="date_range.start">
                            <template v-if="date_range.end">
                                {{
                                    df.format(
                                        date_range.start.toDate(
                                            getLocalTimeZone()
                                        )
                                    )
                                }}
                                -
                                {{
                                    df.format(
                                        date_range.end.toDate(
                                            getLocalTimeZone()
                                        )
                                    )
                                }}
                            </template>
                            <template v-else>
                                {{
                                    df.format(
                                        date_range.start.toDate(
                                            getLocalTimeZone()
                                        )
                                    )
                                }}
                            </template>
                        </template>
                        <template v-else> Pick a date </template>
                    </Button>
                </PopoverTrigger>
                <PopoverContent class="w-auto p-0">
                    <RangeCalendar
                        v-model="date_range"
                        initial-focus
                        :number-of-months="2"
                        @update:start-value="
                            (startDate) => (date_range.start = startDate)
                        "
                        @update:end-value="
                            (endDate) => (date_range.end = endDate)
                        "
                    />
                </PopoverContent>
            </Popover>
            <Navigation />
        </div>
    </div>
    <ScrollArea
        class="w-[90vw] sm:w-[85vw] md:w-[75vw] lg:w-[70vw] xl:w-[75vw]"
    >
        <div
            class="flex flex-row flex-nowrap space-x-2 overflow-x-auto scrollbar-hide"
        >
            <Deferred data="jobsByStatus">
                <template #fallback>
                    <div
                        class="flex items-center justify-center gap-2 w-full h-[70vh]"
                    >
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
                            class="mb-2 rounded-lg p-4 cursor-pointer hover:shadow-lg transition-all border transform-gpu"
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

            <!-- Visits/Schedules View - Limited to first 10 visits -->
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
                        v-for="(visit, index) in selectedJob.visits.slice(
                            0,
                            10
                        )"
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

                    <!-- Show more visits indicator -->
                    <div
                        v-if="selectedJob.visits.length > 10"
                        class="text-center py-2 text-sm text-muted-foreground"
                    >
                        Showing first 10 of
                        {{ selectedJob.visits.length }} visits
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

            <!-- Text Messages View with Virtual Scrolling -->
            <div
                v-if="activeTab === 'messages' && selectedJob"
                class="grid gap-1 overflow-y-auto px-6"
            >
                <p class="font-semibold uppercase text-xs">Job Messages</p>

                <!-- Contact Selection & Recipients - All Inline -->
                <div
                    class="flex flex-wrap gap-2 items-center bg-muted/20 rounded-lg"
                >
                    <!-- Client Search -->
                    <div class="flex flex-col">
                        <Label class="text-sm whitespace-nowrap">Add:</Label>
                        <div class="flex gap-1">
                            <Combobox v-model="selectedClient" by="phone">
                                <ComboboxAnchor class="w-[250px]">
                                    <div
                                        class="relative flex w-full items-center border rounded-md"
                                    >
                                        <Search
                                            class="absolute left-2 h-4 w-4 text-muted-foreground"
                                        />
                                        <ComboboxInput
                                            class="w-[250px] pl-8 pr-2 py-1 text-sm"
                                            :display-value="
                                                (val) =>
                                                    val?.first_name
                                                        ? `${val.first_name} ${val.last_name} - ${val.phone}`
                                                        : ''
                                            "
                                            :model-value="searchQuery"
                                            @update:model-value="
                                                searchQuery = $event
                                            "
                                            placeholder="Search clients..."
                                        />
                                    </div>
                                </ComboboxAnchor>
                                <ComboboxList class="w-[250px]">
                                    <ComboboxEmpty v-if="!isSearchingLoading"
                                        >No client found.</ComboboxEmpty
                                    >
                                    <div
                                        v-if="isSearchingLoading"
                                        class="text-muted-foreground p-2 text-sm"
                                    >
                                        Loading...
                                    </div>
                                    <ComboboxGroup class="w-[250px]">
                                        <ComboboxItem
                                            class="w-[250px]"
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
                                v-if="selectedClient"
                                @click="addRecipientFromClient"
                                size="sm"
                            >
                                <Plus class="h-4 w-4" />
                            </Button>
                            <Input
                                v-model="customPhoneNumber"
                                placeholder="Enter custom number..."
                            />
                        </div>
                    </div>

                    <!-- Sender Info -->
                    <div class="flex items-center gap-2 ml-auto">
                        <Avatar>
                            <AvatarImage
                                :src="
                                    $page.props.auth.user?.profile_photo_url ||
                                    'default.jpg'
                                "
                            />
                            <AvatarFallback>
                                {{ $page.props.auth.user.name?.charAt(0) }}
                            </AvatarFallback>
                        </Avatar>
                        <div class="text-right">
                            <div class="font-medium">
                                {{ $page.props.auth.user.name }}
                            </div>
                            <div class="text-muted-foreground">
                                {{ senderPhoneNumber || "Not configured" }}
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Recipients Tags -->
                <div v-if="selectedRecipients.length > 0" class="flex gap-2">
                    <Label class="text-sm whitespace-nowrap">To:</Label>
                    <div
                        v-for="(recipient, index) in selectedRecipients"
                        :key="`${recipient.phone}-${index}`"
                        class="inline-flex items-center gap-1 bg-primary/10 text-primary rounded px-2 py-1 text-sm"
                    >
                        <span>{{ recipient.name || recipient.phone }}</span>
                        <button
                            @click="selectedRecipients.splice(index, 1)"
                            class="hover:bg-primary/20 rounded p-0.5"
                        >
                            <X class="h-3 w-3" />
                        </button>
                    </div>
                    <Button
                        @click="selectedRecipients = []"
                        variant="ghost"
                        size="sm"
                        class="h-6 px-2 text-xs"
                    >
                        Clear
                    </Button>
                </div>
                <!-- Messages Display with Virtual Scrolling -->
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
                        <template
                            v-else-if="jobMessages && jobMessages.length > 0"
                        >
                            <MessageCard
                                :messages="jobMessages"
                                :sender="senderPhoneNumber"
                            />
                            <div
                                v-if="selectedJob?.text_messages?.length > 50"
                                class="text-center py-2 text-sm text-muted-foreground"
                            >
                                Showing first 50 of
                                {{ selectedJob.text_messages.length }} messages
                            </div>
                        </template>
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
                        class="w-full resize-y rounded-2xl border py-3 pr-24"
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
                <Button
                    variant="destructive"
                    @click="deleteJob(selectedJob?.id)"
                >
                    Delete
                </Button>
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
