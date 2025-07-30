<script setup>
import { DateTime } from "luxon";
import { X } from "lucide-vue-next";
import { computed } from "vue";
import { router } from "@inertiajs/vue3";

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

const messages = computed(() => {
    return props.messages.map((msg) => {
        return {
            ...msg,
            created_at: formatDate(msg.created_at),
        };
    });
});

const removeMessage = (id) => {
    const index = messages.value.findIndex((msg) => msg.id === id);
    if (index !== -1) {
        messages.value.splice(index, 1);
    }
    router.post(
        route("workorder.message.delete", id),
        {
            _method: "delete",
        },
        {
            preserveState: true,
            preserveScroll: true,
        }
    );
};

const openMedia = (mediaUrl) => {
    if (mediaUrl) {
        window.open(mediaUrl, '_blank', 'noopener,noreferrer');
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
            <p
                class="text-xs"
                :class="
                    msg.sender_number === sender
                        ? 'text-white'
                        : 'text-gray-500'
                "
            >
                To: {{ msg.receiver_number }}
            </p>
            <!-- <button
                    class="hover:text-red-500"
                    title="Delete"
                    @click.prevent="removeMessage(msg.id)"
                    v-if="!$page.props.auth.user.roles.includes('vendor')"
                >
                    <X class="w-4 h-4" />
                </button> -->

            <p
                class="text-md py-1"
                :class="
                    msg.sender_number === sender ? 'text-white' : 'text-black'
                "
            >
                {{ msg.message }}
            </p>
            <!-- Handle both formats: is_mms with media array OR single image property -->
            <div v-if="(msg.is_mms && msg.media && msg.media.length > 0) || msg.image" class="mt-2">
                <!-- Multiple media format (original conversation format) -->
                <img
                    v-for="media in msg.media || []"
                    :key="media.id"
                    :src="media.public_url"
                    :alt="media.file_name || 'Attached image'"
                    class="max-w-full h-auto rounded-lg shadow-sm cursor-pointer"
                    style="max-width: 300px; max-height: 200px;"
                    @click="openMedia(media.public_url)"
                />
                <!-- Single image format (jobber text message format) -->
                <img
                    v-if="msg.image && !msg.media"
                    :src="msg.image"
                    :alt="'Attached image'"
                    class="max-w-full h-auto rounded-lg shadow-sm cursor-pointer"
                    style="max-width: 300px; max-height: 200px;"
                    @click="openMedia(msg.image)"
                />
            </div>
            <div class="flex gap-20 items-center justify-between">
                <p
                    class="text-xs"
                    :class="
                        msg.sender_number === sender
                            ? 'text-white'
                            : 'text-gray-500'
                    "
                >
                    {{ msg.created_at }}
                </p>
            </div>
        </div>
    </div>
</template>
