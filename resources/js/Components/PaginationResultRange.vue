<script setup>
defineProps({ data: Object });
</script>

<template>
  <div class="text-xs text-muted-foreground">
    Showing <strong>{{ data.from ?? "0" }} to {{ data.to ?? "0" }}</strong> of
    <strong>{{ data.total }}</strong>
    results
  </div>
</template>
