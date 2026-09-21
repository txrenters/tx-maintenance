<script setup>
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from "@/Components/ui/tooltip";
import { usePage } from "@inertiajs/vue3";
import { Headset, House, KeyRound, Wrench } from "lucide-vue-next";

const props = defineProps({
  buttons: Array, // Expecting an array of button objects
  activeTab: String, // The currently active tab
});

/**
 * Conversation tabs used to be labelled with a bare letter ("V", "O", "T", "W")
 * and a tooltip that didn't say which two parties the thread is between — so
 * "Owner Conversation" meant one thing on the staff tabs and another on the
 * vendor tabs. The tab config lives in eight different pages, so the naming is
 * fixed up here by tab name instead of in each copy.
 *
 * @type {Object<string, {icon: object, label: string, tooltip: string}>}
 */
const CONVERSATION_TABS = {
  vendor_conversation: { icon: Wrench, label: "Vendor", tooltip: "Vendor ↔ Coordinator" },
  owner_conversation: { icon: House, label: "Owner", tooltip: "Owner ↔ Coordinator" },
  tenant_conversation: { icon: KeyRound, label: "Tenant", tooltip: "Tenant ↔ Coordinator" },
  vendor_woc_conversation: { icon: Headset, label: "Coordinator", tooltip: "Vendor ↔ Coordinator" },
  vendor_owner_conversation: { icon: House, label: "Owner", tooltip: "Vendor ↔ Owner" },
  vendor_tenant_conversation: { icon: KeyRound, label: "Tenant", tooltip: "Vendor ↔ Tenant" },
  owner_woc_conversation: { icon: Headset, label: "Coordinator", tooltip: "Owner ↔ Coordinator" },
  owner_vendor_conversation: { icon: Wrench, label: "Vendor", tooltip: "Owner ↔ Vendor" },
  tenant_woc_conversation: { icon: Headset, label: "Coordinator", tooltip: "Tenant ↔ Coordinator" },
  tenant_vendor_conversation: { icon: Wrench, label: "Vendor", tooltip: "Tenant ↔ Vendor" },
};

const iconFor = (button) => CONVERSATION_TABS[button.name]?.icon ?? button.icon;

const labelFor = (button) =>
  CONVERSATION_TABS[button.name]?.label ?? button.label ?? button.tooltip;

const tooltipFor = (button) =>
  CONVERSATION_TABS[button.name]?.tooltip ?? button.tooltip ?? button.label;

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
          <!-- Any config still passing a bare letter degrades to that letter
               rather than throwing. -->
          <span v-if="typeof iconFor(button) === 'string'" class="text-xs font-semibold">{{
            iconFor(button)
          }}</span>

          <component :is="iconFor(button)" class="w-4 h-4" v-else-if="iconFor(button)" />

          <!-- Text label (previously only shown in the tooltip) -->
          <span class="text-xs">{{ labelFor(button) }}</span>

          <!-- Optional count badge. Tabs that don't set `count` render as before.
               `countVariant: 'alert'` marks something needing attention. -->
          <span
            v-if="button.count"
            class="ml-0.5 rounded-full px-1.5 py-0.5 text-[10px] font-semibold leading-none"
            :class="
              button.countVariant === 'alert'
                ? 'bg-destructive text-destructive-foreground'
                : activeTab === button.name
                  ? 'bg-background/20 text-current'
                  : 'bg-muted text-muted-foreground'
            "
            >{{ button.count }}</span
          >

          <!-- Something changed here but a number would be misleading (e.g. the
               attachments tab already owns its own count). A bare dot says
               "look here" without claiming how many. -->
          <span
            v-else-if="button.dot"
            class="ml-0.5 h-2 w-2 rounded-full bg-destructive"
            aria-hidden="true"
          />
        </Button>
      </TooltipTrigger>
      <TooltipContent>
        <p>{{ tooltipFor(button) }}</p>
      </TooltipContent>
    </Tooltip>
  </TooltipProvider>
</template>
