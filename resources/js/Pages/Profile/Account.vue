<script setup>
import AppLayout from "@/Layouts/AppLayout.vue";
import SectionBorder from "@/Components/SectionBorder.vue";
import UpdateProfileInformationForm from "@/Pages/Profile/Partials/UpdateProfileInformationForm.vue";

defineOptions({ layout: AppLayout });

defineProps({
  confirmsTwoFactorAuthentication: Boolean,
  sessions: Array,
  title: String,
});
</script>

<template>
  <Head :title="title" />

  <header
    class="flex h-16 shrink-0 items-center gap-2 transition-[width,height] ease-linear group-has-[[data-collapsible=icon]]/sidebar-wrapper:h-12"
  >
    <BreadcrumbContainer :title="title" />
  </header>
  <Separator />

  <div>
    <div class="max-w-7xl mx-auto py-10 sm:px-6 lg:px-8">
      <div v-if="$page.props.jetstream.canUpdateProfileInformation">
        <UpdateProfileInformationForm :user="$page.props.auth.user" />

        <SectionBorder />
      </div>
    </div>
  </div>
</template>
