<script setup>
import { cn } from '@/lib/utils';
import { CalendarCell, useForwardProps } from 'reka-ui';
import { computed } from 'vue';

const props = defineProps({
  date: { type: null, required: true },
  asChild: { type: Boolean, required: false },
  as: { type: null, required: false },
  class: { type: null, required: false },
});

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props;

  return delegated;
});

const forwardedProps = useForwardProps(delegatedProps);
</script>

<template>
  <CalendarCell
    :class="
      cn(
        'relative p-0 text-center text-sm focus-within:relative focus-within:z-20 [&:has([data-selected])]:rounded-md [&:has([data-selected])]:bg-neutral-100 [&:has([data-selected][data-outside-view])]:bg-neutral-100/50 dark:[&:has([data-selected])]:bg-neutral-800 dark:[&:has([data-selected][data-outside-view])]:bg-neutral-800/50',
        props.class,
      )
    "
    v-bind="forwardedProps"
  >
    <slot />
  </CalendarCell>
</template>
