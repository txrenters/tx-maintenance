<script setup>
/**
 * The company footer the no-login portals share, matching the footer on every
 * automated email.
 */
defineProps({
    label: { type: String, default: "" },
});
</script>

<template>
    <div>
        <div class="rounded-lg border bg-card px-6 py-4 text-center">
            <p class="text-[11px] leading-relaxed text-muted-foreground">
                <a
                    href="https://www.texasrenters.com/"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="text-primary hover:underline"
                    >TexasRenters.com</a
                >
                · 5225 Katy Freeway, Ste 545, Houston, TX 77007
            </p>
        </div>

        <p v-if="label" class="pt-2 pb-6 text-center text-xs text-muted-foreground">
            {{ label }}
        </p>
    </div>
</template>
