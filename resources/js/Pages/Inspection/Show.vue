<script setup>
import { ref, watch, nextTick, onMounted, onUnmounted } from "vue";
import { router, usePage, Head, Link } from "@inertiajs/vue3";
import { DateTime } from "luxon";
import axios from "axios";
import debounce from "lodash.debounce";
import AppLayout from "@/Layouts/AppLayout.vue";
import MessageCard from "@/Components/MessageCard.vue";
import { useToast } from "@/Components/ui/toast/use-toast";
import { Button } from "@/Components/ui/button";
import { Badge } from "@/Components/ui/badge";
import { Input } from "@/Components/ui/input";
import { Textarea } from "@/Components/ui/textarea";
import { Label } from "@/Components/ui/label";
import { Separator } from "@/Components/ui/separator";
import { ScrollArea } from "@/Components/ui/scroll-area";
import { Avatar, AvatarFallback, AvatarImage } from "@/Components/ui/avatar";
import { Card, CardContent, CardHeader, CardTitle, CardFooter } from "@/Components/ui/card";
import { Combobox, ComboboxAnchor, ComboboxEmpty, ComboboxGroup, ComboboxInput, ComboboxItem, ComboboxList } from "@/Components/ui/combobox";
import { ArrowLeft, Calendar, ClipboardList, Eye, Loader2, MapPin, MessageCircle, Paperclip, Plus, Search, Send, Tag, User, X } from "lucide-vue-next";

defineOptions({ layout: AppLayout });

const props = defineProps({ title: String, job: Object });
const { toast } = useToast();
const page = usePage();

const job = ref(props.job ? { ...props.job } : null);
const activeTab = ref("details");
const tabs = [
  { name: "details", label: "Details", icon: ClipboardList },
  { name: "visits", label: "Visits", icon: Calendar },
  { name: "messages", label: "Messages", icon: MessageCircle },
];

const senderPhoneNumber = ref(page.props.twilio_phone_number);
const jobMessages = ref([]);
const isLoadingMessages = ref(false);
const isSendingMessage = ref(false);
const selectedRecipients = ref([]);
const selectedClient = ref(null);
const searchQuery = ref("");
const clients = ref([]);
const customPhoneNumber = ref("");
const newMessage = ref("");
const selectedImages = ref([]);
const fileInput = ref(null);

let messageController = null;
let contactsController = null;

const statusClass = (status) => {
  switch (String(status || "").toLowerCase()) {
    case "late":
    case "ending_within_30_days":
    case "unscheduled":
      return "border-red-200 bg-red-50 text-red-700";
    case "active":
    case "today":
      return "border-blue-200 bg-blue-50 text-blue-700";
    default:
      return "border-slate-200 bg-slate-50 text-slate-700";
  }
};

const formatStatus = (status) => String(status || "").replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
const formatDate = (date) => {
  if (!date) return "-";
  const d = typeof date === "string" && date.includes("T")
    ? DateTime.fromISO(date, { zone: "utc" })
    : DateTime.fromFormat(String(date), "yyyy-MM-dd HH:mm:ss", { zone: "utc" });
  return d.isValid ? d.setZone("America/Chicago").toFormat("MM/dd/yyyy") : "-";
};

const normalizeImage = (image) => {
  if (!image) return null;
  if (/^https?:\/\//i.test(image)) return image;
  return image.startsWith("storage/") ? `/${image}` : `/storage/${image}`;
};

const normalizeMessage = (m) => ({
  ...m,
  message: m?.message ?? m?.messages ?? "",
  messages: m?.messages ?? m?.message ?? "",
  image: normalizeImage(m?.image),
});

const hydrate = () => {
  jobMessages.value = (job.value?.text_messages || []).map(normalizeMessage).slice(0, 50);
  newMessage.value = "";
  customPhoneNumber.value = "";
  selectedRecipients.value = [];
  selectedImages.value.forEach((img) => URL.revokeObjectURL(img.preview));
  selectedImages.value = [];
};

const loadSavedContacts = async () => {
  if (!job.value?.id) return;
  if (contactsController) contactsController.abort();
  contactsController = new AbortController();

  try {
    const response = await axios.get(route("client-contacts.index", { jobber: job.value.id }), { signal: contactsController.signal });
    const contacts = response.data || [];
    selectedRecipients.value = contacts.map((c) => ({ name: c.name, phone: c.phone }));
  } catch (error) {
    if (error.name !== "AbortError") console.error(error);
  } finally {
    contactsController = null;
  }
};

const fetchJobMessages = async () => {
  if (!job.value?.id) return;
  if (messageController) messageController.abort();
  messageController = new AbortController();
  isLoadingMessages.value = true;

  try {
    const response = await axios.get(route("jobber-text-messages.index", { jobber_id: job.value.id }), { signal: messageController.signal });
    const payload = response.data;
    const messages = Array.isArray(payload) ? payload : payload?.messages || payload?.data || [];
    const normalized = messages.map(normalizeMessage);
    jobMessages.value = normalized.slice(0, 50);
    job.value.text_messages = normalized;
    job.value.text_messages_count = normalized.length;
  } catch (error) {
    if (error.name !== "AbortError" && error.code !== "ERR_CANCELED") {
      toast({ variant: "destructive", title: "Error", description: "Failed to load job messages." });
    }
  } finally {
    isLoadingMessages.value = false;
    messageController = null;
  }
};

const saveContacts = async () => {
  if (!job.value?.id || selectedRecipients.value.length === 0) return;
  await axios.post(route("client-contacts.store", { jobber: job.value.id }), {
    contacts: selectedRecipients.value.map((r) => ({ name: r.name || r.phone, phone: r.phone })),
  });
};

const sendMessage = () => {
  if (!job.value?.id) return;
  if (!newMessage.value.trim() && selectedImages.value.length === 0) {
    toast({ variant: "destructive", title: "Error", description: "Please enter a message or add an image." });
    return;
  }

  const recipients = [...selectedRecipients.value];
  if (customPhoneNumber.value.trim()) recipients.push({ name: customPhoneNumber.value.trim(), phone: customPhoneNumber.value.trim() });
  if (recipients.length === 0) {
    toast({ variant: "destructive", title: "Error", description: "Please add at least one recipient." });
    return;
  }

  isSendingMessage.value = true;
  const formData = new FormData();
  formData.append("messages", newMessage.value || "");
  formData.append("sender_number", senderPhoneNumber.value || "");
  recipients.forEach((r, i) => formData.append(`receiver_numbers[${i}]`, r.phone));
  formData.append("jobber_id", job.value.id);
  selectedImages.value.forEach((img) => formData.append("images[]", img.file));

  router.post(route("jobber-text-messages.store"), formData, {
    preserveState: true,
    preserveScroll: true,
    onSuccess: () => {
      toast({ title: "Success", description: "Message sent." });
      newMessage.value = "";
      customPhoneNumber.value = "";
      selectedImages.value.forEach((img) => URL.revokeObjectURL(img.preview));
      selectedImages.value = [];
      nextTick(async () => {
        await saveContacts();
        await fetchJobMessages();
      });
    },
    onError: () => toast({ variant: "destructive", title: "Error", description: "Failed to send message." }),
    onFinish: () => (isSendingMessage.value = false),
  });
};

const fetchClients = async (query) => {
  if (!query) return (clients.value = []);
  const response = await axios.get(route("jobber.searchClient", { search: query }));
  clients.value = response.data || [];
};
const debouncedSearch = debounce(fetchClients, 700);
watch(searchQuery, (value) => debouncedSearch(value));

const addRecipientFromClient = () => {
  if (!selectedClient.value?.phone) return;
  const recipient = { name: `${selectedClient.value.first_name} ${selectedClient.value.last_name}`, phone: selectedClient.value.phone };
  if (!selectedRecipients.value.some((r) => r.phone === recipient.phone)) selectedRecipients.value.push(recipient);
  selectedClient.value = null;
  searchQuery.value = "";
};

const handleImageSelect = (event) => {
  const files = Array.from(event.target.files || []);
  files.forEach((file) => {
  if (!file.type.startsWith("image/") || file.size > 5 * 1024 * 1024) {
    toast({ variant: "destructive", title: "Invalid image", description: "Use an image file up to 5MB." });
    return;
  }
    selectedImages.value.push({ file, preview: URL.createObjectURL(file) });
  });
  if (fileInput.value) fileInput.value.value = "";
};
const removeImage = (index) => {
  URL.revokeObjectURL(selectedImages.value[index].preview);
  selectedImages.value.splice(index, 1);
};

const switchTab = (tab) => {
  activeTab.value = tab;
  if (tab === "messages") fetchJobMessages();
};

const deleteJob = () => {
  if (!job.value?.id) return;
  router.delete(route("inspections.destroy", job.value.id), {
    onSuccess: () => router.visit(route("inspections.index")),
  });
};

watch(() => props.job, (value) => {
  job.value = value ? { ...value } : null;
  hydrate();
  loadSavedContacts();
});

onMounted(() => {
  hydrate();
  loadSavedContacts();
});

onUnmounted(() => {
  selectedImages.value.forEach((img) => URL.revokeObjectURL(img.preview));
  if (messageController) messageController.abort();
  if (contactsController) contactsController.abort();
});
</script>

<template>
  <Head :title="title" />

  <Card>
    <CardHeader class="space-y-4">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <Button variant="outline" size="sm" as-child>
          <Link :href="route('inspections.index')">
            <ArrowLeft class="h-4 w-4" /> Back to Jobs
          </Link>
        </Button>

        <div class="flex gap-2 flex-wrap" v-if="job">
          <Badge :class="['px-3 py-1 font-medium border', statusClass(job)]">{{ formatStatus(job.job_status) }}</Badge>
          <Badge variant="outline" v-if="job.job_type">{{ job.job_type === 'ONE_OFF' ? 'One-off Job' : 'Recurring Job' }}</Badge>
        </div>
      </div>

      <CardTitle class="text-2xl text-primary">{{ job?.title || 'Job Details' }} - Job #{{ job?.job_number || 'N/A' }}</CardTitle>

      <div class="flex justify-center gap-1 p-1 bg-muted rounded-lg self-center">
        <button
          v-for="tab in tabs"
          :key="tab.name"
          @click="switchTab(tab.name)"
          :class="[
            'flex items-center gap-2 px-3 py-2 text-sm font-medium rounded-md transition-colors',
            activeTab === tab.name ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground hover:bg-background/50',
          ]"
        >
          <component :is="tab.icon" class="h-4 w-4" />
          {{ tab.label }}
          <Badge v-if="(tab.name === 'messages' && job?.text_messages_count) || (tab.name === 'visits' && job?.visits_count)" variant="secondary" class="text-xs">
            {{ tab.name === 'messages' ? job.text_messages_count : job.visits_count }}
          </Badge>
        </button>
      </div>
    </CardHeader>

    <Separator />

    <CardContent class="pt-6">
      <div v-if="activeTab === 'details' && job" class="space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="space-y-2">
            <p><span class="font-semibold">Client:</span> {{ job.client?.first_name }} {{ job.client?.last_name }} {{ job.client?.phone ? `- ${job.client.phone}` : '' }}</p>
            <p v-if="job.client_company"><span class="font-semibold">Company:</span> {{ job.client_company }}</p>
            <p><span class="font-semibold">Start:</span> {{ formatDate(job.start_at) }}</p>
            <p v-if="job.end_at"><span class="font-semibold">End:</span> {{ formatDate(job.end_at) }}</p>
            <p v-if="job.completed_at"><span class="font-semibold">Completed:</span> {{ formatDate(job.completed_at) }}</p>
          </div>

          <div class="space-y-2">
            <p class="flex items-start gap-2"><MapPin class="h-4 w-4 mt-0.5" /> {{ job.property_address || '-' }}</p>
            <p><span class="font-semibold">Visits:</span> {{ job.visits_count || 0 }}</p>
            <a v-if="job.jobber_web_uri" :href="job.jobber_web_uri" target="_blank" class="text-primary hover:underline inline-flex items-center gap-1">View in Jobber <Eye class="h-3 w-3" /></a>
          </div>
        </div>
        <div v-if="job.instructions" class="bg-muted/50 p-4 rounded-lg">
          <p class="font-semibold mb-2">Instructions</p>
          <p v-html="job.instructions"></p>
        </div>
      </div>

      <div v-if="activeTab === 'visits' && job" class="space-y-3">
        <div v-if="job.visits?.length">
          <div v-for="(visit, index) in job.visits.slice(0, 10)" :key="visit.id || index" class="border rounded-lg p-3 space-y-2">
            <div class="flex items-center justify-between">
              <p class="font-medium">{{ visit.title || `Visit ${index + 1}` }}</p>
              <Badge :variant="visit.completed ? 'default' : 'secondary'">{{ visit.completed ? 'Completed' : 'Pending' }}</Badge>
            </div>
            <p v-if="visit.instructions" class="text-sm" v-html="visit.instructions"></p>
            <div class="text-sm text-muted-foreground">
              <p>Start: {{ formatDate(visit.start_at) }}</p>
              <p v-if="visit.end_at">End: {{ formatDate(visit.end_at) }}</p>
              <p v-if="visit.duration">Duration: {{ visit.duration }} minutes</p>
            </div>
          </div>
        </div>
        <p v-else class="text-muted-foreground">No visits scheduled.</p>
      </div>

      <div v-if="activeTab === 'messages' && job" class="space-y-3">
        <div class="flex flex-wrap gap-2 items-center bg-muted/20 rounded-lg p-2">
          <div class="flex flex-col">
            <Label class="text-sm">Add:</Label>
            <div class="flex gap-1 flex-wrap">
              <Combobox v-model="selectedClient" by="phone">
                <ComboboxAnchor class="w-[250px]">
                  <div class="relative flex w-full items-center border rounded-md">
                    <Search class="absolute left-2 h-4 w-4 text-muted-foreground" />
                    <ComboboxInput
                      class="w-[250px] pl-8 pr-2 py-1 text-sm"
                      :display-value="(value) => value?.first_name ? `${value.first_name} ${value.last_name} - ${value.phone}` : ''"
                      :model-value="searchQuery"
                      @update:model-value="searchQuery = $event"
                      placeholder="Search clients..."
                    />
                  </div>
                </ComboboxAnchor>
                <ComboboxList class="w-[250px]">
                  <ComboboxEmpty>No client found.</ComboboxEmpty>
                  <ComboboxGroup>
                    <ComboboxItem v-for="client in clients" :key="client.id + '-' + client.phone" :value="client">
                      {{ client.first_name }} {{ client.last_name }} - {{ client.phone }}
                    </ComboboxItem>
                  </ComboboxGroup>
                </ComboboxList>
              </Combobox>

              <Button v-if="selectedClient" @click="addRecipientFromClient" size="sm"><Plus class="h-4 w-4" /></Button>
              <Input v-model="customPhoneNumber" placeholder="Enter custom number..." />
            </div>
          </div>

          <div class="flex items-center gap-2 ml-auto">
            <Avatar>
              <AvatarImage :src="$page.props.auth.user?.profile_photo_url || 'default.jpg'" />
              <AvatarFallback>{{ $page.props.auth.user.name?.charAt(0) }}</AvatarFallback>
            </Avatar>
            <div class="text-right">
              <div class="font-medium">{{ $page.props.auth.user.name }}</div>
              <div class="text-muted-foreground">{{ senderPhoneNumber || 'Not configured' }}</div>
            </div>
          </div>
        </div>

        <div v-if="selectedRecipients.length > 0" class="flex gap-2 flex-wrap items-start">
          <Label class="text-sm">To:</Label>
          <div v-for="(recipient, index) in selectedRecipients" :key="`${recipient.phone}-${index}`" class="inline-flex items-center gap-1 bg-primary/10 text-primary rounded px-2 py-1 text-sm">
            <span>{{ recipient.name || recipient.phone }}</span>
            <button @click="selectedRecipients.splice(index, 1)" class="hover:bg-primary/20 rounded p-0.5"><X class="h-3 w-3" /></button>
          </div>
          <Button @click="selectedRecipients = []" variant="ghost" size="sm" class="h-6 px-2 text-xs">Clear</Button>
        </div>

        <ScrollArea class="bg-secondary h-[520px] rounded-md p-3">
          <div class="flex justify-center" v-if="isLoadingMessages"><Loader2 class="w-12 h-12 animate-spin text-primary" /></div>
          <template v-else-if="jobMessages.length > 0">
            <MessageCard :messages="jobMessages" :sender="senderPhoneNumber" />
          </template>
          <div v-else class="text-center py-8 text-muted-foreground">No messages yet</div>
        </ScrollArea>

        <div v-if="selectedImages.length > 0" class="mb-2 p-3 border rounded-lg bg-muted/20">
          <div class="flex flex-wrap gap-2">
            <div v-for="(img, index) in selectedImages" :key="index" class="relative">
              <img :src="img.preview" :alt="img.file.name" class="w-20 h-20 object-cover rounded-lg border" />
              <Button size="icon" variant="destructive" class="absolute -top-2 -right-2 h-6 w-6" @click="removeImage(index)"><X class="h-3 w-3" /></Button>
            </div>
          </div>
          <p class="text-xs text-muted-foreground mt-2">{{ selectedImages.length }} image{{ selectedImages.length > 1 ? 's' : '' }} selected</p>
        </div>

        <div class="relative w-full">
          <input ref="fileInput" type="file" accept="image/*" multiple @change="handleImageSelect" class="hidden" />
          <Textarea v-model="newMessage" placeholder="Type your message..." class="w-full resize-y rounded-2xl border py-3 pr-24" rows="1" :disabled="isSendingMessage" @keydown.enter.exact.prevent="sendMessage" />
          <div class="flex absolute top-1/2 right-2 -translate-y-1/2">
            <Button size="icon" variant="ghost" @click="fileInput?.click()" :disabled="isSendingMessage" title="Attach image"><Paperclip class="h-4 w-4" /></Button>
            <Button size="icon" variant="ghost" @click.prevent="sendMessage" :disabled="isSendingMessage || isLoadingMessages">
              <Send v-if="!isSendingMessage" class="h-4 w-4" />
              <Loader2 v-else class="w-4 h-4 animate-spin" />
            </Button>
          </div>
        </div>
      </div>
    </CardContent>

    <Separator />

    <CardFooter class="flex gap-2 justify-end p-4" v-if="activeTab !== 'messages' && job">
      <Button variant="destructive" @click="deleteJob">Delete</Button>
      <Button v-if="job.jobber_web_uri" as-child>
        <a :href="job.jobber_web_uri" target="_blank"><Eye class="h-4 w-4" /> View in Jobber</a>
      </Button>
    </CardFooter>
  </Card>
</template>
