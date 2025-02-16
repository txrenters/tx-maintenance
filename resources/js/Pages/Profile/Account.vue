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

  <div v-if="$page.props.jetstream.canUpdateProfileInformation">
    <UpdateProfileInformationForm :user="$page.props.auth.user" />

    <SectionBorder />
  </div>
</template>
