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
                to: {{ msg.receiver_number }}
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
            <div v-if="msg.is_mms">
                <img
                    v-for="media in msg.media"
                    :key="media.key"
                    :src="media.public_url"
                    width="350"
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
