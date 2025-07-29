<script setup>
import { CheckCircle, Dog, Key, User2, Users, Users2 } from "lucide-vue-next";

import { Alert, AlertDescription, AlertTitle } from "@/Components/ui/alert";

const form = defineModel("form");
const emit = defineEmits(["sectionComplete"]);
const props = defineProps({
    completedSections: Array,
});

const markSectionCompleted = (value) => {
    emit("sectionComplete", value);
};
</script>
<template>
    <section id="vendors">
        <Card>
            <CardHeader>
                <div class="flex items-center gap-3">
                    <div class="p-2 bg-purple-100 rounded-lg">
                        <Users class="h-6 w-6 text-purple-600" />
                    </div>
                    <div>
                        <CardTitle>Preferred Vendors</CardTitle>
                        <CardDescription>
                            Your trusted service providers
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent class="space-y-6">
                <Alert class="mb-4">
                    <Info class="h-4 w-4" />
                    <AlertDescription>
                        <span class="font-bold underline"
                            >Preferred Vendors.</span
                        >
                        If you have a preferred vendor, we will use them for
                        non-emergency repairs, and make a reasonable effor to
                        tuse them for emergency repairs. If your preferred
                        vendor cannot be reached in a situation where delay
                        would cause damage to your property or harm to the
                        tenant, we will select a vendor who can get the job
                        completed as soon as possible.
                    </AlertDescription>
                </Alert>

                <div class="grid gap-6 md:grid-cols-2">
                    <div class="space-y-4 border p-3">
                        <h3 class="font-semibold">HVAC</h3>
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Company Name</label
                            >
                            <Input
                                v-model="form.hvacVendorName"
                                placeholder="HVAC company name"
                            />
                        </div>
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Phone Number</label
                            >
                            <Input
                                v-model="form.hvacVendorNumber"
                                placeholder="Phone number"
                            />
                        </div>
                    </div>

                    <div class="space-y-4 border p-3">
                        <h3 class="font-semibold">Electrical</h3>
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Company Name</label
                            >
                            <Input
                                v-model="form.electricVendorName"
                                placeholder="Electrician company name"
                            />
                        </div>
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Phone Number</label
                            >
                            <Input
                                v-model="form.electricVendorNumber"
                                placeholder="Phone number"
                            />
                        </div>
                    </div>

                    <div class="space-y-4 border p-3">
                        <h3 class="font-semibold">Plumbing</h3>
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Company Name</label
                            >
                            <Input
                                v-model="form.plumbingVendorName"
                                placeholder="Plumbing company name"
                            />
                        </div>
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Phone Number</label
                            >
                            <Input
                                v-model="form.plumbingVendorNumber"
                                placeholder="Phone number"
                            />
                        </div>
                    </div>

                    <div class="space-y-4 border p-3">
                        <h3 class="font-semibold">Pest Control</h3>
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Company Name</label
                            >
                            <Input
                                v-model="form.pestControlVendorName"
                                placeholder="Pest control company name"
                            />
                        </div>
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Phone Number</label
                            >
                            <Input
                                v-model="form.pestControlVendorNumber"
                                placeholder="Phone number"
                            />
                        </div>
                    </div>

                    <div class="space-y-4 border p-3">
                        <h3 class="font-semibold">Lawn Care</h3>
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Company Name</label
                            >
                            <Input
                                v-model="form.lawnCareVendorName"
                                placeholder="Lawn care company name"
                            />
                        </div>
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Phone Number</label
                            >
                            <Input
                                v-model="form.lawnCareVendorNumber"
                                placeholder="Phone number"
                            />
                        </div>
                    </div>

                    <div class="space-y-4 border p-3">
                        <h3 class="font-semibold">Other Service</h3>
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Company Name</label
                            >
                            <Input
                                v-model="form.otherVendorName"
                                placeholder="Other service company name"
                            />
                        </div>
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Phone Number</label
                            >
                            <Input
                                v-model="form.otherVendorNumber"
                                placeholder="Phone number"
                            />
                        </div>
                    </div>
                </div>

                <Button
                    @click="markSectionCompleted('vendors')"
                    :variant="
                        completedSections.includes('vendors')
                            ? 'default'
                            : 'outline'
                    "
                    :class="[
                        'w-full sm:w-auto',
                        completedSections.includes('vendors')
                            ? 'bg-green-600 hover:bg-green-700 text-white'
                            : '',
                    ]"
                    :disabled="completedSections.includes('vendors')"
                >
                    <CheckCircle class="w-4 h-4 mr-2" />
                    {{
                        completedSections.includes("vendors")
                            ? "Section Completed"
                            : "Mark Section Complete"
                    }}
                </Button>
            </CardContent>
        </Card>
    </section>
</template>
