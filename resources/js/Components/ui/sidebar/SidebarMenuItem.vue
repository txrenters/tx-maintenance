<script setup>
import { cn } from '@/lib/utils';

const props = defineProps({
  class: { type: null, required: false },
});
</script>

<template>
  <li
    data-sidebar="menu-item"
    :class="cn('group/menu-item relative mx-1 group-data-[collapsible=icon]:mx-0', props.class)"
  >
    <slot />
  </li>
</template>
