<script setup>
const startYear = 2025;
const currentYear = new Date().getFullYear();
</script>

<template>
    <div class="relative flex min-h-svh flex-col bg-background">
        <div
            class="flex min-h-svh flex-col items-center justify-center gap-6 bg-muted p-6 md:p-10"
        >
            <Link href="/"> <img src="tx-portal-logo.png" width="400" /></Link>

            <div class="flex flex-col gap-6 w-full max-w-md mx-auto">
                <slot />
                <div
                    class="text-balance text-center text-xs text-muted-foreground [&_a]:underline [&_a]:underline-offset-4 [&_a]:hover:text-primary"
                >
                    <p class="mt-2">Need help? Message us at 281-248-8018</p>
                    Developed and maintained by Texas Renters IT Department
                    &copy;
                    {{
                        startYear === currentYear
                            ? currentYear
                            : `${startYear}–${currentYear}`
                    }}.

                    <a
                        href="https://www.texasrenters.com/"
                        target="_blank"
                        class="font-bold text-primary text-decoration-none"
                        >TexasRenters.com</a
                    >
                    Maintenance Portal – Powered by Propertyware. All rights
                    reserved.
                    <br />
                </div>
            </div>
        </div>
    </div>
</template>
