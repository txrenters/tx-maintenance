<script setup>
const currentYear = () => {
    return new Date().getFullYear();
};
</script>

<template>
    <div class="relative flex min-h-svh flex-col bg-background">
        <div
            class="flex min-h-svh flex-col items-center justify-center gap-6 bg-muted p-6 md:p-10"
        >
            <Link href="/"> <img src="logo-ct.png" width="200" /></Link>

            <div class="flex flex-col gap-6 w-full max-w-md mx-auto">
                <slot />

                <div
                    class="text-balance text-center text-xs text-muted-foreground [&_a]:underline [&_a]:underline-offset-4 [&_a]:hover:text-primary"
                >
                    Developed and maintained by Texas Renters IT Department
                    &copy;
                    {{ currentYear() }}. All rights reserved.
                    <br />
                </div>
            </div>
        </div>
    </div>
</template>
