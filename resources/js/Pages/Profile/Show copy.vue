<script setup>
import AppLayout from "@/Layouts/AppLayout.vue";
import DeleteUserForm from "@/Pages/Profile/Partials/DeleteUserForm.vue";
import LogoutOtherBrowserSessionsForm from "@/Pages/Profile/Partials/LogoutOtherBrowserSessionsForm.vue";
import SectionBorder from "@/Components/SectionBorder.vue";
import TwoFactorAuthenticationForm from "@/Pages/Profile/Partials/TwoFactorAuthenticationForm.vue";
import UpdatePasswordForm from "@/Pages/Profile/Partials/UpdatePasswordForm.vue";
import UpdateProfileInformationForm from "@/Pages/Profile/Partials/UpdateProfileInformationForm.vue";

defineOptions({ layout: AppLayout });

defineProps({
  confirmsTwoFactorAuthentication: Boolean,
  sessions: Array,
});
</script>

<template>
  <Head :title="(title = 'User Profile')" />

  <header
    class="flex h-16 shrink-0 items-center gap-2 transition-[width,height] ease-linear group-has-[[data-collapsible=icon]]/sidebar-wrapper:h-12"
  >
    <BreadcrumbContainer :title="title" />
  </header>

  <div>
    <div class="max-w-7xl mx-auto py-10 sm:px-6 lg:px-8">
      <div v-if="$page.props.jetstream.canUpdateProfileInformation">
        <UpdateProfileInformationForm :user="$page.props.auth.user" />

        <SectionBorder />
      </div>

      <div v-if="$page.props.jetstream.canUpdatePassword">
        <UpdatePasswordForm class="mt-10 sm:mt-0" />

        <SectionBorder />
      </div>

      <div v-if="$page.props.jetstream.canManageTwoFactorAuthentication">
        <TwoFactorAuthenticationForm
          :requires-confirmation="confirmsTwoFactorAuthentication"
          class="mt-10 sm:mt-0"
        />

        <SectionBorder />
      </div>

      <LogoutOtherBrowserSessionsForm :sessions="sessions" class="mt-10 sm:mt-0" />

      <template v-if="$page.props.jetstream.hasAccountDeletionFeatures">
        <SectionBorder />

        <DeleteUserForm class="mt-10 sm:mt-0" />
      </template>
    </div>
  </div>
</template>
