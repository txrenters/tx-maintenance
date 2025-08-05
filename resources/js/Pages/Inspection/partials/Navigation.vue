<script setup>
import { Calendar, Hammer } from "lucide-vue-next";
const props = defineProps({
    tokenExist: Boolean,
});
</script>
<template>
    <div class="flex gap-2">
        <!-- <Button class="rounded" as-child>
            <a :href="route('jobber.connect')" target="_blank">
                <Hammer class="w-4 h-4" />
                Connect with Jobber
            </a>
        </Button> -->
        <Button
            class="rounded"
            :variant="
                $page.url.startsWith('/inspections') ? 'default' : 'outline'
            "
            as-child
        >
            <Link :href="route('inspections.index')" prefetch>
                <Hammer class="w-4 h-4" />
            </Link>
        </Button>
        <Button
            class="rounded"
            :variant="$page.url.startsWith('/visits') ? 'default' : 'outline'"
            as-child
        >
            <Link :href="route('visits.index')" prefetch>
                <Calendar class="w-4 h-4" />
            </Link>
        </Button>
    </div>
</template>
