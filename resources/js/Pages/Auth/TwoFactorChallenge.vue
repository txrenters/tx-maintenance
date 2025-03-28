<script setup>
import { nextTick, ref } from "vue";
import { Head, useForm } from "@inertiajs/vue3";
import InputError from "@/Components/InputError.vue";
import InputLabel from "@/Components/InputLabel.vue";
import AuthLayout from "@/Layouts/AuthLayout.vue";

defineOptions({ layout: AuthLayout });
const recovery = ref(false);

const form = useForm({
  code: "",
  recovery_code: "",
});

const recoveryCodeInput = ref(null);
const codeInput = ref(null);

const toggleRecovery = async () => {
  recovery.value ^= true;

  await nextTick();

  if (recovery.value) {
    recoveryCodeInput.value.focus();
    form.code = "";
  } else {
    codeInput.value.focus();
    form.recovery_code = "";
  }
};

const submit = () => {
  form.post(route("two-factor.login"));
};
</script>

<template>
  <Head title="Two-factor Confirmation" />

  <div>
    <Card>
      <CardHeader class="text-center">
        <CardTitle class="text-xl"> </CardTitle>
        <CardDescription>
          <div v-if="!recovery">
            Please confirm access to your account by entering the authentication code
            provided by your authenticator application.
          </div>

          <div v-else>
            Please confirm access to your account by entering one of your emergency
            recovery codes.
          </div>
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form @submit.prevent="submit">
          <div v-if="!recovery">
            <InputLabel for="code" value="Code" />
            <Textarea
              id="code"
              ref="codeInput"
              v-model="form.code"
              type="text"
              inputmode="numeric"
              class="mt-1 block w-full"
              autofocus
              autocomplete="one-time-code"
            />
            <InputError class="mt-2" :message="form.errors.code" />
          </div>

          <div v-else>
            <InputLabel for="recovery_code" value="Recovery Code" />
            <Textarea
              id="recovery_code"
              ref="recoveryCodeInput"
              v-model="form.recovery_code"
              type="text"
              class="mt-1 block w-full"
              autocomplete="one-time-code"
            />
            <InputError class="mt-2" :message="form.errors.recovery_code" />
          </div>

          <div class="flex items-center justify-end mt-4">
            <button
              type="button"
              class="text-sm text-gray-600 hover:text-gray-900 underline cursor-pointer"
              @click.prevent="toggleRecovery"
            >
              <template v-if="!recovery"> Use a recovery code </template>

              <template v-else> Use an authentication code </template>
            </button>

            <PrimaryButton
              class="ms-4"
              :class="{ 'opacity-25': form.processing }"
              :disabled="form.processing"
            >
              Log in
            </PrimaryButton>
          </div>
        </form>
      </CardContent>
    </Card>
  </div>
</template>
