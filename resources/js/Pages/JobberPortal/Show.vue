<script setup>
import { ref } from "vue";
import { router, Head } from "@inertiajs/vue3";
import { DateTime } from "luxon";
import { useToast } from "@/Components/ui/toast/use-toast";
import { Button } from "@/Components/ui/button";
import { Badge } from "@/Components/ui/badge";
import { Input } from "@/Components/ui/input";
import { Label } from "@/Components/ui/label";
import { Separator } from "@/Components/ui/separator";
import { Card, CardContent, CardHeader, CardTitle } from "@/Components/ui/card";
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from "@/Components/ui/select";
import { AlertTriangle, Eye, Loader2, MapPin, Paperclip, Phone, Upload, User } from "lucide-vue-next";

const props = defineProps({
  title: String,
  token: String,
  vendorName: String,
  job: Object,
  attachments: { type: Array, default: () => [] },
  invoices: { type: Array, default: () => [] },
  // False until this vendor has uploaded a photo of their own to this job. The
  // server refuses the invoice either way; this only keeps the form from
  // inviting a submission that cannot succeed.
  can_upload_invoice: { type: Boolean, default: false },
  // The office has finished with this job: it stays readable, but uploads are
  // closed off. The server enforces this too.
  isClosed: { type: Boolean, default: false },
});

const { toast } = useToast();

const formatDate = (date) => {
  if (!date) return null;
  const d = typeof date === "string" && date.includes("T")
    ? DateTime.fromISO(date, { zone: "utc" })
    : DateTime.fromFormat(String(date), "yyyy-MM-dd HH:mm:ss", { zone: "utc" });
  return d.isValid ? d.setZone("America/Chicago").toFormat("MM/dd/yyyy h:mm a") : null;
};

const photoForm = ref({ title: "", type: "after", files: [] });
const photoInputKey = ref(0);
const isUploadingPhotos = ref(false);

const handlePhotoSelect = (event) => {
  photoForm.value.files = Array.from(event.target.files || []);
};

const uploadPhotos = () => {
  if (photoForm.value.files.length === 0) {
    toast({ variant: "destructive", title: "Error", description: "Choose at least one photo." });
    return;
  }

  const formData = new FormData();
  formData.append("title", photoForm.value.title.trim());
  formData.append("type", photoForm.value.type);
  photoForm.value.files.forEach((file) => formData.append("files[]", file));

  isUploadingPhotos.value = true;
  router.post(route("jobber.portal.attachments", props.token), formData, {
    preserveScroll: true,
    onSuccess: () => {
      toast({ title: "Uploaded", description: "Thanks — your photos were received." });
      photoForm.value = { title: "", type: "after", files: [] };
      photoInputKey.value++;
    },
    onError: () => toast({ variant: "destructive", title: "Error", description: "Could not upload your photos." }),
    onFinish: () => (isUploadingPhotos.value = false),
  });
};

const invoiceForm = ref({ title: "", amount: "", filename: null });
const invoiceInputKey = ref(0);
const isUploadingInvoice = ref(false);

const handleInvoiceSelect = (event) => {
  invoiceForm.value.filename = event.target.files?.[0] || null;
};

const uploadInvoice = () => {
  if (!invoiceForm.value.filename) {
    toast({ variant: "destructive", title: "Error", description: "Choose your invoice file." });
    return;
  }

  const formData = new FormData();
  formData.append("title", invoiceForm.value.title.trim() || invoiceForm.value.filename.name);
  formData.append("amount", invoiceForm.value.amount || 0);
  formData.append("filename", invoiceForm.value.filename);

  isUploadingInvoice.value = true;
  router.post(route("jobber.portal.invoice", props.token), formData, {
    preserveScroll: true,
    onSuccess: () => {
      toast({ title: "Uploaded", description: "Thanks — your invoice was received." });
      invoiceForm.value = { title: "", amount: "", filename: null };
      invoiceInputKey.value++;
    },
    onError: () => toast({ variant: "destructive", title: "Error", description: "Could not upload your invoice." }),
    onFinish: () => (isUploadingInvoice.value = false),
  });
};
</script>

<template>
  <Head :title="title" />

  <div class="min-h-screen bg-slate-50 py-6 px-4">
    <div class="mx-auto max-w-3xl space-y-4">
      <div class="text-center">
        <p class="text-sm text-muted-foreground">Hello {{ vendorName }},</p>
        <h1 class="text-2xl font-bold text-slate-900">Job #{{ job.job_number }}</h1>
        <p class="text-slate-600">{{ job.title }}</p>
      </div>

      <Card>
        <CardHeader class="pb-3">
          <CardTitle class="text-lg">Job details</CardTitle>
        </CardHeader>
        <CardContent class="space-y-2 text-sm">
          <p v-if="job.property_address" class="flex items-start gap-2">
            <MapPin class="h-4 w-4 mt-0.5 shrink-0" /> {{ job.property_address }}
          </p>
          <p v-if="job.client_name" class="flex items-start gap-2">
            <User class="h-4 w-4 mt-0.5 shrink-0" /> {{ job.client_name }}
          </p>
          <p v-if="job.client_phone" class="flex items-start gap-2">
            <Phone class="h-4 w-4 mt-0.5 shrink-0" />
            <a :href="`tel:${job.client_phone}`" class="text-primary hover:underline">{{ job.client_phone }}</a>
          </p>
          <p v-if="formatDate(job.start_at)"><span class="font-semibold">Scheduled:</span> {{ formatDate(job.start_at) }}</p>
          <div v-if="job.instructions" class="bg-muted/50 p-3 rounded-lg mt-2">
            <p class="font-semibold mb-1">Scope of work</p>
            <div v-html="job.instructions"></div>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader class="pb-3">
          <CardTitle class="text-lg">Photos</CardTitle>
        </CardHeader>
        <CardContent class="space-y-4">
          <div v-if="attachments.length" class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <a v-for="file in attachments" :key="file.id" :href="file.url" target="_blank" class="border rounded-lg overflow-hidden block">
              <img v-if="file.is_image" :src="file.url" :alt="file.title" class="w-full h-28 object-cover" />
              <div v-else class="w-full h-28 flex items-center justify-center bg-muted"><Paperclip class="h-7 w-7 text-muted-foreground" /></div>
              <div class="p-2">
                <p class="text-xs truncate" :title="file.title">{{ file.title }}</p>
                <Badge variant="secondary" class="text-[10px] mt-1">{{ file.type }}</Badge>
              </div>
            </a>
          </div>
          <p v-else class="text-sm text-muted-foreground">No photos yet. Please upload before and after pictures of all repairs.</p>

          <Separator />

          <div v-if="isClosed" class="space-y-2">
            <p class="text-sm text-muted-foreground">This job has been closed. Uploads are no longer accepted.</p>
          </div>
          <div v-else class="space-y-2">
            <div>
              <Label class="text-sm">Title (optional)</Label>
              <Input v-model="photoForm.title" placeholder="e.g. Kitchen sink - after" />
            </div>
            <div>
              <Label class="text-sm">Type</Label>
              <Select v-model="photoForm.type">
                <SelectTrigger class="w-full">
                  <SelectValue placeholder="Select a type" />
                </SelectTrigger>
                <SelectContent>
                  <SelectGroup>
                    <SelectItem value="before">Before</SelectItem>
                    <SelectItem value="after">After</SelectItem>
                    <SelectItem value="attachment">Other</SelectItem>
                  </SelectGroup>
                </SelectContent>
              </Select>
            </div>
            <div>
              <Label class="text-sm">Photos</Label>
              <Input :key="photoInputKey" type="file" multiple accept="image/*,.pdf" @change="handlePhotoSelect" />
            </div>
            <Button class="w-full" @click="uploadPhotos" :disabled="isUploadingPhotos">
              <Loader2 v-if="isUploadingPhotos" class="h-4 w-4 animate-spin" />
              <Upload v-else class="h-4 w-4" />
              Upload photos
            </Button>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader class="pb-3">
          <CardTitle class="text-lg">Your invoices</CardTitle>
        </CardHeader>
        <CardContent class="space-y-4">
          <div v-if="invoices.length" class="space-y-2">
            <div v-for="invoice in invoices" :key="invoice.id" class="border rounded-lg p-3 flex items-center justify-between gap-2">
              <div>
                <p class="font-medium text-sm">{{ invoice.title }}</p>
                <Badge variant="secondary" class="text-[10px]">{{ invoice.status }}</Badge>
              </div>
              <div class="flex items-center gap-3">
                <span class="font-semibold text-sm">${{ invoice.amount }}</span>
                <Button variant="outline" size="sm" as-child><a :href="invoice.url" target="_blank"><Eye class="h-4 w-4" /></a></Button>
              </div>
            </div>
          </div>
          <p v-else class="text-sm text-muted-foreground">No invoice submitted yet.</p>

          <Separator />

          <div v-if="isClosed" class="space-y-2">
            <p class="text-sm text-muted-foreground">This job has been closed. Invoices are no longer accepted.</p>
          </div>
          <div v-else class="space-y-2">
            <!-- Why the form below is locked. Deliberately loud and above the
                 form: many vendors are older or working on a phone outdoors,
                 so a quiet line under the button gets missed and the invoice
                 looks broken instead of blocked. -->
            <div
              v-if="!can_upload_invoice"
              class="rounded-lg border-2 border-amber-500 bg-amber-50 dark:bg-amber-950/40 p-4"
            >
              <div class="flex items-start gap-3">
                <AlertTriangle class="h-7 w-7 shrink-0 text-amber-600 dark:text-amber-500" />
                <div class="min-w-0">
                  <p class="text-base font-bold text-amber-900 dark:text-amber-200">Add photos first</p>
                  <p class="mt-1 text-base leading-6 text-amber-900 dark:text-amber-200">
                    Please upload at least one photo of the work above before you send your invoice.
                  </p>
                </div>
              </div>
            </div>
            <div>
              <Label class="text-sm">Title (optional)</Label>
              <Input v-model="invoiceForm.title" placeholder="Invoice title" />
            </div>
            <div>
              <Label class="text-sm">Amount</Label>
              <Input v-model="invoiceForm.amount" type="number" step="0.01" min="0" placeholder="0.00" />
            </div>
            <div>
              <Label class="text-sm">File</Label>
              <Input :key="invoiceInputKey" type="file" accept=".jpg,.jpeg,.png,.pdf" @change="handleInvoiceSelect" />
            </div>
            <Button class="w-full" @click="uploadInvoice" :disabled="isUploadingInvoice || !can_upload_invoice">
              <Loader2 v-if="isUploadingInvoice" class="h-4 w-4 animate-spin" />
              <Upload v-else class="h-4 w-4" />
              Upload invoice
            </Button>
            <p v-if="!can_upload_invoice" class="text-center text-sm font-medium text-amber-700 dark:text-amber-500">
              Add a photo of the work to turn this on.
            </p>
          </div>
        </CardContent>
      </Card>

      <p class="text-center text-xs text-muted-foreground pb-6">
        This page is private to you. Please do not share the link.
      </p>
    </div>
  </div>
</template>
