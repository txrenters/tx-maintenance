<script setup>
import { ref, watch } from "vue";
import MessageCard2 from "@/Components/MessageCard2.vue";

const props = defineProps({
  workOrder: Object,
  vendorConversation: Object,
  ownerConversation: Object,
  tenantConversation: Object,
  vendorTenantConversation: Object,
  isLoading: Boolean,
});
</script>
<template>
  <div class="overflow-y-auto px-6 w-full min-h-[300px]">
    <p class="font-semibold uppercase text-xs mb-3">Conversations</p>

    <div class="mb-4 p-2 border">
      <p class="mb-2 font-bold">WOC and Vendor</p>
      <MessageCard2 :messages="vendorConversation" />
    </div>
    <div class="mb-4 p-2 border">
      <p class="mb-2 font-bold">WOC and Owner</p>
      <MessageCard2 :messages="ownerConversation" />
    </div>
    <div class="mb-4 p-2 border">
      <p class="mb-2 font-bold">WOC and Tenant</p>
      <MessageCard2 :messages="tenantConversation" />
    </div>
    <div class="mb-14 p-2 border">
      <p class="mb-2 font-bold">Vendor and Tenant</p>
      <MessageCard2 :messages="vendorTenantConversation" />
    </div>
  </div>
</template>
