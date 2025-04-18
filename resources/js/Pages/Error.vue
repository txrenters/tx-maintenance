<script setup>
import { computed } from "vue";

const props = defineProps({ status: Number });

const title = computed(() => {
    return (
        {
            503: "503: Service Unavailable",
            500: "500: Server Error",
            404: "404: Page Not Found",
            403: "403: Forbidden",
        }[props.status] || "Error"
    );
});

const description = computed(() => {
    return (
        {
            503: "Sorry, we are doing some maintenance. Please check back soon.",
            500: "Whoops, something went wrong on our servers.",
            404: "Sorry, the page you are looking for could not be found.",
            403: "Sorry, you are forbidden from accessing this page.",
        }[props.status] || "An unexpected error has occurred."
    );
});
</script>

<template>
    <Head :title="title" :description="description" />
    <div class="relative flex min-h-svh flex-col bg-background">
        <div
            class="flex min-h-svh flex-col items-center justify-center gap-1 bg-muted p-6 md:p-10"
        >
            <div
                class="flex flex-col w-full mx-auto justify-center items-center text-center"
            >
                <h1
                    class="font-bold text-transparent bg-clip-text bg-gradient-to-r from-blue-500 to-red-500 text-center py-10 text-[120px] sm:text-[150px] md:text-[200px] lg:text-[200px] xl:text-[300px]"
                >
                    Oops!
                </h1>

                <h1 class="text-2xl font-bold uppercase mb-3">{{ title }}</h1>
                <p class="text-sm mb-3 md:text-base">
                    {{ description }}
                </p>
                <div
                    class="text-balance text-center text-xs text-muted-foreground [&_a]:underline [&_a]:underline-offset-4 [&_a]:hover:text-primary"
                >
                    <Button
                        as-child
                        variant="link"
                        size="sm"
                        aria-label="Go back to home page"
                    >
                        <Link href="/login"> Go back Home </Link>
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
