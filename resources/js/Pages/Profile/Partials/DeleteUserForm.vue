<script setup>
import { ref } from "vue";
import { useForm } from "@inertiajs/vue3";
import ActionSection from "@/Components/ActionSection.vue";
import { useToast } from "@/Components/ui/toast/use-toast";

const { toast } = useToast();

const confirmingUserDeletion = ref(false);
const passwordInput = ref(null);

const form = useForm({
  password: "",
});

const confirmUserDeletion = () => {
  confirmingUserDeletion.value = true;

  //   setTimeout(() => passwordInput.value.focus(), 250);
};

const deleteUser = () => {
  form.delete(route("current-user.destroy"), {
    preserveScroll: true,
    onSuccess: () => {
      closeModal();
      toast({
        title: "Success",
        description: "Your profile will be deleted!",
      });
    },
    onFinish: () => {
      form.reset();
    },
    onError: () => {
      toast({
        variant: "destructive",
        title: "Uh oh! Something went wrong.",
        description: "There was a problem with your request. Please try again!",
      });
    },
  });
};

const closeModal = () => {
  confirmingUserDeletion.value = false;

  form.reset();
};
</script>

<template>
  <ActionSection>
    <template #title> Delete Account </template>

    <template #description> Permanently delete your account. </template>

    <template #content>
      <div class="max-w-xl text-sm">
        Once your account is deleted, all of its resources and data will be permanently
        deleted. Before deleting your account, please download any data or information
        that you wish to retain.
      </div>

      <div class="mt-5">
        <Button variant="destructive" @click="confirmUserDeletion">
          Delete Account
        </Button>
      </div>

      <Dialog v-model:open="confirmingUserDeletion">
        <DialogContent class="sm:max-w-[600px]">
          <DialogHeader>
            <DialogTitle>Delete Account</DialogTitle>
            <DialogDescription>
              Are you sure you want to delete your account? Once your account is deleted,
              all of its resources and data will be permanently deleted. Please enter your
              password to confirm you would like to permanently delete your account.
            </DialogDescription>
          </DialogHeader>
          <div>
            <Input
              ref="passwordInput"
              v-model="form.password"
              type="password"
              class="mt-1 block"
              placeholder="Password"
              autocomplete="current-password"
              @keyup.enter="deleteUser"
            />
            <Label class="mt-1 text-destructive text-xs">{{
              form.errors.password
            }}</Label>
          </div>
          <DialogFooter class="gap-2">
            <Button type="button" variant="outline" @click="closeModal"> Cancel</Button>
            <Button
              type="submit"
              variant="destructive"
              :disabled="form.processing"
              @click.prevent="deleteUser"
            >
              <Loader2 v-if="form.processing" class="w-4 h-4 animate-spin" />
              Delete Now</Button
            >
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </template>
  </ActionSection>
</template>
