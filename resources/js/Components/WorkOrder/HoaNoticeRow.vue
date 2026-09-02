<script setup>
import { ref, computed, watch } from "vue";
import axios from "axios";
import {
    Combobox,
    ComboboxAnchor,
    ComboboxEmpty,
    ComboboxGroup,
    ComboboxInput,
    ComboboxItem,
    ComboboxList,
} from "@/Components/ui/combobox";
import {
    Search,
    MapPin,
    UserRound,
    Building2,
    FileText,
    TriangleAlert,
    Trash2,
    Maximize2,
} from "lucide-vue-next";

const props = defineProps({
    // One detected notice from the scan (see HoaViolationController::detect).
    notice: { type: Object, required: true },
    buildings: { type: Array, default: () => [] },
    index: { type: Number, required: true },
});

const emit = defineEmits(["remove"]);

// Full-screen editor for the violation description — the inline textarea is
// cramped for anything longer than a line or two.
const descExpanded = ref(false);

// The pages of the source PDF this notice covers (grouped notices span several).
const pageLabel = computed(() => {
    const pages = props.notice.pages ?? [props.notice.page];
    return pages.length > 1
        ? `pages ${pages.join(", ")}`
        : `page ${pages[0]}`;
});

// v-model:buildingId — the property this notice will land on (pre-filled with
// the server's best guess, editable by staff).
const buildingId = defineModel("buildingId", { default: null });

// The editable AI-read fields flow straight back onto the notice object so the
// parent sends whatever Carlo confirmed/edited.
const searchQuery = ref("");

const selectedBuilding = computed({
    get: () =>
        (props.buildings ?? []).find((b) => b.id === buildingId.value) ?? null,
    set: (building) => (buildingId.value = building?.id ?? null),
});

const filteredBuildings = computed(() => {
    const query = searchQuery.value.trim().toLowerCase();
    if (!query) return (props.buildings ?? []).slice(0, 50);
    return (props.buildings ?? [])
        .filter((b) => b.name?.toLowerCase().includes(query))
        .slice(0, 50);
});

const isMatched = computed(() => buildingId.value != null);

// Tenant/owner on record for the selected property — comes with the scan for
// the initial match and is refetched whenever Carlo changes the property.
const contacts = ref(props.notice.contacts ?? { tenant: null, owner: null });

// The HOA violation already open on the selected property, if any (see
// HoaViolationController::existingViolationFor). Staff decide whether this
// notice is a follow-up to it or a new violation of its own.
const existing = ref(props.notice.existing_work_order ?? null);

// v-model:attachToWorkOrderId — null means "new work order"; the open
// violation's id means "attach this notice to it".
const attachToWorkOrderId = defineModel("attachToWorkOrderId", {
    default: null,
});

// A notice dated the same day as the open violation's notice is that same
// letter being uploaded again, so it files under the existing work order.
// Anything else is a new violation until staff say otherwise — deciding
// "follow-up" on our own is how a new notice on 5231 Shadow Breeze vanished
// into the old work order (2026-09-02).
const defaultAttachment = (open) =>
    open && open.notice_date && open.notice_date === props.notice.notice_date
        ? open.id
        : null;

attachToWorkOrderId.value = defaultAttachment(existing.value);

const formatDate = (dateString) =>
    new Date(`${dateString}T00:00:00`).toLocaleDateString("en-US", {
        month: "short",
        day: "numeric",
        year: "numeric",
    });

watch(buildingId, async (id, previous) => {
    if (id == null) {
        contacts.value = { tenant: null, owner: null };
        existing.value = null;
        attachToWorkOrderId.value = null;
        return;
    }
    // Skip the redundant fetch for the property the scan already resolved.
    if (previous === undefined && props.notice.contacts) return;

    try {
        const { data } = await axios.get(route("work_orders.hoa.contacts"), {
            params: { building_id: id },
        });
        contacts.value = {
            tenant: data.tenant ?? null,
            owner: data.owner ?? null,
        };
        existing.value = data.existing_work_order ?? null;
    } catch {
        contacts.value = { tenant: null, owner: null };
        existing.value = null;
    }

    attachToWorkOrderId.value = defaultAttachment(existing.value);
});
</script>

<template>
    <div
        class="rounded-lg border p-3"
        :class="
            isMatched
                ? 'border-border'
                : 'border-destructive/50 bg-destructive/5'
        "
    >
        <div class="flex items-start justify-between gap-2">
            <p class="text-xs font-semibold text-muted-foreground">
                Notice {{ index + 1 }}
                <span class="font-normal">· {{ pageLabel }}</span>
                <span v-if="notice.hoa_name" class="font-normal">
                    · {{ notice.hoa_name }}</span
                >
            </p>
            <div class="flex items-center gap-2">
                <span
                    v-if="!isMatched"
                    class="inline-flex items-center gap-1 text-[11px] font-medium text-destructive"
                >
                    <TriangleAlert class="h-3 w-3" /> Pick a property
                </span>
                <button
                    type="button"
                    class="text-muted-foreground hover:text-destructive"
                    title="Remove this notice"
                    @click.stop="emit('remove')"
                >
                    <Trash2 class="h-4 w-4" />
                </button>
            </div>
        </div>

        <!-- Property picker, pre-filled with the detected address's best match -->
        <div class="mt-2">
            <label class="text-xs font-medium">Property</label>
            <p
                v-if="notice.property_address"
                class="mb-1 flex items-center gap-1 text-xs text-muted-foreground"
            >
                <MapPin class="h-3 w-3" /> Read from notice:
                <span class="font-medium text-foreground">{{
                    notice.property_address
                }}</span>
            </p>
            <Combobox v-model="selectedBuilding" by="id">
                <ComboboxAnchor class="w-full">
                    <div
                        class="relative flex w-full items-center border rounded-md"
                    >
                        <Search
                            class="absolute left-2 h-4 w-4 text-muted-foreground"
                        />
                        <ComboboxInput
                            class="w-full pl-8 pr-2 py-1 text-sm"
                            :display-value="(val) => val?.name ?? ''"
                            :model-value="searchQuery"
                            @update:model-value="searchQuery = $event"
                            placeholder="Search properties..."
                        />
                    </div>
                </ComboboxAnchor>
                <ComboboxList class="w-full max-h-52 overflow-y-auto">
                    <ComboboxEmpty>No property found.</ComboboxEmpty>
                    <ComboboxGroup>
                        <ComboboxItem
                            v-for="building in filteredBuildings"
                            :key="building.id"
                            :value="building"
                        >
                            {{ building.name }}
                        </ComboboxItem>
                    </ComboboxGroup>
                </ComboboxList>
            </Combobox>

            <!-- Tenant / owner on record for the chosen property -->
            <div
                v-if="isMatched"
                class="mt-1 flex flex-wrap gap-x-4 gap-y-0.5 text-xs text-muted-foreground"
            >
                <span class="flex items-center gap-1">
                    <UserRound class="h-3 w-3" /> Tenant:
                    {{ contacts.tenant ?? "—" }}
                </span>
                <span class="flex items-center gap-1">
                    <Building2 class="h-3 w-3" /> Owner:
                    {{ contacts.owner ?? "—" }}
                </span>
            </div>

            <!-- A violation is already open here: new work order, or a follow-up? -->
            <div
                v-if="isMatched && existing"
                class="mt-2 rounded-md border border-amber-400/50 bg-amber-50 p-2 text-xs dark:bg-amber-950/30"
                @click.stop
            >
                <p
                    class="flex items-center gap-1 font-medium text-amber-700 dark:text-amber-300"
                >
                    <TriangleAlert class="h-3 w-3" />
                    This property already has an open HOA violation
                </p>
                <p class="mt-0.5 text-muted-foreground">
                    WO #{{ existing.work_order_no ?? existing.id }}
                    <span v-if="existing.notice_date">
                        · notice dated {{ formatDate(existing.notice_date) }}
                    </span>
                    <span v-if="existing.state"> · {{ existing.state }}</span>
                </p>
                <p
                    v-if="existing.summary"
                    class="mt-0.5 line-clamp-2 text-muted-foreground"
                    :title="existing.summary"
                >
                    {{ existing.summary }}
                </p>
                <div class="mt-1.5 flex flex-col gap-1 text-foreground">
                    <label class="flex cursor-pointer items-center gap-1.5">
                        <input
                            v-model="attachToWorkOrderId"
                            type="radio"
                            :name="`hoa-attach-${index}`"
                            :value="null"
                        />
                        New work order — this is a different violation
                    </label>
                    <label class="flex cursor-pointer items-center gap-1.5">
                        <input
                            v-model="attachToWorkOrderId"
                            type="radio"
                            :name="`hoa-attach-${index}`"
                            :value="existing.id"
                        />
                        Attach to WO #{{ existing.work_order_no ?? existing.id }}
                        — same violation, follow-up notice
                    </label>
                </div>
            </div>
        </div>

        <!-- Editable violation summary -->
        <div class="mt-2">
            <div class="flex items-center justify-between">
                <label class="flex items-center gap-1 text-xs font-medium">
                    <FileText class="h-3 w-3" /> Violation description
                </label>
                <button
                    type="button"
                    class="inline-flex items-center gap-1 text-[11px] text-muted-foreground hover:text-foreground"
                    title="Expand to edit"
                    @click.stop="descExpanded = true"
                >
                    <Maximize2 class="h-3 w-3" /> Expand
                </button>
            </div>
            <textarea
                v-model="notice.description"
                rows="2"
                class="mt-1 w-full resize-y rounded-md border border-input bg-background px-2 py-1 text-sm text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
        </div>

        <!-- Full-screen description editor -->
        <Dialog v-model:open="descExpanded">
            <DialogContent class="max-w-3xl" @click.stop>
                <DialogHeader>
                    <DialogTitle>
                        Violation description — Notice {{ index + 1 }}
                    </DialogTitle>
                    <DialogDescription>
                        Edit the full violation text below. Changes save
                        automatically.
                    </DialogDescription>
                </DialogHeader>
                <textarea
                    v-model="notice.description"
                    rows="18"
                    class="w-full resize-y rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                />
                <DialogFooter>
                    <Button type="button" @click="descExpanded = false">
                        Done
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Editable dates -->
        <div class="mt-2 grid grid-cols-2 gap-3">
            <div>
                <label class="text-xs font-medium">Notice date</label>
                <input
                    v-model="notice.notice_date"
                    type="date"
                    class="mt-1 w-full rounded-md border border-input bg-background px-2 py-1 text-sm text-foreground"
                />
            </div>
            <div>
                <label class="text-xs font-medium">
                    Deadline
                    <span class="font-normal text-muted-foreground"
                        >(optional)</span
                    >
                </label>
                <input
                    v-model="notice.deadline_date"
                    type="date"
                    class="mt-1 w-full rounded-md border border-input bg-background px-2 py-1 text-sm text-foreground"
                />
                <p
                    v-if="!notice.deadline_date && notice.deadline_display"
                    class="mt-0.5 text-[11px] text-muted-foreground"
                >
                    Notice says: {{ notice.deadline_display }}
                </p>
            </div>
        </div>
    </div>
</template>
