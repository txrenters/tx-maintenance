<script setup>
import { ref } from "vue";
import { useForm } from "@inertiajs/vue3";
import ActionMessage from "@/Components/ActionMessage.vue";
import FormSection from "@/Components/FormSection.vue";
import { useToast } from "@/Components/ui/toast/use-toast";

const { toast } = useToast();
const passwordInput = ref(null);
const currentPasswordInput = ref(null);

const form = useForm({
  current_password: "",
  password: "",
  password_confirmation: "",
});

const updatePassword = () => {
  form.put(route("user-password.update"), {
    errorBag: "updatePassword",
    preserveScroll: true,
    onSuccess: () => {
      form.reset();
      toast({
        title: "Success",
        description: "Your two factor authentication has been set!",
      });
    },
    onError: () => {
      if (form.errors.password) {
        form.reset("password", "password_confirmation");
        // passwordInput.value.focus();
      }

      if (form.errors.current_password) {
        form.reset("current_password");
        // currentPasswordInput.value.focus();
      }
      toast({
        variant: "destructive",
        title: "Uh oh! Something went wrong.",
        description: "There was a problem with your request. Please try again!",
      });
    },
  });
};
</script>

<template>
  <FormSection @submitted="updatePassword">
    <template #title> Update Password </template>

    <template #description>
      Ensure your account is using a long, random password to stay secure.
    </template>

    <template #form>
      <div class="col-span-6 sm:col-span-4">
        <Label for="current_password" value="Current Password">Current Password</Label>
        <Input
          id="current_password"
          ref="currentPasswordInput"
          v-model="form.current_password"
          type="password"
          class="mt-1 block w-full"
          autocomplete="current-password"
        />
        <Label class="mt-1 text-destructive text-xs">{{
          form.errors.current_password
        }}</Label>
      </div>

      <div class="col-span-6 sm:col-span-4">
        <Label for="password" value="Current Password">New Password</Label>
        <Input
          id="password"
          ref="passwordInput"
          v-model="form.password"
          type="password"
          class="mt-1 block w-full"
          autocomplete="new-password"
        />
        <Label class="mt-1 text-destructive text-xs">{{ form.errors.password }}</Label>
      </div>

      <div class="col-span-6 sm:col-span-4">
        <Label for="password_confirmation" value="Current Password"
          >Confirm Password</Label
        >
        <Input
          id="password_confirmation"
          v-model="form.password_confirmation"
          type="password"
          class="mt-1 block w-full"
          autocomplete="new-password"
        />
        <Label class="mt-1 text-destructive text-xs">{{
          form.errors.password_confirmation
        }}</Label>
      </div>
    </template>

    <template #actions>
      <ActionMessage :on="form.recentlySuccessful" class="me-3"> Saved. </ActionMessage>

      <Button type="submit" :disabled="form.processing">
        <Loader2 v-if="form.processing" class="w-4 h-4 animate-spin" />
        Save Changes</Button
      >
    </template>
  </FormSection>
</template>
