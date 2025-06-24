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
} from "lucide-vue-next";

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
            logo: "/logo-ct.png",
            plan: "",
        },
    ],
}));

const navs = computed(() => ({
    navMain: [
        {
            title: "Work Orders",
            url: "#",
            icon: Wrench,
            isActive: page.url.startsWith("/work_orders"),
            items: [
                {
                    title: "Active",
                    url: route("work_orders.index"),
                    isActive: page.component === "WorkOrder/Index",
                },
                {
                    title: "Coordinators",
                    url: route("work_orders.coordinators"),
                    isActive: page.component === "WorkOrder/Coordinators",
                },
                {
                    title: "Completed",
                    url: route("work_orders.closed_work_orders"),
                    isActive: page.component === "WorkOrder/Close",
                },
            ],
        },
    ],
    menu: [
        {
            name: "Dashboard",
            url: route("dashboard"),
            isActive: page.url.startsWith("/dashboard"),
            icon: LayoutDashboard,
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
            name: "Calendar",
            url: route("scheduled_service"),
            isActive: page.url.startsWith("/scheduled_service"),
            icon: CalendarDays,
            requires: ["admin", "woc", "vendor"],
        },
        {
            name: "Invoices",
            url: route("invoices.index"),
            isActive: page.url.startsWith("/work_order/invoices"),
            icon: Files,
            requires: ["admin", "woc", "vendor"],
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
}));
const activeTeam = ref(data.value.teams[0]);
const logout = () => {
    router.post(route("logout"));
};

const canAccess = (requiredRoles) => {
    // Ensure roles are valid arrays
    const userRoles = page.props.auth.user.roles || []; // Default to an empty array if undefined
    requiredRoles = requiredRoles || []; // Default to an empty array if undefined

    // Use filter to find matching roles
    const matchingRoles = userRoles.filter((role) =>
        requiredRoles.includes(role)
    );

    // Return true if there are any matches, otherwise false
    return matchingRoles.length > 0;
};

const notifications = ref([]);
let intervalId = null;

const fetchNotifications = async () => {
    try {
        const response = await axios.get("/api/notifications");
        notifications.value = response.data;
    } catch (error) {
        console.error("Failed to fetch notifications:", error);
    }
};

// Mark notification as read and remove it from local state
const markAsRead = async (notificationId) => {
    try {
        // Send API request to mark as read (you might want to implement this in Laravel)
        await axios.put(`/api/notifications/${notificationId}/mark-as-read`);

        // Remove from local notifications array
        notifications.value = notifications.value.filter(
            (notification) => notification.id !== notificationId
        );
        toast({
            title: "Success",
            description: "Notification marked as read!",
        });
    } catch (error) {
        console.error("Failed to mark notification as read:", error);
    }
};

onMounted(() => {
    fetchNotifications(); // initial load

    // Set interval for every 5 minutes (300,000 ms)
    intervalId = setInterval(fetchNotifications, 5000);
});

onUnmounted(() => {
    if (intervalId) clearInterval(intervalId); // cleanup when component is destroyed
});

const mode = useColorMode({ disableTransition: false });

const showBanner = ref(true);

// Close the banner when the close button is clicked
const closeBanner = () => {
    showBanner.value = false;
};
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
                        <SidebarMenuItem
                            v-for="item in navs.menu"
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
                    </SidebarMenu>
                    <SidebarMenu>
                        <Collapsible
                            v-for="item in navs.navMain"
                            :key="item.title"
                            as-child
                            :default-open="item.isActive"
                            :data-state="item.isActive"
                            class="group/collapsible"
                        >
                            <SidebarMenuItem>
                                <CollapsibleTrigger as-child>
                                    <SidebarMenuButton :tooltip="item.title">
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
                    <SidebarMenu>
                        <SidebarMenuItem
                            v-for="item in navs.menu2"
                            :key="item.name"
                        >
                            <SidebarMenuButton
                                as-child
                                v-if="canAccess(item.requires)"
                            >
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
                <!-- Banner section -->
                <!-- <div
                    v-if="showBanner"
                    class="bg-blue-500 text-white p-2 flex justify-between items-center"
                >
                    <span class="text-sm"
                        >Important Notice: We will be performing an update soon.
                        Please be aware!</span
                    >
                </div> -->
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
                                        v-if="notifications.length > 0"
                                        class="bg-destructive text-white px-1 rounded-full text-xs absolute top-1"
                                        >{{ notifications.length }}</span
                                    >
                                </PopoverTrigger>
                                <PopoverContent
                                    class="w-full sm:min-w-[24rem] sm:max-w-2xl mx-auto max-h-[80vh] sm:max-h-96 overflow-y-auto"
                                >
                                    <p
                                        class="uppercase text-xs font-bold flex gap-1"
                                    >
                                        <span v-if="notifications.length === 0"
                                            >No New
                                        </span>
                                    </p>
                                    <template v-if="notifications.length > 0">
                                        <div class="flex flex-col gap-2">
                                            <h3
                                                class="text-2xl font-bold text-gray-800 mb-4"
                                            >
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
                                                    class="flex items-start gap-4 p-3 rounded-lg transition-all duration-200 ease-in-out cursor-pointer relative"
                                                    :class="{
                                                        'bg-white shadow-sm hover:bg-gray-50':
                                                            !notification.read,
                                                        'bg-gray-50 hover:bg-gray-100 text-gray-600':
                                                            notification.read,
                                                    }"
                                                    @click="
                                                        markAsRead(
                                                            notification.id
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
                                                    ></div>

                                                    <!-- Icon placeholder -->
                                                    <div
                                                        class="flex-shrink-0 w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center text-blue-600"
                                                    >
                                                        <svg
                                                            xmlns="http://www.w3.org/2000/svg"
                                                            width="18"
                                                            height="18"
                                                            viewBox="0 0 24 24"
                                                            fill="none"
                                                            stroke="currentColor"
                                                            stroke-width="2"
                                                            stroke-linecap="round"
                                                            stroke-linejoin="round"
                                                            class="lucide lucide-bell"
                                                        >
                                                            <path
                                                                d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"
                                                            />
                                                            <path
                                                                d="M10.3 21a1.94 1.94 0 0 0 3.4 0"
                                                            />
                                                        </svg>
                                                    </div>

                                                    <div class="flex-1">
                                                        <h4
                                                            class="font-semibold text-base text-gray-900 leading-tight"
                                                        >
                                                            {{
                                                                notification.title
                                                            }}
                                                        </h4>
                                                        <p
                                                            class="text-sm text-gray-600 mt-1 line-clamp-2"
                                                        >
                                                            {{
                                                                notification.message
                                                            }}
                                                        </p>
                                                        <div
                                                            class="text-xs text-gray-500 mt-2 flex justify-between items-center"
                                                        >
                                                            <p class="mr-2">
                                                                {{
                                                                    notification.time
                                                                }}
                                                            </p>
                                                            <button
                                                                v-if="
                                                                    !notification.read
                                                                "
                                                                @click.stop="
                                                                    markAsRead(
                                                                        notification.id
                                                                    )
                                                                "
                                                                class="text-blue-600 hover:text-blue-700 font-medium py-1 px-2 rounded-md transition-colors duration-150"
                                                            >
                                                                Mark as read
                                                            </button>
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
                            <!-- <Button
                            variant="outline"
                            @click="router.visit(route('maintenance.chatbot'))"
                        >
                            <BotMessageSquare />
                        </Button> -->
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
</template>
