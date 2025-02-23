<script setup>
import { useForm } from "@inertiajs/vue3";
import AuthLayout from "@/Layouts/AuthLayout.vue";
import { Loader2 } from "lucide-vue-next";

defineOptions({ layout: AuthLayout });

defineProps({
  status: String,
  title: {
    type: String,
    default: "Login",
  },
});

const form = useForm({
  email: "",
});

const submit = () => {
  form.post(route("password.email"));
};
</script>
<template>
  <Head title="Reset Password"></Head>

  <div>
    <div class="mx-auto grid w-[350px] gap-3">
      <div class="grid gap-2 text-center">
        <h1 class="text-2xl font-bold">Forgot Password?</h1>
        <div class="text-sm text-gray-600 text-left">
          No problem. Just let us know your email address and we will email you a password
          reset link that will allow you to choose a new one.
        </div>
      </div>
      <form @submit.prevent="submit">
        <div v-if="status" class="font-medium text-xs text-green-600">
          {{ status }}
        </div>
        <div>
          <Label for="email" value="Email">Email</Label>
          <Input
            id="email"
            v-model="form.email"
            type="email"
            class="mt-1 block w-full"
            required
            autofocus
            autocomplete="username"
          />
          <Label class="mt-2 text-xs text-destructive">{{ form.errors.email }} </Label>
        </div>

        <div class="flex items-center justify-end mt-4">
          <Button type="submit" :disabled="form.processing">
            <Loader2 v-if="form.processing" class="w-4 h-4 animate-spin" />
            Email Password Reset Link</Button
          >
        </div>
      </form>
    </div>
  </div>
</template>
