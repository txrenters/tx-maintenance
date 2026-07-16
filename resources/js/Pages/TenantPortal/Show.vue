<script setup>
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    title: String,
    token: String,
    tenantName: String,
    completed: Boolean,
    workOrder: Object,
    attachments: Array,
});

const fileInput = ref(null);

const form = useForm({
    files: [],
});

const selectedNames = computed(() =>
    form.files.length ? form.files.map((f) => f.name).join(', ') : ''
);

function pickFiles() {
    fileInput.value?.click();
}

function onFilesChosen(event) {
    form.files = Array.from(event.target.files || []);
}

function upload() {
    if (!form.files.length) return;

    form.post(route('tenant.portal.attachments', props.token), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            if (fileInput.value) fileInput.value.value = '';
        },
    });
}

function markDone() {
    router.post(route('tenant.portal.complete', props.token), {}, { preserveScroll: true });
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
                    Service Request #{{ workOrder.work_order_no }}
                </h1>
                <p v-if="tenantName" class="mt-1 text-sm text-gray-600">
                    Hi {{ tenantName }}, please upload photos of the issue below so our team can help resolve it quickly.
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

            <!-- Upload -->
            <div class="rounded-xl bg-white p-5 shadow">
                <h2 class="text-sm font-semibold text-gray-900">Upload photos</h2>
                <p class="mt-1 text-xs text-gray-500">
                    Photos or PDF files, up to 50&nbsp;MB each. No login needed.
                </p>

                <input
                    ref="fileInput"
                    type="file"
                    multiple
                    accept="image/*,.pdf"
                    class="hidden"
                    @change="onFilesChosen"
                />

                <button
                    type="button"
                    class="mt-3 w-full rounded-lg border-2 border-dashed border-gray-300 px-4 py-6 text-sm text-gray-600 hover:border-blue-400 hover:text-blue-700"
                    @click="pickFiles"
                >
                    <span v-if="!form.files.length">Tap to choose photos</span>
                    <span v-else class="break-all">{{ selectedNames }}</span>
                </button>

                <p v-if="form.errors.files || form.errors['files.0']" class="mt-2 text-xs text-red-600">
                    {{ form.errors.files || form.errors['files.0'] }}
                </p>
                <p v-if="form.errors.error" class="mt-2 text-xs text-red-600">{{ form.errors.error }}</p>

                <button
                    type="button"
                    class="mt-3 w-full rounded-lg bg-blue-600 px-4 py-3 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
                    :disabled="!form.files.length || form.processing"
                    @click="upload"
                >
                    {{ form.processing ? 'Uploading…' : 'Upload photos' }}
                </button>

                <p v-if="form.recentlySuccessful" class="mt-2 text-center text-sm font-medium text-green-700">
                    Photos uploaded — thank you!
                </p>
            </div>

            <!-- Uploaded photos -->
            <div v-if="attachments.length" class="rounded-xl bg-white p-5 shadow">
                <h2 class="text-sm font-semibold text-gray-900">Your uploaded photos</h2>
                <div class="mt-3 grid grid-cols-3 gap-2">
                    <a
                        v-for="a in attachments"
                        :key="a.id"
                        :href="a.url"
                        target="_blank"
                        class="block overflow-hidden rounded-lg border border-gray-200"
                    >
                        <img v-if="a.is_image" :src="a.url" class="h-24 w-full object-cover" alt="" />
                        <span v-else class="flex h-24 items-center justify-center text-xs text-gray-500">PDF</span>
                    </a>
                </div>
            </div>

            <!-- Done -->
            <div class="rounded-xl bg-white p-5 shadow">
                <template v-if="!completed">
                    <button
                        type="button"
                        class="w-full rounded-lg border border-green-600 px-4 py-3 text-sm font-semibold text-green-700 hover:bg-green-50"
                        @click="markDone"
                    >
                        I'm done — notify the team
                    </button>
                </template>
                <p v-else class="text-center text-sm font-medium text-green-700">
                    ✓ All set — our team has been notified. You can still add more photos above if needed.
                </p>
            </div>
        </div>
    </div>
</template>
