<script setup>
import { ref, reactive, nextTick } from "vue";
import DialogModal from "./DialogModal.vue";
import InputError from "./InputError.vue";
import { Loader2 } from "lucide-vue-next";

const emit = defineEmits(["confirmed"]);

defineProps({
  title: {
    type: String,
    default: "Confirm Password",
  },
  content: {
    type: String,
    default: "For your security, please confirm your password to continue.",
  },
  button: {
    type: String,
    default: "Confirm",
  },
});

const confirmingPassword = ref(false);

const form = reactive({
  password: "",
  error: "",
  processing: false,
});

const passwordInput = ref(null);

const startConfirmingPassword = () => {
  axios.get(route("password.confirmation")).then((response) => {
    if (response.data.confirmed) {
      emit("confirmed");
    } else {
      confirmingPassword.value = true;

      setTimeout(() => passwordInput.value.focus(), 250);
    }
  });
};

const confirmPassword = () => {
  form.processing = true;

  axios
    .post(route("password.confirm"), {
      password: form.password,
    })
    .then(() => {
      form.processing = false;

      closeModal();
      nextTick().then(() => emit("confirmed"));
    })
    .catch((error) => {
      form.processing = false;
      form.error = error.response.data.errors.password[0];
      passwordInput.value.focus();
    });
};

const closeModal = () => {
  confirmingPassword.value = false;
  form.password = "";
  form.error = "";
};
</script>

<template>
  <span>
    <span @click="startConfirmingPassword">
      <slot />
    </span>

    <Dialog v-model:open="confirmingPassword">
      <DialogContent class="sm:max-w-[600px]">
        <DialogHeader>
          <DialogTitle> {{ title }}</DialogTitle>
          <DialogDescription>
            {{ content }}
          </DialogDescription>
        </DialogHeader>
        <div>
          <Input
            ref="passwordInput"
            v-model="form.password"
            type="password"
            class="mt-1 block w-3/4"
            placeholder="Password"
            autocomplete="current-password"
            @keyup.enter="confirmPassword"
          />
          <Label class="mt-1 text-destructive text-xs">{{ form.error }}</Label>
        </div>
        <DialogFooter class="gap-2">
          <Button type="button" variant="outline" @click="closeModal"> Cancel</Button>
          <Button
            type="submit"
            variant="destructive"
            :disabled="form.processing"
            @click.prevent="confirmPassword"
          >
            <Loader2 v-if="form.processing" class="w-4 h-4 animate-spin" />
            {{ button }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </span>
</template>
