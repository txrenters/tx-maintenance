<script setup>
import { ref } from "vue";
import { usePoll } from "@inertiajs/vue3";
import { Qalendar } from "qalendar";
import AppLayout from "@/Layouts/AppLayout.vue";
import "qalendar/dist/style.css";

defineOptions({ layout: AppLayout });

const props = defineProps({
  title: String,
  service_schedules: Object,
});

console.log(props.service_schedules);

const events = ref(props.service_schedules);

const config = ref({
  week: {
    startsOn: "sunday",
    nDays: 7,
    scrollToHour: 5,
  },
  month: {
    showTrailingAndLeadingDates: false,
  },
  style: {
    fontFamily: "Nunito, sans-serif",
  },
  defaultMode: "week",
  isSilent: true,
  showCurrentTime: true,
});

usePoll(10000);
</script>

<template>
  <Head :title="title" />

  <div class="is-light-mode">
    <Qalendar :events="events" :config="config" />
  </div>
</template>
