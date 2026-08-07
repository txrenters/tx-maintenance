<script setup>
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { router } from "@inertiajs/vue3";
import { Check, Copy } from "lucide-vue-next";
import AppLayout from "@/Layouts/AppLayout.vue";
import ActionSection from "@/Components/ActionSection.vue";

defineOptions({ layout: AppLayout });

const props = defineProps({
  title: String,
  connection: Object,
  plainTextToken: String,
  setupTokenTtl: Number,
  status: String,
});

const processing = ref(false);
const tokenDialogOpen = ref(Boolean(props.plainTextToken));
const copied = ref(false);
const copyFailed = ref(false);

let copyResetTimer;

watch(
  () => props.plainTextToken,
  (token) => {
    tokenDialogOpen.value = Boolean(token);
    copied.value = false;
    copyFailed.value = false;
  }
);

onBeforeUnmount(() => window.clearTimeout(copyResetTimer));

const statusLabel = computed(() => {
  if (props.connection.connected) return "Connected";

  return props.connection.awaiting_connection
    ? "Awaiting connection"
    : "Never connected";
});

const lastUsedLabel = computed(() => {
  if (!props.connection.last_used_at) return "Never used";

  return new Date(props.connection.last_used_at).toLocaleString();
});

// Revoking is useful the moment a code is outstanding; a device only shows up
// here once it has actually handshaken.
const hasAnyToken = computed(
  () => props.connection.connected || props.connection.awaiting_connection
);

const submit = (method, url) => {
  processing.value = true;

  router[method](
    url,
    {},
    {
      preserveScroll: true,
      onFinish: () => {
        processing.value = false;
      },
    }
  );
};

const generateToken = () =>
  submit("post", route("settings.desktop-notifications.store"));
const revokeToken = () =>
  submit("delete", route("settings.desktop-notifications.destroy"));
const sendTestNotification = () =>
  submit("post", route("settings.desktop-notifications.test"));

const copyToken = async () => {
  if (!props.plainTextToken) return;

  window.clearTimeout(copyResetTimer);

  try {
    await navigator.clipboard.writeText(props.plainTextToken);
    copied.value = true;
    copyFailed.value = false;
    copyResetTimer = window.setTimeout(() => (copied.value = false), 2000);
  } catch {
    // Clipboard access is blocked outside a secure context, and the token
    // cannot be shown again — say so rather than failing silently.
    copied.value = false;
    copyFailed.value = true;
  }
};
</script>

<template>
  <Head :title="title" />

  <div class="max-w-7xl mx-auto py-10 sm:px-6 lg:px-8">
    <ActionSection>
      <template #title>Desktop Notifications</template>
      <template #description>
        Connect the TexasRenters Desktop client to receive your notifications
        live on your computer.
      </template>

      <template #content>
        <div class="space-y-6">
          <dl class="grid gap-4 sm:grid-cols-3">
            <div>
              <dt class="text-sm text-muted-foreground">Status</dt>
              <dd class="mt-1">
                <Badge :variant="connection.connected ? 'default' : 'secondary'">
                  {{ statusLabel }}
                </Badge>
              </dd>
            </div>
            <div>
              <dt class="text-sm text-muted-foreground">Device</dt>
              <dd class="mt-1 text-sm">{{ connection.device_name ?? "—" }}</dd>
            </div>
            <div>
              <dt class="text-sm text-muted-foreground">Last used</dt>
              <dd class="mt-1 text-sm">
                {{ connection.connected ? lastUsedLabel : "—" }}
              </dd>
            </div>
          </dl>

          <p v-if="status" class="text-sm text-green-600">{{ status }}</p>

          <p
            v-if="connection.awaiting_connection"
            class="text-sm text-muted-foreground"
          >
            A connection token is waiting to be used. Paste it into the
            TexasRenters Desktop client — once it connects, this page will show
            the device and you can send a test notification.
          </p>

          <div class="flex flex-wrap items-center gap-3">
            <Button :disabled="processing" @click="generateToken">
              Generate Desktop Connection Token
            </Button>
            <!-- Always available: the browser subscribes to the same private
                 channel as the desktop, so this doubles as a check that the
                 queue and Reverb are alive. -->
            <Button
              variant="outline"
              :disabled="processing"
              @click="sendTestNotification"
            >
              Send Test Notification
            </Button>
            <Button
              variant="destructive"
              :disabled="processing || !hasAnyToken"
              @click="revokeToken"
            >
              Revoke Token
            </Button>
          </div>

          <p class="text-sm text-muted-foreground">
            A test notification is broadcast on your private channel, so it
            appears in this browser as well as on any connected desktop — useful
            for checking the connection from either end. Revoking disconnects
            every desktop client signed in to this account. Notifications raised
            while your computer is asleep or offline are not replayed.
          </p>
        </div>
      </template>
    </ActionSection>
  </div>

  <Dialog v-model:open="tokenDialogOpen">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Your desktop connection token</DialogTitle>
        <DialogDescription>
          Copy this token now. It will never be displayed again. It expires in
          {{ setupTokenTtl }} minutes, and the desktop client swaps it for a
          permanent one as soon as you connect.
        </DialogDescription>
      </DialogHeader>

      <!-- min-w-0: DialogContent is a grid, whose items default to
           min-width:auto and would otherwise stretch to the token's full
           unwrapped width instead of scrolling. -->
      <div class="relative min-w-0 rounded-lg bg-muted">
        <code
          class="block overflow-x-auto whitespace-nowrap py-2.5 pl-3 pr-14 font-mono text-xs select-all"
          >{{ plainTextToken }}</code
        >

        <!-- Fades the token out under the button rather than letting it run beneath it. -->
        <div
          class="pointer-events-none absolute inset-y-0 right-0 w-14 rounded-r-lg bg-gradient-to-l from-muted to-transparent"
        />

        <button
          type="button"
          class="absolute right-2 top-1/2 -translate-y-1/2 rounded-md border bg-background p-1.5 text-muted-foreground shadow-sm transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          :aria-label="copied ? 'Token copied' : 'Copy token'"
          @click="copyToken"
        >
          <Check v-if="copied" class="size-4 text-green-600" />
          <Copy v-else class="size-4" />
        </button>
      </div>

      <p v-if="copyFailed" class="text-xs text-destructive">
        Couldn't reach the clipboard — select the token above and copy it
        manually.
      </p>
      <p
        v-else
        class="h-4 text-xs"
        :class="copied ? 'text-green-600' : 'text-muted-foreground'"
      >
        {{ copied ? "Copied to clipboard" : "Click the icon to copy" }}
      </p>

      <DialogFooter>
        <Button @click="tokenDialogOpen = false">Close</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
