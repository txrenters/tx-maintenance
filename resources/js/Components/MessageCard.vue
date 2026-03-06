<script setup>
import { DateTime } from "luxon";
import { AlertCircle, CheckCheck, Clock3, Send, XIcon } from "lucide-vue-next";
import { computed } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { useToast } from "./ui/toast";

const { toast } = useToast();
const page = usePage();

const props = defineProps({
    messages: Object,
    sender: String,
});

const formatDate = (date) => {
    if (!date) return "------";

    let parsedDate;

    // Set timezone to America/Chicago (Dallas)
    const timezone = "America/Chicago";

    if (typeof date === "string") {
        if (date.includes("T")) {
            // Parse ISO string in UTC and convert to America/Chicago time
            parsedDate = DateTime.fromISO(date, { zone: "utc" }).setZone(
                timezone,
            );
        } else {
            // Parse custom formatted date string in UTC and convert to America/Chicago time
            parsedDate = DateTime.fromFormat(date, "yyyy-MM-dd", {
                zone: "utc",
            }).setZone(timezone);
        }
    } else if (date instanceof Date) {
        // If it's a JavaScript Date object, convert to America/Chicago time
        parsedDate = DateTime.fromJSDate(date).setZone(timezone);
    } else {
        return "Invalid Date";
    }

    return parsedDate.isValid
        ? parsedDate.toFormat("EEE, MMMM d, yyyy hh:mm a") // Format as desired
        : "Invalid Date";
};

const messages = computed(() => {
    return props.messages.map((msg) => {
        return {
            ...msg,
            created_at: formatDate(msg.created_at),
        };
    });
});

const isAdmin = computed(() =>
    (page.props.auth?.user?.roles || []).includes("admin"),
);
const getTwilioStatusLabel = (status) => {
    if (!status) return "";

    return String(status)
        .replace(/_/g, " ")
        .replace(/\b\w/g, (char) => char.toUpperCase());
};

const getTwilioStatusTextClasses = (status) => {
    switch (String(status).toLowerCase()) {
        case "undelivered":
        case "failed":
        case "canceled":
            return "text-red-300";
        default:
            return "text-white";
    }
};

const getTwilioStatusIcon = (status) => {
    switch (String(status || "").toLowerCase()) {
        case "delivered":
        case "read":
            return CheckCheck;
        case "sent":
            return Send;
        case "queued":
        case "accepted":
        case "sending":
        case "scheduled":
            return Clock3;
        case "undelivered":
        case "failed":
        case "canceled":
            return AlertCircle;
        default:
            return Clock3;
    }
};

const removeMessage = (id) => {
    router.post(
        route("workorder.message.delete", id),
        {
            _method: "delete",
        },
        {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                toast({
                    title: "Danger",
                    description: "Mesasge has been removed!",
                });

                const index = props.messages.findIndex((msg) => msg.id === id);
                if (index !== -1) {
                    props.messages.splice(index, 1);
                }
            },
        },
    );
};

const openMedia = (mediaUrl) => {
    if (mediaUrl) {
        window.open(mediaUrl, "_blank", "noopener,noreferrer");
    }
};
</script>
<template>
    <div
        v-for="msg in messages"
        :key="msg.id"
        class="mt-2 flex items-end"
        :class="msg.sender_number === sender ? 'flex-row-reverse' : 'flex-row'"
        v-motion-slide-visible-right
    >
        <div
            class="relative max-w-[70%] rounded-2xl px-4 py-2 text-sm shadow-md"
            :class="
                msg.sender_number !== sender
                    ? 'rounded-bl-none bg-white text-black'
                    : 'bg-primary text-primary-foreground rounded-br-none'
            "
        >
            <button
                v-if="isAdmin"
                type="button"
                class="absolute right-1 top-[-5px] flex items-center justify-center w-4 h-4 rounded-full bg-destructive text-white hover:bg-red-600 transition-colors"
                @click="removeMessage(msg.id)"
            >
                <XIcon class="w-3 h-3" />
            </button>

            <span
                class="text-md py-1 whitespace-pre-line"
                :class="
                    msg.sender_number === sender ? 'text-white' : 'text-black'
                "
                v-html="msg.message ?? msg.messages"
            ></span>
            <!-- Handle both formats: is_mms with media array OR single image property -->
            <div
                v-if="
                    (msg.is_mms && msg.media && msg.media.length > 0) ||
                    msg.image
                "
                class="mt-2"
            >
                <!-- Multiple media format (original conversation format) -->
                <img
                    v-for="media in msg.media || []"
                    :key="media.id"
                    :src="media.public_url"
                    :alt="media.file_name || 'Attached image'"
                    class="max-w-full h-auto rounded-lg shadow-sm cursor-pointer"
                    style="max-width: 300px; max-height: 200px"
                    @click="openMedia(media.public_url)"
                />
                <!-- Single image format (jobber text message format) -->
                <img
                    v-if="msg.image && !msg.media"
                    :src="msg.image"
                    :alt="'Attached image'"
                    class="max-w-full h-auto rounded-lg shadow-sm cursor-pointer"
                    style="max-width: 300px; max-height: 200px"
                    @click="openMedia(msg.image)"
                />
            </div>
            <div class="flex gap-20 items-center justify-between">
                <div class="flex items-center gap-2">
                    <p
                        class="text-xs"
                        :class="
                            msg.sender_number === sender
                                ? 'text-white'
                                : 'text-gray-500'
                        "
                    >
                        <span
                            v-if="
                                msg.sender_number === sender &&
                                msg.twilio_status
                            "
                            class="inline-flex items-center gap-1 font-semibold"
                            :class="
                                getTwilioStatusTextClasses(msg.twilio_status)
                            "
                            :title="
                                msg.twilio_error_message
                                    ? `Delivery error: ${msg.twilio_error_message}`
                                    : ''
                            "
                        >
                            <component
                                :is="getTwilioStatusIcon(msg.twilio_status)"
                                class="h-3 w-3"
                            />
                            {{ getTwilioStatusLabel(msg.twilio_status) }} -
                        </span>
                        {{ msg.created_at }}
                    </p>
                </div>
                <p
                    class="text-xs"
                    :class="
                        msg.sender_number === sender
                            ? 'text-white'
                            : 'text-gray-500'
                    "
                >
                    From: {{ msg.sender_number }}
                </p>
            </div>
        </div>
    </div>
</template>
