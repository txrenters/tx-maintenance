<script setup>
import { ref, onMounted, onUnmounted, computed } from "vue";
import { router } from "@inertiajs/vue3";
import { usePage } from "@inertiajs/vue3";
import { Icon } from "@iconify/vue";
import { useColorMode } from "@vueuse/core";
import axios from "axios";
import { useToast } from "@/Components/ui/toast/use-toast";

const { toast } = useToast();

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
    Users,
    UserRoundCheck,
    Circle,
    Files,
    ClipboardList,
    Wrench,
    Truck,
    BotMessageSquare,
    BadgeAlert,
    Bell,
    Users2,
    Globe2,
    Globe,
    Handshake,
    Hammer,
    BookOpen,
    PaperclipIcon,
    Sprout,
    SendIcon,
    Loader2Icon,
    HammerIcon,
    FileIcon,
    WrenchIcon,
    Briefcase,
} from "lucide-vue-next";
import MessageCard from "@/Components/MessageCard.vue";

const page = usePage();

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
                              title: "Completed",
                              url: route("work_orders.closed_work_orders"),
                              isActive: page.component === "WorkOrder/Close",
                          },
                      ],
                requires: [
                    "admin",
                    "woc",
                    "vendor",
                    "tenant",
                    "owner",
                    "accounting",
                ],
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
                requires: ["admin", "woc", "vendor"],
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
        ],
        menu2: [
            {
                name: "Inspections",
                url: route("work_orders.inspections"),
                isActive: page.url.startsWith("/work_orders/inspections"),
                icon: Hammer,
                requires: ["admin", "woc", "vendor"],
            },
            {
                name: "Lawn Service",
                url: route("work_orders.lawn_service"),
                isActive: page.url.startsWith("/work_orders/lawn_service"),
                icon: Sprout,
                requires: ["admin", "woc", "vendor"],
            },
            {
                name: "Coordinators",
                url: route("work_orders.coordinators"),
                isActive: page.url.startsWith("/WorkOrder/Coordinators"),
                icon: Users2,
                requires: ["admin", "woc"],
            },
            {
                name: "Tasks",
                url: route("tasks.index"),
                isActive: page.url.startsWith("/tasks"),
                icon: ClipboardList,
                requires: ["admin", "woc", "vendor"],
            },

            {
                name: "Calendar",
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
                name: "Vendors",
                url: route("vendors.index"),
                isActive: page.url.startsWith("/vendors"),
                icon: Truck,
                requires: ["admin", "woc"],
            },
            {
                name: "Owners",
                url: route("owners.index"),
                isActive: page.url.startsWith("/owners"),
                icon: Users,
                requires: ["admin", "woc"],
            },
            {
                name: "Tenants",
                url: route("tenants.index"),
                isActive: page.url.startsWith("/tenants"),
                icon: Users,
                requires: ["admin", "woc"],
            },
            {
                name: "Conversations",
                url: route("conversation_logs.index"),
                isActive: page.component === "ConversationLogs",
                icon: Globe,
                requires: ["admin", "woc"],
            },
        ],
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
    if (userEmail === "thmp@texasrenters.com") {
        return true;
    }

    // Use filter to find matching roles
    const matchingRoles = userRoles.filter((role) =>
        requiredRoles.includes(role),
    );

    // Return true if there are any matches, otherwise false
    return matchingRoles.length > 0;
};

const notifications = ref([]);
const markingAsRead = ref(new Set()); // Track which notifications are being marked as read
let intervalId = null;

// Computed property to count unread notifications
const unreadCount = computed(() => {
    return notifications.value.filter((n) => !n.read).length;
});

// Unread notifications always appear first, then sorted by newest
const sortedNotifications = computed(() => {
    return [...notifications.value].sort((a, b) => {
        if (a.read !== b.read) {
            return a.read ? 1 : -1;
        }
        return b.timestamp - a.timestamp;
    });
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
const isLoading = ref(false);
const newMessage = ref("");
const conversations = ref([]);
const receiver_number = ref("");
const sender_number = ref("");
const conversation_type = ref("");
const reference_id = ref("");
const notif = ref(null);

const handleChatModal = async (model) => {
    const response = await axios.post("/api/notification/messages", {
        data: model,
    });

    conversations.value = response.data;
    sender_number.value = model.receiver_number; // since this is a text message from a client we need to reverse
    receiver_number.value = model.sender_number; // since this is a text message from a client we need to reverse
    conversation_type.value = model?.conversation_type ?? "job";
    reference_id.value = model?.work_order_id ?? model?.jobber_id;
    notif.value = model;
    openModal.value = true;
};
const loading = ref(false);
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

    if (!newMessage.value || !newMessage.value.trim()) {
        toast({
            variant: "destructive",
            title: "Uh oh! Something went wrong.",
            description: "Please type a message!",
        });
        loading.value = false;

        return;
    }

    if (newMessage.value.trim() !== "" && conversation_type.value !== "job") {
        const formData = new FormData();
        formData.append("text", newMessage.value.trim());
        formData.append("sender_phone_number", sender_number.value);
        formData.append("receiver_phone_number", receiver_number.value);
        formData.append("work_order_id", reference_id.value);
        formData.append("conversation_type", conversation_type.value);

        router.post(route("work_order.conversation.send"), formData, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                toast({
                    title: "Success",
                    description: "Message has been sent successfully!",
                });
                newMessage.value = "";
                // Reset textarea height
                const textarea = document.querySelector(
                    'textarea[placeholder="Type your message..."]',
                );
                if (textarea) textarea.style.height = "auto";
                handleChatModal(notif.value);
            },
            onError: () => {
                toast({
                    variant: "destructive",
                    title: "Uh oh! Something went wrong.",
                    description:
                        "There was a problem with your request. Please try again!",
                });
            },
            onFinish: () => {
                loading.value = false;
            },
        });
    }

    if (newMessage.value.trim() !== "" && conversation_type.value === "job") {
        const formData = new FormData();
        formData.append("messages", newMessage.value.trim());
        formData.append("sender_number", sender_number.value);
        formData.append("receiver_numbers[]", receiver_number.value);
        formData.append("jobber_id", reference_id.value);
        formData.append("conversation_type", conversation_type.value);

        router.post(route("jobber-text-messages.store"), formData, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                toast({
                    title: "Success",
                    description: "Message has been sent successfully!",
                });
                newMessage.value = "";
                // Reset textarea height
                const textarea = document.querySelector(
                    'textarea[placeholder="Type your message..."]',
                );
                if (textarea) textarea.style.height = "auto";
                handleChatModal(notif.value);
            },
            onError: () => {
                toast({
                    variant: "destructive",
                    title: "Uh oh! Something went wrong.",
                    description:
                        "There was a problem with your request. Please try again!",
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
        <SidebarInset>
            <div>
                <header
                    class="flex h-16 shrink-0 items-center gap-2 transition-[width,height] ease-linear group-has-[[data-collapsible=icon]]/sidebar-wrapper:h-12"
                >
                    <div class="flex justify-between w-full">
                        <BreadcrumbContainer :title="page.props.title" />
                        <div class="mr-5 flex gap-2">
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
                                                    v-for="notification in sortedNotifications"
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
                                                                'jobber_not_sent',
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
                                                                'jobber_not_sent',
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
                                                                    Reply
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
            <div class="flex flex-1 flex-col gap-4 p-4 pt-4">
                <Toaster />
                <slot />
            </div>
        </SidebarInset>
    </SidebarProvider>

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

                <!-- Message Input -->
                <div class="relative w-full mt-4 mb-6">
                    <Textarea
                        v-model="newMessage"
                        placeholder="Type your message..."
                        class="w-full resize-none rounded-2xl border py-3 pr-24 min-h-[44px] max-h-[200px] overflow-y-auto"
                        rows="3"
                        @input="autoResize"
                    />
                    <div class="flex absolute top-3 right-2">
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
</template>
