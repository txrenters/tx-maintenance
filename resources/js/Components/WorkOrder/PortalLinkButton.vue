<script setup>
import { computed, ref } from "vue";
import { usePage } from "@inertiajs/vue3";
import axios from "axios";
import { Check, Copy, Link2, Loader2 } from "lucide-vue-next";
import { Button } from "@/Components/ui/button";
import { Input } from "@/Components/ui/input";
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from "@/Components/ui/popover";
import { useToast } from "@/Components/ui/toast/use-toast";

// Copy the no-login portal link for this work order's tenant or owner(s), so
// a coordinator can send it by hand. Built for the move to the new domain: the
// links already texted or emailed point at the old host, and this hands out
// the same portal on the current one. Nothing is sent from here.
const props = defineProps({
    workOrderId: { type: [Number, String], required: true },
    audience: {
        type: String,
        required: true,
        validator: (value) => ["tenant", "owner"].includes(value),
    },
});

const { toast } = useToast();

// Staff only (the endpoint decides; this just keeps the button out of sight).
const page = usePage();
const isStaff = computed(() =>
    ["admin", "woc", "accounting"].some((role) =>
        (page.props.auth?.user?.roles ?? []).includes(role),
    ),
);

const open = ref(false);
const loading = ref(false);
const error = ref("");
const links = ref([]);
const copied = ref(null);

const emptyMessage =
    props.audience === "tenant"
        ? "The tenant link could not be created. Please try again."
        : "No owner is linked to this work order.";

const load = async () => {
    loading.value = true;
    error.value = "";
    links.value = [];

    try {
        const { data } = await axios.post(
            `/work_orders/${props.workOrderId}/portal-links/${props.audience}`,
        );
        links.value = data.links ?? [];
    } catch (e) {
        error.value =
            e?.response?.data?.error ||
            "Could not load the link. Please try again.";
    } finally {
        loading.value = false;
    }
};

const onOpenChange = (value) => {
    open.value = value;

    if (value) {
        load();
    }
};

const copy = async (link, index) => {
    try {
        await navigator.clipboard.writeText(link.url);
        copied.value = index;
        setTimeout(() => (copied.value = null), 2000);
        toast({
            title: "Link copied",
            description: `${link.label} copied. Paste it into your message.`,
        });
    } catch (e) {
        toast({
            variant: "destructive",
            title: "Couldn't copy",
            description: "Please select the link and copy it manually.",
        });
    }
};
</script>

<template>
    <Popover v-if="isStaff" :open="open" @update:open="onOpenChange">
        <PopoverTrigger as-child>
            <Button
                type="button"
                size="sm"
                variant="outline"
                class="h-7 gap-1.5 text-xs"
                :title="`Copy the ${audience} portal link for this work order`"
                data-portal-link-button
            >
                <Link2 class="h-3.5 w-3.5" />
                Portal link
            </Button>
        </PopoverTrigger>
        <PopoverContent align="end" class="w-[min(24rem,calc(100vw-2rem))] p-3">
            <p class="text-xs font-semibold uppercase tracking-wide">
                {{ audience === "tenant" ? "Tenant" : "Owner" }} portal link
            </p>
            <p class="text-muted-foreground mb-3 mt-1 text-xs">
                Copy the link and send it yourself. Nothing is sent from here.
            </p>

            <div
                v-if="loading"
                class="text-muted-foreground flex items-center gap-2 py-2 text-xs"
            >
                <Loader2 class="h-3.5 w-3.5 animate-spin" />
                Getting the link...
            </div>

            <p v-else-if="error" class="text-destructive py-2 text-xs">
                {{ error }}
            </p>

            <p
                v-else-if="!links.length"
                class="text-muted-foreground py-2 text-xs"
            >
                {{ emptyMessage }}
            </p>

            <div v-else class="space-y-3">
                <div
                    v-for="(link, index) in links"
                    :key="link.url"
                    data-portal-link-row
                >
                    <p class="mb-1 truncate text-xs font-medium">
                        {{ link.label }}
                    </p>
                    <div class="flex items-center gap-1.5">
                        <Input
                            :model-value="link.url"
                            readonly
                            class="h-8 text-xs"
                            @focus="$event.target.select()"
                        />
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            class="h-8 shrink-0 gap-1.5 text-xs"
                            @click="copy(link, index)"
                        >
                            <Check
                                v-if="copied === index"
                                class="h-3.5 w-3.5 text-green-600"
                            />
                            <Copy v-else class="h-3.5 w-3.5" />
                            {{ copied === index ? "Copied" : "Copy" }}
                        </Button>
                    </div>
                </div>
            </div>
        </PopoverContent>
    </Popover>
</template>
