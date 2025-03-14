<script setup>
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from "@/components/ui/tooltip";

const props = defineProps({
  buttons: Array, // Expecting an array of button objects
  activeTab: String, // The currently active tab
});

const emit = defineEmits(["switchTab"]);

const handleSwitchTab = (tabName) => {
  emit("switchTab", tabName);
};
</script>
<template>
  <TooltipProvider v-for="button in buttons" :key="button.name">
    <Tooltip>
      <TooltipTrigger as-child>
        <Button
          :variant="activeTab === button.name ? '' : 'outline'"
          size="icon"
          @click="handleSwitchTab(button.name)"
        >
          <component :is="button.icon" class="w-4 h-4" v-if="button.icon" />
          <span v-else>{{ button.label }}</span>
          <!-- Fallback if no icon -->
        </Button>
      </TooltipTrigger>
      <TooltipContent>
        <p>{{ button.tooltip }}</p>
      </TooltipContent>
    </Tooltip>
  </TooltipProvider>
</template>
