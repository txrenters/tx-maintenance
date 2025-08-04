<script setup>
import { CheckCircle, Info, PaintBucket, Sparkle } from "lucide-vue-next";
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
    <!-- Property Preparation Section -->
    <section id="preparation">
        <Card>
            <CardHeader>
                <div class="flex items-center gap-3">
                    <div class="p-2 bg-purple-100 rounded-lg">
                        <PaintBucket class="h-6 w-6 text-purple-600" />
                    </div>
                    <div>
                        <CardTitle>Getting your Home Rent Ready</CardTitle>
                        <CardDescription class="mt-2">
                            Getting the Property Ready to Market
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent class="space-y-6">
                <div class="prose prose-sm max-w-none text-gray-600">
                    <p>
                        We want to make sure that your home is presentable
                        during the marketing phase. There are a few key areas
                        that need to be taken care of prior to placing your home
                        on the market. A neat, clean home will help you to
                        attract the best possible tenant.
                    </p>
                </div>
                <Alert class="mb-4">
                    <AlertDescription>
                        <div class="space-y-2">
                            <p>
                                <span class="font-bold underline"
                                    >Curb Appeal:</span
                                >
                                The first thing that someone will see when they
                                pull up to the home is the lawn. Your home will
                                lease faster if the yard is cut and the bushes &
                                trees are trimmed. Also, if the home has any
                                mildew or mold it should be cleaned or pressure
                                washed.
                            </p>
                        </div>
                    </AlertDescription>
                </Alert>
                <Alert class="mb-4">
                    <AlertDescription>
                        <div class="space-y-2">
                            <p>
                                <span class="font-bold underline"
                                    >Flooring:</span
                                >
                                The flooring should be free of obvious defects,
                                large stains, and pet odors.
                            </p>
                        </div>
                    </AlertDescription>
                </Alert>

                <div class="space-y-4">
                    <h3 class="font-semibold flex items-center gap-2">
                        <Sparkle class="h-5 w-5 text-yellow-500" />
                        Paint & Aesthetics
                    </h3>
                    <Alert class="mb-4">
                        <AlertDescription>
                            <div class="space-y-2">
                                <p>
                                    <span class="font-bold underline"
                                        >Walls:</span
                                    >
                                    Neutral colors will appeal to the most
                                    people. Brighter colors may be acceptable in
                                    some areas of the home. While it is not a
                                    requirement that you repaint the property,
                                    you may want to consider it if you have
                                    numerous scuff marks, holes, or rooms that
                                    have colors that might not appeal to a large
                                    group of people. If you have the paint code
                                    for your wall, please include them in the
                                    space provided below.
                                </p>
                            </div>
                        </AlertDescription>
                    </Alert>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="space-y-2">
                            <Label class="text-sm font-medium"
                                >Do you know the paint color of your
                                house?</Label
                            >
                            <Select v-model="form.paint">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select option" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="No">No</SelectItem>
                                    <SelectItem value="Yes">Yes</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="flex gap-3 space-y-2 items-end">
                            <div v-if="form.paint === 'Yes'" class="">
                                <Label> Color Name</Label>
                                <div class="flex items-center gap-3">
                                    <Input
                                        type="text"
                                        v-model="form.paintColor"
                                        class="w-full"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <Alert class="mb-4">
                    <AlertDescription>
                        <div class="space-y-2">
                            <p>
                                <span class="font-bold underline"
                                    >Valuables:</span
                                >
                                Crime can happen at any time. Please help to
                                minimize the risk by putting away or removing
                                valuables and fire arms from the property.
                            </p>
                        </div>
                    </AlertDescription>
                </Alert>
                <Alert class="mb-4">
                    <AlertDescription>
                        <div class="space-y-2">
                            <p>
                                <span class="font-bold underline"
                                    >Insurance:</span
                                >
                                Make sure to contact your insurance company to
                                learn about your insurance policy. If you are
                                moving out of the property, and it will be
                                vacant for any period, let your insurance
                                company know. Some policies do not cover
                                vandalism when home is vacant
                            </p>
                        </div>
                    </AlertDescription>
                </Alert>
                <Alert class="mb-4">
                    <AlertDescription>
                        <div class="space-y-2">
                            <p>
                                <span class="font-bold underline"
                                    >Utilities:</span
                                >
                                We require that utilities are turned on. If
                                vacant, we ask that you shut off water at the
                                main cut off valve to prevent water leaks that
                                might otherwise go unnoticed before causing
                                significant damage. It's Texas, and it's hot.
                                Prospective tenant's viewing your home will turn
                                down your AC to see if it blows cold air, and
                                will then walk out without turning the
                                thermostat back up or off. Make sure that you
                                have programmable thermostat that automatically
                                go back to a reasonable temperature, so that you
                                don't receive a surprise electric bill for a
                                vacant home.
                            </p>
                        </div>
                    </AlertDescription>
                </Alert>
                <div class="prose prose-sm max-w-none text-gray-600">
                    <p class="mb-4">
                        For the above items, we are happy to arrange the
                        services below. Please let us know by indicating in the
                        appropriate box with your initials, if you would like to
                        take care of the above items before the property goes on
                        the market, or if you would like us to handle them for
                        you.
                    </p>
                </div>

                <Button
                    @click="markSectionCompleted('preparation')"
                    :variant="
                        completedSections.includes('preparation')
                            ? 'default'
                            : 'outline'
                    "
                    :class="[
                        'w-full sm:w-auto',
                        completedSections.includes('preparation')
                            ? 'bg-green-600 hover:bg-green-700 text-white'
                            : '',
                    ]"
                    :disabled="completedSections.includes('preparation')"
                >
                    <CheckCircle class="w-4 h-4 mr-2" />
                    {{
                        completedSections.includes("preparation")
                            ? "Section Completed"
                            : "Mark Section Complete"
                    }}
                </Button>
            </CardContent>
        </Card>
    </section>
</template>
