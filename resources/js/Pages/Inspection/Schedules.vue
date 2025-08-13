<script setup>
import { ref, watch } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import { useToast } from "@/Components/ui/toast/use-toast";
import SearchBar from "@/Components/SearchBar.vue";
import Navigation from "./partials/Navigation.vue";
import MessageCard from "@/Components/MessageCard.vue";
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
import { router, usePage, Head } from "@inertiajs/vue3";
import debounce from "lodash.debounce";
import "@schedule-x/theme-shadcn/dist/index.css";
import { ScheduleXCalendar } from "@schedule-x/vue";
import {
    MapPin,
    Calendar,
    User,
    Edit,
    Eye,
    Loader2,
    Loader2Icon,
    Send,
    Paperclip,
    X,
    MessageSquare,
    Search,
    Plus,
    MessageCircle,
    ClipboardList,
} from "lucide-vue-next";
import {
    createCalendar,
    createViewDay,
    createViewMonthAgenda,
    createViewMonthGrid,
    createViewWeek,
    viewMonthGrid,
    createViewList,
} from "@schedule-x/calendar";
import { useEchoPublic } from "@laravel/echo-vue";
import { Deferred } from "@inertiajs/vue3";

const { toast } = useToast();

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    events: Array,
    filters: Object,
});
// Helper function to get date status and add CSS class
const getEventDateClass = (eventStart, eventEnd) => {
    const today = new Date();
    const startDate = new Date(eventStart);
    const endDate = eventEnd ? new Date(eventEnd) : startDate;

    // Reset time to compare only dates
    today.setHours(0, 0, 0, 0);
    startDate.setHours(0, 0, 0, 0);
    endDate.setHours(0, 0, 0, 0);

    // For multi-day events, determine class based on relationship to today
    if (endDate < today) {
        return "event-past";
    } else if (startDate <= today && today <= endDate) {
        return "event-today"; // Event is currently happening (spans today)
    } else if (startDate > today) {
        return "event-future";
    } else {
        return "event-future"; // Default fallback
    }
};

// Process events to add date-based styling and ensure proper multi-day format
const processedEvents =
    props.events?.map((event) => {
        const startDate = event.start || event.date;
        const endDate = event.end;

        // Get date class for color coding
        const dateClass = getEventDateClass(startDate, endDate);

        return {
            ...event,
            // Ensure required properties are present
            id: event.id || `event-${Math.random()}`,
            start: startDate,
            end: endDate || startDate, // Ensure end date exists, default to start date
            title: event.title || event.summary || "Untitled Event",
            // Use Schedule-X _options to add CSS classes properly
            _options: {
                ...event._options,
                additionalClasses: [
                    dateClass,
                    ...(event._options?.additionalClasses || []),
                ],
            },
        };
    }) || [];

// Debug log to see the processed events
console.log("Processed events for Schedule-X:", processedEvents);

const calendarApp = createCalendar({
    selectedDate: new Date().now,
    theme: "shadcn",
    month: {
        showTrailingAndLeadingDates: false,
    },
    monthGridOptions: {
        nEventsPerDay: 20,
    },
    defaultView: viewMonthGrid.name,
    firstDayOfWeek: 0,
    views: [
        createViewDay(),
        createViewMonthGrid(),
        createViewMonthAgenda(),
        createViewList(),
    ],
    events: processedEvents,
    callbacks: {
        onEventClick(calendarEvent) {
            openEventModal(calendarEvent);
        },
    },
});

const url = route("visits.index");
const search = ref(props.filters.search ?? "");

// Modal state management
const isModalOpen = ref(false);
const selectedEvent = ref(null);

// Messaging state
const newMessage = ref("");
const selectedRecipients = ref([]);
const senderPhoneNumber = ref(usePage().props.twilio_phone_number);
const isLoadingMessages = ref(false);
const isSendingMessage = ref(false);
const jobMessages = ref([]);
const selectedImage = ref(null);
const imagePreview = ref(null);
const searchQuery = ref("");
const searchResults = ref([]);
const isSearching = ref(false);
const selectedClient = ref(null);
const clients = ref([]);
const isSearchingLoading = ref(false);
const fileInput = ref(null);
const selectedContact = ref("");
const jobContacts = ref([]);

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

const addRecipientFromClient = () => {
    if (
        selectedClient.value &&
        !selectedRecipients.value.find(
            (r) => r.phone === selectedClient.value.phone
        )
    ) {
        selectedRecipients.value.push({
            id: selectedClient.value.id,
            name: `${selectedClient.value.first_name} ${selectedClient.value.last_name}`,
            phone: selectedClient.value.phone,
        });
        selectedClient.value = null;
        searchQuery.value = "";
        clients.value = [];
    }
};

// Send message function
const sendMessage = () => {
    if (selectedRecipients.value.length === 0) {
        toast({
            variant: "destructive",
            title: "Error",
            description: "Please add at least one recipient",
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

    // Add all recipient numbers
    selectedRecipients.value.forEach((recipient, index) => {
        formData.append(`receiver_numbers[${index}]`, recipient.phone);
    });

    formData.append("jobber_id", selectedEvent.value.job.id);

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

            // Create and push the new message(s) to jobMessages immediately
            const timestamp = new Date().toISOString();
            selectedRecipients.value.forEach((recipient) => {
                const newMessageObj = {
                    id: Date.now() + Math.random(), // Temporary ID
                    message: newMessage.value || "",
                    sender_number: senderPhoneNumber.value,
                    receiver_number: recipient.phone,
                    image: selectedImage.value
                        ? URL.createObjectURL(selectedImage.value)
                        : null,
                    created_at: timestamp,
                    jobber_id: selectedEvent.value.job.id,
                };

                // Push the new message to the beginning of the array
                jobMessages.value.unshift(newMessageObj);

                // Also update the selectedJob's text_messages if it exists
                // if (selectedJob.value.text_messages) {
                //     selectedJob.value.text_messages.unshift(newMessageObj);
                // }
            });

            // Update the job in jobsByStatus to reflect the new message count
            Object.keys(props.jobsByStatus).forEach((status) => {
                const jobIndex = props.jobsByStatus[status].findIndex(
                    (job) => job.id === selectedEvent.value.job.id
                );
                if (jobIndex !== -1) {
                    props.jobsByStatus[status][jobIndex].text_messages_count =
                        (props.jobsByStatus[status][jobIndex]
                            .text_messages_count || 0) +
                        selectedRecipients.value.length;
                }
            });

            newMessage.value = "";
            removeImage();

            // Save contacts for future use
            saveContactsForJob();
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

const removeRecipient = (clientId) => {
    selectedRecipients.value = selectedRecipients.value.filter(
        (r) => r.id !== clientId
    );
};

const fetchMessages = async () => {
    if (!selectedEvent.value?.job?.id) return;

    isLoadingMessages.value = true;
    try {
        const response = await fetch(
            route("jobber-text-messages.index", {
                job_id: selectedEvent.value.job.id,
            })
        );
        const data = await response.json();
        jobMessages.value = data.messages || [];
    } catch (error) {
        console.error("Error fetching messages:", error);
        jobMessages.value = [];
    } finally {
        isLoadingMessages.value = false;
    }
};

const handleImageSelect = (event) => {
    const file = event.target.files[0];
    if (file) {
        // Validate file type
        if (!file.type.startsWith("image/")) {
            toast({
                title: "Invalid file type",
                description: "Please select an image file.",
                variant: "destructive",
            });
            return;
        }

        // Validate file size (5MB limit)
        if (file.size > 5 * 1024 * 1024) {
            toast({
                title: "File too large",
                description: "Please select an image under 5MB.",
                variant: "destructive",
            });
            return;
        }

        selectedImage.value = file;
        const reader = new FileReader();
        reader.onload = (e) => {
            imagePreview.value = e.target.result;
        };
        reader.readAsDataURL(file);
    }
};

const removeImage = () => {
    selectedImage.value = null;
    imagePreview.value = null;
};

const triggerFileInput = () => {
    fileInput.value?.click();
};

const activeTab = ref("details");

const tabButtons = [
    {
        name: "details",
        tooltip: "Details",
        icon: ClipboardList,
    },
    {
        name: "messages",
        tooltip: "Messages",
        icon: MessageCircle,
    },
];

const switchTab = (tabName) => {
    activeTab.value = tabName;
    if (tabName === "messages" && selectedEvent.value?.id) {
        // Fetch messages when switching to messages tab

        fetchJobMessages(selectedEvent.value.id);
    }
};
const fetchJobMessages = async (visitId) => {
    try {
        isLoadingMessages.value = true;
        // Messages are already loaded with the job data, so just use what we have
        if (selectedEvent.value?.text_messages) {
            jobMessages.value = selectedEvent.value.text_messages;
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
useEchoPublic("visits", "VisitDeleted", (e) => {
    events.value = events.value.filter((event) => event.id !== e.visitId);
});
useEchoPublic("visits", "VisitUpdated", (e) => {
    const updatedVisit = e.visit;

    const existingIndex = props.events.findIndex(
        (event) => event.id === updatedVisit.id
    );

    const formattedEvent = {
        ...updatedVisit,
        id: updatedVisit.id,
        start: updatedVisit.start || updatedVisit.date,
        end: updatedVisit.end || updatedVisit.date,
        title: updatedVisit.title || updatedVisit.summary || "Untitled Event",
        _options: {
            ...updatedVisit._options,
            additionalClasses: [
                getEventDateClass(updatedVisit.start, updatedVisit.end),
                ...(updatedVisit._options?.additionalClasses || []),
            ],
        },
    };

    if (existingIndex !== -1) {
        props.events.splice(existingIndex, 1, formattedEvent); // update
    } else {
        props.events.push(formattedEvent); // insert new
    }
});
// Function to open modal with event details
const openEventModal = (event) => {
    selectedEvent.value = event;
    isModalOpen.value = true;
    // Fetch messages when opening modal
    if (event?.job?.id) {
        fetchMessages();
    }
};

// Function to close modal
const closeEventModal = () => {
    isModalOpen.value = false;
    selectedEvent.value = null;
    // Reset messaging state when closing modal
    selectedRecipients.value = [];
    newMessage.value = "";
    selectedImage.value = null;
    imagePreview.value = null;
};
</script>

<template>
    <Head :title="title" />
    <div class="flex gap-3 flex-col sm:flex-row items-center justify-between">
        <SearchBar :url="url" v-model="search" class="w-full" />
        <Navigation />
    </div>
    <Deferred data="visits">
        <template #fallback>
            <div class="relative w-full h-[70vh]">
                <div
                    class="absolute inset-0 flex items-center justify-center bg-white"
                >
                    <Loader2Icon class="animate-spin" />
                    <span class="text-gray-700 ml-3">Loading...</span>
                </div>
            </div>
        </template>

        <div class="is-light-mode calendar-theme-override">
            <ScheduleXCalendar :calendar-app="calendarApp"> </ScheduleXCalendar>
        </div>
    </Deferred>
    <!-- Event Details Modal -->
    <Dialog :open="isModalOpen" @update:open="isModalOpen = $event">
        <DialogContent
            class="flex max-h-[90dvh] w-full !max-w-4xl grid-rows-[auto_minmax(0,1fr)_auto] flex-col p-0 md:max-w-2xl"
        >
            <DialogHeader class="p-6 pb-0 text-left">
                <DialogTitle class="text-2xl text-primary">
                    {{ selectedEvent?.title || "Event Details" }}
                </DialogTitle>
                <DialogDescription
                    v-if="selectedEvent"
                    class="text-sm text-muted-foreground flex gap-2"
                >
                    <Badge>Job #{{ selectedEvent.job.job_number }}</Badge>

                    <Badge
                        :variant="
                            selectedEvent.is_complete ? 'default' : 'secondary'
                        "
                        :class="[
                            'px-3 py-1 font-medium',
                            selectedEvent.is_complete
                                ? 'bg-green-100 text-green-800 border-green-200 hover:bg-green-200'
                                : 'bg-yellow-100 text-yellow-800 border-yellow-200 hover:bg-yellow-200',
                        ]"
                    >
                        <div class="flex items-center gap-1.5">
                            <div
                                :class="[
                                    'w-2 h-2 rounded-full',
                                    selectedEvent.is_complete
                                        ? 'bg-green-500'
                                        : 'bg-yellow-500',
                                ]"
                            ></div>
                            {{
                                selectedEvent.is_complete
                                    ? "Completed"
                                    : "In Progress"
                            }}
                        </div>
                    </Badge>
                </DialogDescription>
                <div class="flex justify-center gap-2 flex-wrap mt-2">
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
                                    tab.name === 'messages' &&
                                    selectedEvent?.text_messages_count
                                "
                            >
                                {{
                                    tab.name === "messages"
                                        ? selectedEvent.text_messages_count
                                        : ""
                                }}
                            </Badge>
                        </button>
                    </div>
                </div>
            </DialogHeader>
            <Separator />
            <div
                v-if="activeTab === 'details' && selectedEvent"
                class="grid gap-1 overflow-y-auto px-6"
            >
                <!-- Work Description -->
                <div v-if="selectedEvent.description" class="overflow-y-auto">
                    <h3 class="font-semibold">Details</h3>
                    <div class="bg-muted/50 rounded-lg">
                        <a
                            :href="selectedEvent.job.jobber_web_uri"
                            target="_blank"
                            class="text-sm leading-relaxed text-primary font-semibold"
                        >
                            {{ selectedEvent.job.title }} - Job #{{
                                selectedEvent.job.job_number
                            }}
                        </a>
                        <p
                            class="text-sm leading-relaxed"
                            v-html="selectedEvent.description"
                        ></p>
                    </div>
                </div>

                <div
                    v-if="selectedEvent.location || selectedEvent.address"
                    class="space-y-2"
                >
                    <div class="flex items-center gap-2">
                        <MapPin class="h-4 w-4 text-muted-foreground" />
                        <span class="font-medium">Location</span>
                    </div>
                    <p class="text-sm pl-6">
                        {{ selectedEvent.location || selectedEvent.address }}
                    </p>
                </div>

                <div
                    v-if="selectedEvent.teamMember || selectedEvent.assignedTo"
                    class="space-y-2"
                >
                    <div class="flex items-center gap-2">
                        <User class="h-4 w-4 text-muted-foreground" />
                        <span class="font-medium">Team Member</span>
                    </div>
                    <p class="text-sm pl-6">
                        {{
                            selectedEvent.teamMember || selectedEvent.assignedTo
                        }}
                    </p>
                </div>

                <!-- Details Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Start Date -->
                    <div v-if="selectedEvent.start">
                        <div class="flex items-center gap-2">
                            <Calendar class="h-4 w-4 text-muted-foreground" />
                            <span class="font-medium">Start Date</span>
                        </div>
                        <p class="text-sm pl-6">
                            {{
                                new Date(
                                    selectedEvent.start
                                ).toLocaleDateString("en-US", {
                                    weekday: "long",
                                    year: "numeric",
                                    month: "long",
                                    day: "numeric",
                                })
                            }}
                        </p>
                    </div>

                    <div
                        v-if="
                            selectedEvent.end &&
                            selectedEvent.end !== selectedEvent.start
                        "
                    >
                        <div class="flex items-center gap-2">
                            <Calendar class="h-4 w-4 text-muted-foreground" />
                            <span class="font-medium">End Date</span>
                        </div>
                        <p class="text-sm pl-6">
                            {{
                                new Date(selectedEvent.end).toLocaleDateString(
                                    "en-US",
                                    {
                                        weekday: "long",
                                        year: "numeric",
                                        month: "long",
                                        day: "numeric",
                                    }
                                )
                            }}
                        </p>
                    </div>
                </div>

                <!-- Additional Event Properties -->
                <div
                    v-if="
                        selectedEvent.notes ||
                        selectedEvent.priority ||
                        selectedEvent.category
                    "
                    class="space-y-4"
                >
                    <div v-if="selectedEvent.notes" class="space-y-2">
                        <span class="font-medium">Notes</span>
                        <p class="text-sm text-muted-foreground">
                            {{ selectedEvent.notes }}
                        </p>
                    </div>

                    <div class="flex gap-4">
                        <div v-if="selectedEvent.priority" class="space-y-1">
                            <span class="font-medium text-xs">Priority</span>
                            <Badge variant="outline" class="text-xs">{{
                                selectedEvent.priority
                            }}</Badge>
                        </div>

                        <div v-if="selectedEvent.category" class="space-y-1">
                            <span class="font-medium text-xs">Category</span>
                            <Badge variant="outline" class="text-xs">{{
                                selectedEvent.category
                            }}</Badge>
                        </div>
                    </div>
                </div>
            </div>

            <div
                v-if="activeTab === 'messages' && selectedEvent"
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
                v-if="activeTab !== 'messages' && selectedEvent"
            >
                <Button variant="outline" @click="closeEventModal">
                    Close
                </Button>
                <Button
                    v-if="
                        selectedEvent?.job.jobber_web_uri || selectedEvent?.id
                    "
                    as-child
                >
                    <a :href="selectedEvent.job.jobber_web_uri" target="_blank">
                        <Eye class="h-4 w-4" /> View Details</a
                    >
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
<style scoped>
.sx-vue-calendar-wrapper {
    height: 1300px;
}

.sx__event .sx__month-grid-event .sx__month-grid-cell {
    height: fit-content;
}

/* Shadcn Theme Color Integration - Override Schedule-X dark theme */
.calendar-theme-override {
    --sx-color-surface: hsl(var(--background));
    --sx-color-on-surface: hsl(var(--foreground));
    --sx-color-background: hsl(var(--background));
    --sx-color-on-background: hsl(var(--foreground));
    --sx-color-primary: hsl(var(--primary));
    --sx-color-on-primary: hsl(var(--primary-foreground));
    --sx-color-secondary: hsl(var(--secondary));
    --sx-color-on-secondary: hsl(var(--secondary-foreground));
    --sx-color-outline: hsl(var(--border));
    --sx-color-outline-variant: hsl(var(--border));
    --sx-internal-color-text: hsl(var(--foreground));
}

/* Calendar background and text colors */
:deep(.sx__calendar-wrapper) {
    background-color: hsl(var(--background)) !important;
    color: hsl(var(--foreground)) !important;
}

/* Month grid styling */
:deep(.sx__month-grid) {
    background-color: hsl(var(--background)) !important;
    color: hsl(var(--foreground)) !important;
}

/* Calendar cells */
:deep(.sx__month-grid-cell) {
    background-color: hsl(var(--background)) !important;
    border-color: hsl(var(--border)) !important;
}

/* Day headers */
:deep(.sx__week-grid__day-name) {
    color: hsl(var(--muted-foreground)) !important;
    background-color: hsl(var(--muted)) !important;
}

/* Date numbers */
:deep(.sx__week-grid__date-number) {
    color: hsl(var(--foreground)) !important;
}

/* Date-based Event Color Coding */
/* Past events - Red */
:deep(.sx__month-grid-event.event-past),
:deep(.event-past) {
    background-color: #fee2e2 !important; /* red-100 */
    color: #991b1b !important; /* red-800 */
    border: 1px solid #fca5a5 !important; /* red-300 */
}

:deep(.sx__month-grid-event.event-past:hover),
:deep(.event-past:hover) {
    background-color: #fecaca !important; /* red-200 */
    color: #7f1d1d !important; /* red-900 */
}

/* Today's events - Blue */
:deep(.sx__month-grid-event.event-today),
:deep(.event-today) {
    background-color: #dbeafe !important; /* blue-100 */
    color: #1e40af !important; /* blue-800 */
    border: 1px solid #93c5fd !important; /* blue-300 */
}

:deep(.sx__month-grid-event.event-today:hover),
:deep(.event-today:hover) {
    background-color: #bfdbfe !important; /* blue-200 */
    color: #1e3a8a !important; /* blue-900 */
}

/* Future events - Green */
:deep(.sx__month-grid-event.event-future),
:deep(.event-future) {
    background-color: #dcfce7 !important; /* green-100 */
    color: #166534 !important; /* green-800 */
    border: 1px solid #86efac !important; /* green-300 */
}

:deep(.sx__month-grid-event.event-future:hover),
:deep(.event-future:hover) {
    background-color: #bbf7d0 !important; /* green-200 */
    color: #14532d !important; /* green-900 */
}

/* Default fallback (if date cannot be determined) */
:deep(
        .sx__month-grid-event:not(.event-past):not(.event-today):not(
                .event-future
            )
    ) {
    background-color: hsl(var(--muted)) !important;
    color: hsl(var(--foreground)) !important;
    border: 1px solid hsl(var(--border)) !important;
}

:deep(
        .sx__month-grid-event:not(.event-past):not(.event-today):not(
                .event-future
            ):hover
    ) {
    background-color: hsl(var(--accent)) !important;
    color: hsl(var(--accent-foreground)) !important;
}

/* Calendar header */
:deep(.sx__calendar-header) {
    background-color: hsl(var(--background)) !important;
    color: hsl(var(--foreground)) !important;
    border-bottom: 1px solid hsl(var(--border)) !important;
}

/* Navigation buttons */
:deep(.sx__calendar-header button) {
    color: hsl(var(--foreground)) !important;
    background-color: transparent !important;
}

:deep(.sx__calendar-header button:hover) {
    background-color: hsl(var(--accent)) !important;
    color: hsl(var(--accent-foreground)) !important;
}

/* Today's date highlighting */
:deep(.sx__month-grid-day.is-today) {
    background-color: hsl(var(--accent)) !important;
}

:deep(.sx__month-grid-day.is-today .sx__week-grid__date-number) {
    color: hsl(var(--accent-foreground)) !important;
    font-weight: 600;
}

/* Week view and day view date-based coloring */
:deep(.sx__time-grid-event.event-past) {
    background-color: #fee2e2 !important;
    color: #991b1b !important;
    border: 1px solid #fca5a5 !important;
}

:deep(.sx__time-grid-event.event-today) {
    background-color: #dbeafe !important;
    color: #1e40af !important;
    border: 1px solid #93c5fd !important;
}

:deep(.sx__time-grid-event.event-future) {
    background-color: #dcfce7 !important;
    color: #166534 !important;
    border: 1px solid #86efac !important;
}

:deep(
        .sx__time-grid-event:not(.event-past):not(.event-today):not(
                .event-future
            )
    ) {
    background-color: hsl(var(--muted)) !important;
    color: hsl(var(--foreground)) !important;
    border: 1px solid hsl(var(--border)) !important;
}

/* Agenda view date-based coloring */
:deep(.sx__month-agenda-event.event-past) {
    background-color: #fee2e2 !important;
    color: #991b1b !important;
    border: 1px solid #fca5a5 !important;
}

:deep(.sx__month-agenda-event.event-today) {
    background-color: #dbeafe !important;
    color: #1e40af !important;
    border: 1px solid #93c5fd !important;
}

:deep(.sx__month-agenda-event.event-future) {
    background-color: #dcfce7 !important;
    color: #166534 !important;
    border: 1px solid #86efac !important;
}

:deep(
        .sx__month-agenda-event:not(.event-past):not(.event-today):not(
                .event-future
            )
    ) {
    background-color: hsl(var(--muted)) !important;
    color: hsl(var(--foreground)) !important;
    border: 1px solid hsl(var(--border)) !important;
}
</style>
