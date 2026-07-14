<script setup>
import { DateTime } from "luxon";

const props = defineProps({
    messages: Object,
});

// Classify an attachment so the row renders the right element. Prefer the
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

const formatDate = (date) => {
    if (!date) return "------";

    let parsedDate;

    // Set timezone to America/Chicago (Dallas)
    const timezone = "America/Chicago";

    if (typeof date === "string") {
        if (date.includes("T")) {
            // Parse ISO string in UTC and convert to America/Chicago time
            parsedDate = DateTime.fromISO(date, { zone: "utc" }).setZone(
                timezone
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
</script>
<template>
    <div
        class="bg-secondary p-2 mb-2 text-xs"
        v-for="conversation in [...messages].reverse()"
        :key="conversation.id"
    >
        <p><strong>From:</strong> {{ conversation.sender_number }}</p>
        <p><strong>To:</strong> {{ conversation.receiver_number }}</p>
        <p><strong>Message:</strong> {{ conversation.message }}</p>
        <div v-if="conversation.is_mms" class="m-1">
            <template v-for="media in conversation.media" :key="media.key">
                <video
                    v-if="mediaKind(media) === 'video'"
                    :src="media.public_url"
                    controls
                    playsinline
                    width="350"
                    class="bg-black"
                />
                <a
                    v-else-if="mediaKind(media) === 'pdf'"
                    :href="media.public_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="text-blue-600 underline"
                >
                    {{ media.file_name || "Document.pdf" }}
                </a>
                <img v-else :src="media.public_url" width="350" />
            </template>
        </div>
        <small>{{ formatDate(conversation.created_at) }}</small>
    </div>
</template>
