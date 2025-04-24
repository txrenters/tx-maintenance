<script setup>
import { ref, computed, onMounted } from "vue";
import { router } from "@inertiajs/vue3";
import { usePage } from "@inertiajs/vue3";
import { Icon } from "@iconify/vue";
import { useColorMode } from "@vueuse/core";

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
    ListTodo,
    Users,
    UserRoundCheck,
    Circle,
    Files,
    ClipboardList,
    Wrench,
    Truck,
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

const mode = useColorMode({ disableTransition: false });

const showChatbot = ref(true);

onMounted(() => {
    if (showChatbot.value) {
        const script = document.createElement("script");
        script.src = "https://app.relevanceai.com/embed/chat-bubble.js";
        script.setAttribute(
            "data-relevanceai-share-id",
            "bcbe5a/4e86ee120de5-498d-a5f8-c4ff0eca63a7/1a1a3e95-4012-498a-8cc5-5a4fd8bc8f3d"
        );
        script.setAttribute(
            "data-share-styles",
            "starting_message_prompts=There%27s+a+water+leak+in+my+unit.%7CThe+toilet+is+clogged.%7CThe+AC+isn%27t+working+properly.&hide_tool_steps=false&hide_file_uploads=true&hide_conversation_list=false&bubble_style=agent&primary_color=%232b2b50&bubble_icon=notebook%2Fdeploy&input_placeholder_text=Type+property+issues...&hide_logo=true"
        );
        document.body.appendChild(script);
    }
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
                                    <form @submit.prevent="logout">
                                        <button
                                            type="submit"
                                            class="flex gap-2"
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
            <Toaster />
            <header
                class="flex h-16 shrink-0 items-center gap-2 transition-[width,height] ease-linear group-has-[[data-collapsible=icon]]/sidebar-wrapper:h-12"
            >
                <div class="flex justify-between w-full">
                    <BreadcrumbContainer :title="page.props.title" />
                    <div class="mr-5">
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button variant="outline">
                                    <Icon
                                        icon="radix-icons:moon"
                                        class="h-[1.2rem] w-[1.2rem] rotate-0 scale-100 transition-all dark:-rotate-90 dark:scale-0"
                                    />
                                    <Icon
                                        icon="radix-icons:sun"
                                        class="absolute h-[1.2rem] w-[1.2rem] rotate-90 scale-0 transition-all dark:rotate-0 dark:scale-100"
                                    />
                                    <span class="sr-only">Toggle theme</span>
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
            <Separator />
            <div class="flex flex-1 flex-col gap-4 p-4 pt-4">
                <slot />
            </div>
        </SidebarInset>
    </SidebarProvider>
</template>
