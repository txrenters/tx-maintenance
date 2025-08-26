<script setup>
import { ref, computed, onMounted, nextTick, watch } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import { useToast } from "@/Components/ui/toast/use-toast";
import SearchBar from "@/Components/SearchBar.vue";
import Navigation from "./partials/Navigation.vue";
import MessageCard from "@/Components/MessageCard.vue";
import { router, usePage, Head } from "@inertiajs/vue3";
import debounce from "lodash.debounce";
import { useEchoPublic } from "@laravel/echo-vue";
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogFooter,
} from "@/Components/ui/dialog";
import { Badge } from "@/Components/ui/badge";
import { Avatar, AvatarImage, AvatarFallback } from "@/Components/ui/avatar";
import { Button } from "@/Components/ui/button";
import { Card, CardContent } from "@/Components/ui/card";
import { Input } from "@/Components/ui/input";
import { Label } from "@/Components/ui/label";
import { Textarea } from "@/Components/ui/textarea";
import { ScrollArea } from "@/Components/ui/scroll-area";
import { Separator } from "@/Components/ui/separator";
import {
    Combobox,
    ComboboxAnchor,
    ComboboxEmpty,
    ComboboxGroup,
    ComboboxInput,
    ComboboxItem,
    ComboboxList,
} from "@/Components/ui/combobox";
import {
    MapPin,
    Calendar,
    User,
    Eye,
    Loader2,
    Send,
    Paperclip,
    X,
    MessageCircle,
    Search,
    Plus,
    ClipboardList,
    ChevronLeft,
    ChevronRight,
    Clock,
    CheckCircle2,
    AlertCircle,
} from "lucide-vue-next";

const { toast } = useToast();

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    events: Array,
    filters: Object,
    weekStart: String, // Backend provides the current week start
});

// Week Navigation State
const currentWeekStart = ref(new Date());
const touchStartX = ref(0);
const touchEndX = ref(0);
const isTransitioning = ref(false);
const weekContainer = ref(null);

// Initialize week from backend prop
const initializeWeek = () => {
    if (props.weekStart) {
        // Use the week start provided by backend (already a Sunday in Chicago timezone)
        // Parse as UTC to avoid timezone issues, then treat as local date
        currentWeekStart.value = new Date(props.weekStart + "T00:00:00");
        console.log(
            "Initialized week from backend prop:",
            props.weekStart,
            currentWeekStart.value,
            "Day of week:",
            currentWeekStart.value.getDay() // Should be 0 (Sunday)
        );
    } else {
        // Fallback to current week in Chicago timezone
        const today = new Date();
        const chicagoTime = new Intl.DateTimeFormat("en-CA", {
            timeZone: "America/Chicago",
            year: "numeric",
            month: "2-digit",
            day: "2-digit",
        }).format(today);

        const chicagoDate = new Date(chicagoTime + "T00:00:00");
        const dayOfWeek = chicagoDate.getDay();
        const diff = dayOfWeek; // Days to subtract to get to Sunday
        currentWeekStart.value = new Date(chicagoDate);
        currentWeekStart.value.setDate(chicagoDate.getDate() - diff);
        console.log("Fallback to current week:", currentWeekStart.value);
    }

    currentWeekStart.value.setHours(0, 0, 0, 0);
};

// Get week dates - use UTC calculations to avoid timezone issues
const weekDates = computed(() => {
    const dates = [];
    const start = new Date(currentWeekStart.value);
    const startTime = start.getTime();

    // Add exactly 24 hours * i days to avoid DST issues
    for (let i = 0; i < 7; i++) {
        const date = new Date(startTime + i * 24 * 60 * 60 * 1000);
        dates.push(date);
    }

    return dates;
});

// Format week range for header
const weekRangeText = computed(() => {
    const start = weekDates.value[0];
    const end = weekDates.value[6];
    const startMonth = start.toLocaleDateString("en-US", { month: "short" });
    const endMonth = end.toLocaleDateString("en-US", { month: "short" });
    const startDate = start.getDate();
    const endDate = end.getDate();
    const year = end.getFullYear();

    if (startMonth === endMonth) {
        return `${startMonth} ${startDate}-${endDate}, ${year}`;
    } else {
        return `${startMonth} ${startDate} - ${endMonth} ${endDate}, ${year}`;
    }
});

// Check if a date is today (in Chicago timezone)
const isToday = (date) => {
    const today = new Date();
    const chicagoTime = new Intl.DateTimeFormat("en-CA", {
        timeZone: "America/Chicago",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    }).format(today);

    const [year, month, day] = chicagoTime.split("-");
    const chicagoToday = new Date(year, month - 1, day);

    return (
        date.getDate() === chicagoToday.getDate() &&
        date.getMonth() === chicagoToday.getMonth() &&
        date.getFullYear() === chicagoToday.getFullYear()
    );
};

// Check if viewing current week
const isCurrentWeek = computed(() => {
    const today = new Date();
    const chicagoTime = new Intl.DateTimeFormat("en-CA", {
        timeZone: "America/Chicago",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    }).format(today);

    const [year, month, day] = chicagoTime.split("-");
    const chicagoToday = new Date(year, month - 1, day);

    const weekStart = new Date(currentWeekStart.value);
    const weekEnd = new Date(weekStart);
    weekEnd.setDate(weekEnd.getDate() + 6);

    return chicagoToday >= weekStart && chicagoToday <= weekEnd;
});

// Navigate weeks - use millisecond arithmetic to avoid timezone issues
const navigateWeek = (direction) => {
    if (isTransitioning.value) return;

    isTransitioning.value = true;

    // Calculate new date using millisecond arithmetic (exactly 7 days)
    const currentTime = currentWeekStart.value.getTime();
    const newTime = currentTime + direction * 7 * 24 * 60 * 60 * 1000;
    const newDate = new Date(newTime);

    // Format as YYYY-MM-DD in local timezone
    const year = newDate.getFullYear();
    const month = String(newDate.getMonth() + 1).padStart(2, "0");
    const day = String(newDate.getDate()).padStart(2, "0");
    const weekKey = `${year}-${month}-${day}`;

    console.log(
        "Navigating:",
        direction > 0 ? "forward" : "back",
        "from:",
        currentWeekStart.value.toISOString().split("T")[0],
        "to:",
        weekKey
    );

    // Simple page reload with new week parameter
    router.visit(route("visits.index"), {
        method: "get",
        data: {
            week_start: weekKey,
            search: search.value,
        },
        preserveState: false,
        preserveScroll: false,
        onStart: () => {
            console.log("Navigation started");
        },
        onError: (errors) => {
            console.error("Navigation failed:", errors);
            isTransitioning.value = false;
        },
        onFinish: () => {
            // Reset transitioning state when navigation completes
            isTransitioning.value = false;
        },
    });
};

// Go to today's week - simplified
const goToToday = () => {
    console.log("Going to today");

    // Simple page reload without week parameter
    router.visit(route("visits.index"), {
        method: "get",
        data: {
            search: search.value,
            // No week_start = current week
        },
        preserveState: false,
        preserveScroll: false,
    });
};

// Touch handlers for swipe navigation
const handleTouchStart = (e) => {
    touchStartX.value = e.touches[0].clientX;
};

const handleTouchMove = (e) => {
    touchEndX.value = e.touches[0].clientX;
};

const handleTouchEnd = () => {
    if (!touchStartX.value || !touchEndX.value) return;

    const distance = touchStartX.value - touchEndX.value;
    const isLeftSwipe = distance > 50;
    const isRightSwipe = distance < -50;

    if (isLeftSwipe) {
        navigateWeek(1); // Next week
    }
    if (isRightSwipe) {
        navigateWeek(-1); // Previous week
    }

    touchStartX.value = 0;
    touchEndX.value = 0;
};

// Group events by date (using Chicago timezone)
const eventsByDate = computed(() => {
    const grouped = {};

    weekDates.value.forEach((date) => {
        const dateStr = date.toISOString().split("T")[0];
        grouped[dateStr] = [];
    });

    if (props.events) {
        props.events.forEach((event) => {
            const eventDate = new Date(event.start || event.date);

            // Get the date in Chicago timezone for proper day grouping
            const chicagoDateStr = new Intl.DateTimeFormat("en-CA", {
                timeZone: "America/Chicago",
                year: "numeric",
                month: "2-digit",
                day: "2-digit",
            }).format(eventDate);

            if (grouped[chicagoDateStr]) {
                grouped[chicagoDateStr].push({
                    ...event,
                    time: eventDate.toLocaleTimeString("en-US", {
                        hour: "numeric",
                        minute: "2-digit",
                        hour12: true,
                        timeZone: "America/Chicago",
                    }),
                });
            }
        });
    }

    // Sort events by time within each day
    Object.keys(grouped).forEach((date) => {
        grouped[date].sort((a, b) => {
            const timeA = new Date(a.start || a.date);
            const timeB = new Date(b.start || b.date);
            return timeA - timeB;
        });
    });

    return grouped;
});

// Get status color classes
const getStatusColor = (event) => {
    if (event.is_complete) {
        return "bg-green-50 border-green-200 hover:bg-green-100";
    }

    const eventDate = new Date(event.start || event.date);

    // Get event date in Chicago timezone
    const eventChicagoStr = new Intl.DateTimeFormat("en-CA", {
        timeZone: "America/Chicago",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    }).format(eventDate);

    // Get today in Chicago timezone
    const todayChicagoStr = new Intl.DateTimeFormat("en-CA", {
        timeZone: "America/Chicago",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    }).format(new Date());

    if (eventChicagoStr < todayChicagoStr) {
        return "bg-red-50 border-red-200 hover:bg-red-100";
    } else if (eventChicagoStr === todayChicagoStr) {
        return "bg-blue-50 border-blue-200 hover:bg-blue-100";
    } else {
        return "bg-gray-50 border-gray-200 hover:bg-gray-100";
    }
};

// Get status icon
const getStatusIcon = (event) => {
    if (event.is_complete) {
        return CheckCircle2;
    }

    const eventDate = new Date(event.start || event.date);

    // Get event date in Chicago timezone
    const eventChicagoStr = new Intl.DateTimeFormat("en-CA", {
        timeZone: "America/Chicago",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    }).format(eventDate);

    // Get today in Chicago timezone
    const todayChicagoStr = new Intl.DateTimeFormat("en-CA", {
        timeZone: "America/Chicago",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    }).format(new Date());

    if (eventChicagoStr < todayChicagoStr) {
        return AlertCircle;
    } else {
        return Clock;
    }
};

// Get status icon color
const getStatusIconColor = (event) => {
    if (event.is_complete) {
        return "text-green-600";
    }

    const eventDate = new Date(event.start || event.date);

    // Get event date in Chicago timezone
    const eventChicagoStr = new Intl.DateTimeFormat("en-CA", {
        timeZone: "America/Chicago",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    }).format(eventDate);

    // Get today in Chicago timezone
    const todayChicagoStr = new Intl.DateTimeFormat("en-CA", {
        timeZone: "America/Chicago",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    }).format(new Date());

    if (eventChicagoStr < todayChicagoStr) {
        return "text-red-600";
    } else if (eventChicagoStr === todayChicagoStr) {
        return "text-blue-600";
    } else {
        return "text-gray-600";
    }
};

// Search functionality
const url = route("visits.index");
const search = ref(props.filters.search ?? "");

// Modal state management
const isModalOpen = ref(false);
const selectedEvent = ref(null);
const activeTab = ref("details");

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
const isSearchingLoading = ref(false);
const fileInput = ref(null);
const selectedClient = ref(null);
const clients = ref([]);
const customePhoneNumber = ref("");
const selectedContact = ref("");
const jobContacts = ref([]);

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
        fetchJobMessages(selectedEvent.value.id);
    }
};

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

const debouncedSearch = debounce(fetchClients, 700);
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

    const formData = new FormData();
    formData.append("messages", newMessage.value || "");
    formData.append("sender_number", senderPhoneNumber.value);

    selectedRecipients.value.forEach((recipient, index) => {
        formData.append(`receiver_numbers[${index}]`, recipient.phone);
    });

    if (customePhoneNumber.value.trim() !== "") {
        formData.append(
            `receiver_numbers[${selectedRecipients.value.length}]`,
            customePhoneNumber.value.trim()
        );
    }

    formData.append("jobber_id", selectedEvent.value.job.id);

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

            const timestamp = new Date().toISOString();
            const newMessages = selectedRecipients.value.map((recipient) => ({
                id: Date.now() + Math.random(),
                message: newMessage.value || "",
                sender_number: senderPhoneNumber.value,
                receiver_number: recipient.phone,
                image: imagePreview.value,
                created_at: timestamp,
                jobber_id: selectedEvent.value.job.id,
            }));

            jobMessages.value = [...newMessages, ...jobMessages.value];
            newMessage.value = "";
            removeImage();
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

const saveContactsForJob = async () => {
    if (!selectedEvent.value || selectedRecipients.value.length === 0) return;

    try {
        await axios.post(
            route("client-contacts.store", {
                jobber: selectedEvent.value.job.id,
            }),
            {
                contacts: selectedRecipients.value.map((recipient) => ({
                    name: recipient.name || recipient.phone,
                    phone: recipient.phone,
                })),
            }
        );
    } catch (error) {
        console.error("Error saving contacts:", error);
    }
};

watch(selectedContact, (newContactId) => {
    if (newContactId) {
        const foundContact = jobContacts.value.find(
            (contact) => contact.id == newContactId
        );
        contactPhoneNumber.value = foundContact ? foundContact.phone : "";
    }
});

let messageController = null;
const fetchMessages = async () => {
    if (!selectedEvent.value?.job?.id) return;

    if (messageController) {
        messageController.abort();
    }

    messageController = new AbortController();
    isLoadingMessages.value = true;

    try {
        const response = await fetch(
            route("jobber-text-messages.index", {
                job_id: selectedEvent.value.job.id,
            }),
            { signal: messageController.signal }
        );
        const data = await response.json();
        jobMessages.value = data.messages || [];
    } catch (error) {
        if (error.name !== "AbortError") {
            console.error("Error fetching messages:", error);
            jobMessages.value = [];
        }
    } finally {
        isLoadingMessages.value = false;
        messageController = null;
    }
};

const fetchJobMessages = async (visitId) => {
    try {
        isLoadingMessages.value = true;
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

const handleImageSelect = async (event) => {
    const file = event.target.files[0];
    if (!file) return;

    if (!file.type.startsWith("image/")) {
        toast({
            title: "Invalid file type",
            description: "Please select an image file.",
            variant: "destructive",
        });
        return;
    }

    if (file.size > 5 * 1024 * 1024) {
        toast({
            title: "File too large",
            description: "Please select an image under 5MB.",
            variant: "destructive",
        });
        return;
    }

    selectedImage.value = file;

    if (imagePreview.value) {
        URL.revokeObjectURL(imagePreview.value);
    }
    imagePreview.value = URL.createObjectURL(file);
};

const removeImage = () => {
    if (imagePreview.value) {
        URL.revokeObjectURL(imagePreview.value);
    }
    selectedImage.value = null;
    imagePreview.value = null;
};

const triggerFileInput = () => {
    fileInput.value?.click();
};

const openEventModal = async (event) => {
    activeTab.value = "details";
    selectedEvent.value = event;
    isModalOpen.value = true;

    // Load full event details including messages on demand
    if (event?.id) {
        try {
            const response = await fetch(route("visits.details", event.id));
            const fullEventData = await response.json();

            // Merge full data with current event
            selectedEvent.value = {
                ...event,
                ...fullEventData,
                job: {
                    ...event.job,
                    ...fullEventData.job,
                },
            };

            // Update text messages
            if (fullEventData.text_messages) {
                jobMessages.value = fullEventData.text_messages;
            }
        } catch (error) {
            console.error("Failed to load event details:", error);
        }
    }
};

const closeEventModal = () => {
    isModalOpen.value = false;
    if (messageController) {
        messageController.abort();
    }
    requestAnimationFrame(() => {
        selectedEvent.value = null;
        selectedRecipients.value = [];
        newMessage.value = "";
        selectedImage.value = null;
        imagePreview.value = null;
        jobMessages.value = [];
    });
};

// WebSocket listeners for real-time updates
useEchoPublic("visits", "VisitDeleted", (e) => {
    // Trigger a refresh or remove the event from the list
    router.reload({ only: ["events"] });
});

useEchoPublic("visits", "VisitUpdated", (e) => {
    // Trigger a refresh to get updated data
    router.reload({ only: ["events"] });
});

// Initialize on mount
onMounted(() => {
    initializeWeek();
});
</script>

<template>
    <Head :title="title" />

    <!-- Search and Navigation -->
    <div class="flex gap-3 flex-col sm:flex-row items-center justify-between">
        <SearchBar :url="url" v-model="search" class="w-full" />
        <Navigation />
    </div>

    <!-- Week Navigation Header -->
    <div class="bg-background border rounded-lg p-4">
        <div class="flex items-center justify-between">
            <!-- Previous Week Button -->
            <Button
                variant="ghost"
                size="icon"
                @click="navigateWeek(-1)"
                :disabled="isTransitioning"
                class="hover:bg-accent"
            >
                <ChevronLeft class="h-5 w-5" />
            </Button>

            <!-- Week Range and Today Button -->
            <div class="flex items-center gap-3">
                <h2 class="text-lg font-semibold">{{ weekRangeText }}</h2>
                <Button
                    v-if="!isCurrentWeek"
                    size="sm"
                    @click="goToToday"
                    class="text-xs"
                >
                    Today
                </Button>
            </div>

            <!-- Next Week Button -->
            <Button
                variant="ghost"
                size="icon"
                @click="navigateWeek(1)"
                :disabled="isTransitioning"
                class="hover:bg-accent"
            >
                <ChevronRight class="h-5 w-5" />
            </Button>
        </div>
    </div>

    <!-- Week View Container -->
    <div
        ref="weekContainer"
        class="bg-background border rounded-lg overflow-hidden transition-all duration-300 relative"
        @touchstart="handleTouchStart"
        @touchmove="handleTouchMove"
        @touchend="handleTouchEnd"
    >
        <!-- Days Header -->
        <div class="grid grid-cols-7 border-b bg-muted/30">
            <div
                v-for="date in weekDates"
                :key="date.toISOString()"
                :class="[
                    'p-3 text-center border-r last:border-r-0',
                    isToday(date) ? 'bg-primary/10' : '',
                ]"
            >
                <div class="font-medium text-sm">
                    {{ date.toLocaleDateString("en-US", { weekday: "short" }) }}
                </div>
                <div
                    :class="[
                        'text-lg',
                        isToday(date) ? 'font-bold text-primary' : '',
                    ]"
                >
                    {{ date.getDate() }}
                </div>
                <div class="text-xs text-muted-foreground">
                    {{ date.toLocaleDateString("en-US", { month: "short" }) }}
                </div>
            </div>
        </div>

        <!-- Days Content -->
        <div class="grid grid-cols-7 min-h-[500px]">
            <div
                v-for="date in weekDates"
                :key="date.toISOString()"
                :class="[
                    'border-r last:border-r-0 p-2 overflow-y-auto max-h-[600px]',
                    isToday(date) ? 'bg-primary/5' : '',
                ]"
            >
                <!-- Visit Cards -->
                <div class="space-y-2">
                    <Card
                        v-for="event in eventsByDate[
                            date.toISOString().split('T')[0]
                        ]"
                        :key="event.id"
                        :class="[
                            'cursor-pointer transition-all hover:shadow-md',
                            getStatusColor(event),
                            event.job.message_count > 0
                                ? 'bg-green-50 border-green-200 hover:bg-green-100'
                                : null,
                        ]"
                        @click="openEventModal(event)"
                    >
                        <CardContent class="p-3">
                            <!-- Time and Status -->
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-xs font-semibold">
                                    Job #{{ event.job?.job_number }}
                                </span>
                                <component
                                    :is="getStatusIcon(event)"
                                    :class="[
                                        'h-4 w-4',
                                        getStatusIconColor(event),
                                    ]"
                                />
                            </div>

                            <!-- Title -->
                            <div class="font-medium text-sm line-clamp-2 mb-2">
                                {{ event.title || event.summary }}
                            </div>

                            <!-- Location -->
                            <div class="flex items-start gap-1">
                                <MapPin
                                    class="h-3 w-3 text-muted-foreground mt-0.5"
                                />
                                <span
                                    class="text-xs text-muted-foreground line-clamp-2"
                                >
                                    {{ event.teamMember || event.assignedTo }}
                                </span>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Empty State -->
                    <div
                        v-if="
                            !eventsByDate[date.toISOString().split('T')[0]]
                                ?.length
                        "
                        class="text-center py-8 text-muted-foreground"
                    >
                        <Calendar class="h-8 w-8 mx-auto mb-2 opacity-20" />
                        <p class="text-xs">No visits</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Event Details Modal (keeping the same modal functionality) -->
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
                                {{ selectedEvent.text_messages_count }}
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

                <div class="space-y-1">
                    <div class="flex items-center gap-2">
                        <MapPin class="h-4 w-4 text-muted-foreground" />
                        <span class="font-medium">Location</span>
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
                            <Input
                                v-model="customePhoneNumber"
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
                                :messages="jobMessages.slice(0, 50)"
                                :sender="senderPhoneNumber"
                            />
                            <div
                                v-if="jobMessages.length > 50"
                                class="text-center py-2 text-sm text-muted-foreground"
                            >
                                Showing first 50 messages of
                                {{ jobMessages.length }}
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
/* Smooth transitions */
.transition-all {
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

/* Card hover effects */
.hover\:shadow-md:hover {
    box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
}

/* Scrollbar styling for week columns */
::-webkit-scrollbar {
    width: 6px;
}

::-webkit-scrollbar-track {
    background: transparent;
}

::-webkit-scrollbar-thumb {
    background: hsl(var(--muted-foreground) / 0.3);
    border-radius: 3px;
}

::-webkit-scrollbar-thumb:hover {
    background: hsl(var(--muted-foreground) / 0.5);
}

/* Line clamp utilities */
.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

/* Mobile responsiveness */
@media (max-width: 768px) {
    .grid-cols-7 {
        grid-template-columns: repeat(7, minmax(80px, 1fr));
        overflow-x: auto;
    }
}
</style>
