<script setup>
import { Head, Link } from "@inertiajs/vue3";
import { DateTime } from "luxon";
import AppLayout from "@/Layouts/AppLayout.vue";
import { Badge } from "@/Components/ui/badge";
import { Button } from "@/Components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/Components/ui/card";
import { Eye, MapPin, User } from "lucide-vue-next";

defineOptions({ layout: AppLayout });

defineProps({
  title: String,
  jobs: { type: Array, default: () => [] },
});

const formatDate = (date) => {
  if (!date) return "-";
  const d = typeof date === "string" && date.includes("T")
    ? DateTime.fromISO(date, { zone: "utc" })
    : DateTime.fromFormat(String(date), "yyyy-MM-dd HH:mm:ss", { zone: "utc" });
  return d.isValid ? d.setZone("America/Chicago").toFormat("MM/dd/yyyy") : "-";
};

const formatStatus = (status) =>
  String(status || "").replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
</script>

<template>
  <Head :title="title" />

  <Card>
    <CardHeader>
      <CardTitle class="text-2xl text-primary">My Jobs</CardTitle>
    </CardHeader>

    <CardContent>
      <div v-if="jobs.length" class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div v-for="job in jobs" :key="job.id" class="border rounded-lg p-4 space-y-2">
          <div class="flex items-start justify-between gap-2">
            <div>
              <p class="font-semibold">{{ job.title }}</p>
              <p class="text-sm text-muted-foreground">Job #{{ job.job_number }}</p>
            </div>
            <Badge variant="secondary">{{ formatStatus(job.job_status) }}</Badge>
          </div>

          <p v-if="job.property_address" class="text-sm flex items-start gap-2">
            <MapPin class="h-4 w-4 mt-0.5 shrink-0" /> {{ job.property_address }}
          </p>
          <p v-if="job.client_name" class="text-sm flex items-start gap-2">
            <User class="h-4 w-4 mt-0.5 shrink-0" /> {{ job.client_name }}
          </p>
          <p class="text-sm text-muted-foreground">Scheduled: {{ formatDate(job.start_at) }}</p>

          <Button v-if="job.portal_url" variant="outline" size="sm" as-child class="w-full">
            <a :href="job.portal_url"><Eye class="h-4 w-4" /> Open job</a>
          </Button>
        </div>
      </div>

      <p v-else class="text-muted-foreground py-8 text-center">
        You have no jobs assigned right now.
      </p>
    </CardContent>
  </Card>
</template>
