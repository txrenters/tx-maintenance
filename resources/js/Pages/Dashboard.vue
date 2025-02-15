<script setup>
import AppLayout from "@/Layouts/AppLayout.vue";

defineOptions({ layout: AppLayout });

const props = defineModel({
  title: String,
});
</script>

<template>
  <Head :title="(title = 'Dashboard')" />
  <header
    class="flex h-16 shrink-0 items-center gap-2 transition-[width,height] ease-linear group-has-[[data-collapsible=icon]]/sidebar-wrapper:h-12"
  >
    <BreadcrumbContainer :title="title" />
  </header>
  <Separator />

  <div class="space-y-0.5 p-5">
    <h2 class="text-2xl font-bold tracking-tight">{{ title }}</h2>
  </div>

  <div class="flex flex-1 flex-col gap-4 p-4 mt-4 pt-0">
    <div class="grid auto-rows-min gap-4 md:grid-cols-3">
      <div class="aspect-video rounded-xl bg-muted/50" />
      <div class="aspect-video rounded-xl bg-muted/50" />
      <div class="aspect-video rounded-xl bg-muted/50" />
    </div>
    <div class="min-h-[100vh] flex-1 rounded-xl bg-muted/50 md:min-h-min" />
  </div>
</template>
