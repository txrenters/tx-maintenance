<script setup>
import PinInput from "@/Components/ui/pin-input/PinInput.vue";
import PinInputGroup from "@/Components/ui/pin-input/PinInputGroup.vue";
import PinInputSeparator from "@/Components/ui/pin-input/PinInputSeparator.vue";
import PinInputSlot from "@/Components/ui/pin-input/PinInputSlot.vue";
import { RadioGroup, RadioGroupItem } from "@/Components/ui/radio-group";
import { CheckCircle, CoinsIcon, Home, AlertCircle } from "lucide-vue-next";
import { onMounted, ref, watch, computed } from "vue";
import { useToast } from "@/Components/ui/toast/use-toast";

const form = defineModel("form");
const emit = defineEmits(["sectionComplete"]);
const props = defineProps({
    completedSections: Array,
});

const { toast } = useToast();
const validationErrors = ref([]);

const validateW9Form = () => {
    const errors = [];

    // Field 1: Entity name (required)
    if (!form.value.w9_entity_name || form.value.w9_entity_name.trim() === "") {
        errors.push("Entity/Individual name is required (Field 1)");
    }

    // Field 3a: Tax classification (required)
    if (!form.value.w9_tax_class || form.value.w9_tax_class.trim() === "") {
        errors.push("Tax classification is required (Field 3a)");
    }

    // Field 5: Address (required)
    if (!form.value.w9_address || form.value.w9_address.trim() === "") {
        errors.push("Address is required (Field 5)");
    }

    // Field 6: City, state, ZIP (required)
    if (!form.value.w9_address2 || form.value.w9_address2.trim() === "") {
        errors.push("City, state, and ZIP code are required (Field 6)");
    }

    // Either SSN or EIN is required
    const hasSSN = form.value.w9_ssn && form.value.w9_ssn.trim() !== "";
    const hasEIN = form.value.w9_ein && form.value.w9_ein.trim() !== "";

    if (!hasSSN && !hasEIN) {
        errors.push("Either Social Security Number or Employer Identification Number is required");
    }

    // Validate SSN length if provided
    if (hasSSN && form.value.w9_ssn.length !== 9) {
        errors.push("Social Security Number must be exactly 9 digits");
    }

    // Validate EIN length if provided
    if (hasEIN && form.value.w9_ein.length !== 9) {
        errors.push("Employer Identification Number must be exactly 9 digits");
    }

    validationErrors.value = errors;
    return errors.length === 0;
};

const markSectionCompleted = (value) => {
    if (!validateW9Form()) {
        toast({
            variant: "destructive",
            title: "Validation Error",
            description: "Please fill in all required fields before completing this section.",
        });
        return;
    }
    validationErrors.value = [];
    emit("sectionComplete", value);
};

const ssn = ref([]);
const ein = ref([]);

const handleSSNComplete = (e) => (form.w9_ssn = e.join(""));
const handleEINComplete = (e) => (form.w9_ein = e.join(""));

onMounted(() => {
    if (form.w9_ssn) {
        ssn.value = form.w9_ssn.split("");
    }
    if (form.w9_ein) {
        ein.value = form.w9_ein.split("");
    }
});

watch(ssn, (newVal) => {
    form.value.w9_ssn = newVal.join("");
});

watch(ein, (newVal) => {
    form.value.w9_ein = newVal.join("");
});
</script>
<template>
    <section id="w9">
        <Card>
            <CardHeader>
                <div class="flex items-center gap-3">
                    <div class="p-2 bg-orange-100 rounded-lg">
                        <CoinsIcon class="h-6 w-6 text-orange-600" />
                    </div>
                    <div>
                        <CardTitle
                            >Form W-9. Request for Taxpayer Identification
                            Number and Certification</CardTitle
                        >
                        <CardDescription class="mt-1">
                            <span class="font-bold">Before you begin</span>. For
                            guidance related to the purpose of Form W-9, go to
                            <a
                                href="https://irs.gov/FormW9"
                                class="text-primary"
                                target="_blank"
                                >www.irs.gov/FormW9</a
                            >
                            for instructions and the latest information.
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent class="space-y-6">
                <!-- Validation Errors Display -->
                <div
                    v-if="validationErrors.length > 0"
                    class="bg-red-50 border border-red-200 rounded-lg p-4"
                >
                    <div class="flex items-start gap-2">
                        <AlertCircle class="h-5 w-5 text-red-600 flex-shrink-0 mt-0.5" />
                        <div>
                            <h4 class="text-sm font-semibold text-red-800 mb-2">
                                Please correct the following errors:
                            </h4>
                            <ul class="text-sm text-red-700 space-y-1 list-disc list-inside">
                                <li v-for="error in validationErrors" :key="error">
                                    {{ error }}
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="text-sm font-medium"
                        >1. Name of entity/individual.
                        <span class="text-red-600">*</span>
                        <span class="text-xs text-gray-500">(Required)</span>
                        <br>
                        <span class="font-normal text-xs text-gray-600">
                            For a sole proprietor or disregarded entity, enter the
                            owner's name on line 1, and enter the
                            business/disregarded entity's name on line 2.
                        </span>
                    </label>
                    <Input
                        type="text"
                        placeholder="Entity name..."
                        v-model="form.w9_entity_name"
                        class="w-full"
                    />
                </div>
                <div class="space-y-2">
                    <label class="text-sm font-medium"
                        >2. Business name/disregarded entity name, if different
                        from above.
                        <span class="text-xs text-gray-500">(Optional)</span>
                    </label>
                    <Input
                        type="text"
                        placeholder="Business name..."
                        v-model="form.w9_business_name"
                        class="w-full"
                    />
                </div>
                <div class="space-y-2">
                    <label class="text-sm"
                        ><span class="font-medium">3a. </span>Check the
                        appropriate box for federal tax classification of the
                        entity/individual whose name is entered on line 1. Check
                        only <span class="font-medium">one</span> of the
                        following seven boxes.
                        <span class="text-red-600">*</span>
                        <span class="text-xs text-gray-500">(Required)</span>
                    </label>
                    <div class="space-y-3 mt-3">
                        <RadioGroup v-model="form.w9_tax_class">
                            <div
                                class="flex flex-wrap md:flex-nowrap md:justify-between gap-3"
                            >
                                <div class="flex items-center space-x-2">
                                    <RadioGroupItem id="r1" value="r1" />
                                    <Label for="r1"
                                        >Individual/sole proprietor</Label
                                    >
                                </div>
                                <div class="flex items-center space-x-2">
                                    <RadioGroupItem id="r2" value="r2" />
                                    <Label for="r2">C corporation</Label>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <RadioGroupItem id="r3" value="r3" />
                                    <Label for="r3">S corporation</Label>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <RadioGroupItem id="r4" value="r4" />
                                    <Label for="r4">Partnership</Label>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <RadioGroupItem id="r5" value="r5" />
                                    <Label for="r5">Trust/estate</Label>
                                </div>
                            </div>

                            <div
                                class="flex flex-wrap gap-2 md:flex-nowrap items-center space-x-2 mt-3"
                            >
                                <RadioGroupItem id="r6" value="r6" />
                                <Label for="r6">LLC.</Label>
                                <Label>
                                    Enter the tax classification (C = C
                                    corporation, S = S corporation, P =
                                    Partnership) . . .
                                </Label>
                                <input
                                    type="text"
                                    v-model="form.w9_llc_tax_class"
                                    class="border p-1 w-[40px] text-center uppercase"
                                    maxlength="1"
                                />
                            </div>

                            <p class="text-sm mt-2">
                                <span class="font-bold">Note:</span> Check the
                                “LLC” box above and, in the entry space, enter
                                the appropriate code (C, S, or P) for the tax
                                classification of the LLC, unless it is a
                                disregarded entity. A disregarded entity should
                                instead check the appropriate box for the tax
                                classification of its owner.
                            </p>

                            <div
                                class="flex flex-wrap md:flex-nowrap items-center gap-2 mt-3"
                            >
                                <div class="flex items-center space-x-2">
                                    <RadioGroupItem id="r7" value="r7" />
                                    <Label for="r7"
                                        >Other (see instructions)</Label
                                    >
                                </div>
                                <input
                                    type="text"
                                    v-model="form.w9_other_tax_class"
                                    class="border p-1 w-full md:w-[240px]"
                                />
                            </div>
                        </RadioGroup>

                        <p
                            class="text-sm font-medium flex flex-wrap items-center"
                        >
                            3b. If on line 3a you checked "Partnership" or
                            "Trust/estate," or checked "LLC" and entered "P" as
                            its tax classification, and you are providing this
                            form to a partnership, trust, or estate in which you
                            have an ownership interest, check this box if you
                            have any foreign partners, owners, or beneficiaries.
                            <span class="text-xs text-gray-500 ml-2">(Optional)</span>
                        </p>
                        <RadioGroup v-model="form.w9_tax_class1">
                            <p
                                class="text-sm font-medium inline-flex items-center"
                            >
                                See instructions . . . .
                                <span class="inline-flex items-center ml-2">
                                    <RadioGroupItem
                                        id="w9_tax_class1"
                                        value="w9_tax_class1"
                                        class="!inline-flex !items-center !w-auto !min-w-0 !h-4 !align-middle"
                                    />
                                </span>
                            </p>
                        </RadioGroup>
                    </div>
                </div>
                <div class="space-y-2">
                    <label class="text-sm font-medium block"
                        >4. Exemptions (codes apply only to certain entities,
                        not individuals; see instructions on page 3)
                        <span class="text-xs text-gray-500">(Optional)</span>
                    </label>
                    <label class="text-sm font-medium"
                        >Exempt payee code (if any)
                    </label>
                    <Input
                        type="text"
                        v-model="form.w9_exempt_payee_code"
                        class="w-full"
                        placeholder="Optional"
                    />
                    <label class="text-sm font-medium"
                        >Exemption from Foreign Account Tax Compliance Act
                        (FATCA) reporting code (if any)
                    </label>
                    <Input
                        type="text"
                        v-model="form.w9_exempt_reporting_code"
                        class="w-full"
                        placeholder="Optional"
                    />
                </div>

                <div class="space-y-2">
                    <label class="text-sm font-medium block"
                        >5. Address (number, street, and apt. or suite no.). See
                        instructions.
                        <span class="text-red-600">*</span>
                        <span class="text-xs text-gray-500">(Required)</span>
                    </label>
                    <Input
                        type="text"
                        v-model="form.w9_address"
                        class="w-full"
                        placeholder="Street address..."
                    />
                </div>
                <div class="space-y-2">
                    <label class="text-sm font-medium block"
                        >6. City, state, and ZIP code
                        <span class="text-red-600">*</span>
                        <span class="text-xs text-gray-500">(Required)</span>
                    </label>
                    <Input
                        type="text"
                        v-model="form.w9_address2"
                        class="w-full"
                        placeholder="City, State ZIP..."
                    />
                </div>
                <div class="space-y-2">
                    <label class="text-sm font-medium block"
                        >7. List account number(s) here
                        <span class="text-xs text-gray-500">(Optional)</span>
                    </label>
                    <Input
                        type="text"
                        v-model="form.w9_account_list"
                        class="w-full"
                        placeholder="Optional"
                    />
                </div>

                <div class="space-y-2">
                    <label class="text-sm font-medium block"
                        >Requester’s name and address (optional)
                    </label>
                    <Input
                        type="text"
                        v-model="form.w9_requester_name_and_address"
                        class="w-full"
                    />
                </div>

                <div class="space-y-2">
                    <h3 class="font-bold mb-0">
                        Part I: Taxpayer Identification Number (TIN)
                        <span class="text-red-600">*</span>
                        <span class="text-xs text-gray-500 font-normal">(Required - provide either SSN or EIN)</span>
                    </h3>

                    <label class="text-sm block"
                        >Enter your TIN in the appropriate box. The TIN provided
                        must match the name given on line 1 to avoid backup
                        withholding. For individuals, this is generally your
                        social security number (SSN). However, for a resident
                        alien, sole proprietor, or disregarded entity, see the
                        instructions for Part I, later. For other entities, it
                        is your employer identification number (EIN). If you do
                        not have a number, see How to get a TIN, later.
                    </label>
                    <label class="text-sm block"
                        ><span class="font-medium">Note:</span> If the account
                        is in more than one name, see the instructions for line
                        1. See also What Name and Number To Give the Requester
                        for guidelines on whose number to enter.
                    </label>
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 my-3">
                        <p class="text-sm text-blue-800 font-medium">
                            <span class="text-red-600">*</span> You must provide either a Social Security Number OR an Employer Identification Number
                        </p>
                    </div>
                    <label class="text-sm font-medium block"
                        >Social security number
                    </label>
                    <PinInput
                        id="pin-input"
                        v-model="ssn"
                        @update:modelValue="
                            (val) => (form.w9_ssn = val.join(''))
                        "
                        class="w-full flex justify-center md:justify-start"
                    >
                        <PinInputGroup
                            class="flex gap-1 md:gap-2 flex-wrap justify-center md:justify-start"
                        >
                            <template v-for="(id, index) in 9" :key="id">
                                <PinInputSlot
                                    class="rounded-md border w-8 h-10 text-center md:w-10 md:h-12"
                                    :index="index"
                                />
                                <template v-if="index === 2 || index === 4">
                                    <PinInputSeparator class="mx-1 md:mx-2" />
                                </template>
                            </template>
                        </PinInputGroup>
                    </PinInput>

                    <label class="text-sm font-medium block">or </label>
                    <label class="text-sm font-medium block"
                        >Employer identification number
                    </label>
                    <PinInput
                        id="pin-input"
                        v-model="ein"
                        @update:modelValue="
                            (val) => (form.w9_ein = val.join(''))
                        "
                        class="w-full flex justify-center md:justify-start"
                    >
                        <PinInputGroup
                            class="flex gap-1 md:gap-2 flex-wrap justify-center md:justify-start"
                        >
                            <template v-for="(id, index) in 9" :key="id">
                                <PinInputSlot
                                    class="rounded-md border w-8 h-10 text-center md:w-10 md:h-12"
                                    :index="index"
                                />
                                <template v-if="index === 1">
                                    <PinInputSeparator class="mx-1 md:mx-2" />
                                </template>
                            </template>
                        </PinInputGroup>
                    </PinInput>
                </div>

                <div class="space-y-1">
                    <h3 class="font-bold mb-0">Part II Certification</h3>
                    <label class="text-sm block"
                        >Under penalties of perjury, I certify that:
                    </label>
                    <label class="text-sm block"
                        >1. The number shown on this form is my correct taxpayer
                        identification number (or I am waiting for a number to
                        be issued to me); and
                    </label>
                    <label class="text-sm block"
                        >2. I am not subject to backup withholding because (a) I
                        am exempt from backup withholding, or (b) I have not
                        been notified by the Internal Revenue Service (IRS) that
                        I am subject to backup withholding as a result of a
                        failure to report all interest or dividends, or (c) the
                        IRS has notified me that I am no longer subject to
                        backup withholding; and
                    </label>
                    <label class="text-sm block"
                        >3. I am a U.S. citizen or other U.S. person (defined
                        below); and
                    </label>
                    <label class="text-sm block"
                        >4. The FATCA code(s) entered on this form (if any)
                        indicating that I am exempt from FATCA reporting is
                        correct.
                    </label>
                    <label class="text-sm block"
                        ><span class="font-bold"
                            >Certification instructions.</span
                        >
                        You must cross out item 2 above if you have been
                        notified by the IRS that you are currently subject to
                        backup withholding because you have failed to report all
                        interest and dividends on your tax return. For real
                        estate transactions, item 2 does not apply. For mortgage
                        interest paid, acquisition or abandonment of secured
                        property, cancellation of debt, contributions to an
                        individual retirement arrangement (IRA), and, generally,
                        payments other than interest and dividends, you are not
                        required to sign the certification, but you must provide
                        your correct TIN. See the instructions for Part II,
                        later.
                    </label>
                </div>
                <Button
                    @click="markSectionCompleted('w9')"
                    :variant="
                        completedSections.includes('w9') ? 'default' : 'outline'
                    "
                    :class="[
                        'w-full sm:w-auto',
                        completedSections.includes('w9')
                            ? 'bg-green-600 hover:bg-green-700 text-white'
                            : '',
                    ]"
                    :disabled="completedSections.includes('w9')"
                >
                    <CheckCircle class="w-4 h-4 mr-2" />
                    {{
                        completedSections.includes("w9")
                            ? "Section Completed"
                            : "Mark Section Complete"
                    }}
                </Button>
            </CardContent>
        </Card>
    </section>
</template>
