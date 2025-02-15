<script setup>
import AppLayout from "@/Layouts/AppLayout.vue";
import SidebarNav from "@/Pages/Profile/Partials/SidebarNav.vue";

defineOptions({ layout: AppLayout });

defineProps({
  confirmsTwoFactorAuthentication: Boolean,
  sessions: Array,
});
</script>

<template>
  <Head :title="(title = 'Profile')" />

  <header
    class="flex h-16 shrink-0 items-center gap-2 transition-[width,height] ease-linear group-has-[[data-collapsible=icon]]/sidebar-wrapper:h-12"
  >
    <BreadcrumbContainer :title="title" />
  </header>

  <div class="space-y-6 p-10 pb-16 md:block">
    <div class="space-y-0.5">
      <h2 class="text-2xl font-bold tracking-tight">{{ title }}</h2>
      <p class="text-muted-foreground">
        Manage your account settings and set e-mail preferences.
      </p>
    </div>
    <Separator class="my-6" />
    <div class="flex flex-col space-y-8 lg:flex-row lg:space-x-12 lg:space-y-0">
      <aside class="-mx-4 lg:w-1/5">
        <SidebarNav />
      </aside>
      <div class="flex-1 lg:max-w-2xl">
        <div class="space-y-6">
          <slot />
        </div>
      </div>
    </div>
  </div>
</template>
