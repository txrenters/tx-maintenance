<script setup>
import { Head } from "@inertiajs/vue3";

defineProps({
    title: String,
    workOrder: Object,
    before: Array,
    after: Array,
});
</script>

<template>
    <Head :title="title" />

    <div class="min-h-screen bg-gray-100 py-6 px-4">
        <div class="mx-auto max-w-2xl space-y-4">
            <div class="rounded-xl bg-white p-5 shadow">
                <p class="text-xs font-semibold uppercase tracking-wide text-blue-700">
                    TexasRenters.com Maintenance
                </p>
                <h1 class="mt-1 text-xl font-bold text-gray-900">
                    HOA Violation — #{{ workOrder.work_order_no }}
                </h1>
                <p v-if="workOrder.property" class="mt-1 text-sm text-gray-600">
                    {{ workOrder.property }}
                </p>
                <p class="mt-2 text-sm text-green-700 font-medium">
                    ✓ This violation has been corrected. The photos are below.
                </p>
            </div>

            <div v-if="after.length" class="rounded-xl bg-white p-5 shadow">
                <h2 class="text-sm font-semibold text-gray-900">After (corrected)</h2>
                <div class="mt-3 grid grid-cols-2 sm:grid-cols-3 gap-2">
                    <a
                        v-for="photo in after"
                        :key="photo.id"
                        :href="photo.url"
                        target="_blank"
                        class="block overflow-hidden rounded-lg border border-gray-200"
                    >
                        <img v-if="photo.is_image" :src="photo.url" class="h-28 w-full object-cover" alt="" />
                        <span v-else class="flex h-28 items-center justify-center text-xs text-gray-500">PDF</span>
                    </a>
                </div>
            </div>

            <div v-if="before.length" class="rounded-xl bg-white p-5 shadow">
                <h2 class="text-sm font-semibold text-gray-900">
                    {{ after.length ? "Before" : "Proof photos" }}
                </h2>
                <div class="mt-3 grid grid-cols-2 sm:grid-cols-3 gap-2">
                    <a
                        v-for="photo in before"
                        :key="photo.id"
                        :href="photo.url"
                        target="_blank"
                        class="block overflow-hidden rounded-lg border border-gray-200"
                    >
                        <img v-if="photo.is_image" :src="photo.url" class="h-28 w-full object-cover" alt="" />
                        <span v-else class="flex h-28 items-center justify-center text-xs text-gray-500">PDF</span>
                    </a>
                </div>
            </div>

            <div
                v-if="!before.length && !after.length"
                class="rounded-xl bg-white p-5 shadow text-center text-sm text-gray-500"
            >
                No photos are available for this work order.
            </div>
        </div>
    </div>
</template>
