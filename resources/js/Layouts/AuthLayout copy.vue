<script setup>
import AuthenticationCardLogo from "@/Components/AuthenticationCardLogo.vue";
</script>

<template>
  <div class="w-full lg:grid lg:min-h-screen lg:grid-cols-2">
    <!-- Left Side (Form) -->
    <div
      class="relative flex flex-col items-center justify-center py-32 w-full max-w-sm mx-auto"
    >
      <!-- Logo Positioned Above -->
      <AuthenticationCardLogo class="flex justify-center mb-6" />

      <!-- Form Slot (Does Not Move Down) -->
      <slot />
    </div>

    <!-- Right Side (Image) -->
    <div class="hidden bg-muted lg:block">
      <img
        src="/login.jpg"
        alt="Login Image"
        class="h-full w-full object-cover dark:brightness-[0.2] dark:grayscale"
      />
    </div>
  </div>
</template>
