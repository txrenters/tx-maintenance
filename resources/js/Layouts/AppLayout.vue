<script setup>
import { ref } from "vue";
import { router } from "@inertiajs/vue3";
import AuthenticationCard from "@/Components/AuthenticationCard.vue";
import AuthenticationCardLogo from "@/Components/AuthenticationCardLogo.vue";

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
  Bell,
  ChevronRight,
  ChevronsUpDown,
  Forward,
  Frame,
  GalleryVerticalEnd,
  LogOut,
  MoreHorizontal,
  UserRoundCog,
  Phone,
  LayoutTemplate,
  ContactRound,
  Settings2,
  CalendarClock,
  CalendarDays,
  Trash2,
  LayoutDashboard,
  ListTodo,
} from "lucide-vue-next";
import { usePage } from "@inertiajs/vue3";

const page = usePage();

const data = {
  user: {
    name: page.props.auth.user.name,
    email: page.props.auth.user.email,
    avatar: page.props.auth.user.profile_photo_url,
  },
  teams: [
    {
      name: "Texas Renters",
      logo: GalleryVerticalEnd,
      plan: "",
    },
  ],
  navMain: [
    {
      title: "Work Order",
      url: "#",
      icon: ListTodo,
      isActive: false,
      items: [
        {
          title: "Ongoing",
          url: "#",
        },
        {
          title: "Archives",
          url: "#",
        },
      ],
    },
  ],
  projects: [
    {
      name: "Dashboard",
      url: route("dashboard"),
      icon: LayoutDashboard,
    },
    {
      name: "Calendar",
      url: "#",
      icon: CalendarDays,
    },
    {
      name: "Meetings",
      url: "#",
      icon: CalendarClock,
    },
  ],

  settings: [
    {
      name: "Templates",
      url: "#",
      icon: LayoutTemplate,
    },
    {
      name: "Vendors",
      url: "#",
      icon: ContactRound,
    },
    {
      name: "Twilio Numbers",
      url: "#",
      icon: Phone,
    },
    {
      name: "Users",
      url: "#",
      icon: UserRoundCog,
    },
    {
      name: "General",
      url: "#",
      icon: Settings2,
    },
  ],
};

const activeTeam = ref(data.teams[0]);
const logout = () => {
  router.post(route("logout"));
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
                  size="lg"
                  class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                >
                  <div
                    class="flex aspect-square size-8 items-center justify-center rounded-lg bg-sidebar-primary text-sidebar-primary-foreground"
                  >
                    <component :is="activeTeam.logo" class="size-4" />
                  </div>
                  <div class="grid flex-1 text-left text-sm leading-tight">
                    <span class="truncate font-semibold">{{ activeTeam.name }}</span>
                    <span class="truncate text-xs">{{ activeTeam.plan }}</span>
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
            <SidebarMenuItem v-for="item in data.projects" :key="item.name">
              <SidebarMenuButton as-child>
                <Link :href="item.url" prefetch>
                  <component :is="item.icon" />
                  <span>{{ item.name }}</span>
                </Link>
              </SidebarMenuButton>
            </SidebarMenuItem>
          </SidebarMenu>
        </SidebarGroup>
        <SidebarGroup>
          <SidebarGroupLabel>Feature</SidebarGroupLabel>
          <SidebarMenu>
            <Collapsible
              v-for="item in data.navMain"
              :key="item.title"
              as-child
              :default-open="item.isActive"
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
                        <Link :href="subItem.url" prefetch>
                          <span>{{ subItem.title }}</span>
                        </Link>
                      </SidebarMenuSubButton>
                    </SidebarMenuSubItem>
                  </SidebarMenuSub>
                </CollapsibleContent>
              </SidebarMenuItem>
            </Collapsible>
          </SidebarMenu>
        </SidebarGroup>
        <SidebarGroup>
          <SidebarGroupLabel>Settings</SidebarGroupLabel>
          <SidebarMenu>
            <SidebarMenuItem v-for="item in data.settings" :key="item.name">
              <SidebarMenuButton as-child>
                <Link :href="item.url" prefetch>
                  <component :is="item.icon" />
                  <span>{{ item.name }}</span>
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
                    <AvatarImage :src="data.user.avatar" :alt="data.user.name" />
                    <AvatarFallback class="rounded-lg"> CN </AvatarFallback>
                  </Avatar>
                  <div class="grid flex-1 text-left text-sm leading-tight">
                    <span class="truncate font-semibold">{{ data.user.name }}</span>
                    <span class="truncate text-xs">{{ data.user.email }}</span>
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
                  <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                    <Avatar class="h-8 w-8 rounded-lg">
                      <AvatarImage :src="data.user.avatar" :alt="data.user.name" />
                      <AvatarFallback class="rounded-lg"> CN </AvatarFallback>
                    </Avatar>
                    <div class="grid flex-1 text-left text-sm leading-tight">
                      <span class="truncate font-semibold">{{ data.user.name }}</span>
                      <span class="truncate text-xs">{{ data.user.email }}</span>
                    </div>
                  </div>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuGroup>
                  <Link :href="route('profile.show')" prefetch>
                    <DropdownMenuItem class="cursor-pointer">
                      <BadgeCheck />
                      Account
                    </DropdownMenuItem>
                  </Link>
                </DropdownMenuGroup>
                <DropdownMenuSeparator />
                <DropdownMenuItem>
                  <form @submit.prevent="logout">
                    <button type="submit" class="flex gap-2">
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
      <slot />
    </SidebarInset>
  </SidebarProvider>
</template>
