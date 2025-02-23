<script setup>
import { ref } from "vue";
import { useForm } from "@inertiajs/vue3";
import AuthLayout from "@/Layouts/AuthLayout.vue";
import { Loader2 } from "lucide-vue-next";

defineOptions({ layout: AuthLayout });

defineProps({
  canResetPassword: Boolean,
  status: String,
});

const form = useForm({
  password: "",
});

const passwordInput = ref(null);

const submit = () => {
  form.post(route("password.confirm"), {
    onFinish: () => {
      form.reset();

      passwordInput.value.focus();
    },
  });
};
</script>
<template>
  <Head title="Confirm Password"></Head>

  <div>
    <div class="mx-auto grid w-[350px] gap-6">
      <div class="grid gap-2 text-center">
        <h1 class="text-3xl font-bold">Confirm Password</h1>
        <p class="text-balance text-muted-foreground">
          This is a secure area of the application. Please confirm your password before
          continuing.
        </p>
      </div>
      <form @submit.prevent="submit">
        <div class="grid gap-4">
          <Label for="password">Password</Label>
          <TextInput
            id="password"
            ref="passwordInput"
            v-model="form.password"
            type="password"
            class="mt-1 block w-full"
            required
            autocomplete="current-password"
            autofocus
          />
          <Label class="text-xs text-destructive mt-2">{{ form.errors.password }}</Label>
        </div>
        <Button type="submit" :disabled="form.processing">
          <Loader2 v-if="form.processing" class="w-4 h-4 animate-spin" />
          Login</Button
        >
      </form>
    </div>
  </div>
</template>
