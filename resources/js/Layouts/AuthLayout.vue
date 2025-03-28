<script setup>
import AuthenticationCardLogo from "@/Components/AuthenticationCardLogo.vue";
</script>

<template>
  <div class="relative flex min-h-svh flex-col bg-background">
    <div
      class="flex min-h-svh flex-col items-center justify-center gap-6 bg-muted p-6 md:p-10"
    >
      <Link href="/"> <img src="logo-ct.png" width="200" /></Link>

      <div class="flex flex-col gap-6 w-full max-w-md mx-auto">
        <slot />

        <div
          class="text-balance text-center text-xs text-muted-foreground [&_a]:underline [&_a]:underline-offset-4 [&_a]:hover:text-primary"
        >
          By clicking continue, you agree to our
          <a href="#">Terms of Service</a> and <a href="#">Privacy Policy</a>.
        </div>
      </div>
    </div>
  </div>
</template>
