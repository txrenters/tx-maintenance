<script setup>
import { ref, watch } from "vue";
import { router } from "@inertiajs/vue3";
import RichTextEditor from "@/Components/RichTextEditor.vue";
import { useToast } from "@/Components/ui/toast/use-toast";

const props = defineProps({
    workOrder: { type: Object, required: true },
    vendorEmails: { type: Array, default: () => [] },
    vendorId: { type: Number, default: null },
    senderEmail: { type: String, default: "" },
    recipientEmail: { type: String, default: "" },
});

const emit = defineEmits(["update-vendor-email"]);
const { toast } = useToast();

const subject = ref("");
const body = ref("");
const files = ref([]);
const fileInput = ref(null);
const sending = ref(false);
const composeOpen = ref(false);
const fromEmail = ref(props.senderEmail);
const toEmail = ref(props.recipientEmail);

watch(() => props.senderEmail, (email) => { fromEmail.value = email; });
watch(() => props.recipientEmail, (email) => { toEmail.value = email; });

const onFiles = (event) => {
    files.value = Array.from(event.target.files ?? []);
};

const formatDate = (value) =>
    value
        ? new Intl.DateTimeFormat(undefined, {
              dateStyle: "medium",
              timeStyle: "short",
          }).format(new Date(value))
        : "";

const send = () => {
    if (!props.vendorId || !subject.value.trim() || !body.value.trim()) {
        return;
    }

    sending.value = true;

    const formData = new FormData();
    formData.append("vendor_id", props.vendorId);
    formData.append("from_email", fromEmail.value);
    formData.append("to", toEmail.value);
    formData.append("subject", subject.value.trim());
    formData.append("body", body.value);
    files.value.forEach((file) => formData.append("attachments[]", file));

    router.post(route("work_order.email.send", props.workOrder.id), formData, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            subject.value = "";
            body.value = "";
            files.value = [];
            composeOpen.value = false;

            if (fileInput.value) {
                fileInput.value.value = "";
            }

            toast({
                title: "Email sent",
                description: "The vendor email was sent and added to the thread.",
            });
            emit("update-vendor-email");
        },
        onError: () => {
            toast({
                variant: "destructive",
                title: "Email not sent",
                description: "Check the email details and try again.",
            });
        },
        onFinish: () => {
            sending.value = false;
        },
    });
};
</script>

<template>
    <div class="flex min-h-[32rem] flex-col">
        <div class="flex-1 space-y-3 overflow-y-auto p-2">
            <p
                v-if="!vendorEmails.length"
                class="py-8 text-center text-sm text-muted-foreground"
            >
                No vendor emails yet.
            </p>

            <article
                v-for="email in vendorEmails"
                :key="email.id"
                class="rounded-lg border p-3"
                :class="
                    email.direction === 'outbound'
                        ? 'ml-4 border-primary bg-primary text-primary-foreground shadow-md sm:ml-8'
                        : 'mr-4 bg-white text-black shadow-md dark:bg-muted dark:text-foreground sm:mr-8'
                "
            >
                <div
                    class="flex flex-col gap-1 text-xs sm:flex-row sm:items-center sm:justify-between"
                    :class="email.direction === 'outbound' ? 'text-primary-foreground/80' : 'text-muted-foreground'"
                >
                    <span class="font-semibold">
                        {{ email.direction === "outbound" ? "Sent to" : "Received from" }}
                        {{ email.direction === "outbound" ? email.to_email : email.from_email }}
                    </span>
                    <time>{{ formatDate(email.emailed_at) }}</time>
                </div>
                <h3 class="mt-2 text-sm font-medium">{{ email.subject }}</h3>
                <div
                    v-if="email.body_html"
                    class="prose prose-sm mt-2 max-w-none"
                    v-html="email.body_html"
                />
                <p v-else class="mt-2 whitespace-pre-wrap text-sm">
                    {{ email.body_text }}
                </p>

                <div
                    v-if="email.attachments?.length"
                    class="mt-3 flex flex-wrap gap-2"
                >
                    <a
                        v-for="attachment in email.attachments"
                        :key="attachment.id"
                        :href="route('work_order.email.attachment', attachment.id)"
                        class="rounded border border-input bg-background px-2 py-1 text-xs text-primary hover:underline"
                    >
                        {{ attachment.filename }}
                    </a>
                </div>
                <p
                    v-else-if="email.direction === 'inbound' && email.has_attachments"
                    class="mt-2 text-xs italic text-muted-foreground"
                >
                    Attachments could not be retrieved. Check the mailbox.
                </p>
            </article>
        </div>

        <div class="flex justify-end border-t border-border p-2 pt-4">
            <Button type="button" :disabled="!vendorId" @click="composeOpen = true">
                Compose Email
            </Button>
        </div>

        <Dialog v-model:open="composeOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Compose vendor email</DialogTitle>
                    <DialogDescription>This email will be saved in the vendor thread.</DialogDescription>
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="send">
            <p v-if="!vendorId" class="text-sm text-destructive">
                Assign a vendor before sending an email.
            </p>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="flex flex-col gap-1 text-sm">
                    <span class="font-medium text-muted-foreground">From</span>
                    <input v-model="fromEmail" type="email" required class="rounded-md border border-input bg-background px-3 py-2 text-sm" />
                </label>
                <label class="flex flex-col gap-1 text-sm">
                    <span class="font-medium text-muted-foreground">To</span>
                    <input v-model="toEmail" type="email" required class="rounded-md border border-input bg-background px-3 py-2 text-sm" />
                </label>
            </div>
            <input
                v-model="subject"
                type="text"
                placeholder="Subject"
                maxlength="255"
                required
                :disabled="!vendorId || sending"
                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-50"
            />
            <RichTextEditor v-model="body" />
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <input
                    ref="fileInput"
                    type="file"
                    multiple
                    :disabled="!vendorId || sending"
                    class="text-xs file:mr-3 file:rounded-md file:border-0 file:bg-muted file:px-3 file:py-2 file:text-xs file:font-medium"
                    @change="onFiles"
                />
                <button
                    type="submit"
                    :disabled="!vendorId || sending || !subject.trim() || !body.trim()"
                    class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {{ sending ? "Sending..." : "Send Email" }}
                </button>
            </div>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
