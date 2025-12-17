<script setup>
import { Download, Expand, X, EllipsisVertical, Ellipsis } from "lucide-vue-next";
import { DateTime } from "luxon";

const props = defineProps({
  files: Object,
  loading: Boolean,
});

const emit = defineEmits(["expandImage", "deleteImage", "updateInvoice", "deleteInvoice"]);

const openImageModal = (image) => {
  emit("expandImage", image);
};
const updateInvoice = (image, status) => {
  emit("updateInvoice", image, status);
};
const isImage = (file) => {
  return file.filetype.startsWith("image/");
};

const getFileIcon = (filename) => {
  const ext = filename.split(".").pop().toLowerCase();
  switch (ext) {
    case "pdf":
      return "icons/pdf.png";
    case "doc":
    case "docx":
      return "icons/docx.png";
    case "xls":
    case "xlsx":
    case "txt":
      return "icons/excel.png";
    default:
      return "icons/file.png";
  }
};

const formatDate = (date) => {
  if (!date) return "------";

  let parsedDate;

  if (typeof date === "string") {
    parsedDate = DateTime.fromISO(date, { zone: "utc" }).isValid
      ? DateTime.fromISO(date, { zone: "utc" })
      : DateTime.fromFormat(date, "yyyy-MM-dd HH:mm:ss", { zone: "utc" });
  } else if (date instanceof Date) {
    parsedDate = DateTime.fromJSDate(date);
  } else {
    return "Invalid Date";
  }

  return parsedDate.isValid ? parsedDate.toFormat("MM/dd/yyyy") : "Invalid Date";
};
</script>

<template>
  <div class="flex gap-5 flex-wrap">
    <Card
      v-for="file in files"
      :key="file.id"
      class="flex gap-2 w-full p-3 justify-between cursor-pointer"
    >
      <template v-if="isImage(file)">
        <div
          class="relative group inline-block p-3 border"
          title="View"
          v-if="!loading"
          @click="openImageModal(file)"
        >
          <!-- Delete Icon (Outside the div) -->
          <!-- <button
            @click.stop="deleteFile(file.id)"
            class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full p-1"
          >
            <X class="w-3 h-3" />
          </button> -->

          <div class="relative flex items-center justify-center w-32 h-32">
            <!-- Expand Icon -->
            <Expand
              width="40"
              height="40"
              stroke-width="1"
              class="absolute inset-0 m-auto opacity-0 group-hover:opacity-100 transition-opacity duration-200"
            />

            <!-- Image Preview (Fixed Size) -->
            <img
              :src="file.invoice_url"
              :alt="file.title"
              class="w-full h-full object-contain opacity-100 group-hover:opacity-20 transition-opacity duration-200"
            />
          </div>
        </div>

        <div v-else class="w-32 h-32 bg-gray-200 animate-pulse p-3 border"></div>
      </template>

      <template v-else>
        <!-- Show file icon -->
        <div class="relative group inline-block p-3 border" v-if="!loading">
          <!-- <button
            @click.stop="deleteFile(file.id)"
            class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full p-1"
          >
            <X class="w-3 h-3" />
          </button> -->
          <a
            :href="file.invoice_url"
            download=""
            class="relative flex items-center justify-center w-20 h-20"
            title="Download"
          >
            <!-- Delete Icon (Small X) -->

            <!-- Download icon (Hidden by default, shown on hover) -->
            <Download
              width="40"
              height="40"
              stroke-width="1"
              class="absolute inset-0 m-auto opacity-0 group-hover:opacity-100 transition-opacity duration-200"
            />

            <!-- File Icon -->
            <img
              :src="getFileIcon(file.filename)"
              class="w-full h-full object-contain opacity-100 group-hover:opacity-20 transition-opacity duration-200"
              alt="File Icon"
            />
          </a>
        </div>
        <div v-else class="w-20 h-20 bg-gray-200 animate-pulse p-3 border"></div>
      </template>
      <div class="flex flex-col gap-1 flex-grow">
        <p class="mt-2 font-semibold">Invoice: {{ file.title }}</p>
        <p class="text-sm">Amount: ${{ file.amount }}</p>
        <p class="text-xs">
          <Badge :variant="file.status === 'decline' ? 'destructive' : ''">{{
            file.status
          }}</Badge>
        </p>
        <p class="text-sm">Vendor: {{ file.vendor.name }}</p>
        <p class="text-sm">Date: {{ formatDate(file.created_at) }}</p>
      </div>

      <div
        class="flex flex-col gap-1"
        v-if="!$page.props.auth.user.roles.includes('vendor')"
      >
        <DropdownMenu>
          <DropdownMenuTrigger as-child>
            <Button aria-haspopup="true" size="icon" variant="ghost">
              <EllipsisVertical class="w-3 h-3" />
              <span class="sr-only">Toggle menu</span>
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuLabel>Actions</DropdownMenuLabel>
            <DropdownMenuItem
              class="cursor-pointer hover:bg-secondary"
              @click="() => updateInvoice(file, 'approved')"
            >
              Mark as Approved
            </DropdownMenuItem>
            <DropdownMenuItem
              class="cursor-pointer hover:bg-secondary"
              @click="() => updateInvoice(file, 'decline')"
            >
              Mark as Declined
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem
              class="cursor-pointer hover:bg-destructive text-destructive"
              @click="() => emit('deleteInvoice', file)"
            >
              Delete Invoice
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </Card>
  </div>
</template>
