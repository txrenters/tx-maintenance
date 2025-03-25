<script setup>
import { ref } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import DeleteUserForm from "@/Pages/Profile/Partials/DeleteUserForm.vue";
import LogoutOtherBrowserSessionsForm from "@/Pages/Profile/Partials/LogoutOtherBrowserSessionsForm.vue";
import SectionBorder from "@/Components/SectionBorder.vue";
import TwoFactorAuthenticationForm from "@/Pages/Profile/Partials/TwoFactorAuthenticationForm.vue";
import UpdatePasswordForm from "@/Pages/Profile/Partials/UpdatePasswordForm.vue";
import Appearance from "@/Pages/Profile/Partials/Appearance.vue";

defineOptions({ layout: AppLayout });

const props = defineProps({
  confirmsTwoFactorAuthentication: Boolean,
  sessions: Array,
});
const title = ref("User Settings");
</script>

<template>
  <Head :title="title" />

  <div>
    <div class="max-w-7xl mx-auto py-10 sm:px-6 lg:px-8">
      <div v-if="$page.props.jetstream.canUpdatePassword">
        <UpdatePasswordForm class="mt-10 sm:mt-0" />

        <SectionBorder />
      </div>

      <!-- <div v-if="$page.props.jetstream.canManageTwoFactorAuthentication">
        <TwoFactorAuthenticationForm
          :requires-confirmation="confirmsTwoFactorAuthentication"
          class="mt-10 sm:mt-0"
        />

        <SectionBorder />
      </div> -->

      <!-- <div>
        <Appearance />

        <SectionBorder />
      </div> -->

      <LogoutOtherBrowserSessionsForm :sessions="sessions" class="mt-10 sm:mt-0" />

      <template v-if="$page.props.jetstream.hasAccountDeletionFeatures">
        <SectionBorder />

        <DeleteUserForm class="mt-10 sm:mt-0" />
      </template>
    </div>
  </div>
</template>
