<script setup>
import { DateTime } from "luxon";
import {
    AlertCircle,
    CheckCheck,
    Clock3,
    FileIcon,
    Loader2,
    RotateCw,
    Send,
    XIcon,
} from "lucide-vue-next";
import { computed, ref } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import axios from "axios";
import { useToast } from "./ui/toast";
import {
    friendlyTwilioError,
    isRetryableTwilioError,
} from "@/utils/twilioErrorCatalog.js";
import { linkifyParts } from "@/utils/linkify.js";
import { detectTapback, quotedExcerpt } from "@/utils/tapback.js";

const { toast } = useToast();
const page = usePage();

const props = defineProps({
    messages: Object,
    sender: String,
    /** Accepted for API compatibility with the conversation tabs; unused. */
    participants: { type: Object, default: () => ({}) },
    showDelete: { type: Boolean, default: true },
    /** Accepted for API compatibility; the To/From numbers always render. */
    showNumbers: { type: Boolean, default: false },
});

const TIMEZONE = "America/Chicago";

const digitsOf = (value) => String(value ?? "").replace(/\D+/g, "");

const phoneKey = (value) => {
    const digits = digitsOf(value);
    return digits.length >= 10 ? digits.slice(-10) : "";
};

const toDateTime = (date) => {
    if (!date) return null;

    if (typeof date === "string") {
        const parsed = date.includes("T")
            ? DateTime.fromISO(date, { zone: "utc" })
            : DateTime.fromFormat(date, "yyyy-MM-dd HH:mm:ss", { zone: "utc" });

        const usable = parsed.isValid
            ? parsed
            : DateTime.fromFormat(date, "yyyy-MM-dd", { zone: "utc" });

        return usable.isValid ? usable.setZone(TIMEZONE) : null;
    }

    if (date instanceof Date) {
        return DateTime.fromJSDate(date).setZone(TIMEZONE);
    }

    return null;
};

const formatDate = (date) => {
    const parsed = toDateTime(date);
    if (!date) return "------";
    return parsed ? parsed.toFormat("EEE, MMMM d, yyyy hh:mm a") : "Invalid Date";
};

/**
 * Direction is inferred by comparing the sender against "our" number. Compare
 * on the last ten digits so formatting drift (+1 vs 1 vs bare) still matches,
 * but never treat an unknown sender as ours when we have no number to compare.
 */
const isOutbound = (msg) => {
    if (msg?.chatbot_direction) {
        return msg.chatbot_direction === "outbound";
    }
    const ours = phoneKey(props.sender);
    const theirs = phoneKey(msg?.sender_number);

    if (!ours || !theirs) {
        return msg?.sender_number === props.sender && !!props.sender;
    }

    return ours === theirs;
};

const sourceMessages = computed(() =>
    Array.isArray(props.messages) ? props.messages : [],
);

/**
 * An inbound iPhone tapback ("Liked \"…\"") renders as a compact reaction
 * chip instead of a bubble quoting the entire original message. Outbound
 * messages never qualify: only the other party reacts to us.
 */
const tapbackOf = (msg) =>
    isOutbound(msg) ? null : detectTapback(msg?.message ?? msg?.messages);

const isAdmin = computed(() =>
    (page.props.auth?.user?.roles || []).includes("admin"),
);

const getTwilioStatusLabel = (status) => {
    if (!status) return "";

    return String(status)
        .replace(/_/g, " ")
        .replace(/\b\w/g, (char) => char.toUpperCase());
};

/** Only these two are re-sendable — a canceled message is not retried. */
const isFailedStatus = (status) => {
    if (!status) return false;
    const normalized = String(status).toLowerCase();
    return normalized === "failed" || normalized === "undelivered";
};

/** Anything the coordinator should see as a delivery problem. */
const isErrorStatus = (status) => {
    if (!status) return false;
    return (
        isFailedStatus(status) || String(status).toLowerCase() === "canceled"
    );
};

const getTwilioStatusTextClasses = (status) =>
    isErrorStatus(status) ? "text-destructive" : "";

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

                const index = sourceMessages.value.findIndex(
                    (msg) => msg.id === id,
                );
                if (index !== -1) {
                    sourceMessages.value.splice(index, 1);
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

// Classify an attachment so the bubble renders the right element. Prefer the
// stored content_type; fall back to the file extension for older rows.
const mediaKind = (media) => {
    const type = (media?.content_type || "").toLowerCase();
    const name = (media?.file_name || "").toLowerCase();

    if (type.startsWith("video/") || /\.(mp4|mov|m4v|3gp|3gpp|webm)$/.test(name)) {
        return "video";
    }
    if (type === "application/pdf" || name.endsWith(".pdf")) {
        return "pdf";
    }
    return "image";
};

const resendingIds = ref(new Set());

const canResend = (msg) => {
    if (!isFailedStatus(msg.twilio_status)) return false;
    if (!isRetryableTwilioError(msg.twilio_error_code)) return false;
    // Only outbound (messages from "us") are eligible — we cannot resend
    // an inbound reply on the tenant's behalf.
    return isOutbound(msg);
};

const resendMessage = async (msg) => {
    if (!canResend(msg) || resendingIds.value.has(msg.id)) return;

    const url = msg.work_order_id
        ? `/api/conversations/${msg.id}/resend`
        : msg.jobber_id
          ? `/api/jobber-text-messages/${msg.id}/resend`
          : null;

    if (!url) {
        toast({
            variant: "destructive",
            title: "Can't resend",
            description: "This message can't be identified for resending.",
        });
        return;
    }

    resendingIds.value.add(msg.id);
    try {
        await axios.post(url);
        toast({
            title: "Message queued",
            description: "Twilio is redelivering this message.",
        });
    } catch (error) {
        const description =
            error?.response?.data?.error ||
            error?.response?.data?.message ||
            "Could not resend. Please try again.";
        toast({
            variant: "destructive",
            title: "Resend failed",
            description,
        });
    } finally {
        resendingIds.value.delete(msg.id);
    }
};
</script>
<template>
    <div
        v-for="(msg, index) in sourceMessages"
        :key="msg.id ?? `msg-${index}`"
        class="mt-2 flex items-end"
        :class="isOutbound(msg) ? 'flex-row-reverse' : 'flex-row'"
        v-motion-slide-visible-right
    >
        <div
            class="relative max-w-[70%] rounded-2xl px-4 py-2 text-sm shadow-md"
            :class="[
                isOutbound(msg)
                    ? 'bg-primary text-white rounded-br-none'
                    : 'rounded-bl-none bg-white text-black',
                msg.__pending ? 'opacity-70' : '',
            ]"
        >
            <button
                v-if="isAdmin && showDelete && !msg.__pending"
                type="button"
                class="absolute right-1 top-[-5px] flex items-center justify-center w-4 h-4 rounded-full bg-destructive text-white hover:bg-red-600 transition-colors"
                @click="removeMessage(msg.id)"
            >
                <XIcon class="w-3 h-3" />
            </button>

            <p
                class="text-xs mb-1"
                :class="isOutbound(msg) ? 'text-white/70' : 'text-gray-500'"
            >
                To: {{ msg.receiver_number }}
            </p>
            <span
                v-if="!tapbackOf(msg)"
                class="text-md py-1 whitespace-pre-line break-words [overflow-wrap:anywhere]"
                :class="isOutbound(msg) ? 'text-white' : 'text-black'"
            >
                <template
                    v-for="(part, partIndex) in linkifyParts(
                        msg.message ?? msg.messages,
                    )"
                    :key="partIndex"
                >
                    <a
                        v-if="part.type === 'link'"
                        :href="part.href"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="underline underline-offset-2 hover:opacity-80"
                        >{{ part.value }}</a
                    >
                    <template v-else>{{ part.value }}</template>
                </template>
            </span>
            <span
                v-else
                class="flex items-center gap-1.5 py-1 text-sm break-words [overflow-wrap:anywhere]"
                :class="[
                    isOutbound(msg) ? 'text-white' : 'text-black',
                    tapbackOf(msg).removal ? 'opacity-60' : '',
                ]"
            >
                <span class="text-base leading-none">{{
                    tapbackOf(msg).emoji
                }}</span>
                <span>{{ tapbackOf(msg).label }}</span>
                <span
                    v-if="quotedExcerpt(tapbackOf(msg))"
                    class="text-xs italic"
                    :class="isOutbound(msg) ? 'text-white/60' : 'text-gray-400'"
                    >“{{ quotedExcerpt(tapbackOf(msg)) }}”</span
                >
            </span>
            <!-- Handle both formats: is_mms with media array OR single image property -->
            <div
                v-if="
                    (msg.is_mms && msg.media && msg.media.length > 0) ||
                    msg.image
                "
                class="mt-2"
            >
                <!-- Multiple media format (original conversation format) -->
                <template v-for="media in msg.media || []" :key="media.id">
                    <video
                        v-if="mediaKind(media) === 'video'"
                        :src="media.public_url"
                        controls
                        playsinline
                        class="max-w-full h-auto rounded-lg shadow-sm bg-black"
                        style="max-width: 300px; max-height: 200px"
                    />
                    <button
                        v-else-if="mediaKind(media) === 'pdf'"
                        type="button"
                        class="flex items-center gap-2 max-w-[300px] rounded-lg border bg-white/90 px-3 py-2 text-left text-sm text-gray-700 shadow-sm cursor-pointer hover:bg-white"
                        @click="openMedia(media.public_url)"
                    >
                        <FileIcon class="h-5 w-5 shrink-0 text-red-500" />
                        <span class="truncate">{{ media.file_name || "Document.pdf" }}</span>
                    </button>
                    <img
                        v-else
                        :src="media.public_url"
                        :alt="media.file_name || 'Attached image'"
                        class="max-w-full h-auto rounded-lg shadow-sm cursor-pointer"
                        style="max-width: 300px; max-height: 200px"
                        @click="openMedia(media.public_url)"
                    />
                </template>
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
                            isOutbound(msg) ? 'text-white' : 'text-gray-500'
                        "
                    >
                        <span
                            v-if="msg.twilio_status"
                            class="inline-flex items-center gap-1 font-semibold"
                            :class="
                                getTwilioStatusTextClasses(msg.twilio_status)
                            "
                            :title="
                                friendlyTwilioError(
                                    msg.twilio_error_code,
                                    msg.twilio_error_message,
                                )
                                    ? `Delivery error: ${friendlyTwilioError(msg.twilio_error_code, msg.twilio_error_message)}`
                                    : ''
                            "
                        >
                            <component
                                :is="getTwilioStatusIcon(msg.twilio_status)"
                                class="h-3 w-3"
                            />
                            {{ getTwilioStatusLabel(msg.twilio_status) }} -
                        </span>
                        {{ formatDate(msg.created_at) }}
                    </p>
                </div>
                <p
                    class="text-xs"
                    :class="isOutbound(msg) ? 'text-white' : 'text-gray-500'"
                >
                    From: {{ msg.chatbot_sender_name || msg.sender_number }}
                </p>
            </div>
            <p
                v-if="
                    friendlyTwilioError(
                        msg.twilio_error_code,
                        msg.twilio_error_message,
                    )
                "
                class="mt-1 text-xs text-red-600"
            >
                Error:
                {{
                    friendlyTwilioError(
                        msg.twilio_error_code,
                        msg.twilio_error_message,
                    )
                }}
            </p>
            <button
                v-if="canResend(msg)"
                type="button"
                :disabled="resendingIds.has(msg.id)"
                class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-red-700 hover:text-red-900 disabled:opacity-60"
                title="Resend this message via Twilio"
                @click="resendMessage(msg)"
            >
                <Loader2
                    v-if="resendingIds.has(msg.id)"
                    class="h-3 w-3 animate-spin"
                />
                <RotateCw v-else class="h-3 w-3" />
                {{ resendingIds.has(msg.id) ? "Resending…" : "Retry" }}
            </button>
        </div>
    </div>
</template>
