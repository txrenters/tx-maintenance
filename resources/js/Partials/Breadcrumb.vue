<script setup>
import { SidebarTrigger } from "@/Components/ui/sidebar";

const props = defineProps({
  title: String,
});
</script>
<template>
  <div class="flex items-center gap-2 px-4">
    <SidebarTrigger class="-ml-1" />
    <Separator orientation="vertical" class="mr-2 h-4" />
    <Breadcrumb>
      <BreadcrumbList>
        <!-- <BreadcrumbItem class="hidden md:block">
            <BreadcrumbLink href="#">
                Building Your Application
            </BreadcrumbLink>
            </BreadcrumbItem>
            <BreadcrumbSeparator class="hidden md:block" /> -->
        <BreadcrumbItem>
          <BreadcrumbPage>{{ props.title }}</BreadcrumbPage>
        </BreadcrumbItem>
      </BreadcrumbList>
    </Breadcrumb>
  </div>
</template>
