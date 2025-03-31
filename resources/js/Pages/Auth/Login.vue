<script setup>
import { useForm } from "@inertiajs/vue3";
import AuthLayout from "@/Layouts/AuthLayout.vue";
import { Loader2 } from "lucide-vue-next";

defineOptions({ layout: AuthLayout });

defineProps({
  canResetPassword: Boolean,
  status: String,
});

const form = useForm({
  email: "",
  password: "",
  remember: false,
});

const submit = () => {
  form
    .transform((data) => ({
      ...data,
      remember: form.remember ? "on" : "",
    }))
    .post(route("login"), {
      onFinish: () => form.reset("password"),
    });
};
</script>
<template>
  <Head title="Login" />
  <Card>
    <CardHeader class="text-center">
      <CardTitle class="text-xl"> Welcome back!</CardTitle>
      <CardDescription> Login with your email account </CardDescription>
    </CardHeader>
    <CardContent>
      <form @submit.prevent="submit">
        <div class="grid gap-6">
          <div class="grid gap-2">
            <div class="grid gap-2">
              <Label html-for="email">Email</Label>
              <Input
                id="email"
                type="email"
                placeholder="m@example.com"
                v-model="form.email"
                required
              />
              <Label class="text-xs text-destructive">{{ form.errors.email }}</Label>
            </div>
            <div class="grid gap-2">
              <div class="flex items-center">
                <Label html-for="password">Password</Label>
                <Link
                  class="ml-auto text-sm underline-offset-4 hover:underline"
                  :href="route('password.request')"
                >
                  Forgot your password?
                </Link>
              </div>
              <Input id="password" v-model="form.password" type="password" required />
            </div>
            <div class="flex items-center space-x-2 mb-4">
              <Checkbox v-model="form.remember" id="remember_me" />
              <label
                for="remember_me"
                class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70"
              >
                Remember me
              </label>
            </div>
            <Button type="submit" :disabled="form.processing" class="w-full">
              <Loader2 v-if="form.processing" class="w-4 h-4 animate-spin" /> Login
            </Button>
          </div>
        </div>
      </form>
    </CardContent>
  </Card>
</template>
