<script setup>
import { CheckCircle, Key } from "lucide-vue-next";

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
    <section id="rekey">
        <Card>
            <CardHeader>
                <div class="flex items-center gap-3">
                    <div class="p-2 bg-red-100 rounded-lg">
                        <Key class="h-6 w-6 text-red-600" />
                    </div>
                    <div>
                        <CardTitle>Re-Key and Code Work</CardTitle>
                        <CardDescription class="mt-1">
                            Re-Key and Code Work
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent class="space-y-6">
                <div>
                    <Alert class="mb-4">
                        <AlertDescription>
                            <div class="space-y-2">
                                <p>
                                    <span class="font-bold underline"
                                        >Re-Key & Code Work:</span
                                    >
                                    State law requires a working smoke detector
                                    in each bedroom and each hallway servicing
                                    more than one bedroom along with a peep hole
                                    and keyless locking device on each exterior
                                    door. Homes must be re-keyed between each
                                    tenant.
                                </p>
                                <p>
                                    <span class="font-bold underline"
                                        >This is a very large liability issue,
                                        therefore, if the items have not been
                                        completed prior to our move in
                                        inspection, the management company will
                                        automatically complete the items.</span
                                    >
                                </p>
                            </div>
                        </AlertDescription>
                    </Alert>

                    <div class="space-y-2">
                        <label class="text-sm font-medium"
                            >Re-Key & Code Work Responsibility</label
                        >
                        <Select v-model="form.reKey">
                            <SelectTrigger>
                                <SelectValue
                                    placeholder="Select responsibility"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="Management"
                                    >Management</SelectItem
                                >
                                <SelectItem value="Owner">Owner</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <Button
                    @click="markSectionCompleted('rekey')"
                    :variant="
                        completedSections.includes('rekey')
                            ? 'default'
                            : 'outline'
                    "
                    :class="[
                        'w-full sm:w-auto',
                        completedSections.includes('rekey')
                            ? 'bg-green-600 hover:bg-green-700 text-white'
                            : '',
                    ]"
                    :disabled="completedSections.includes('rekey')"
                >
                    <CheckCircle class="w-4 h-4 mr-2" />
                    {{
                        completedSections.includes("rekey")
                            ? "Section Completed"
                            : "Mark Section Complete"
                    }}
                </Button>
            </CardContent>
        </Card>
    </section>
</template>
