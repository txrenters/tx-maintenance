<script setup>
import { CheckCircle, Dog, Key, User2, Users2 } from "lucide-vue-next";

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
    <section id="pet_policies">
        <Card>
            <CardHeader>
                <div class="flex items-center gap-3">
                    <div class="p-2 bg-red-100 rounded-lg">
                        <Dog class="h-6 w-6 text-red-600" />
                    </div>
                    <div>
                        <CardTitle>Pet Policies & Rules</CardTitle>
                        <CardDescription>
                            Set your preferences for tenant policies
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent class="space-y-6">
                <div>
                    <Alert variant="secondary" class="mb-4">
                        <AlertDescription>
                            <p>
                                <span class="font-bold underline">Pets:</span>
                                Many renters are choosing to live in a home
                                versus an apartment because they have pets. This
                                reality means that if you do not accept pets, it
                                will take about twice as long to rent your home
                                if you do not allow pets. However, this is of
                                course your choice. Please let us know if you
                                would accept pets, and what type below.
                            </p>
                        </AlertDescription>
                    </Alert>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Dogs Allowed?</label
                            >
                            <Select v-model="form.dogsAllowed">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select option" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Yes"
                                        >Yes - Dogs allowed</SelectItem
                                    >
                                    <SelectItem value="No">No dogs</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Cats Allowed?</label
                            >
                            <Select v-model="form.catsAllowed">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select option" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Yes"
                                        >Yes - Cats allowed</SelectItem
                                    >
                                    <SelectItem value="No">No cats</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div
                            class="space-y-2"
                            v-if="form.dogsAllowed === 'Yes'"
                        >
                            <label class="text-sm font-medium"
                                >Maximum Dog Weight (lbs)</label
                            >
                            <Input
                                type="number"
                                v-model="form.dogsMaxWeight"
                                placeholder="e.g. 50"
                            />
                        </div>

                        <div
                            class="space-y-2"
                            v-if="form.catsAllowed === 'Yes'"
                        >
                            <label class="text-sm font-medium"
                                >Cat Restrictions</label
                            >
                            <Input
                                v-model="form.catRestrictions"
                                placeholder="Any specific restrictions"
                            />
                        </div>
                    </div>

                    <div class="space-y-2 mt-4">
                        <label class="text-sm font-medium"
                            >Other Pet Restrictions</label
                        >
                        <Textarea
                            v-model="form.otherPetsRestriction"
                            placeholder="Any other pet-related restrictions or policies"
                            rows="2"
                        />
                    </div>
                </div>

                <Button
                    @click="markSectionCompleted('pet_policies')"
                    :variant="
                        completedSections.includes('pet_policies')
                            ? 'default'
                            : 'outline'
                    "
                    :class="[
                        'w-full sm:w-auto',
                        completedSections.includes('pet_policies')
                            ? 'bg-green-600 hover:bg-green-700 text-white'
                            : '',
                    ]"
                    :disabled="completedSections.includes('pet_policies')"
                >
                    <CheckCircle class="w-4 h-4 mr-2" />
                    {{
                        completedSections.includes("pet_policies")
                            ? "Section Completed"
                            : "Mark Section Complete"
                    }}
                </Button>
            </CardContent>
        </Card>
    </section>
</template>
