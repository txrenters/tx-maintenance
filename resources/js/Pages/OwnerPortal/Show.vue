<script setup>
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed, onMounted, nextTick } from 'vue';

const props = defineProps({
    title: String,
    token: String,
    ownerName: String,
    wocName: String,
    workOrder: Object,
    messages: Array,
    attachments: Array,
});

const thread = ref(null);
const fileInput = ref(null);
const imageInput = ref(null);

const messageForm = useForm({
    text: '',
    images: [],
});

const uploadForm = useForm({
    files: [],
});

const selectedNames = computed(() =>
    uploadForm.files.length ? uploadForm.files.map((f) => f.name).join(', ') : ''
);

const selectedImageNames = computed(() =>
    messageForm.images.length ? messageForm.images.map((f) => f.name).join(', ') : ''
);

function scrollThread() {
    nextTick(() => {
        if (thread.value) thread.value.scrollTop = thread.value.scrollHeight;
    });
}

onMounted(() => {
    scrollThread();
    router.post(route('owner.portal.messages.read', props.token), {}, {
        preserveScroll: true,
        preserveState: true,
        only: [],
    });
});

function onImagesChosen(event) {
    messageForm.images = Array.from(event.target.files || []);
}

function sendMessage() {
    if (!messageForm.text.trim() && !messageForm.images.length) return;

    messageForm.post(route('owner.portal.message', props.token), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            messageForm.reset();
            if (imageInput.value) imageInput.value.value = '';
            scrollThread();
        },
    });
}

function onFilesChosen(event) {
    uploadForm.files = Array.from(event.target.files || []);
}

function upload() {
    if (!uploadForm.files.length) return;

    uploadForm.post(route('owner.portal.attachments', props.token), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            uploadForm.reset();
            if (fileInput.value) fileInput.value.value = '';
        },
    });
}

function formatTime(value) {
    if (!value) return '';

    return new Date(value).toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}
</script>

<template>
    <Head :title="title" />

    <div class="min-h-screen bg-gray-100 py-6 px-4">
        <div class="mx-auto max-w-lg space-y-4">
            <!-- Header -->
            <div class="rounded-xl bg-white p-5 shadow">
                <p class="text-xs font-semibold uppercase tracking-wide text-blue-700">
                    TexasRenters.com Maintenance
                </p>
                <h1 class="mt-1 text-xl font-bold text-gray-900">
                    Work Order #{{ workOrder.work_order_no }}
                </h1>
                <p v-if="ownerName" class="mt-1 text-sm text-gray-600">
                    Hi {{ ownerName }}, here is the latest on this service request for your
                    property. You can message your coordinator and share photos below.
                </p>
            </div>

            <!-- Request details -->
            <div class="rounded-xl bg-white p-5 shadow">
                <h2 class="text-sm font-semibold text-gray-900">Request details</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div v-if="workOrder.address">
                        <dt class="font-medium text-gray-500">Property</dt>
                        <dd class="text-gray-900">{{ workOrder.address }}</dd>
                    </div>
                    <div v-if="workOrder.description">
                        <dt class="font-medium text-gray-500">Issue</dt>
                        <dd class="whitespace-pre-line text-gray-900">{{ workOrder.description }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-500">Status</dt>
                        <dd class="text-gray-900">{{ workOrder.status }}</dd>
                    </div>
                </dl>
            </div>

            <!-- Messages -->
            <div class="rounded-xl bg-white p-5 shadow">
                <h2 class="text-sm font-semibold text-gray-900">
                    Messages<span v-if="wocName" class="font-normal text-gray-500"> with {{ wocName }}</span>
                </h2>

                <div ref="thread" class="mt-3 max-h-96 space-y-3 overflow-y-auto pr-1">
                    <p v-if="!messages.length" class="py-6 text-center text-sm text-gray-500">
                        No messages yet. Send us a note below and your coordinator will reply here.
                    </p>

                    <div
                        v-for="m in messages"
                        :key="m.id"
                        class="flex"
                        :class="m.from_owner ? 'justify-end' : 'justify-start'"
                    >
                        <div
                            class="max-w-[85%] rounded-2xl px-3 py-2 text-sm"
                            :class="m.from_owner
                                ? 'bg-blue-600 text-white'
                                : 'bg-gray-100 text-gray-900'"
                        >
                            <p v-if="m.message" class="whitespace-pre-line break-words">{{ m.message }}</p>

                            <div v-if="m.media.length" class="mt-2 grid grid-cols-2 gap-1">
                                <a
                                    v-for="media in m.media"
                                    :key="media.id"
                                    :href="media.url"
                                    target="_blank"
                                    class="block overflow-hidden rounded-lg"
                                >
                                    <img
                                        v-if="media.is_image"
                                        :src="media.url"
                                        class="h-20 w-full object-cover"
                                        alt=""
                                    />
                                    <span
                                        v-else
                                        class="flex h-20 items-center justify-center bg-white/20 text-xs"
                                    >
                                        Attachment
                                    </span>
                                </a>
                            </div>

                            <p
                                class="mt-1 text-[11px]"
                                :class="m.from_owner ? 'text-blue-100' : 'text-gray-500'"
                            >
                                {{ formatTime(m.created_at) }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="mt-3 border-t border-gray-100 pt-3">
                    <textarea
                        v-model="messageForm.text"
                        rows="3"
                        placeholder="Type a message to your coordinator…"
                        class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500"
                    ></textarea>

                    <input
                        ref="imageInput"
                        type="file"
                        multiple
                        accept="image/*,video/*"
                        class="hidden"
                        @change="onImagesChosen"
                    />

                    <p v-if="selectedImageNames" class="mt-1 break-all text-xs text-gray-500">
                        {{ selectedImageNames }}
                    </p>

                    <p v-if="messageForm.errors.message" class="mt-1 text-xs text-red-600">
                        {{ messageForm.errors.message }}
                    </p>

                    <div class="mt-2 flex gap-2">
                        <button
                            type="button"
                            class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                            @click="imageInput?.click()"
                        >
                            Attach
                        </button>
                        <button
                            type="button"
                            class="flex-1 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
                            :disabled="messageForm.processing || (!messageForm.text.trim() && !messageForm.images.length)"
                            @click="sendMessage"
                        >
                            {{ messageForm.processing ? 'Sending…' : 'Send' }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Photos -->
            <div class="rounded-xl bg-white p-5 shadow">
                <h2 class="text-sm font-semibold text-gray-900">Photos</h2>
                <p class="mt-1 text-xs text-gray-500">
                    Photos, videos or PDF files, up to 50&nbsp;MB each. No login needed.
                </p>

                <div v-if="attachments.length" class="mt-3 grid grid-cols-3 gap-2">
                    <a
                        v-for="a in attachments"
                        :key="a.id"
                        :href="a.url"
                        target="_blank"
                        class="block overflow-hidden rounded-lg border border-gray-200"
                    >
                        <img v-if="a.is_image" :src="a.url" class="h-24 w-full object-cover" alt="" />
                        <span v-else class="flex h-24 items-center justify-center text-xs text-gray-500">
                            File
                        </span>
                    </a>
                </div>

                <input
                    ref="fileInput"
                    type="file"
                    multiple
                    accept="image/*,video/*,.pdf"
                    class="hidden"
                    @change="onFilesChosen"
                />

                <button
                    type="button"
                    class="mt-3 w-full rounded-lg border-2 border-dashed border-gray-300 px-4 py-6 text-sm text-gray-600 hover:border-blue-400 hover:text-blue-700"
                    @click="fileInput?.click()"
                >
                    <span v-if="!uploadForm.files.length">Tap to choose photos</span>
                    <span v-else class="break-all">{{ selectedNames }}</span>
                </button>

                <p v-if="uploadForm.errors.files || uploadForm.errors['files.0']" class="mt-2 text-xs text-red-600">
                    {{ uploadForm.errors.files || uploadForm.errors['files.0'] }}
                </p>
                <p v-if="uploadForm.errors.error" class="mt-2 text-xs text-red-600">
                    {{ uploadForm.errors.error }}
                </p>

                <button
                    type="button"
                    class="mt-3 w-full rounded-lg bg-blue-600 px-4 py-3 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
                    :disabled="!uploadForm.files.length || uploadForm.processing"
                    @click="upload"
                >
                    {{ uploadForm.processing ? 'Uploading…' : 'Upload photos' }}
                </button>

                <p v-if="uploadForm.recentlySuccessful" class="mt-2 text-center text-sm font-medium text-green-700">
                    Photos uploaded — thank you!
                </p>
            </div>
        </div>
    </div>
</template>
