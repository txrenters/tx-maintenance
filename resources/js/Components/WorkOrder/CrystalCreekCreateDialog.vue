<script setup>
import { computed, ref, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import { useToast } from "@/Components/ui/toast/use-toast";
import { Input } from "@/Components/ui/input";
import { Textarea } from "@/Components/ui/textarea";
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from "@/Components/ui/select";
import { PlusCircle, Loader2 } from "lucide-vue-next";

// "Create Work Order" on the Crystal Creek page. The caller is not a Texas
// Renters tenant, so there is nothing to look up: the office types in who
// they are, where they are and what needs doing. The server makes the work
// order, puts THMP on it and creates the Jobber job; PropertyWare is never
// involved.
//
// `catalog` is the coordinator's fixed list (CrystalCreekCatalog::forForm()):
// { categories: ["HVAC", "Pest Control"], scopes: { HVAC: [{label, items}…], … } }.
// The Scope of Work dropdown follows the chosen category.
const props = defineProps({
    catalog: {
        type: Object,
        default: () => ({ categories: [], scopes: {} }),
    },
});

const { toast } = useToast();

const open = ref(false);

const blankForm = () => ({
    name: "",
    phone: "",
    email: "",
    street: "",
    city: "",
    state: "TX",
    postal_code: "",
    category: "",
    type: "",
    description: "",
});

const form = useForm(blankForm());

// Empty strings become null so "no email" is stored as nothing, not "".
form.transform((data) =>
    Object.fromEntries(
        Object.entries(data).map(([key, value]) => [
            key,
            typeof value === "string" && value.trim() === "" ? null : value,
        ]),
    ),
);

const submit = () => {
    form.post(route("work_orders.crystal_creek.store"), {
        preserveScroll: true,
        only: ["service_status", "flash", "errors"],
        onSuccess: (page) => {
            toast({
                title: "Work order created",
                description:
                    page.props.flash?.success ??
                    "THMP is assigned and the Jobber job is being created.",
            });
            open.value = false;
        },
        onError: (errors) => {
            // Field errors show under their inputs; only a general failure
            // needs the toast.
            if (errors?.error) {
                toast({
                    variant: "destructive",
                    title: "The work order could not be created",
                    description: errors.error,
                });
            }
        },
    });
};

watch(open, (isOpen) => {
    if (!isOpen) {
        form.reset();
        form.clearErrors();
    }
});

// The scope groups for the chosen category; a scope picked under one
// category is never carried over to another.
const scopeGroups = computed(() => props.catalog?.scopes?.[form.category] ?? []);

watch(
    () => form.category,
    () => {
        form.type = "";
        form.clearErrors("type");
    },
);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button
                class="bg-primary px-3 py-3 rounded text-white hover:bg-primary/80 whitespace-nowrap"
                title="Create a work order for a Crystal Creek customer"
            >
                <PlusCircle class="w-4 h-4 mr-2" />
                Create Work Order
            </Button>
        </DialogTrigger>

        <DialogContent class="max-w-2xl max-h-[90vh] overflow-y-auto">
            <DialogHeader>
                <DialogTitle>New Crystal Creek work order</DialogTitle>
                <DialogDescription>
                    For a customer whose home is not a Texas Renters property.
                    THMP is assigned and the Jobber job is created for you;
                    nothing goes to PropertyWare.
                </DialogDescription>
            </DialogHeader>

            <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
                <div class="space-y-1 sm:col-span-2">
                    <Label>Customer name</Label>
                    <Input v-model="form.name" placeholder="Who called" autofocus />
                    <p v-if="form.errors.name" class="text-xs text-red-500">
                        {{ form.errors.name }}
                    </p>
                </div>

                <div class="space-y-1">
                    <Label>Phone</Label>
                    <Input v-model="form.phone" placeholder="(512) 555-1234" inputmode="tel" />
                    <p v-if="form.errors.phone" class="text-xs text-red-500">
                        {{ form.errors.phone }}
                    </p>
                </div>

                <div class="space-y-1">
                    <Label>Email</Label>
                    <Input v-model="form.email" placeholder="name@example.com" inputmode="email" />
                    <p v-if="form.errors.email" class="text-xs text-red-500">
                        {{ form.errors.email }}
                    </p>
                </div>

                <div class="space-y-1 sm:col-span-2">
                    <Label>Street address</Label>
                    <Input v-model="form.street" placeholder="1234 Oak St" />
                    <p v-if="form.errors.street" class="text-xs text-red-500">
                        {{ form.errors.street }}
                    </p>
                </div>

                <div class="space-y-1">
                    <Label>City</Label>
                    <Input v-model="form.city" placeholder="Cypress" />
                    <p v-if="form.errors.city" class="text-xs text-red-500">
                        {{ form.errors.city }}
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-1">
                        <Label>State</Label>
                        <Input v-model="form.state" maxlength="2" placeholder="TX" class="uppercase" />
                        <p v-if="form.errors.state" class="text-xs text-red-500">
                            {{ form.errors.state }}
                        </p>
                    </div>
                    <div class="space-y-1">
                        <Label>ZIP</Label>
                        <Input v-model="form.postal_code" placeholder="77429" inputmode="numeric" />
                        <p v-if="form.errors.postal_code" class="text-xs text-red-500">
                            {{ form.errors.postal_code }}
                        </p>
                    </div>
                </div>

                <div class="space-y-1">
                    <Label>Category</Label>
                    <Select v-model="form.category">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Select a category" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                <SelectItem
                                    v-for="category in catalog.categories"
                                    :key="category"
                                    :value="category"
                                >
                                    {{ category }}
                                </SelectItem>
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                    <p v-if="form.errors.category" class="text-xs text-red-500">
                        {{ form.errors.category }}
                    </p>
                </div>

                <div class="space-y-1">
                    <Label>Scope of Work</Label>
                    <Select v-model="form.type" :disabled="!form.category">
                        <SelectTrigger class="w-full">
                            <SelectValue
                                :placeholder="
                                    form.category
                                        ? 'Select the scope of work'
                                        : 'Choose a category first'
                                "
                            />
                        </SelectTrigger>
                        <SelectContent class="max-h-72">
                            <SelectGroup
                                v-for="group in scopeGroups"
                                :key="group.label || 'all'"
                            >
                                <SelectLabel v-if="group.label" class="text-xs font-semibold">
                                    {{ group.label }}
                                </SelectLabel>
                                <SelectItem
                                    v-for="scope in group.items"
                                    :key="scope"
                                    :value="scope"
                                >
                                    {{ scope }}
                                </SelectItem>
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                    <p v-if="form.errors.type" class="text-xs text-red-500">
                        {{ form.errors.type }}
                    </p>
                </div>

                <div class="space-y-1 sm:col-span-2">
                    <Label>Work Description</Label>
                    <Textarea
                        v-model="form.description"
                        rows="4"
                        placeholder="What the customer described, as they said it"
                    />
                    <p v-if="form.errors.description" class="text-xs text-red-500">
                        {{ form.errors.description }}
                    </p>
                </div>
            </form>

            <DialogFooter class="flex items-center justify-between gap-2">
                <Button type="button" variant="outline" :disabled="form.processing" @click="open = false">
                    Cancel
                </Button>
                <Button type="button" :disabled="form.processing" @click="submit">
                    <Loader2 v-if="form.processing" class="w-4 h-4 mr-2 animate-spin" />
                    <PlusCircle v-else class="w-4 h-4 mr-2" />
                    {{ form.processing ? "Creating…" : "Create work order" }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
