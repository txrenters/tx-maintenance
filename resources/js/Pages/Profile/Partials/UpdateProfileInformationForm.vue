<script setup>
import { ref } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import ActionMessage from "@/Components/ActionMessage.vue";
import FormSection from "@/Components/FormSection.vue";
import { useToast } from "@/Components/ui/toast/use-toast";

const { toast } = useToast();

const props = defineProps({
  user: Object,
});

const form = useForm({
  _method: "PUT",
  name: props.user.name,
  email: props.user.email,
  photo: null,
});

const verificationLinkSent = ref(null);
const photoPreview = ref(null);
const photoInput = ref(null);

const updateProfileInformation = () => {
  if (photoInput.value) {
    form.photo = photoInput.value.files[0];
  }

  form.post(route("user-profile-information.update"), {
    errorBag: "updateProfileInformation",
    preserveScroll: true,
    onSuccess: () => {
      clearPhotoFileInput();
      toast({
        title: "Success",
        description: "Your profile has been updated successfully!",
      });
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

const sendEmailVerification = () => {
  verificationLinkSent.value = true;
};

const selectNewPhoto = () => {
  photoInput.value.click();
};

const updatePhotoPreview = () => {
  const photo = photoInput.value.files[0];

  if (!photo) return;

  const reader = new FileReader();

  reader.onload = (e) => {
    photoPreview.value = e.target.result;
  };

  reader.readAsDataURL(photo);
};

const deletePhoto = () => {
  router.delete(route("current-user-photo.destroy"), {
    preserveScroll: true,
    onSuccess: () => {
      photoPreview.value = null;
      clearPhotoFileInput();
    },
  });
};

const clearPhotoFileInput = () => {
  if (photoInput.value?.value) {
    photoInput.value.value = null;
  }
};
</script>

<template>
  <FormSection @submitted="updateProfileInformation">
    <template #title> Profile Information </template>

    <template #description>
      Update your account's profile information and email address.
    </template>

    <template #form>
      <!-- Profile Photo -->
      <div class="col-span-6 sm:col-span-4">
        <!-- Profile Photo File Input -->
        <input
          id="photo"
          ref="photoInput"
          type="file"
          class="hidden"
          @change="updatePhotoPreview"
        />

        <Label for="photo">Photo</Label>
        <!-- Current Profile Photo -->
        <div v-show="!photoPreview" class="mt-2">
          <img
            :src="user.profile_photo_url"
            :alt="user.name"
            class="rounded-full size-20 object-cover"
          />
        </div>

        <!-- New Profile Photo Preview -->
        <div v-show="photoPreview" class="mt-2">
          <span
            class="block rounded-full size-20 bg-cover bg-no-repeat bg-center"
            :style="'background-image: url(\'' + photoPreview + '\');'"
          />
        </div>

        <Button
          variant="outline"
          class="mt-2 me-2"
          type="button"
          @click.prevent="selectNewPhoto"
        >
          Select A New Photo</Button
        >

        <Button
          v-if="user.profile_photo_path"
          variant="destructive"
          type="button"
          class="mt-2"
          @click.prevent="deletePhoto"
        >
          Remove Photo</Button
        >
        <Label class="mt-1 text-destructive text-xs">{{ form.errors.photo }}</Label>
      </div>

      <!-- Name -->
      <div class="col-span-6 sm:col-span-4">
        <Label for="name" value="Name">Name</Label>
        <Input
          id="name"
          v-model="form.name"
          type="text"
          class="mt-1 block w-full"
          required
          autocomplete="name"
        />
        <Label class="mt-1 text-destructive text-xs">{{ form.errors.name }}</Label>
      </div>

      <!-- Email -->
      <div class="col-span-6 sm:col-span-4">
        <Label for="email" value="email">Email</Label>
        <Input
          id="email"
          v-model="form.email"
          type="email"
          class="mt-1 block w-full"
          required
          autocomplete="username"
        />
        <Label class="mt-1 text-destructive text-xs">{{ form.errors.email }}</Label>

        <div
          v-if="
            $page.props.jetstream.hasEmailVerification && user.email_verified_at === null
          "
        >
          <p class="text-sm mt-2">
            Your email address is unverified.

            <Link
              :href="route('verification.send')"
              method="post"
              as="button"
              class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
              @click.prevent="sendEmailVerification"
            >
              Click here to re-send the verification email.
            </Link>
          </p>

          <div
            v-show="verificationLinkSent"
            class="mt-2 font-medium text-sm text-green-600"
          >
            A new verification link has been sent to your email address.
          </div>
        </div>
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
