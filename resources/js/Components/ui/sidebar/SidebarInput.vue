<script setup>
import { cn } from "@/lib/utils";
import { Input } from "@/Components/ui/input";

const props = defineProps({
  class: { type: null, required: false },
});
</script>

<template>
  <Input
    data-sidebar="input"
    :class="
      cn(
        'h-8 w-full bg-white shadow-none focus-visible:ring-2 focus-visible:ring-sidebar-ring dark:bg-neutral-950',
        props.class
      )
    "
  >
    <slot />
  </Input>
</template>
