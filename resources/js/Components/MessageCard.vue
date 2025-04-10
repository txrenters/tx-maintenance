<script setup>
import { DateTime } from "luxon";
import { X } from "lucide-vue-next";
import { computed } from "vue";

const props = defineProps({
    messages: Object,
    sender: String,
});

const formatDate = (date) => {
    if (!date) return "------";

    let parsedDate;

    if (typeof date === "string") {
        if (date.includes("T")) {
            parsedDate = DateTime.fromISO(date, { zone: "utc" });
        } else if (date.includes(" ")) {
            // Handling the format 'yyyy-MM-dd HH:mm:ss'
            parsedDate = DateTime.fromFormat(date, "yyyy-MM-dd HH:mm:ss", {
                zone: "utc",
            });
        } else {
            parsedDate = DateTime.fromFormat(date, "yyyy-MM-dd", {
                zone: "utc",
            });
        }
    } else if (date instanceof Date) {
        parsedDate = DateTime.fromJSDate(date);
    } else {
        return "Invalid Date";
    }

    return parsedDate.isValid
        ? parsedDate.toFormat("EEE, MMMM d, yyyy hh:mm a")
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
};
</script>
<template>
    <div
        v-for="msg in messages"
        :key="msg.id"
        class="p-2 rounded-lg text-sm w-fit max-w-[75%]"
        :class="
            msg.sender_number === sender
                ? 'bg-blue-500 text-white self-end'
                : 'bg-gray-200 text-gray-900 self-start'
        "
    >
        <div class="flex flex-col gap-2 relative">
            <div
                class="flex gap-1 items-start justify-between"
                :class="
                    msg.sender_number === sender
                        ? 'flex-row-reverse'
                        : 'flex-row'
                "
            >
                <p
                    class="text-xs"
                    :class="
                        msg.sender_number === sender
                            ? 'flex-row-reverse'
                            : 'flex-row'
                    "
                >
                    {{ msg.sender_number }}
                </p>
                <Link
                    as="button"
                    class="hover:text-red-500"
                    :href="route('workorder.message.delete', msg.id)"
                    method="post"
                    title="Delete"
                    @click.prevent="removeMessage(msg.id)"
                    preserve-state
                    preserve-scroll
                    v-if="!$page.props.auth.user.roles.includes('vendor')"
                >
                    <X class="w-4 h-4" />
                </Link>
            </div>

            <p
                class="font-bold"
                :class="
                    msg.sender_number === sender ? 'text-right' : 'text-left'
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
            <p
                class="text-xs"
                :class="
                    msg.sender_number === sender ? 'text-right' : 'text-left'
                "
            >
                {{ msg.created_at }}
            </p>
        </div>
    </div>
</template>
