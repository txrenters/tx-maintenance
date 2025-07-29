<script setup>
import { CheckCircle, Home } from "lucide-vue-next";

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
    <section id="history">
        <Card>
            <CardHeader>
                <div class="flex items-center gap-3">
                    <div class="p-2 bg-orange-100 rounded-lg">
                        <Home class="h-6 w-6 text-orange-600" />
                    </div>
                    <div>
                        <CardTitle>Property History</CardTitle>
                        <CardDescription class="mt-1">
                            Set your preferences for tenant policies
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent class="space-y-6">
                <div>
                    <h3 class="font-semibold">Property History</h3>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Ever Flooded?</label
                            >
                            <Select v-model="form.floodedProperty">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select option" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Yes"
                                        >Yes - Has flooded</SelectItem
                                    >
                                    <SelectItem value="No"
                                        >No flooding history</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>

                        <div
                            class="space-y-2"
                            v-if="form.floodedProperty === 'Yes'"
                        >
                            <label class="text-sm font-medium"
                                >Last Flood Date</label
                            >
                            <Input
                                type="date"
                                v-model="form.floodedPropertyDate"
                            />
                        </div>
                    </div>
                </div>

                <Button
                    @click="markSectionCompleted('history')"
                    :variant="
                        completedSections.includes('history')
                            ? 'default'
                            : 'outline'
                    "
                    :class="[
                        'w-full sm:w-auto',
                        completedSections.includes('history')
                            ? 'bg-green-600 hover:bg-green-700 text-white'
                            : '',
                    ]"
                    :disabled="completedSections.includes('history')"
                >
                    <CheckCircle class="w-4 h-4 mr-2" />
                    {{
                        completedSections.includes("history")
                            ? "Section Completed"
                            : "Mark Section Complete"
                    }}
                </Button>
            </CardContent>
        </Card>
    </section>
</template>
