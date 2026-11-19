<script setup>
/**
 * The "Get the TexasRenters app" card on the owner and tenant portals,
 * matching the block at the bottom of the emails that link to them. The links
 * come from config/client_app.php.
 */
import { Play, Smartphone } from "lucide-vue-next";

defineProps({
    app: { type: Object, default: null },
});
</script>

<template>
    <div
        v-if="app && (app.app_store_url || app.play_store_url)"
        class="rounded-lg border bg-card px-5 py-4 text-card-foreground shadow-sm"
    >
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0">
                <p class="flex items-center gap-2 text-sm font-semibold">
                    <Smartphone class="h-4 w-4 shrink-0 text-primary" />
                    Get the TexasRenters app
                </p>
                <p class="mt-1 text-xs leading-relaxed text-muted-foreground">
                    Follow your maintenance requests, message our team and find
                    your documents from your phone.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <a
                    v-if="app.app_store_url"
                    :href="app.app_store_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex min-h-10 items-center justify-center rounded-md bg-primary px-4 text-xs font-semibold text-primary-foreground hover:bg-primary/90"
                >
                    App Store (iPhone)
                </a>
                <a
                    v-if="app.play_store_url"
                    :href="app.play_store_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex min-h-10 items-center justify-center rounded-md bg-primary px-4 text-xs font-semibold text-primary-foreground hover:bg-primary/90"
                >
                    Google Play (Android)
                </a>
                <a
                    v-if="app.app_video_url"
                    :href="app.app_video_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex min-h-10 items-center gap-1.5 px-2 text-xs font-semibold text-primary hover:underline"
                >
                    <Play class="h-3.5 w-3.5" />
                    Watch the app video
                </a>
                <a
                    v-if="app.signup_video_url"
                    :href="app.signup_video_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex min-h-10 items-center gap-1.5 px-2 text-xs font-semibold text-primary hover:underline"
                >
                    <Play class="h-3.5 w-3.5" />
                    Watch how to sign up
                </a>
            </div>
        </div>
    </div>
</template>
