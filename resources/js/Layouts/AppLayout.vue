<script setup>
import { ref, onMounted, onUnmounted, computed } from "vue";
import GlobalSearch from "@/Components/GlobalSearch.vue";
import { router } from "@inertiajs/vue3";
import { usePage } from "@inertiajs/vue3";
import { Icon } from "@iconify/vue";
import { useColorMode } from "@vueuse/core";
import axios from "axios";
import { useToast } from "@/Components/ui/toast/use-toast";
import WorkOrderModal from "@/Components/WorkOrder/WorkOrderModal.vue";
import VendorMobileNav from "@/Components/VendorMobileNav.vue";
import { useWorkOrderModal } from "@/composables/useWorkOrderModal";

const { toast } = useToast();
const { open: openWorkOrderModal } = useWorkOrderModal();

import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from "@/Components/ui/popover";
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarInset,
    SidebarMenu,
    SidebarMenuAction,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
    SidebarProvider,
    SidebarRail,
    SidebarTrigger,
} from "@/Components/ui/sidebar";

import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from "@/Components/ui/dropdown-menu";

import { Button } from "@/Components/ui/button";
import { Separator } from "@/Components/ui/separator";

import {
    BellRing,
    BadgeCheck,
    ChevronRight,
    ChevronsUpDown,
    LogOut,
    UserRoundCog,
    Phone,
    LayoutTemplate,
    GlobeLock,
    Settings,
    CalendarDays,
    LayoutDashboard,
    XIcon,
    UserRoundCheck,
    Circle,
    Files,
    ClipboardList,
    Wrench,
    BotMessageSquare,
    BadgeAlert,
    Bell,
    Users2,
    Globe2,
    Globe,
    Handshake,
    Warehouse,
    BookOpen,
    PaperclipIcon,
    SendIcon,
    Loader2Icon,
    HammerIcon,
    FileIcon,
    WrenchIcon,
    Briefcase,
    Search,
    AlertTriangleIcon,
    ExternalLink,
    Check,
    Undo2,
    Reply,
    Info,
    ChartBarBig,
} from "lucide-vue-next";
import MessageCard from "@/Components/MessageCard.vue";
import BoardSummaryDialog from "@/Components/WorkOrder/BoardSummaryDialog.vue";
import { friendlyTwilioError } from "@/utils/twilioErrorCatalog.js";

const page = usePage();

// The work order boards that offer an AI summary. Prefix-matched, so the
// board's own filters in the query string do not stop it resolving. The main
// board is matched exactly instead — /work_orders/{id}/details and the
// coordinators page share its prefix but are not boards.
const SUMMARY_BOARDS = [
    ["/work_orders/inspections", "inspections"],
    ["/work_orders/lawn_service", "lawn_service"],
    ["/work_orders/turnovers", "turnovers"],
    ["/work_orders/waiting_on_payment", "waiting_on_payment"],
    ["/work_orders/closed", "closed"],
    ["/work_orders/paid", "paid"],
    ["/work_orders/hoa", "hoa"],
];

// Only the staff who work the boards; the endpoint enforces this too.
const canSeeSummary = computed(() => {
    const userRoles = page.props.auth.user.roles || [];

    return ["admin", "woc", "accounting"].some((role) =>
        userRoles.includes(role),
    );
});

const summaryBoard = computed(() => {
    if (!canSeeSummary.value) return null;

    // Drop the query string so a filtered board still resolves to its key.
    const path = page.url.split("?")[0].replace(/\/$/, "");
    const match = SUMMARY_BOARDS.find(([prefix]) => path.startsWith(prefix));

    if (match) return match[1];

    return path === "/work_orders" ? "main" : null;
});

const data = computed(() => ({
    user: {
        name: page.props.auth.user.name,
        email: page.props.auth.user.email,
        avatar: page.props.auth.user.profile_photo_url,
    },
    teams: [
        {
            name: "Texas Renters",
            logo: "/tx-portal-logo.png",
            plan: "",
        },
    ],
}));

const navs = computed(() => {
    const userRoles = page.props.auth.user.roles || [];
    const isAccounting = userRoles.includes("accounting");

    return {
        navMain: [
            {
                title: "Work Orders",
                url: "#",
                icon: Wrench,
                isActive: page.url.startsWith("/work_orders"),
                items: isAccounting
                    ? [
                          {
                              title: "Waiting on Payment",
                              url: route("work_orders.waiting_on_payment"),
                              isActive:
                                  page.component ===
                                  "WorkOrder/WaitingOnPayment",
                          },
                          {
                              title: "Paid",
                              url: route("work_orders.paid"),
                              isActive: page.component === "WorkOrder/Paid",
                          },
                          {
                              title: "Completed",
                              url: route("work_orders.closed_work_orders"),
                              isActive: page.component === "WorkOrder/Close",
                          },
                      ]
                    : [
                          {
                              title: "Active",
                              url: route("work_orders.index"),
                              isActive: page.component === "WorkOrder/Index",
                          },
                          {
                              title: "Inspections",
                              url: route("work_orders.inspections"),
                              isActive: page.url.startsWith(
                                  "/work_orders/inspections"
                              ),
                          },
                          {
                              title: "Lawn Service",
                              url: route("work_orders.lawn_service"),
                              isActive: page.url.startsWith(
                                  "/work_orders/lawn_service"
                              ),
                          },
                          {
                              title: "Turnovers",
                              url: route("work_orders.turnovers"),
                              isActive: page.url.startsWith(
                                  "/work_orders/turnovers"
                              ),
                          },
                          {
                              title: "HOA Violations",
                              url: route("work_orders.hoa"),
                              isActive: page.url.startsWith(
                                  "/work_orders/hoa"
                              ),
                          },
                          {
                              title: "Completed",
                              url: route("work_orders.closed_work_orders"),
                              isActive: page.component === "WorkOrder/Close",
                          },
                      ],
                // Vendors get a single "Work Orders" link (in `menu`) instead of
                // this split group, so they are intentionally excluded here.
                requires: ["admin", "woc", "tenant", "owner", "accounting"],
            },
            {
                title: "Jobs (Jobber)",
                url: "#",
                icon: Briefcase,
                isActive:
                    page.url.startsWith("/inspections") ||
                    page.url.startsWith("/visits"),
                items: [
                    {
                        title: "All Jobs",
                        url: route("inspections.index"),
                        isActive: page.url.startsWith("/inspections"),
                    },
                    {
                        title: "Visits",
                        url: route("visits.index"),
                        isActive: page.url.startsWith("/visits"),
                    },
                ],
                // THMP vendor keeps access via the email bypass in canAccess(); other vendors don't.
                requires: ["admin", "woc"],
            },
            {
                title: "People",
                url: "#",
                icon: Users2,
                isActive:
                    page.url.startsWith("/WorkOrder/Coordinators") ||
                    page.url.startsWith("/vendors") ||
                    page.url.startsWith("/owners") ||
                    page.url.startsWith("/tenants") ||
                    page.url.startsWith("/fallback_vendors"),
                items: [
                    {
                        title: "Coordinators",
                        url: route("work_orders.coordinators"),
                        isActive: page.url.startsWith("/WorkOrder/Coordinators"),
                    },
                    {
                        title: "Vendors",
                        url: route("vendors.index"),
                        isActive: page.url.startsWith("/vendors"),
                    },
                    {
                        title: "Owners",
                        url: route("owners.index"),
                        isActive: page.url.startsWith("/owners"),
                    },
                    {
                        title: "Tenants",
                        url: route("tenants.index"),
                        isActive: page.url.startsWith("/tenants"),
                    },
                    {
                        title: "Fallback Vendors",
                        url: route("fallback_vendors.index"),
                        isActive: page.url.startsWith("/fallback_vendors"),
                    },
                ],
                requires: ["admin", "woc"],
            },
            {
                title: "Messages",
                url: "#",
                icon: Globe,
                isActive:
                    page.component === "ConversationLogs" ||
                    page.component === "TwilioMessageSearch",
                items: [
                    {
                        title: "Conversations",
                        url: route("conversation_logs.index"),
                        isActive: page.component === "ConversationLogs",
                    },
                    {
                        title: "Search Twilio",
                        url: route("twilio_messages.search"),
                        isActive: page.component === "TwilioMessageSearch",
                    },
                ],
                requires: ["admin", "woc"],
            },
        ],
        menu: [
            {
                name: "Dashboard",
                url: route("dashboard"),
                isActive: page.url.startsWith("/dashboard"),
                icon: LayoutDashboard,
                requires: ["admin", "woc", "vendor", "owner", "tenant"],
            },
            {
                name: "Work Orders",
                url: route("work_orders.vendor"),
                isActive: page.component === "WorkOrder/VendorWorkOrders",
                icon: Wrench,
                requires: ["vendor"],
            },
            // Jobber jobs (non-TexasRenters clients) assigned to this vendor.
            // Scoped to their own jobs — unlike the staff "Jobs (Jobber)" board.
            {
                name: "Jobs",
                url: route("jobber.vendor_jobs"),
                isActive: page.component === "Inspection/VendorJobs",
                icon: Briefcase,
                requires: ["vendor"],
            },
        ],
        menu2: [
            {
                name: "Tasks",
                url: route("tasks.index"),
                isActive: page.url.startsWith("/tasks"),
                icon: ClipboardList,
                requires: ["admin", "woc", "vendor"],
            },

            {
                name: "Schedules",
                url: route("scheduled_service"),
                isActive: page.url.startsWith("/scheduled_service"),
                icon: CalendarDays,
                requires: ["admin", "woc", "vendor", "owner"],
            },
            {
                name: "Invoices",
                url: route("invoices.index"),
                isActive: page.url.startsWith("/work_order/invoices"),
                icon: Files,
                requires: ["admin", "woc", "vendor", "owner"],
            },
            {
                name: "Buildings",
                url: route("buildings.index"),
                isActive: page.url.startsWith("/buildings"),
                icon: Warehouse,
                requires: ["admin", "woc"],
            },
        ],
        reports: {
            title: "Reports",
            icon: ChartBarBig,
            isActive: page.url.startsWith("/reports"),
            requires: ["admin", "woc"],
            items: [
                {
                    title: "Unresolved in 7 Days",
                    url: route("reports.unresolved_7_days"),
                    isActive: page.url.startsWith("/reports/unresolved-7-days"),
                },
                {
                    title: "Not Scheduled in 3 Days",
                    url: route("reports.not_scheduled_3_days"),
                    isActive: page.url.startsWith("/reports/not-scheduled-3-days"),
                },
                {
                    title: "Tasks On Time",
                    url: route("reports.tasks_on_time"),
                    isActive: page.url.startsWith("/reports/tasks-on-time"),
                },
                {
                    title: "Open Over 30 Days",
                    url: route("reports.open_over_30_days"),
                    isActive: page.url.startsWith("/reports/open-over-30-days"),
                },
            ],
        },
        settings: [
            {
                name: "Task Templates",
                url: route("task_templates.index"),
                isActive: page.url.startsWith("/task_templates"),
                icon: LayoutTemplate,
            },

            {
                name: "Service Status",
                url: route("service_status.index"),
                isActive: page.url.startsWith("/service_status"),
                icon: Circle,
            },
            {
                name: "WOC Numbers",
                url: route("woc_numbers.index"),
                isActive: page.url.startsWith("/woc_numbers"),
                icon: UserRoundCheck,
            },
            {
                name: "Twilio Numbers",
                url: route("twilio_numbers.index"),
                isActive: page.url.startsWith("/twilio_numbers"),
                icon: Phone,
            },

            {
                name: "Users",
                url: route("users.index"),
                isActive: page.url.startsWith("/users"),
                icon: UserRoundCog,
            },
        ],
    };
});
const activeTeam = ref(data.value.teams[0]);
const logout = () => {
    router.post(route("logout"));
};

const canAccess = (requiredRoles) => {
    // Ensure roles are valid arrays
    const userRoles = page.props.auth.user.roles || []; // Default to an empty array if undefined
    requiredRoles = requiredRoles || []; // Default to an empty array if undefined

    // Special access for specific vendor email
    const userEmail = page.props.auth.user.email;
    if (userEmail === "service@txhomemp.com") {
        return true;
    }

    // Use filter to find matching roles
    const matchingRoles = userRoles.filter((role) =>
        requiredRoles.includes(role),
    );

    // Return true if there are any matches, otherwise false
    return matchingRoles.length > 0;
};

const searchRef = ref(null);
const openSearch = () => searchRef.value?.open();

const notifications = ref([]);
const markingAsRead = ref(new Set()); // Track which notifications are being marked as read
let intervalId = null;

// Computed property to count unread notifications
const unreadCount = computed(() => {
    return notifications.value.filter((n) => !n.read).length;
});

const fetchNotifications = async () => {
    try {
        const response = await axios.get("/notifications");
        notifications.value = response.data;
    } catch (error) {
        console.error("Failed to fetch notifications:", error);
    }
};

// Mark notification as read
const markAsRead = async (notificationId) => {
    try {
        // Find the notification
        const notification = notifications.value.find(
            (n) => n.id === notificationId,
        );
        if (
            !notification ||
            notification.read ||
            markingAsRead.value.has(notificationId)
        )
            return; // Already read, not found, or in progress

        // Add to marking set to prevent double clicks
        markingAsRead.value.add(notificationId);

        // Send API request to mark as read
        await axios.put(`/api/notifications/${notificationId}/mark-as-read`);

        // Update the notification's read status instead of removing it
        const index = notifications.value.findIndex(
            (n) => n.id === notificationId,
        );
        if (index !== -1) {
            notifications.value[index].read = true;
        }

        toast({
            title: "Success",
            description: "Notification marked as read!",
        });
    } catch (error) {
        console.error("Failed to mark notification as read:", error);
        toast({
            variant: "destructive",
            title: "Error",
            description: "Failed to mark notification as read",
        });
    } finally {
        // Remove from marking set
        markingAsRead.value.delete(notificationId);
    }
};

// Mark notification as unread
const markAsUnread = async (notificationId) => {
    try {
        const notification = notifications.value.find(
            (n) => n.id === notificationId,
        );
        if (
            !notification ||
            !notification.read ||
            markingAsRead.value.has(notificationId)
        )
            return; // Already unread, not found, or in progress

        markingAsRead.value.add(notificationId);

        await axios.put(`/api/notifications/${notificationId}/mark-as-unread`);

        const index = notifications.value.findIndex(
            (n) => n.id === notificationId,
        );
        if (index !== -1) {
            notifications.value[index].read = false;
        }

        toast({
            title: "Success",
            description: "Notification marked as unread!",
        });
    } catch (error) {
        console.error("Failed to mark notification as unread:", error);
        toast({
            variant: "destructive",
            title: "Error",
            description: "Failed to mark notification as unread",
        });
    } finally {
        markingAsRead.value.delete(notificationId);
    }
};

const openModal = ref(false);
const openFailedModal = ref(false);
const failedNotification = ref(null);
const isLoading = ref(false);
const MAX_MESSAGE_LENGTH = 1600;
const messageBody = ref("");
const workOrderNo = ref("");
const includeRefSuffix = ref(true);
const refSuffix = computed(() =>
    workOrderNo.value ? ` (Ref: WO#${workOrderNo.value})` : ""
);
const activeRefSuffix = computed(() =>
    includeRefSuffix.value ? refSuffix.value : ""
);
const maxBodyLength = computed(() =>
    Math.max(0, MAX_MESSAGE_LENGTH - activeRefSuffix.value.length)
);
const displayMessage = computed({
    get: () => `${messageBody.value}${activeRefSuffix.value}`,
    set: (value) => {
        const suffix = refSuffix.value;
        const withSuffixLimit = Math.max(0, MAX_MESSAGE_LENGTH - suffix.length);
        if (!suffix) {
            messageBody.value = value.slice(0, MAX_MESSAGE_LENGTH);
            return;
        }
        if (value.endsWith(suffix)) {
            includeRefSuffix.value = true;
            messageBody.value = value
                .slice(0, -suffix.length)
                .slice(0, withSuffixLimit);
            return;
        }
        if (value.includes(suffix)) {
            includeRefSuffix.value = true;
            messageBody.value = value
                .replace(suffix, "")
                .slice(0, withSuffixLimit);
            return;
        }
        includeRefSuffix.value = false;
        messageBody.value = value.slice(0, MAX_MESSAGE_LENGTH);
    },
});
const quickMessageCount = computed(() => displayMessage.value.length);
const conversations = ref([]);
const receiver_number = ref("");
const sender_number = ref("");
const conversation_type = ref("");
const reference_id = ref("");
const notif = ref(null);

const handleFailedModal = (notification) => {
    failedNotification.value = notification;
    openFailedModal.value = true;
};

const openNotificationTarget = (notification) => {
    if (notification.work_order_id) {
        // Show the work order in the reusable modal instead of navigating away.
        openWorkOrderModal(notification.work_order_id);
        return;
    }

    if (notification.job_id) {
        router.visit(
            route("jobber.jobDetails", { job: notification.job_id }),
        );
    }
};

const handleChatModal = async (model, shouldReverse = true) => {
    const response = await axios.post(route("work_order.notification_messages"), {
        data: model,
    });

    conversations.value = response.data;
    // shouldReverse=true: inbound notification (client sent to us, so receiver = our number)
    // shouldReverse=false: outbound/failed notification (we sent, so sender = our number)
    sender_number.value = shouldReverse ? model.receiver_number : model.sender_number;
    receiver_number.value = shouldReverse ? model.sender_number : model.receiver_number;
    conversation_type.value = model?.conversation_type ?? "job";
    reference_id.value = model?.work_order_id ?? model?.jobber_id;
    notif.value = model;
    includeRefSuffix.value = true;
    workOrderNo.value =
        response.data?.[0]?.work_order?.work_order_no ??
        model?.work_order_no ??
        "";
    openModal.value = true;
};
const loading = ref(false);
const selectedImages = ref([]);
const fileInput = ref(null);

const MAX_ATTACHMENT_BYTES = 50 * 1024 * 1024; // 50MB — matches backend validation

const handleImageSelect = (event) => {
    const files = Array.from(event.target.files || []);
    files.forEach((file) => {
        const isImage = file.type.startsWith("image/");
        const isVideo = file.type.startsWith("video/");
        const isPdf = file.type === "application/pdf";
        if (!isImage && !isVideo && !isPdf) {
            toast({
                variant: "destructive",
                title: "Invalid file type",
                description: "Please select an image, video, or PDF file",
            });
            return;
        }
        if (file.size > MAX_ATTACHMENT_BYTES) {
            toast({
                variant: "destructive",
                title: "File too large",
                description: `${file.name} exceeds 50MB limit`,
            });
            return;
        }
        // PDFs get a filename tile rather than a data-URL thumbnail — no need to
        // read (potentially large) bytes into memory just to preview.
        if (isPdf) {
            selectedImages.value.push({ file, preview: null, isPdf });
            return;
        }
        const reader = new FileReader();
        reader.onload = (e) => {
            selectedImages.value.push({
                file,
                preview: e.target.result,
                isVideo,
            });
        };
        reader.readAsDataURL(file);
    });
    if (fileInput.value) {
        fileInput.value.value = "";
    }
};

const removeImage = (index) => {
    selectedImages.value.splice(index, 1);
};

const triggerFileInput = () => {
    fileInput.value?.click();
};

const autoResize = (event) => {
    const textarea = event.target;
    textarea.style.height = "auto";
    textarea.style.height = Math.min(textarea.scrollHeight, 200) + "px";
};

const sendMessage = () => {
    loading.value = true;
    if (!receiver_number.value) {
        toast({
            variant: "destructive",
            title: "Uh oh! Something went wrong.",
            description:
                "There was a problem with your request. Please select a receiver!",
        });
        loading.value = false;

        return;
    }

    if (!messageBody.value.trim() && selectedImages.value.length === 0) {
        toast({
            variant: "destructive",
            title: "Uh oh! Something went wrong.",
            description: "Please type a message or attach an image!",
        });
        loading.value = false;

        return;
    }

    if (
        (messageBody.value.trim() !== "" || selectedImages.value.length > 0) &&
        conversation_type.value !== "job"
    ) {
        const formData = new FormData();
        formData.append("text", displayMessage.value.trim());
        formData.append("sender_phone_number", sender_number.value);
        formData.append("receiver_phone_number", receiver_number.value);
        formData.append("work_order_id", reference_id.value);
        formData.append("conversation_type", conversation_type.value);
        selectedImages.value.forEach((img) => {
            formData.append("images[]", img.file);
        });

        router.post(route("work_order.conversation.send"), formData, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                toast({
                    title: "Success",
                    description: "Message sent. Delivery may take a moment.",
                });
                messageBody.value = "";
                selectedImages.value = [];
                // Reset textarea height
                const textarea = document.querySelector(
                    'textarea[placeholder="Type your message..."]',
                );
                if (textarea) textarea.style.height = "auto";
                handleChatModal(notif.value);
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
    }

    if (
        (messageBody.value.trim() !== "" || selectedImages.value.length > 0) &&
        conversation_type.value === "job"
    ) {
        const formData = new FormData();
        formData.append("messages", messageBody.value.trim());
        formData.append("sender_number", sender_number.value);
        formData.append("receiver_numbers[]", receiver_number.value);
        formData.append("jobber_id", reference_id.value);
        formData.append("conversation_type", conversation_type.value);
        selectedImages.value.forEach((img) => {
            formData.append("images[]", img.file);
        });

        router.post(route("jobber-text-messages.store"), formData, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                toast({
                    title: "Success",
                    description: "Message has been sent successfully!",
                });
                messageBody.value = "";
                selectedImages.value = [];
                // Reset textarea height
                const textarea = document.querySelector(
                    'textarea[placeholder="Type your message..."]',
                );
                if (textarea) textarea.style.height = "auto";
                handleChatModal(notif.value);
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
    }
};

const closedJobStatuses = [
    "archived",
    "closed",
    "completed",
    "cancelled",
    "done",
];

const isJobClosed = (notification) => {
    if (!notification.subject?.jobber_id) return false;
    const status = notification.subject?.jobber?.job_status;
    return status ? closedJobStatuses.includes(status.toLowerCase()) : false;
};

const mode = useColorMode({ disableTransition: false });

const showBanner = ref(true);

// Close the banner when the close button is clicked
const closeBanner = () => {
    showBanner.value = false;
};

onMounted(() => {
    fetchNotifications(); // initial load
    // Set interval for every 5 minutes (300,000 ms)
    intervalId = setInterval(fetchNotifications, 5000);

    document.addEventListener("keydown", (e) => {
        if ((e.metaKey || e.ctrlKey) && e.key === "k") {
            e.preventDefault();
            openSearch();
        }
    });
});

onUnmounted(() => {
    if (intervalId) clearInterval(intervalId); // cleanup when component is destroyed
});
</script>

<template>
    <SidebarProvider>
        <Sidebar collapsible="icon">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <SidebarMenuButton
                                    size="md"
                                    class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                                >
                                    <div
                                        class="flex items-center justify-center rounded-lg text-sidebar-primary-foreground"
                                    >
                                        <img :src="activeTeam.logo" width="" />
                                    </div>
                                </SidebarMenuButton>
                            </DropdownMenuTrigger>
                        </DropdownMenu>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>
            <SidebarContent>
                <SidebarGroup>
                    <SidebarGroupLabel>Menu</SidebarGroupLabel>
                    <SidebarMenu>
                        <template v-for="item in navs.menu" :key="item?.name">
                            <SidebarMenuItem
                                v-if="
                                    item &&
                                    (!item.requires || canAccess(item.requires))
                                "
                            >
                                <SidebarMenuButton as-child>
                                    <Link
                                        :href="item.url"
                                        prefetch
                                        view-transition
                                        :class="{
                                            'font-bold border': item.isActive,
                                        }"
                                    >
                                        <component :is="item.icon" />
                                        <span>{{ item.name }}</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        </template>
                    </SidebarMenu>
                    <SidebarMenu class="mb-1">
                        <template
                            v-for="item in navs.navMain"
                            :key="item?.title"
                        >
                            <Collapsible
                                v-if="
                                    item &&
                                    (!item.requires || canAccess(item.requires))
                                "
                                as-child
                                :default-open="item.isActive"
                                :data-state="item.isActive"
                                class="group/collapsible"
                            >
                                <SidebarMenuItem>
                                    <CollapsibleTrigger as-child>
                                        <SidebarMenuButton
                                            :tooltip="item.title"
                                        >
                                            <component :is="item.icon" />
                                            <span>{{ item.title }}</span>
                                            <ChevronRight
                                                v-if="item.items.length > 0"
                                                class="ml-auto transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90"
                                            />
                                        </SidebarMenuButton>
                                    </CollapsibleTrigger>
                                    <CollapsibleContent>
                                        <SidebarMenuSub>
                                            <SidebarMenuSubItem
                                                v-for="subItem in item.items"
                                                :key="subItem?.title"
                                            >
                                                <SidebarMenuSubButton as-child>
                                                    <Link
                                                        :href="subItem.url"
                                                        prefetch
                                                        :class="{
                                                            'font-semibold p-2 border':
                                                                subItem.isActive,
                                                        }"
                                                    >
                                                        <span>{{
                                                            subItem.title
                                                        }}</span>
                                                    </Link>
                                                </SidebarMenuSubButton>
                                            </SidebarMenuSubItem>
                                        </SidebarMenuSub>
                                    </CollapsibleContent>
                                </SidebarMenuItem>
                            </Collapsible>
                        </template>
                    </SidebarMenu>
                    <SidebarMenu>
                        <template v-for="item in navs.menu2" :key="item?.name">
                            <SidebarMenuItem
                                v-if="
                                    item &&
                                    (!item.requires || canAccess(item.requires))
                                "
                            >
                                <SidebarMenuButton as-child>
                                    <Link
                                        :href="item.url"
                                        prefetch
                                        :class="{
                                            'font-bold border': item.isActive,
                                        }"
                                    >
                                        <component :is="item.icon" />
                                        <span>{{ item.name }}</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        </template>
                    </SidebarMenu>
                    <SidebarMenu
                        v-if="
                            !navs.reports.requires ||
                            canAccess(navs.reports.requires)
                        "
                    >
                        <Collapsible
                            as-child
                            :default-open="navs.reports.isActive"
                            :data-state="navs.reports.isActive"
                            class="group/collapsible"
                        >
                            <SidebarMenuItem>
                                <CollapsibleTrigger as-child>
                                    <SidebarMenuButton
                                        :tooltip="navs.reports.title"
                                    >
                                        <component :is="navs.reports.icon" />
                                        <span>{{ navs.reports.title }}</span>
                                        <ChevronRight
                                            class="ml-auto transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90"
                                        />
                                    </SidebarMenuButton>
                                </CollapsibleTrigger>
                                <CollapsibleContent>
                                    <SidebarMenuSub>
                                        <SidebarMenuSubItem
                                            v-for="subItem in navs.reports.items"
                                            :key="subItem.title"
                                        >
                                            <SidebarMenuSubButton as-child>
                                                <Link
                                                    :href="subItem.url"
                                                    prefetch
                                                    :class="{
                                                        'font-semibold p-2 border':
                                                            subItem.isActive,
                                                    }"
                                                >
                                                    <span>{{
                                                        subItem.title
                                                    }}</span>
                                                </Link>
                                            </SidebarMenuSubButton>
                                        </SidebarMenuSubItem>
                                    </SidebarMenuSub>
                                </CollapsibleContent>
                            </SidebarMenuItem>
                        </Collapsible>
                    </SidebarMenu>
                </SidebarGroup>
                <SidebarGroup
                    v-if="page.props.auth.user.roles.includes('admin')"
                >
                    <SidebarGroupLabel>Settings</SidebarGroupLabel>
                    <SidebarMenu>
                        <SidebarMenuItem
                            v-for="item in navs.settings"
                            :key="item.name"
                        >
                            <SidebarMenuButton as-child>
                                <Link
                                    :href="item.url"
                                    prefetch
                                    :class="{
                                        'font-bold border': item.isActive,
                                    }"
                                >
                                    <component :is="item.icon" />
                                    <span>{{ item.name }}</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                        <SidebarMenuItem>
                            <SidebarMenuButton as-child>
                                <a href="/log-viewer"><GlobeLock /> Logs</a>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                        <SidebarMenuItem>
                            <SidebarMenuButton as-child>
                                <Link href="/it-tools/jobber" prefetch>
                                    <Wrench />
                                    <span>IT Tools</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </SidebarGroup>
            </SidebarContent>
            <SidebarFooter>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <SidebarMenuButton
                                    size="lg"
                                    class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                                >
                                    <Avatar class="h-8 w-8 rounded-lg">
                                        <AvatarImage
                                            :src="data.user.avatar"
                                            :alt="data.user.name"
                                        />
                                        <AvatarFallback class="rounded-lg">
                                            CN
                                        </AvatarFallback>
                                    </Avatar>
                                    <div
                                        class="grid flex-1 text-left text-sm leading-tight"
                                    >
                                        <span class="truncate font-semibold">{{
                                            data.user.name
                                        }}</span>
                                        <span class="truncate text-xs">{{
                                            data.user.email
                                        }}</span>
                                    </div>
                                    <ChevronsUpDown class="ml-auto size-4" />
                                </SidebarMenuButton>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent
                                class="w-[--radix-dropdown-menu-trigger-width] min-w-56 rounded-lg"
                                side="bottom"
                                align="end"
                                :side-offset="4"
                            >
                                <DropdownMenuLabel class="p-0 font-normal">
                                    <div
                                        class="flex items-center gap-2 px-1 py-1.5 text-left text-sm"
                                    >
                                        <Avatar class="h-8 w-8 rounded-lg">
                                            <AvatarImage
                                                :src="data.user.avatar"
                                                :alt="data.user.name"
                                            />
                                            <AvatarFallback class="rounded-lg">
                                                CN
                                            </AvatarFallback>
                                        </Avatar>
                                        <div
                                            class="grid flex-1 text-left text-sm leading-tight"
                                        >
                                            <span
                                                class="truncate font-semibold"
                                                >{{ data.user.name }}</span
                                            >
                                            <span class="truncate text-xs">{{
                                                data.user.email
                                            }}</span>
                                        </div>
                                    </div>
                                </DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                <DropdownMenuGroup>
                                    <Link
                                        :href="route('profile.settings')"
                                        prefetch
                                    >
                                        <DropdownMenuItem
                                            class="cursor-pointer"
                                        >
                                            <BadgeCheck />
                                            Account
                                        </DropdownMenuItem>
                                    </Link>
                                </DropdownMenuGroup>
                                <DropdownMenuSeparator />
                                <DropdownMenuGroup>
                                    <Link
                                        :href="route('profile.show')"
                                        prefetch
                                    >
                                        <DropdownMenuItem
                                            class="cursor-pointer"
                                        >
                                            <Settings />
                                            Settings
                                        </DropdownMenuItem>
                                    </Link>
                                </DropdownMenuGroup>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem>
                                    <form
                                        @submit.prevent="logout"
                                        class="w-full"
                                    >
                                        <button
                                            type="submit"
                                            class="flex gap-2 w-full"
                                        >
                                            <LogOut class="w-4" />Log Out
                                        </button>
                                    </form>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarFooter>
            <SidebarRail />
        </Sidebar>
        <SidebarInset class="min-w-0">
            <div>
                <header
                    class="flex h-16 shrink-0 items-center gap-2 transition-[width,height] ease-linear group-has-[[data-collapsible=icon]]/sidebar-wrapper:h-12"
                >
                    <div class="flex justify-between w-full">
                        <BreadcrumbContainer :title="page.props.title" />
                        <div class="mr-5 flex gap-2">
                            <Button
                                @click="openSearch"
                                variant="icon"
                                aria-label="Search work orders"
                            >
                                <Search class="h-4 w-4" />
                            </Button>
                            <Popover>
                                <PopoverTrigger class="relative">
                                    <BellRing class="w-4 h-4" />
                                    <span
                                        v-if="unreadCount > 0"
                                        class="bg-destructive text-white px-1 rounded-full text-xs absolute top-1"
                                        >{{ unreadCount }}</span
                                    >
                                </PopoverTrigger>
                                <PopoverContent
                                    class="w-full sm:min-w-[24rem] sm:max-w-2xl mx-auto max-h-[calc(100vh-200px)] overflow-y-auto"
                                >
                                    <p
                                        class="uppercase text-xs font-bold flex gap-1"
                                    >
                                        <span v-if="notifications.length === 0"
                                            >No Notifications
                                        </span>
                                        <span v-else-if="unreadCount === 0"
                                            >All Notifications Read
                                        </span>
                                        <span v-else
                                            >{{ unreadCount }} New
                                            Notification{{
                                                unreadCount > 1 ? "s" : ""
                                            }}
                                        </span>
                                    </p>
                                    <template v-if="notifications.length > 0">
                                        <div class="flex flex-col gap-2">
                                            <h3 class="font-bold my-2">
                                                Notifications
                                            </h3>
                                            <template
                                                v-if="
                                                    notifications &&
                                                    notifications.length > 0
                                                "
                                            >
                                                <div
                                                    v-for="notification in notifications"
                                                    :key="notification.id"
                                                    class="flex items-start border gap-4 p-3 rounded-lg transition-all duration-200 ease-in-out cursor-pointer relative"
                                                    :class="{
                                                        'border-blue-400 shadow ':
                                                            !notification.read &&
                                                            !markingAsRead.has(
                                                                notification.id,
                                                            ),
                                                        ' ': notification.read,
                                                        'opacity-50 cursor-wait':
                                                            markingAsRead.has(
                                                                notification.id,
                                                            ),
                                                    }"
                                                    @click="
                                                        !notification.read &&
                                                        markAsRead(
                                                            notification.id,
                                                        )
                                                    "
                                                >
                                                    <!-- Unread indicator (optional) -->
                                                    <div
                                                        v-if="
                                                            !notification.read
                                                        "
                                                        class="absolute -top-1 -right-1 w-3 h-3 bg-blue-500 rounded-full border-2 border-white animate-pulse"
                                                        title="Unread Notification"
                                                        :class="{
                                                            'bg-blue-500':
                                                                notification.event ===
                                                                'work_order_received',
                                                            'bg-green-500':
                                                                notification.event ===
                                                                'job_message_received',
                                                            'bg-red-100 text-red-600':
                                                                notification.event ===
                                                                    'jobber_not_sent' ||
                                                                notification.event ===
                                                                    'message_undelivered',
                                                            'bg-yellow-500':
                                                                notification.event ===
                                                                    'invoice_uploaded' ||
                                                                notification.event ===
                                                                    'uploaded',
                                                        }"
                                                    ></div>

                                                    <!-- Icon placeholder -->
                                                    <div
                                                        class="flex-shrink-0 w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center text-blue-600"
                                                        :class="{
                                                            'bg-blue-100 text-blue-600':
                                                                notification.event ===
                                                                'work_order_received',
                                                            'bg-green-100 text-green-600':
                                                                notification.event ===
                                                                'job_message_received',
                                                            'bg-red-100 text-red-600':
                                                                notification.event ===
                                                                    'jobber_not_sent' ||
                                                                notification.event ===
                                                                    'message_undelivered',
                                                            'bg-yellow-100 text-yellow-600':
                                                                notification.event ===
                                                                    'invoice_uploaded' ||
                                                                notification.event ===
                                                                    'uploaded',
                                                        }"
                                                    >
                                                        <WrenchIcon
                                                            class="w-4 h-4"
                                                            v-if="
                                                                notification.event ===
                                                                'work_order_message_received'
                                                            "
                                                        />
                                                        <AlertTriangleIcon
                                                            class="w-4 h-4"
                                                            v-else-if="
                                                                notification.event ===
                                                                'message_undelivered'
                                                            "
                                                        />
                                                        <HammerIcon
                                                            class="w-4 h-4"
                                                            v-else-if="
                                                                notification.event ===
                                                                    'job_message_received' ||
                                                                notification.event ===
                                                                    'jobber_not_sent'
                                                            "
                                                        />
                                                        <FileIcon
                                                            class="w-4 h-4"
                                                            v-else-if="
                                                                notification.event ===
                                                                    'invoice_uploaded' ||
                                                                notification.event ===
                                                                    'uploaded'
                                                            "
                                                        />
                                                        <Bell
                                                            v-else
                                                            class="w-4 h-4"
                                                        />
                                                    </div>

                                                    <div class="flex-1">
                                                        <h4
                                                            class="font-semibold text-base leading-tight"
                                                        >
                                                            {{
                                                                notification.title
                                                            }}
                                                        </h4>
                                                        <p
                                                            class="text-sm mt-1 line-clamp-2"
                                                        >
                                                            {{
                                                                notification.message
                                                            }}
                                                        </p>
                                                        <div
                                                            class="text-xs flex justify-between items-center"
                                                        >
                                                            <p class="mr-2">
                                                                {{
                                                                    notification.time
                                                                }}
                                                            </p>
                                                            <div>
                                                                <Button
                                                                    size="sm"
                                                                    variant="link"
                                                                    class="text-xs"
                                                                    as="button"
                                                                    v-if="
                                                                        !notification.read
                                                                    "
                                                                    @click.prevent="
                                                                        markAsRead(
                                                                            notification.id,
                                                                        )
                                                                    "
                                                                    :disabled="
                                                                        markingAsRead.has(
                                                                            notification.id,
                                                                        )
                                                                    "
                                                                >
                                                                    <Check
                                                                        class="w-3 h-3"
                                                                    />
                                                                    {{
                                                                        markingAsRead.has(
                                                                            notification.id,
                                                                        )
                                                                            ? "Marking..."
                                                                            : "Mark as read"
                                                                    }}
                                                                </Button>
                                                                <Button
                                                                    size="sm"
                                                                    variant="link"
                                                                    class="text-xs text-yellow-600"
                                                                    as="button"
                                                                    v-if="
                                                                        notification.read
                                                                    "
                                                                    @click.prevent="
                                                                        markAsUnread(
                                                                            notification.id,
                                                                        )
                                                                    "
                                                                    :disabled="
                                                                        markingAsRead.has(
                                                                            notification.id,
                                                                        )
                                                                    "
                                                                >
                                                                    <Undo2
                                                                        class="w-3 h-3"
                                                                    />
                                                                    {{
                                                                        markingAsRead.has(
                                                                            notification.id,
                                                                        )
                                                                            ? "Marking..."
                                                                            : "Mark as unread"
                                                                    }}
                                                                </Button>
                                                                <Button
                                                                    v-if="
                                                                        notification.event ===
                                                                            'message_undelivered' ||
                                                                        notification.event ===
                                                                            'jobber_not_sent'
                                                                    "
                                                                    as="button"
                                                                    size="sm"
                                                                    variant="link"
                                                                    class="text-xs text-red-600"
                                                                    @click.stop.prevent="
                                                                        handleFailedModal(
                                                                            notification,
                                                                        )
                                                                    "
                                                                >
                                                                    <Info
                                                                        class="w-3 h-3"
                                                                    />
                                                                    View Details
                                                                </Button>
                                                                <Button
                                                                    v-else-if="
                                                                        (notification
                                                                            .subject
                                                                            ?.conversation_type ||
                                                                            notification
                                                                                .subject
                                                                                ?.jobber_id) &&
                                                                        !isJobClosed(
                                                                            notification,
                                                                        )
                                                                    "
                                                                    as="button"
                                                                    size="sm"
                                                                    variant="link"
                                                                    class="text-xs"
                                                                    @click.prevent="
                                                                        handleChatModal(
                                                                            notification.subject,
                                                                        )
                                                                    "
                                                                >
                                                                    <Reply
                                                                        class="w-3 h-3"
                                                                    />
                                                                    Reply
                                                                </Button>
                                                                <Button
                                                                    v-if="
                                                                        notification.work_order_id ||
                                                                        notification.job_id
                                                                    "
                                                                    as="button"
                                                                    size="sm"
                                                                    variant="link"
                                                                    class="text-xs"
                                                                    @click.stop.prevent="
                                                                        openNotificationTarget(
                                                                            notification,
                                                                        )
                                                                    "
                                                                >
                                                                    <ExternalLink
                                                                        class="w-3 h-3"
                                                                    />
                                                                    Open
                                                                </Button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </template>
                                            <template v-else>
                                                <div
                                                    class="p-6 text-center text-gray-500 bg-gray-50 rounded-lg shadow-sm mt-4"
                                                >
                                                    <p>No new notifications.</p>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                </PopoverContent>
                            </Popover>
                            <DropdownMenu
                                v-if="
                                    $page.props.auth.user.roles.includes(
                                        'admin',
                                    ) ||
                                    $page.props.auth.user.roles.includes('woc')
                                "
                            >
                                <DropdownMenuTrigger as-child>
                                    <Button variant="icon">
                                        <BookOpen class="w-4 h-4" />
                                        <span class="sr-only">User Guide</span>
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        v-if="
                                            page.props.auth.user.roles.includes(
                                                'vendor',
                                            )
                                        "
                                        @click="
                                            router.visit(route('guide.vendor'))
                                        "
                                    >
                                        Vendor Guide
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="
                                            page.props.auth.user.roles.includes(
                                                'woc',
                                            )
                                        "
                                        @click="
                                            router.visit(route('guide.woc'))
                                        "
                                    >
                                        Coordinator Guide
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="
                                            page.props.auth.user.roles.includes(
                                                'admin',
                                            )
                                        "
                                        @click="
                                            router.visit(route('guide.admin'))
                                        "
                                    >
                                        Admin Guide
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button variant="icon">
                                        <Icon
                                            icon="radix-icons:moon"
                                            class="h-[1.2rem] w-[1.2rem] rotate-0 scale-100 transition-all dark:-rotate-90 dark:scale-0"
                                        />
                                        <Icon
                                            icon="radix-icons:sun"
                                            class="absolute h-[1.2rem] w-[1.2rem] rotate-90 scale-0 transition-all dark:rotate-0 dark:scale-100"
                                        />
                                        <span class="sr-only"
                                            >Toggle theme</span
                                        >
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem @click="mode = 'light'">
                                        Light
                                    </DropdownMenuItem>
                                    <DropdownMenuItem @click="mode = 'dark'">
                                        Dark
                                    </DropdownMenuItem>
                                    <DropdownMenuItem @click="mode = 'auto'">
                                        System
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </div>
                </header>
            </div>
            <Separator />
            <div
                class="flex flex-1 flex-col gap-4 overflow-x-clip p-4 pt-4"
                :class="{ 'pb-24 md:pb-4': canAccess(['vendor']) }"
            >
                <Toaster />
                <slot />
            </div>

            <!--
                Floating summary button, pinned to the bottom-right of the board
                area. SidebarInset is relative, so this tracks the content column
                rather than the viewport as the sidebar collapses.
            -->
            <div v-if="summaryBoard" class="absolute bottom-6 right-6 z-40">
                <BoardSummaryDialog :key="summaryBoard" :board="summaryBoard" />
            </div>
        </SidebarInset>
    </SidebarProvider>

    <GlobalSearch ref="searchRef" />

    <Dialog v-model:open="openFailedModal">
        <DialogContent class="w-full !max-w-lg">
            <DialogHeader class="text-left">
                <DialogTitle class="text-xl text-red-600 flex items-center gap-2">
                    <AlertTriangleIcon class="w-5 h-5" />
                    Message Delivery Failed
                </DialogTitle>
                <DialogDescription>
                    The following message could not be delivered.
                </DialogDescription>
            </DialogHeader>
            <Separator />
            <div
                v-if="failedNotification"
                class="flex flex-col gap-3 py-2"
            >
                <div class="rounded-lg bg-red-50 border border-red-200 p-4 flex flex-col gap-2">
                    <p class="font-semibold text-sm text-red-700">
                        {{ failedNotification.title }}
                    </p>
                    <p class="text-sm text-gray-700">
                        <span class="font-medium">Message:</span>
                        {{ failedNotification.message }}
                    </p>
                    <p
                        v-if="failedNotification.twilio_status"
                        class="text-sm text-gray-700"
                    >
                        <span class="font-medium">Status:</span>
                        <span class="capitalize ml-1 text-red-600">{{
                            failedNotification.twilio_status
                        }}</span>
                    </p>
                    <p
                        v-if="
                            friendlyTwilioError(
                                failedNotification.error_code,
                                failedNotification.error_message,
                            )
                        "
                        class="text-sm text-gray-700"
                    >
                        <span class="font-medium">What this means:</span>
                        {{
                            friendlyTwilioError(
                                failedNotification.error_code,
                                failedNotification.error_message,
                            )
                        }}
                    </p>
                    <p
                        v-if="failedNotification.error_code"
                        class="text-xs text-gray-500"
                    >
                        <span class="font-medium">Twilio error code:</span>
                        {{ failedNotification.error_code }}
                    </p>
                    <p class="text-xs text-gray-400 mt-1">
                        {{ failedNotification.time }}
                    </p>
                </div>
                <div class="flex justify-end gap-2">
                    <Button
                        v-if="
                            failedNotification.subject?.conversation_type ||
                            failedNotification.subject?.jobber_id
                        "
                        size="sm"
                        variant="outline"
                        @click.prevent="
                            openFailedModal = false;
                            handleChatModal(failedNotification.subject, false);
                        "
                    >
                        View Conversation
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        @click.prevent="openFailedModal = false"
                    >
                        Close
                    </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="openModal">
        <DialogContent
            class="flex max-h-[90dvh] w-full !max-w-4xl grid-rows-[auto_minmax(0,1fr)_auto] flex-col p-0 md:max-w-2xl"
        >
            <DialogHeader class="p-6 pb-0 text-left">
                <DialogTitle class="text-2xl text-primary">
                    <p v-if="!isLoading">Quick Message Response</p>
                </DialogTitle>
                <DialogDescription> </DialogDescription>
            </DialogHeader>
            <Separator />
            <div class="grid gap-3 overflow-y-auto px-6">
                <div class="flex justify-between gap-2 mb-2">
                    <div class="flex flex-col text-left">
                        <p>Receiver:</p>
                        {{ receiver_number }}
                    </div>

                    <div class="flex flex-col text-left">
                        <p>Sender:</p>
                        {{ sender_number }}
                    </div>
                </div>
                <div
                    class="flex flex-col gap-4 overflow-y-auto"
                    ref="chatContainer"
                >
                    <ScrollArea
                        class="bg-secondary h-[50vh] max-h-[520px] min-h-[300px] rounded-md p-3"
                    >
                        <div class="flex justify-center" v-if="isLoading">
                            <Loader2Icon
                                class="w-12 h-12 animate-spin text-primary"
                            />
                        </div>
                        <MessageCard
                            v-else
                            :messages="conversations"
                            :sender="sender_number"
                        />
                    </ScrollArea>
                </div>

                <!-- Attachment Preview -->
                <div
                    v-if="selectedImages.length > 0"
                    class="mb-3 p-3 border rounded-lg bg-muted/20"
                >
                    <div class="flex flex-wrap gap-2">
                        <div
                            v-for="(img, index) in selectedImages"
                            :key="index"
                            class="relative"
                        >
                            <video
                                v-if="img.isVideo"
                                :src="img.preview"
                                class="w-20 h-20 object-cover rounded-lg border bg-black"
                                muted
                                playsinline
                            />
                            <div
                                v-else-if="img.isPdf"
                                class="w-20 h-20 flex flex-col items-center justify-center gap-1 rounded-lg border bg-muted p-1 text-center"
                            >
                                <FileIcon class="h-6 w-6 text-muted-foreground" />
                                <span class="w-full truncate text-[10px] text-muted-foreground">{{ img.file.name }}</span>
                            </div>
                            <img
                                v-else
                                :src="img.preview"
                                :alt="img.file.name"
                                class="w-20 h-20 object-cover rounded-lg border"
                            />
                            <Button
                                size="icon"
                                variant="destructive"
                                class="absolute -top-2 -right-2 h-6 w-6"
                                @click="removeImage(index)"
                            >
                                <XIcon class="h-3 w-3" />
                            </Button>
                        </div>
                    </div>
                    <p class="text-xs text-muted-foreground mt-2">
                        {{ selectedImages.length }} file{{
                            selectedImages.length > 1 ? "s" : ""
                        }}
                        selected
                    </p>
                </div>

                <!-- Hidden file input -->
                <input
                    ref="fileInput"
                    type="file"
                    accept="image/*,video/*,application/pdf"
                    multiple
                    @change="handleImageSelect"
                    class="hidden"
                />

                <!-- Message Input -->
                <div class="relative w-full mt-4 mb-6">
                    <Textarea
                        v-model="displayMessage"
                        placeholder="Type your message..."
                        class="w-full resize-none rounded-2xl border py-3 pr-24 min-h-[44px] max-h-[200px] overflow-y-auto"
                        rows="3"
                        @input="autoResize"
                        :maxlength="MAX_MESSAGE_LENGTH"
                    />
                    <p class="text-xs text-muted-foreground text-right mt-1">
                        {{ quickMessageCount }}/{{ MAX_MESSAGE_LENGTH }}
                    </p>
                    <div class="flex absolute top-3 right-2">
                        <!-- Paperclip Button -->
                        <Button
                            size="icon"
                            variant="ghost"
                            @click.prevent="triggerFileInput"
                            :disabled="loading"
                        >
                            <PaperclipIcon class="h-4 w-4" />
                        </Button>
                        <!-- Send Button -->
                        <Button
                            size="icon"
                            variant="ghost"
                            @click.prevent="sendMessage"
                            :disabled="loading"
                        >
                            <SendIcon v-if="!loading" class="h-4 w-4" />
                            <Loader2Icon v-else class="w-4 h-4 animate-spin" />
                        </Button>
                    </div>
                </div>
            </div>
        </DialogContent>
    </Dialog>

    <WorkOrderModal />

    <VendorMobileNav />
</template>
