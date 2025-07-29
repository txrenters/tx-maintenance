<script setup>
import { Calendar, Hammer } from "lucide-vue-next";
</script>
<template>
    <div class="flex gap-2">
        <Button
            class="rounded"
            :variant="
                $page.url.startsWith('/inspections') ? 'default' : 'outline'
            "
            as-child
        >
            <Link :href="route('inspections.index')" prefetch>
                <Hammer class="w-4 h-4" />
                Jobs
            </Link>
        </Button>
        <Button
            class="rounded"
            :variant="$page.url.startsWith('/visits') ? 'default' : 'outline'"
            as-child
        >
            <Link :href="route('visits.index')" prefetch>
                <Calendar class="w-4 h-4" />
                Schedule Visit
            </Link>
        </Button>
    </div>
</template>
