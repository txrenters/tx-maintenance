<script setup>
import { DateTime } from "luxon";
import {
    AlertCircle,
    CheckCheck,
    Clock3,
    FileIcon,
    Loader2,
    MessageSquare,
    RotateCw,
    Send,
    XIcon,
} from "lucide-vue-next";
import { computed, ref } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import axios from "axios";
import { useToast } from "./ui/toast";
import { Avatar, AvatarFallback, AvatarImage } from "./ui/avatar";
import {
    friendlyTwilioError,
    isRetryableTwilioError,
} from "@/utils/twilioErrorCatalog.js";
import { linkifyParts } from "@/utils/linkify.js";

const { toast } = useToast();
const page = usePage();

const props = defineProps({
    messages: Object,
    sender: String,
    /**
     * Phone number -> identity map so bubbles can show who is talking instead
     * of a raw number. Keys may be in any format; they are normalised to the
     * last ten digits, the same way Conversation::lastTenDigits() matches.
     *
     * @type {Object<string, {name?: string, role?: string, avatar?: string}>}
     */
    participants: { type: Object, default: () => ({}) },
    showDelete: { type: Boolean, default: true },
    /** Escape hatch: print the raw To/From numbers under each message group. */
    showNumbers: { type: Boolean, default: false },
});

const TIMEZONE = "America/Chicago";

/** Messages sent within this window by the same person share one group. */
const GROUP_WINDOW_MINUTES = 5;

const digitsOf = (value) => String(value ?? "").replace(/\D+/g, "");

const phoneKey = (value) => {
    const digits = digitsOf(value);
    return digits.length >= 10 ? digits.slice(-10) : "";
};

/** Fall back to a human-readable number when we cannot resolve a name. */
const formatPhone = (value) => {
    const raw = String(value ?? "").trim();
    const digits = digitsOf(raw);

    if (digits.length === 10) {
        return `(${digits.slice(0, 3)}) ${digits.slice(3, 6)}-${digits.slice(6)}`;
    }
    if (digits.length === 11 && digits.startsWith("1")) {
        return `+1 (${digits.slice(1, 4)}) ${digits.slice(4, 7)}-${digits.slice(7)}`;
    }

    return raw || "Unknown";
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

/** Long form, kept for the hover tooltip. */
const formatDate = (date) => {
    const parsed = toDateTime(date);
    if (!date) return "------";
    return parsed ? parsed.toFormat("EEE, MMMM d, yyyy hh:mm a") : "Invalid Date";
};

const formatDayLabel = (parsed) => {
    if (!parsed) return "";

    const today = DateTime.now().setZone(TIMEZONE).startOf("day");
    const day = parsed.startOf("day");
    const diffDays = today.diff(day, "days").days;

    if (diffDays === 0) return "Today";
    if (diffDays === 1) return "Yesterday";
    if (day.year === today.year) return parsed.toFormat("EEE, MMM d");

    return parsed.toFormat("MMM d, yyyy");
};

const participantLookup = computed(() => {
    const lookup = {};

    for (const [number, identity] of Object.entries(props.participants || {})) {
        const key = phoneKey(number);
        if (key && identity) {
            lookup[key] = identity;
        }
    }

    return lookup;
});

const identityFor = (number) => {
    const identity = participantLookup.value[phoneKey(number)] || {};

    return {
        name: identity.name || formatPhone(number),
        role: identity.role || "",
        avatar: identity.avatar || "",
        // Shown alongside a resolved name so a client texting from a new or
        // different number is visible at a glance. Blank when the name already
        // IS the number (unknown sender), to avoid printing it twice.
        phone: identity.name ? formatPhone(number) : "",
    };
};

const initialsFor = (name) =>
    String(name || "")
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join("") || "?";

/**
 * Direction is inferred by comparing the sender against "our" number. Compare
 * on the last ten digits so formatting drift (+1 vs 1 vs bare) still matches,
 * but never treat an unknown sender as ours when we have no number to compare.
 */
const isOutbound = (msg) => {
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
 * Flatten the thread into render rows: day separators plus messages tagged with
 * their position in a group, so consecutive messages from one person collapse
 * into a single visual block.
 */
const rows = computed(() => {
    const list = sourceMessages.value;
    const built = [];
    let previous = null;
    let previousParsed = null;

    list.forEach((msg, index) => {
        const parsed = toDateTime(msg.created_at);
        const dayKey = parsed ? parsed.toISODate() : null;
        const previousDayKey = previousParsed ? previousParsed.toISODate() : null;
        const outbound = isOutbound(msg);

        if (parsed && dayKey !== previousDayKey) {
            built.push({
                kind: "day",
                key: `day-${dayKey}-${index}`,
                label: formatDayLabel(parsed),
            });
        }

        const sameDay = dayKey !== null && dayKey === previousDayKey;
        const withinWindow =
            parsed && previousParsed
                ? Math.abs(parsed.diff(previousParsed, "minutes").minutes) <=
                  GROUP_WINDOW_MINUTES
                : false;

        const continuesGroup =
            previous !== null &&
            sameDay &&
            withinWindow &&
            isOutbound(previous) === outbound &&
            phoneKey(previous.sender_number) === phoneKey(msg.sender_number);

        if (continuesGroup) {
            built[built.length - 1].lastOfGroup = false;
        }

        built.push({
            kind: "msg",
            key: msg.id ?? `msg-${index}`,
            msg,
            outbound,
            firstOfGroup: !continuesGroup,
            lastOfGroup: true,
            identity: outbound ? null : identityFor(msg.sender_number),
            shortTime: parsed ? parsed.toFormat("h:mm a") : "",
            fullTimestamp: formatDate(msg.created_at),
        });

        previous = msg;
        previousParsed = parsed;
    });

    return built;
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
        v-if="!rows.length"
        class="flex min-h-[200px] flex-col items-center justify-center gap-2 text-center"
    >
        <MessageSquare class="text-muted-foreground/50 h-8 w-8" />
        <p class="text-muted-foreground text-sm">No messages yet.</p>
    </div>

    <template v-for="row in rows" :key="row.key">
        <!-- Day separator -->
        <div v-if="row.kind === 'day'" class="my-4 flex items-center gap-3">
            <div class="bg-border h-px flex-1" />
            <span
                class="bg-background text-muted-foreground rounded-full px-2 py-0.5 text-[11px] font-medium"
            >
                {{ row.label }}
            </span>
            <div class="bg-border h-px flex-1" />
        </div>

        <div
            v-else
            class="flex w-full gap-2"
            :class="[
                row.outbound ? 'justify-end' : 'justify-start',
                row.firstOfGroup ? 'mt-3' : 'mt-0.5',
            ]"
        >
            <!-- Avatar rail: only the last bubble of an inbound group carries a
                 face, the rest keep the same indent via the empty spacer. -->
            <div v-if="!row.outbound" class="w-7 shrink-0 self-end">
                <Avatar
                    v-if="row.lastOfGroup"
                    class="h-7 w-7"
                    :title="row.msg.sender_number"
                >
                    <AvatarImage v-if="row.identity.avatar" :src="row.identity.avatar" />
                    <AvatarFallback class="text-[10px]">
                        {{ initialsFor(row.identity.name) }}
                    </AvatarFallback>
                </Avatar>
            </div>

            <div
                class="group relative min-w-0 max-w-[85%] rounded-lg px-3 py-2 text-sm shadow-sm sm:max-w-[70%]"
                :class="[
                    row.outbound
                        ? 'bg-primary text-primary-foreground'
                        : 'bg-background text-foreground border',
                    row.outbound && row.lastOfGroup ? 'rounded-br-sm' : '',
                    !row.outbound && row.lastOfGroup ? 'rounded-bl-sm' : '',
                    row.msg.__pending ? 'opacity-70' : '',
                ]"
            >
                <button
                    v-if="isAdmin && showDelete && !row.msg.__pending"
                    type="button"
                    title="Delete message"
                    class="bg-destructive text-destructive-foreground absolute -top-2 flex h-5 w-5 items-center justify-center rounded-full opacity-0 shadow transition-opacity focus-visible:opacity-100 group-hover:opacity-100"
                    :class="row.outbound ? '-left-2' : '-right-2'"
                    @click="removeMessage(row.msg.id)"
                >
                    <XIcon class="h-3 w-3" />
                </button>

                <p
                    v-if="!row.outbound && row.firstOfGroup"
                    class="text-muted-foreground mb-0.5 text-xs font-semibold"
                    :title="row.msg.sender_number"
                >
                    {{ row.identity.name
                    }}<span v-if="row.identity.role" class="font-normal">
                        · {{ row.identity.role }}</span
                    ><span v-if="row.identity.phone" class="font-normal">
                        · {{ row.identity.phone }}</span
                    >
                </p>

                <p class="whitespace-pre-line break-words [overflow-wrap:anywhere]">
                    <template
                        v-for="(part, index) in linkifyParts(
                            row.msg.message ?? row.msg.messages,
                        )"
                        :key="index"
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
                </p>

                <!-- Handle both formats: is_mms with media array OR single image property -->
                <div
                    v-if="
                        (row.msg.is_mms &&
                            row.msg.media &&
                            row.msg.media.length > 0) ||
                        row.msg.image
                    "
                    class="mt-2"
                    :class="
                        (row.msg.media?.length ?? 0) > 1
                            ? 'grid grid-cols-2 gap-1'
                            : ''
                    "
                >
                    <!-- Multiple media format (original conversation format) -->
                    <template v-for="media in row.msg.media || []" :key="media.id">
                        <video
                            v-if="mediaKind(media) === 'video'"
                            :src="media.public_url"
                            controls
                            playsinline
                            class="h-auto max-h-52 w-full max-w-[300px] rounded-lg bg-black shadow-sm"
                        />
                        <button
                            v-else-if="mediaKind(media) === 'pdf'"
                            type="button"
                            class="bg-background text-foreground hover:bg-accent flex max-w-[300px] cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-left text-sm shadow-sm"
                            @click="openMedia(media.public_url)"
                        >
                            <FileIcon class="text-destructive h-5 w-5 shrink-0" />
                            <span class="truncate">{{
                                media.file_name || "Document.pdf"
                            }}</span>
                        </button>
                        <img
                            v-else
                            :src="media.public_url"
                            :alt="media.file_name || 'Attached image'"
                            class="max-h-52 w-full max-w-[300px] cursor-pointer rounded-lg object-cover shadow-sm"
                            @click="openMedia(media.public_url)"
                        />
                    </template>
                    <!-- Single image format (jobber text message format) -->
                    <img
                        v-if="row.msg.image && !row.msg.media"
                        :src="row.msg.image"
                        :alt="'Attached image'"
                        class="max-h-52 w-full max-w-[300px] cursor-pointer rounded-lg object-cover shadow-sm"
                        @click="openMedia(row.msg.image)"
                    />
                </div>

                <div
                    class="mt-1 flex items-center justify-end gap-1 text-[10px]"
                    :class="
                        row.outbound
                            ? 'text-primary-foreground/70'
                            : 'text-muted-foreground'
                    "
                    :title="row.fullTimestamp"
                >
                    <span class="tabular-nums">{{ row.shortTime }}</span>
                    <component
                        v-if="row.msg.twilio_status"
                        :is="getTwilioStatusIcon(row.msg.twilio_status)"
                        class="h-3 w-3 shrink-0"
                        :class="getTwilioStatusTextClasses(row.msg.twilio_status)"
                        :title="getTwilioStatusLabel(row.msg.twilio_status)"
                    />
                    <span
                        v-if="isErrorStatus(row.msg.twilio_status)"
                        class="text-destructive font-semibold"
                    >
                        {{ getTwilioStatusLabel(row.msg.twilio_status) }}
                    </span>
                </div>

                <p
                    v-if="showNumbers && row.lastOfGroup"
                    class="mt-0.5 text-[10px]"
                    :class="
                        row.outbound
                            ? 'text-primary-foreground/70'
                            : 'text-muted-foreground'
                    "
                >
                    {{ row.msg.sender_number }} → {{ row.msg.receiver_number }}
                </p>

                <p
                    v-if="
                        friendlyTwilioError(
                            row.msg.twilio_error_code,
                            row.msg.twilio_error_message,
                        )
                    "
                    class="text-destructive mt-1 text-xs"
                >
                    Error:
                    {{
                        friendlyTwilioError(
                            row.msg.twilio_error_code,
                            row.msg.twilio_error_message,
                        )
                    }}
                </p>
                <button
                    v-if="canResend(row.msg)"
                    type="button"
                    :disabled="resendingIds.has(row.msg.id)"
                    class="text-destructive mt-1 inline-flex items-center gap-1 text-xs font-medium hover:opacity-80 disabled:opacity-60"
                    title="Resend this message via Twilio"
                    @click="resendMessage(row.msg)"
                >
                    <Loader2
                        v-if="resendingIds.has(row.msg.id)"
                        class="h-3 w-3 animate-spin"
                    />
                    <RotateCw v-else class="h-3 w-3" />
                    {{ resendingIds.has(row.msg.id) ? "Resending…" : "Retry" }}
                </button>
            </div>
        </div>
    </template>
</template>
