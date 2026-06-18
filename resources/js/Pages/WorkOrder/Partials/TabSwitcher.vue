<script setup>
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from "@/Components/ui/tooltip";
import { usePage } from "@inertiajs/vue3";

const props = defineProps({
  buttons: Array, // Expecting an array of button objects
  activeTab: String, // The currently active tab
});

const page = usePage();

const emit = defineEmits(["switchTab"]);

const handleSwitchTab = (tabName) => {
  emit("switchTab", tabName);
};

const canAccess = (requiredRoles) => {
  // Ensure roles are valid arrays
  const userRoles = page.props.auth.user.roles || []; // Default to an empty array if undefined
  requiredRoles = requiredRoles || []; // Default to an empty array if undefined

  // Use filter to find matching roles
  const matchingRoles = userRoles.filter((role) => requiredRoles.includes(role));

  // Return true if there are any matches, otherwise false
  return matchingRoles.length > 0;
};
</script>
<template>
  <TooltipProvider v-for="button in buttons" :key="button.name">
    <Tooltip v-if="canAccess(button.requires)">
      <TooltipTrigger as-child>
        <Button
          :variant="activeTab === button.name ? '' : 'outline'"
          size="sm"
          class="gap-1.5"
          @click="handleSwitchTab(button.name)"
        >
          <span
            v-if="
              button.icon === 'V' ||
              button.icon === 'O' ||
              button.icon === 'T' ||
              button.icon === 'W'
            "
            class="text-xs font-semibold"
            >{{ button.icon }}</span
          >

          <!-- If the icon exists and is not a letter, render the icon -->
          <component :is="button.icon" class="w-4 h-4" v-else-if="button.icon" />

          <!-- Text label (previously only shown in the tooltip) -->
          <span class="text-xs">{{ button.tooltip || button.label }}</span>
        </Button>
      </TooltipTrigger>
      <TooltipContent>
        <p>{{ button.tooltip }}</p>
      </TooltipContent>
    </Tooltip>
  </TooltipProvider>
</template>
