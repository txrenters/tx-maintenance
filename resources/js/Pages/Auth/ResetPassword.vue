<script setup>
import { useForm } from "@inertiajs/vue3";
import AuthLayout from "@/Layouts/AuthLayout.vue";
import { Loader2 } from "lucide-vue-next";

defineOptions({ layout: AuthLayout });

const props = defineProps({
  email: String,
  token: String,
});

const form = useForm({
  token: props.token,
  email: props.email,
  password: "",
  password_confirmation: "",
});

const submit = () => {
  form.post(route("password.update"), {
    onFinish: () => form.reset("password", "password_confirmation"),
  });
};
</script>
<template>
  <Head title="Reset Password" />
  <Card>
    <CardHeader class="text-center">
      <CardTitle class="text-xl"> Login </CardTitle>
      <CardDescription> Enter your email below to login to your account </CardDescription>
    </CardHeader>
    <CardContent>
      <form @submit.prevent="submit">
        <div class="grid gap-4">
          <div class="grid gap-2">
            <Label for="email">Email</Label>
            <Input
              id="email"
              v-model="form.email"
              type="email"
              class="mt-1 block w-full"
              required
              autofocus
              autocomplete="username"
            />
            <Label class="text-xs text-destructive mt-2">{{ form.errors.email }}</Label>
          </div>

          <div class="mt-4">
            <Label for="password">Password</Label>
            <Input
              id="password"
              v-model="form.password"
              type="password"
              class="mt-1 block w-full"
              required
              autocomplete="new-password"
            />
            <Label class="text-xs text-destructive mt-2">{{
              form.errors.password
            }}</Label>
          </div>

          <div class="mt-4">
            <Label for="password_confirmation">Confirm Password</Label>
            <Input
              id="password_confirmation"
              v-model="form.password_confirmation"
              type="password"
              class="mt-1 block w-full"
              required
              autocomplete="new-password"
            />
            <Label class="text-xs text-destructive mt-2">{{
              form.errors.password_confirmation
            }}</Label>
          </div>

          <div class="flex items-center justify-end mt-4">
            <Button type="submit" :disabled="form.processing">
              <Loader2 v-if="form.processing" class="w-4 h-4 animate-spin" />
              Reset Password</Button
            >
          </div>
        </div>
      </form>
    </CardContent>
  </Card>
</template>
