<script setup>
import { Calendar, Hammer, MessageCircleIcon } from "lucide-vue-next";
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
            <a :href="route('inspections.index')" prefetch>
                <Hammer class="w-4 h-4" />
            </a>
        </Button>
        <Button
            class="rounded"
            :variant="$page.url.startsWith('/visits') ? 'default' : 'outline'"
            as-child
        >
            <a :href="route('visits.index')" prefetch>
                <Calendar class="w-4 h-4" />
            </a>
        </Button>
        <Button
            class="rounded"
            :variant="
                $page.url.startsWith('/inspections/text/messages')
                    ? 'default'
                    : 'outline'
            "
            as-child
        >
            <Link :href="route('jobber.messages')" prefetch>
                <MessageCircleIcon class="w-4 h-4" />
            </Link>
        </Button>
    </div>
</template>
