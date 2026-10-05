<script setup>
import { Button } from "@/Components/ui/button";
import { Checkbox } from "@/Components/ui/checkbox";
import Input from "@/Components/ui/input/Input.vue";
import { Textarea } from "@/Components/ui/textarea";
import { Head, useForm } from "@inertiajs/vue3";
import { reactive, ref, computed, watch, onMounted, onUnmounted } from "vue";
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from "@/Components/ui/select";
import { useToast } from "@/Components/ui/toast/use-toast";
import { FIREPLACE_OPTIONS } from "./fireplaceOptions";
import {
    Home,
    PaintBucket,
    Wrench,
    Shield,
    Users,
    Clipboard,
    Sparkles,
    CheckCircle,
    Info,
    AlertTriangle,
    Calendar,
    Phone,
    Building,
    Truck,
    Clock,
    MapIcon,
    MessageCircle,
    ChevronRight,
    ChevronLeft,
    Loader,
    Menu,
    X,
    Lock,
    Users2,
    Dog,
    AppWindow,
    CoinsIcon,
    FileIcon,
} from "lucide-vue-next";
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from "@/Components/ui/card";
import { Alert, AlertDescription, AlertTitle } from "@/Components/ui/alert";
import FindProperty from "./partial/FindProperty.vue";
import PreparetionProperty from "./partial/PropertyPreparetion.vue";
import PropertyPriorToMarket from "./partial/PropertyPriorToMarket.vue";
import ReKeyCodeWork from "./partial/ReKeyCodeWork.vue";
import TenantServiceRequest from "./partial/TenantServiceRequest.vue";
import PetPolicy from "./partial/PetPolicy.vue";
import Amenities from "./partial/Amenities.vue";
import HVACMaintenance from "./partial/HVACMaintenance.vue";
import Vendors from "./partial/Vendors.vue";
import FloodHistory from "./partial/FloodHistory.vue";
import Footer from "./partial/Footer.vue";
import W9Form from "./partial/W9Form.vue";

const { toast } = useToast();

const fullName = ref("");
const contactNumber = ref("");
const propertyName = ref("");
const showThankYou = ref(false);
const redirectCountdown = ref(10);
let countdownTimer = null;

const form = useForm({
    paint: "",
    paintColor: "",
    //Who will do what prior to the home going on the market
    goingOnTheMarketLawnCare: "",
    goingOnTheMarketCleaning: "",
    goingOnTheMarketDebrisRemoval: "",
    goingOnTheMarketPaint: "",
    goingOnTheMarketCarpetCleaning: "",
    goingOnTheMarketCarpetReplacement: "",
    goingOnTheMarketUtilities: "",
    //Who will do what While the Home is on the Market?
    homeOnTheMarketLawnCare: "",
    homeOnTheMarketCleaning: "Management",
    homeOnTheMarketUtilities: "",
    //Who will do what Just Before Tenant Move In?
    beforeTenantMoveInLawnCare: "",
    beforeTenantMoveInPestControl: "",
    //Who will do lawn care After Tenant Move In?
    afterTenantMoveInLawnCare: "",
    //Re-Key and Code Work?
    reKey: "Management",
    //How would you like Us to Handle Tenant Service Request?
    tenantServiceRequest: "",
    //Pets
    dogsAllowed: "",
    dogsMaxWeight: "",
    catsAllowed: "",
    catRestrictions: "",
    otherPetsRestriction: "",
    petOtherAllowed: "",
    //Swimming Pool
    swimmingPool: "",
    poolService: "",
    poolServiceName: "",
    poolServiceNumber: "",
    //Alarm System
    alarmSystem: "",
    alarmSystemIncludedInPrice: "",
    alarmSystemUnderContract: "",
    alarmSystemCode: "",
    alarmSystemBeArmDuringMarketing: "",
    //Neighborhood Amenities
    communityPool: "",
    park: "",
    playGround: "",
    tennisCourt: "",
    tenantToContactNeighborhoodAmenities: "",
    //Gate, Garage Access & Mailbox
    gatedCommunity: "",
    gateCode: "",
    garageDoorOpener: "",
    garageDoorRemote: "",
    lockboxCode: "",
    mailboxKeyNo: "",
    mailboxLocation: "",
    //Appliances
    refrigerator: "",
    microwave: "",
    washingMachine: "",
    washingMachineHookups: "",
    dryer: "",
    dryerHookups: "",
    waterSoftener: "",
    waterHeaterModelYear: "",
    dishWasherModelYear: "",
    hvacModelYear: "",
    //Utilities & Trash Service
    waterProvider: "",
    gasProvider: "",
    trashProvider: "",
    trashPickupDays: "",
    recyclePickupDays: "",
    //Utilities handled by the HOA
    utilitiesHandledByHoa: false,
    hoaUtilities: "",
    // Sprinkler / Irrigation System - most homes have neither, so the form
    // opens on "Not Applicable"; a saved "Yard Features" option replaces it.
    sprinklerSystem: "Not Applicable",
    //Fireplace
    fireplace: "",
    //HVAC Issue Prevention
    hvacMaintenancePlan: "",
    installFloatSwitch: "",
    //Home Warrany
    homeWarranty: "",
    homeWarrantyCompanyName: "",
    homeWarrantyServiceNumber: "",
    homeWarrantyContactNumber: "",
    //Preferred Vendors
    hvacVendorName: "",
    hvacVendorNumber: "",
    electricVendorName: "",
    electricVendorNumber: "",
    plumbingVendorName: "",
    plumbingVendorNumber: "",
    pestControlVendorName: "",
    pestControlVendorNumber: "",
    lawnCareVendorName: "",
    lawnCareVendorNumber: "",
    otherVendorName: "",
    otherVendorNumber: "",
    //Has the property ever flooded?
    floodedProperty: "",
    floodedPropertyDate: "",

    otherComments: "",
    preferredCommunication: "",
    e_1099_consent: "",

    // New location fields
    gasShutoffValveLocation: "",
    breakerBoxLocation: "",
    hvacFilterLocation1: "",
    hvacFilterLocation2: "",
    hvacFilterLocation3: "",
    hvacFilterLocation4: "",
    hvacFilterSize1: "",
    hvacFilterSize2: "",
    hvacFilterSize3: "",
    hvacFilterSize4: "",

    w9_entity_name: "",
    w9_ssn: "",
    w9_ein: "",
    w9_business_name: "",
    w9_tax_class: "",
    w9_tax_class1: "",
    w9_llc_tax_class: "",
    w9_other_tax_class: "",
    w9_exempt_payee_code: "",
    w9_exempt_reporting_code: "",
    w9_address: "",
    w9_address2: "",
    w9_account_list: "",
    w9_requester_name_and_address: "",
});

const buildingInfo = reactive({
    id: "",
    abbreviation: "",
    name: "",
    address: {
        address: "",
        addressCont: "",
        city: "",
        country: "",
        postalCode: "",
        stateRegion: "",
    },
});

const state = reactive({
    count: 0,
    option: {
        penColor: "rgb(0, 0, 0)",
        backgroundColor: "rgb(0,0,0,0)",
    },
    disabled: false,
});

const signature1 = ref(null);

const save = (t) => {
    console.log(signature1.value?.save(t));
};

const clear = () => {
    signature1.value?.clear();
};

const undo = () => {
    signature1.value?.undo();
};

const loading = ref(false);
const buildings = ref([]);
const selectedBuildingId = ref(null);
const building = computed(() => {
    if (!selectedBuildingId.value || buildings.value.length === 0) return null;
    return buildings.value.find((b) => b.id === selectedBuildingId.value);
});

// Sidebar state
const isSidebarOpen = ref(true); // Default open on desktop
const toggleSidebar = () => {
    isSidebarOpen.value = !isSidebarOpen.value;
};
const closeSidebar = () => {
    // Only close on mobile/tablet
    if (window.innerWidth < 1280) {
        isSidebarOpen.value = false;
    }
};

// Set initial state based on screen size
onMounted(() => {
    isSidebarOpen.value = window.innerWidth >= 1280;

    // Update on resize
    const handleResize = () => {
        if (window.innerWidth >= 1280) {
            isSidebarOpen.value = true;
        }
    };
    window.addEventListener("resize", handleResize);

    onUnmounted(() => {
        window.removeEventListener("resize", handleResize);
        // Clean up countdown timer on component unmount
        if (countdownTimer) {
            clearInterval(countdownTimer);
            countdownTimer = null;
        }
    });

    const immediateCheck = localStorage.getItem("buildingOnboardingForm");
    console.log("buildingOnboardingForm exists on load:", !!immediateCheck);
    if (immediateCheck) {
        console.log(
            "Data found on page load:",
            immediateCheck.substring(0, 100) + "...",
        );

        // Try to restore building ID from localStorage
        try {
            const savedData = JSON.parse(immediateCheck);
            if (savedData.buildingId) {
                console.log(
                    "🏗️ Setting buildingInfo.id from localStorage:",
                    savedData.buildingId,
                );
                buildingInfo.id = savedData.buildingId;

                // Also restore search fields if they exist
                if (savedData.savedSearchData) {
                    console.log("🔍 Restoring search fields from localStorage");
                    const searchData = savedData.savedSearchData;

                    fullName.value = searchData.fullName || "";
                    contactNumber.value = searchData.contactNumber || "";
                    propertyName.value = searchData.propertyName || "";
                    selectedBuildingId.value =
                        searchData.selectedBuildingId || "";

                    // Restore buildings search results
                    if (
                        searchData.buildings &&
                        Array.isArray(searchData.buildings)
                    ) {
                        buildings.value = searchData.buildings;
                        console.log(
                            "🏢 Restored",
                            searchData.buildings.length,
                            "buildings from localStorage",
                        );
                    }

                    // Restore buildingInfo details
                    if (searchData.buildingInfo) {
                        buildingInfo.name = searchData.buildingInfo.name || "";
                        buildingInfo.abbreviation =
                            searchData.buildingInfo.abbreviation || "";
                        if (searchData.buildingInfo.address) {
                            buildingInfo.address = {
                                ...buildingInfo.address,
                                ...searchData.buildingInfo.address,
                            };
                        }
                    }

                    // Restore building data including custom fields map
                    if (
                        searchData.buildings &&
                        searchData.buildings.length > 0
                    ) {
                        const savedBuilding = searchData.buildings.find(
                            (b) => b.id === savedData.buildingId,
                        );
                        if (savedBuilding) {
                            console.log(
                                "🔧 Restoring building data and populating customFieldsMap",
                            );
                            building.value = savedBuilding;
                            loadBuildingData();
                        }
                    }
                }
            }
        } catch (error) {
            console.error("Error parsing saved data:", error);
        }
    }

    // Note: localStorage restoration moved to a watcher that triggers when buildingInfo becomes available
});

// Restore form data from localStorage when buildingInfo becomes available
const restoreFromLocalStorage = () => {
    const savedFormData = localStorage.getItem("buildingOnboardingForm");

    if (savedFormData && buildingInfo?.id) {
        try {
            const parsedData = JSON.parse(savedFormData);
            console.log("parsedData.buildingId:", parsedData.buildingId);
            console.log(
                "ID match check:",
                parsedData.buildingId === buildingInfo?.id,
            );

            // Only restore data if building ID matches
            if (parsedData.buildingId === buildingInfo.id) {
                console.log("✅ Restoring form data from localStorage");
                let restoredFields = 0;

                Object.keys(parsedData).forEach((key) => {
                    if (key !== "buildingId" && form.hasOwnProperty(key)) {
                        form[key] = parsedData[key];
                        restoredFields++;
                    }
                });

                // Drafts saved by the old form may hold the gate field's text
                // in the Garage Door Opener answer (it used to pre-fill from it)
                if (!["Yes", "No"].includes(form.garageDoorOpener)) {
                    form.garageDoorOpener = "";
                }

                console.log("📝 Restored", restoredFields, "form fields");

                // Show notification that data was restored
                toast({
                    title: "Form Restored",
                    description: "Your previous progress has been restored.",
                });

                return true; // Restoration successful
            } else {
                console.log(
                    "❌ Building ID mismatch - saved:",
                    parsedData.buildingId,
                    "current:",
                    buildingInfo.id,
                );
            }
        } catch (error) {
            console.error("❌ Error restoring form data:", error);
        }
    } else {
        if (!savedFormData) {
            console.log("📭 No localStorage data found");
        }
        if (!buildingInfo?.id) {
            console.log("⏳ buildingInfo not ready yet");
        }
    }
    return false; // Restoration failed or not ready
};

// Watch for buildingInfo to become available and restore localStorage
watch(
    () => buildingInfo?.id,
    (newId) => {
        if (newId) {
            console.log("🏢 buildingInfo loaded with ID:", newId);
            restoreFromLocalStorage();
        }
    },
    { immediate: true },
);

// Auto-save form data to localStorage
const saveFormToLocalStorage = () => {
    console.log("💾 Attempting to save to localStorage:");
    console.log("buildingInfo?.id:", buildingInfo?.id);

    if (buildingInfo?.id) {
        // Extract only the data from the Inertia form object + search fields
        const formData = {
            buildingId: buildingInfo.id,
            // Save search/building info for restoration
            savedSearchData: {
                fullName: fullName.value,
                contactNumber: contactNumber.value,
                propertyName: propertyName.value,
                selectedBuildingId: selectedBuildingId.value,
                buildings: buildings.value, // Save the search results
                buildingInfo: {
                    id: buildingInfo.id,
                    name: buildingInfo.name,
                    abbreviation: buildingInfo.abbreviation,
                    address: buildingInfo.address,
                },
            },
            ...form.data(),
        };

        console.log("✅ Saving form data:", {
            buildingId: formData.buildingId,
            fieldsCount: Object.keys(formData).length - 1, // -1 for buildingId
        });

        localStorage.setItem(
            "buildingOnboardingForm",
            JSON.stringify(formData),
        );

        // Immediately verify the save worked
        const verification = localStorage.getItem("buildingOnboardingForm");
        if (verification) {
            console.log("📁 localStorage save verified - data exists");
        } else {
            console.log(
                "🚨 localStorage save FAILED - data missing immediately after save!",
            );
        }

        console.log("📁 localStorage updated successfully");
    } else {
        console.log("❌ Cannot save: buildingInfo?.id is not available");
        console.log("buildingInfo:", buildingInfo);
    }
};

// Debounce timer reference
let saveTimer = null;

// Debounced save function to prevent too many localStorage writes
const debouncedSave = () => {
    if (saveTimer) {
        clearTimeout(saveTimer);
    }
    saveTimer = setTimeout(() => {
        saveFormToLocalStorage();
    }, 1000); // Save after 1 second of no changes
};

// Watch for form changes and auto-save
// Watch the form.data() which contains the actual form values
watch(
    () => form.data(),
    () => {
        debouncedSave();
    },
    { deep: true },
);

// Define sections for navigation
const sections = [
    { id: "search", title: "Property Search", icon: Home },
    { id: "preparation", title: "Property Preparation", icon: PaintBucket },
    { id: "services", title: "Service Responsibilities", icon: Wrench },
    { id: "rekey", title: "Re-key and Code Work", icon: Lock },
    {
        id: "tenant_service_request",
        title: "Tenant Service Request",
        icon: Users2,
    },
    { id: "pet_policies", title: "Pet Policies", icon: Dog },
    { id: "amenities", title: "Amenities & Features", icon: Sparkles },
    { id: "hvac_maintenance", title: "HVAC & Home Warranty", icon: AppWindow },
    { id: "vendors", title: "Preferred Vendors", icon: Users },
    { id: "history", title: "Property History", icon: Home },
    { id: "w9", title: "W-9 Form", icon: FileIcon },
    { id: "signature", title: "Signature & Submit", icon: CheckCircle },
];

const currentSection = ref("search");
const completedSections = ref([]);

// Compute progress
const progress = computed(() => {
    return (completedSections.value.length / sections.length) * 100;
});

// Watch for changes in alarmSystemCode - if it has a value, set alarmSystem to "Yes"
watch(
    () => form.alarmSystemCode,
    (newValue) => {
        if (
            newValue &&
            newValue.trim() !== "" &&
            newValue !== "Not Completed"
        ) {
            form.alarmSystem = "Yes";
        }
    },
);

// Watch for changes in reKey field based on Property Re-Key custom field
watch(
    () => form.reKey,
    (newValue) => {
        // This watcher is for when form.reKey is directly modified
        // The logic in populateFormFromBuilding handles the initial mapping
    },
);

// Navigate to section
const navigateToSection = (sectionId) => {
    currentSection.value = sectionId;
    const element = document.getElementById(sectionId);
    if (element) {
        element.scrollIntoView({ behavior: "smooth", block: "start" });
    }
};

// Mark section as completed
const markSectionCompleted = (sectionId) => {
    if (!completedSections.value.includes(sectionId)) {
        completedSections.value.push(sectionId);
    }
    // Save form data when section is completed
    saveFormToLocalStorage();
};

// Define the mapping between form fields and Propertyware custom field names
const FORM_FIELD_TO_CUSTOM_FIELD_MAPPING = {
    // Paint fields
    paintColor: "Paint Color",
    paint: "Painting Required",

    // Going on the market services
    goingOnTheMarketLawnCare: "Lawn Care During Marketing",
    goingOnTheMarketCleaning: "Cleaning Service",
    goingOnTheMarketDebrisRemoval: "Debris Removal",
    goingOnTheMarketPaint: "Painting Required",
    goingOnTheMarketCarpetCleaning: "Carpet Cleaning",
    goingOnTheMarketCarpetReplacement: "Carpet Care",
    goingOnTheMarketUtilities: "Utilities",

    // While home is on market
    homeOnTheMarketLawnCare: "Lawn Care During Marketing",
    homeOnTheMarketCleaning: "Final Clean",
    homeOnTheMarketUtilities: "Utilities",

    // After tenant move in
    afterTenantMoveInLawnCare: "Yard Care During Lease",

    // Re-Key
    reKey: "Property Re-Key",

    // Tenant Service Request
    tenantServiceRequest: "Service Provided",

    // Pets
    otherPetsRestriction: "Pet Restrictions",

    // Pool
    poolService: "Pool Service",

    // Alarm - handled by buildAlarmSystemCodeValue()/parseAlarmSystemCustomField()
    // because the single Propertyware field carries the code and the alarm answers
    alarmSystemCode: "Alarm System Code",

    // Gate, Garage Access & Mailbox
    // Gate - handled by buildGatedCommunityValue()/parseGatedCommunityCustomField()
    // because the single Propertyware field carries the answer and the code
    lockboxCode: "Lockbox Code",
    mailboxKeyNo: "Mailbox Keys",

    // Utilities
    // Sprinkler - handled by buildSprinklerSystemValue()/parseSprinklerSystemCustomField()
    // because "Yard Features" is a picklist with its own option values
    // HOA utilities - handled by buildHoaUtilitiesValue()/parseHoaUtilitiesCustomField()
    // because the checkbox and the list share the "Utilities Handled by HOA" field
    waterProvider: "Water Provider",
    gasProvider: "Gas Provider",
    trashProvider: "Trash Provider",

    // HVAC
    hvacMaintenancePlan: "HVAC Plan",

    // Home Warranty
    homeWarranty: "Home Warranty",

    // Other
    otherComments: "Make Ready Notes",
    preferredCommunication: "Owner Preferred Communication",

    // Neighborhood Amenities Contact
    tenantToContactNeighborhoodAmenities:
        "Contact Info for Neighborhood Amenities",

    // New location fields
    gasShutoffValveLocation: "Gas Shut Off Valve Location",
    breakerBoxLocation: "Breaker Box Location",
    hvacFilterLocation1: "HVAC Filter Location Information 1",
    hvacFilterLocation2: "HVAC Filter Location Information 2",
    hvacFilterLocation3: "HVAC Filter Location Information 3",
    hvacFilterLocation4: "HVAC Filter Location Information 4",
    hvacFilterSize1: "HVAC Filter Size 1",
    hvacFilterSize2: "HVAC Filter Size 2",
    hvacFilterSize3: "HVAC Filter Size 3",
    hvacFilterSize4: "HVAC Filter Size 4",
};

// Every alarm answer is stored in the single Propertyware "Alarm System Code"
// field (Property Information field set), formatted as:
// "Alarm System Present - No, Included in Price - No, Under Contract - No, Armed During Marketing - Not specified"
const ALARM_SYSTEM_CUSTOM_FIELD = "Alarm System Code";
const ALARM_CODE_LABEL = "Alarm Code";
const ALARM_ANSWER_LABELS = {
    alarmSystem: "Alarm System Present",
    alarmSystemIncludedInPrice: "Included in Price",
    alarmSystemUnderContract: "Under Contract",
    alarmSystemBeArmDuringMarketing: "Armed During Marketing",
};
const ALARM_UNANSWERED_VALUE = "Not specified";
const ALARM_LABEL_SEPARATOR = " - ";
const ALARM_ITEM_SEPARATOR = ", ";

/**
 * Build the combined Propertyware value for the "Alarm System Code" field.
 *
 * @return {?string} Combined value, or null when the alarm section is untouched
 */
const buildAlarmSystemCodeValue = () => {
    const alarmCode = String(form.alarmSystemCode || "").trim();
    const hasAlarmCode = alarmCode !== "" && alarmCode !== "Not Completed";
    const answeredFields = Object.keys(ALARM_ANSWER_LABELS).filter(
        (formField) => form[formField] === "Yes" || form[formField] === "No",
    );

    // Nothing to sync when the owner left the whole alarm section blank
    if (!hasAlarmCode && answeredFields.length === 0) {
        return null;
    }

    const buildItem = (label, value) =>
        `${label}${ALARM_LABEL_SEPARATOR}${value}`;
    const valueParts = [
        buildItem(
            ALARM_ANSWER_LABELS.alarmSystem,
            form.alarmSystem === "Yes" || form.alarmSystem === "No"
                ? form.alarmSystem
                : ALARM_UNANSWERED_VALUE,
        ),
        buildItem(
            ALARM_ANSWER_LABELS.alarmSystemIncludedInPrice,
            form.alarmSystemIncludedInPrice || ALARM_UNANSWERED_VALUE,
        ),
        buildItem(
            ALARM_ANSWER_LABELS.alarmSystemUnderContract,
            form.alarmSystemUnderContract || ALARM_UNANSWERED_VALUE,
        ),
    ];

    // Only list the code itself when the owner provided one
    if (hasAlarmCode) {
        valueParts.push(buildItem(ALARM_CODE_LABEL, alarmCode));
    }

    valueParts.push(
        buildItem(
            ALARM_ANSWER_LABELS.alarmSystemBeArmDuringMarketing,
            form.alarmSystemBeArmDuringMarketing || ALARM_UNANSWERED_VALUE,
        ),
    );

    return valueParts.join(ALARM_ITEM_SEPARATOR);
};

/**
 * Populate the alarm form fields from the combined "Alarm System Code" value.
 * Plain codes saved before this format was introduced still load correctly.
 */
const parseAlarmSystemCustomField = () => {
    const rawValue = customFieldsMap.value[ALARM_SYSTEM_CUSTOM_FIELD]?.value;

    if (!rawValue || rawValue === "Not Completed") {
        return;
    }

    const segments = String(rawValue)
        .split(",")
        .map((segment) => segment.trim())
        .filter((segment) => segment !== "");
    let matchedAnyLabel = false;

    segments.forEach((segment) => {
        const separatorIndex = segment.indexOf(ALARM_LABEL_SEPARATOR);

        if (separatorIndex === -1) {
            return;
        }

        const label = segment.slice(0, separatorIndex).trim().toLowerCase();
        const value = segment
            .slice(separatorIndex + ALARM_LABEL_SEPARATOR.length)
            .trim();

        if (label === ALARM_CODE_LABEL.toLowerCase()) {
            matchedAnyLabel = true;
            form.alarmSystemCode = value;
            return;
        }

        const answerEntry = Object.entries(ALARM_ANSWER_LABELS).find(
            ([, answerLabel]) => answerLabel.toLowerCase() === label,
        );

        if (!answerEntry) {
            return;
        }

        matchedAnyLabel = true;

        if (value.toLowerCase() === "yes" || value.toLowerCase() === "no") {
            form[answerEntry[0]] = value.toLowerCase() === "yes" ? "Yes" : "No";
        }
    });

    // Legacy values hold the bare alarm code
    if (!matchedAnyLabel) {
        form.alarmSystem = "Yes";
        form.alarmSystemCode = String(rawValue).trim();
    }
};

// Both gate answers are stored in the single Propertyware
// "Gated Community? Gate Code?" field (Property Information field set), as:
// "Yes - Gate code: #1234" or "No gate"
const GATED_COMMUNITY_CUSTOM_FIELD = "Gated Community? Gate Code?";
const GATED_YES_PREFIX = "Yes";
const GATED_NO_VALUE = "No gate";
const GATE_CODE_LABEL = "Gate code";
const GATE_LABEL_SEPARATOR = " - ";
// Values staff typed by hand before this format: "No", "No gate",
// "Not gated", "Not gated community", "N/A", "None"
const GATED_NO_PATTERN = /^(no|not|n\/?a|none)\b/i;
// Propertyware's own placeholder, sometimes with a note tacked on
const NOT_COMPLETED_PATTERN = /^not completed\b/i;
// A leading "Yes" and whatever punctuation followed it: "Yes, #2373#"
const GATED_YES_PREFIX_PATTERN = /^yes\b[\s,:;.\-–]*/i;
// A "Gate Code:" / "code:" / "Gate Code is" label written in front of the code itself
const GATE_CODE_LABEL_PATTERN = /^(gate\s*)?code\b(\s+is\b)?[\s:=\-–]*/i;

/**
 * Strip a "Gate code:" style label from the front of a gate code.
 *
 * @param {?string} text
 * @return {string}
 */
const stripGateCodeLabel = (text) =>
    String(text || "")
        .replace(GATE_CODE_LABEL_PATTERN, "")
        .trim();

/**
 * Build the combined Propertyware value for the "Gated Community? Gate Code?" field.
 *
 * @return {?string} Combined value, or null when the owner has not answered
 */
const buildGatedCommunityValue = () => {
    if (form.gatedCommunity === "No") {
        return GATED_NO_VALUE;
    }

    if (form.gatedCommunity !== "Yes") {
        return null;
    }

    const gateCode = stripGateCodeLabel(form.gateCode);

    // A bare "Yes" is what the old form wrote (it was the garage door opener
    // answer) and is useless to a vendor - never write it again
    if (gateCode === "") {
        return null;
    }

    return `${GATED_YES_PREFIX}${GATE_LABEL_SEPARATOR}${GATE_CODE_LABEL}: ${gateCode}`;
};

/**
 * Split a raw "Gated Community? Gate Code?" value into the two form answers.
 * Handles the canonical format and the values typed by hand before it.
 *
 * @param {?string} rawValue
 * @return {?{gatedCommunity: string, gateCode: string}} null when there is nothing to pre-fill
 */
const parseGatedCommunityValue = (rawValue) => {
    const value = String(rawValue ?? "").trim();

    if (value === "" || NOT_COMPLETED_PATTERN.test(value)) {
        return null;
    }

    if (GATED_NO_PATTERN.test(value)) {
        return { gatedCommunity: "No", gateCode: "" };
    }

    // A bare "Yes" leaves the code empty so the owner has to type it
    const gateCode = stripGateCodeLabel(
        value.replace(GATED_YES_PREFIX_PATTERN, ""),
    );

    return { gatedCommunity: "Yes", gateCode };
};

/**
 * Populate the gate form fields from the "Gated Community? Gate Code?" value.
 */
const parseGatedCommunityCustomField = () => {
    const parsed = parseGatedCommunityValue(
        customFieldsMap.value[GATED_COMMUNITY_CUSTOM_FIELD]?.value,
    );

    if (!parsed) {
        return;
    }

    form.gatedCommunity = parsed.gatedCommunity;
    form.gateCode = parsed.gateCode;
};

// The sprinkler answer goes to the Propertyware "Yard Features" picklist
// (Property Information field set). A picklist only accepts its own options,
// and anything else is rejected and takes the whole submission down with it,
// so the form offers those options and stores the option itself - there is no
// wording of our own in between to get wrong.
//
// Only two values are used anywhere in the Propertyware data - "Lawn
// Irrigation" for a home that has a system and "Not Applicable" for one that
// does not - so those are the two the form writes. "Sprinkler System" has
// never been written to a single property and Propertyware rejects it, which
// is what stopped owners submitting the form at all.
const SPRINKLER_CUSTOM_FIELD = "Yard Features";
const SPRINKLER_HAS_SYSTEM_OPTION = "Lawn Irrigation";
const SPRINKLER_NO_SYSTEM_OPTION = "Not Applicable";
const SPRINKLER_PICKLIST_OPTIONS = [
    SPRINKLER_HAS_SYSTEM_OPTION,
    SPRINKLER_NO_SYSTEM_OPTION,
];

/**
 * Pick the "Yard Features" option for the sprinkler answer.
 *
 * @return {?string} Option value, or null when there is nothing to write -
 *                   the owner has not answered, or said the home has no system
 */
const buildSprinklerSystemValue = () => {
    return SPRINKLER_PICKLIST_OPTIONS.includes(form.sprinklerSystem)
        ? form.sprinklerSystem
        : null;
};

/**
 * Map a raw "Yard Features" option back to the form's answer.
 * Anything that is not one of the picklist's options - "Not Provided", the
 * picklist default, among them - leaves the answer as it is.
 *
 * @param {?string} rawValue
 * @return {?string} The matching option, or null when there is nothing to pre-fill
 */
const parseSprinklerSystemValue = (rawValue) => {
    const value = String(rawValue ?? "").trim().toLowerCase();

    return (
        SPRINKLER_PICKLIST_OPTIONS.find(
            (option) => option.toLowerCase() === value,
        ) ?? null
    );
};

/**
 * Populate the sprinkler Yes/No from the "Yard Features" option.
 */
const parseSprinklerSystemCustomField = () => {
    const parsed = parseSprinklerSystemValue(
        customFieldsMap.value[SPRINKLER_CUSTOM_FIELD]?.value,
    );

    if (parsed === null) {
        return;
    }

    form.sprinklerSystem = parsed;
};

// The fireplace choice goes to the Propertyware "Fireplace" custom field, a
// plain text field, so the option picked on the form is written as-is.
// "Not Completed" (the field's default) or any other text pre-fills nothing.
const FIREPLACE_CUSTOM_FIELD = "Fireplace";

/**
 * Map a raw "Fireplace" value back to one of the form's options.
 *
 * @param {?string} rawValue
 * @return {?string} The canonical option, or null when there is nothing to pre-fill
 */
const parseFireplaceValue = (rawValue) => {
    const value = String(rawValue ?? "").trim().toLowerCase();

    return (
        FIREPLACE_OPTIONS.find((option) => option.toLowerCase() === value) ??
        null
    );
};

/**
 * Populate the fireplace choice from the "Fireplace" custom field.
 */
const parseFireplaceCustomField = () => {
    const parsed = parseFireplaceValue(
        customFieldsMap.value[FIREPLACE_CUSTOM_FIELD]?.value,
    );

    if (parsed === null) {
        return;
    }

    form.fireplace = parsed;
};

// The HOA utilities answer goes to the Propertyware "Utilities Handled by HOA"
// custom field, a plain text field: the list the owner typed is written as-is
// when the box is ticked, "None" when it is not. An empty value (the field's
// default), "Not Completed" or "Not Provided" pre-fills nothing.
const HOA_UTILITIES_CUSTOM_FIELD = "Utilities Handled by HOA";
const HOA_UTILITIES_NONE_VALUE = "None";
// "None", "No", "N/A" - however staff or an earlier owner said "nothing"
const HOA_UTILITIES_NONE_PATTERN = /^(none|no|n\/?a)\b/i;
// Propertyware's other placeholder for a field nobody has filled in
const NOT_PROVIDED_PATTERN = /^not provided\b/i;

/**
 * Build the "Utilities Handled by HOA" value. The owner may list one utility
 * per line; blank lines and stray spaces are dropped, the line breaks kept.
 *
 * @return {?string} The list, "None", or null when the box is ticked but the list is empty
 */
const buildHoaUtilitiesValue = () => {
    if (form.utilitiesHandledByHoa !== true) {
        return HOA_UTILITIES_NONE_VALUE;
    }

    const utilities = String(form.hoaUtilities ?? "")
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter((line) => line !== "")
        .join("\n");

    return utilities === "" ? null : utilities;
};

/**
 * Split a raw "Utilities Handled by HOA" value into the checkbox and the list.
 *
 * @param {?string} rawValue
 * @return {?{utilitiesHandledByHoa: boolean, hoaUtilities: string}} null when there is nothing to pre-fill
 */
const parseHoaUtilitiesValue = (rawValue) => {
    const value = String(rawValue ?? "").trim();

    if (
        value === "" ||
        NOT_COMPLETED_PATTERN.test(value) ||
        NOT_PROVIDED_PATTERN.test(value)
    ) {
        return null;
    }

    if (HOA_UTILITIES_NONE_PATTERN.test(value)) {
        return { utilitiesHandledByHoa: false, hoaUtilities: "" };
    }

    return { utilitiesHandledByHoa: true, hoaUtilities: value };
};

/**
 * Populate the HOA utilities checkbox and list from the custom field.
 */
const parseHoaUtilitiesCustomField = () => {
    const parsed = parseHoaUtilitiesValue(
        customFieldsMap.value[HOA_UTILITIES_CUSTOM_FIELD]?.value,
    );

    if (!parsed) {
        return;
    }

    form.utilitiesHandledByHoa = parsed.utilitiesHandledByHoa;
    form.hoaUtilities = parsed.hoaUtilities;
};

// Store for custom field IDs (add this after your reactive declarations)
const customFieldsMap = ref({});
const customFieldsData = ref([]);

// Update your searchProperty function to store the custom field IDs
const searchProperty = async () => {
    loading.value = true;
    buildings.value = [];
    selectedBuildingId.value = null;
    customFieldsMap.value = {}; // Reset the map
    customFieldsData.value = []; // Reset the data

    if (
        !fullName.value.trim() ||
        !contactNumber.value.trim() ||
        !propertyName.value.trim()
    ) {
        toast({
            variant: "destructive",
            title: "Missing fields",
            description: "Please fill out all required fields.",
        });
        loading.value = false;
        return;
    }

    try {
        const res = await axios.post("/api/search-building", {
            fullName: fullName.value,
            contactNumber: contactNumber.value,
            propertyName: propertyName.value,
        });

        if (!res.data || res.data.error) {
            toast({
                variant: "destructive",
                title: "Search failed",
                description: res.data.error,
            });
            buildings.value = [];
            return;
        }

        // Handle the new response format
        if (res.data.buildings && Array.isArray(res.data.buildings)) {
            buildings.value = res.data.buildings;
            // Auto-select if only one building is found
            if (buildings.value.length === 1) {
                selectedBuildingId.value = buildings.value[0].id;
                loadBuildingData();
            }
        } else {
            // Fallback for old format (single building)
            buildings.value = [res.data];
            selectedBuildingId.value = res.data.id;
            loadBuildingData();
        }

        if (buildings.value.length > 1) {
            toast({
                title: "Multiple Properties Found!",
                description: `Found ${buildings.value.length} properties. Please select one to continue.`,
            });
        }
    } catch (error) {
        buildings.value = [];
        selectedBuildingId.value = null;
        toast({
            variant: "destructive",
            title: "Search failed",
            description: "Unable to fetch building info. Please try again.",
        });
        console.error("Error searching owner's property:", error);
    } finally {
        loading.value = false;
    }
};

// Function to populate form with existing custom field values
const populateFormFromCustomFields = () => {
    Object.entries(FORM_FIELD_TO_CUSTOM_FIELD_MAPPING).forEach(
        ([formField, customFieldName]) => {
            // The alarm field holds a combined value, parsed separately below
            if (customFieldName === ALARM_SYSTEM_CUSTOM_FIELD) {
                return;
            }

            const customField = customFieldsMap.value[customFieldName];
            if (
                customField &&
                customField.value &&
                customField.value !== "Not Completed"
            ) {
                // Only populate if the field has a meaningful value
                if (form[formField] !== undefined) {
                    form[formField] = customField.value;
                }
            }
        },
    );

    // Handle special field mappings that require transformation

    // Paint Color - if it has a hex value like "#b11010"
    if (customFieldsMap.value["Paint Color"]?.value) {
        form.paintColor = customFieldsMap.value["Paint Color"].value;
    }

    // Service Provided -> tenantServiceRequest with reverse mapping
    if (customFieldsMap.value["Service Provided"]?.value) {
        const serviceValue = customFieldsMap.value["Service Provided"].value;
        if (serviceValue === "Lease Only") {
            form.tenantServiceRequest = "Lease Only";
        } else if (serviceValue === "Property Management") {
            form.tenantServiceRequest = "Property Management";
        }
    }

    // Pool Service handling
    if (customFieldsMap.value["Pool Service"]?.value) {
        const poolValue = customFieldsMap.value["Pool Service"].value;
        if (poolValue === "No Pool") {
            form.swimmingPool = "No";
        } else if (poolValue === "Required Contract") {
            form.swimmingPool = "Yes";
            form.poolService = "Yes";
        } else if (poolValue === "Cared By Owner") {
            form.swimmingPool = "Yes";
            form.poolService = "No";
        }
    }

    // HVAC Plan handling
    if (customFieldsMap.value["HVAC Plan"]?.value) {
        const hvacValue = customFieldsMap.value["HVAC Plan"].value;
        if (hvacValue === "On our AC Plan") {
            form.hvacMaintenancePlan = "Yes";
        } else if (hvacValue === "Opted out HVAC Plan") {
            form.hvacMaintenancePlan = "No";
        }
    }

    // Home Warranty - extract info from the combined field
    if (
        customFieldsMap.value["Home Warranty"]?.value &&
        customFieldsMap.value["Home Warranty"].value !== "Not Completed"
    ) {
        form.homeWarranty = "Yes";
        // Parse the warranty value which might be in format "Company - ServiceNumber"
        const warrantyValue = customFieldsMap.value["Home Warranty"].value;
        const parts = warrantyValue.split(" - ");
        if (parts.length > 0) {
            form.homeWarrantyCompanyName = parts[0];
            if (parts.length > 1 && parts[1] !== "N/A") {
                form.homeWarrantyServiceNumber = parts[1];
            }
        } else {
            form.homeWarrantyCompanyName = warrantyValue;
        }
    }

    // Garage/Mailbox info
    if (customFieldsMap.value["Garage Remotes_Garage Code"]?.value) {
        const garageValue =
            customFieldsMap.value["Garage Remotes_Garage Code"].value;
        if (garageValue !== "Not Completed") {
            form.garageDoorRemote = garageValue;
        }
    }

    // Gated community + gate code share one Propertyware field
    parseGatedCommunityCustomField();

    // Sprinkler Yes/No comes from the "Yard Features" picklist
    parseSprinklerSystemCustomField();

    // Fireplace type comes from the "Fireplace" text field
    parseFireplaceCustomField();

    // HOA utilities checkbox + list come from the "Utilities Handled by HOA" text field
    parseHoaUtilitiesCustomField();

    // Appliances - extract from "Included Appliances" field
    if (
        customFieldsMap.value["Included Appliances"]?.value &&
        customFieldsMap.value["Included Appliances"].value !== "Not Completed"
    ) {
        const appliances = String(
            customFieldsMap.value["Included Appliances"].value || "",
        ).toLowerCase();

        if (appliances.includes("refrigerator")) form.refrigerator = "Yes";
        if (appliances.includes("microwave")) form.microwave = "Yes";
        if (appliances.includes("washing") || appliances.includes("washer"))
            form.washingMachine = "Yes";
        if (appliances.includes("dryer")) form.dryer = "Yes";
        if (appliances.includes("dishwasher"))
            form.dishWasherModelYear = "Included";
        if (appliances.includes("water softener")) form.waterSoftener = "Yes";
    }

    // HVAC Filter info - populate the new filter size fields
    if (
        customFieldsMap.value["HVAC Filter Size 1"]?.value &&
        customFieldsMap.value["HVAC Filter Size 1"].value !== "NA"
    ) {
        form.hvacFilterSize1 =
            customFieldsMap.value["HVAC Filter Size 1"].value;
    }
    if (
        customFieldsMap.value["HVAC Filter Size 2"]?.value &&
        customFieldsMap.value["HVAC Filter Size 2"].value !== "NA"
    ) {
        form.hvacFilterSize2 =
            customFieldsMap.value["HVAC Filter Size 2"].value;
    }
    if (
        customFieldsMap.value["HVAC Filter Size 3"]?.value &&
        customFieldsMap.value["HVAC Filter Size 3"].value !== "NA"
    ) {
        form.hvacFilterSize3 =
            customFieldsMap.value["HVAC Filter Size 3"].value;
    }
    if (
        customFieldsMap.value["HVAC Filter Size 4"]?.value &&
        customFieldsMap.value["HVAC Filter Size 4"].value !== "NA"
    ) {
        form.hvacFilterSize4 =
            customFieldsMap.value["HVAC Filter Size 4"].value;
    }

    // Alarm System - code and answers both come from the Alarm System Code field
    parseAlarmSystemCustomField();

    // Re-Key & Code Work Responsibility - combine Property Re-Key and Code Work fields
    const reKeyValue = customFieldsMap.value["Property Re-Key"]?.value;
    const codeWorkValue = customFieldsMap.value["Code Work"]?.value;

    // Check if either field indicates management responsibility
    const reKeyByManagement = reKeyValue && reKeyValue.includes("Management");
    const codeWorkCompleted =
        codeWorkValue && codeWorkValue !== "Not Completed";

    if (reKeyByManagement || codeWorkCompleted) {
        form.reKey = "Management";
    } else if (reKeyValue && reKeyValue.includes("Owner")) {
        form.reKey = "Owner";
    } else {
        form.reKey = "";
    }

    // Yard Care During Lease mapping
    if (
        customFieldsMap.value["Yard Care During Lease"]?.value === "By Tenant"
    ) {
        form.afterTenantMoveInLawnCare = "Tenant";
    } else if (
        customFieldsMap.value["Yard Care During Lease"]?.value === "By Owner"
    ) {
        form.afterTenantMoveInLawnCare = "Owner";
    }

    // Cleaning Service mapping
    if (customFieldsMap.value["Cleaning Service"]?.value === "By Management") {
        form.goingOnTheMarketCleaning = "Management";
    }

    // Debris Removal mapping
    if (customFieldsMap.value["Debris Removal"]?.value === "By Management") {
        form.goingOnTheMarketDebrisRemoval = "Management";
    } else if (
        customFieldsMap.value["Debris Removal"]?.value === "Not Required"
    ) {
        form.goingOnTheMarketDebrisRemoval = "Owner";
    }

    // Lawn Care During Marketing mapping
    if (
        customFieldsMap.value["Lawn Care During Marketing"]?.value ===
        "By Management"
    ) {
        form.goingOnTheMarketLawnCare = "Management";
        form.homeOnTheMarketLawnCare = "Management";
    }

    // Utilities mapping
    if (
        customFieldsMap.value["Utilities"]?.value === "By Managment" ||
        customFieldsMap.value["Utilities"]?.value === "By Management"
    ) {
        form.goingOnTheMarketUtilities = "Management";
        form.homeOnTheMarketUtilities = "Management";
    }

    // Carpet Cleaning mapping
    if (customFieldsMap.value["Carpet Cleaning"]?.value === "By Management") {
        form.goingOnTheMarketCarpetCleaning = "Management";
    }

    // Final Clean mapping
    if (customFieldsMap.value["Final Clean"]?.value === "By Management") {
        form.beforeTenantMoveInClearning = "Management";
    }

    // Carpet Care mapping
    if (customFieldsMap.value["Carpet Care"]?.value === "Management") {
        form.goingOnTheMarketCarpetReplacement = "Management";
    }

    // Painting Required mapping
    if (customFieldsMap.value["Painting Required"]?.value === "Management") {
        form.paint = "Yes";
        form.goingOnTheMarketPaint = "Management";
    }

    // Owner Pet Preferences handling
    if (
        customFieldsMap.value["Owner Pet Prefences"]?.value &&
        customFieldsMap.value["Owner Pet Prefences"].value !== "Not Completed"
    ) {
        const petPrefs = String(
            customFieldsMap.value["Owner Pet Prefences"].value || "",
        ).toLowerCase();

        // Check for dogs
        if (petPrefs.includes("dog")) {
            form.dogsAllowed = "Yes";

            // Extract weight limit if mentioned
            const weightMatch = petPrefs.match(/(\d+)\s*(lb|lbs|pound)/i);
            if (weightMatch) {
                form.dogsMaxWeight = weightMatch[1];
            }
        } else if (petPrefs.includes("no dog")) {
            form.dogsAllowed = "No";
        }

        // Check for cats
        if (petPrefs.includes("cat") && !petPrefs.includes("no cat")) {
            form.catsAllowed = "Yes";

            // Extract cat restrictions if any
            const catMatch = petPrefs.match(
                /cat[s]?\s*[-:]?\s*(.+?)(?:,|;|$)/i,
            );
            if (catMatch && catMatch[1]) {
                form.catRestrictions = catMatch[1].trim();
            }
        } else if (petPrefs.includes("no cat")) {
            form.catsAllowed = "No";
        }

        // Check if no pets allowed
        if (petPrefs.includes("no pet") || petPrefs.includes("not allowed")) {
            form.dogsAllowed = "No";
            form.catsAllowed = "No";
        }

        // Store any other pet restrictions only if we couldn't parse specific pet info
        // and it's not just the default generated text
        if (
            !petPrefs.includes("no pet") &&
            !petPrefs.includes("dog") &&
            !petPrefs.includes("cat")
        ) {
            form.otherPetsRestriction =
                customFieldsMap.value["Owner Pet Prefences"].value;
        }
    }

    // Also check the Pet Restrictions field for additional info
    if (
        customFieldsMap.value["Pet Restrictions"]?.value &&
        customFieldsMap.value["Pet Restrictions"].value !== "Not Completed" &&
        customFieldsMap.value["Pet Restrictions"].value !== "test"
    ) {
        // If we haven't set other restrictions yet, use this field
        if (!form.otherPetsRestriction) {
            form.otherPetsRestriction =
                customFieldsMap.value["Pet Restrictions"].value;
        }
    }
};

// Function to parse maintenance notice field for vendor information
const parseMaintenanceNotice = (maintenanceNotice) => {
    if (!maintenanceNotice || maintenanceNotice.trim() === "") return;

    // Split by semicolon to get individual vendor entries
    const vendorEntries = maintenanceNotice
        .split(";")
        .map((entry) => entry.trim());

    vendorEntries.forEach((entry) => {
        // Parse each entry format: "Type: Name Phone"
        const colonIndex = entry.indexOf(":");
        if (colonIndex > -1) {
            const entryType = entry.substring(0, colonIndex).trim();
            const entryInfo = entry.substring(colonIndex + 1).trim();

            // Parse vendor/warranty info to separate name and phone
            // Assume phone number is the last part that matches a phone pattern
            const phoneMatch = entryInfo.match(
                /(\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4})$/,
            );

            if (phoneMatch) {
                const phone = phoneMatch[1].trim();
                const name = entryInfo
                    .substring(0, entryInfo.lastIndexOf(phoneMatch[0]))
                    .trim();

                // Populate the appropriate fields based on vendor type
                switch (entryType.toLowerCase()) {
                    case "warranty":
                        form.homeWarranty = "Yes";
                        form.homeWarrantyCompanyName = name;
                        form.homeWarrantyContactNumber = phone;
                        break;
                    case "hvac":
                        form.hvacVendorName = name;
                        form.hvacVendorNumber = phone;
                        break;
                    case "electric":
                        form.electricVendorName = name;
                        form.electricVendorNumber = phone;
                        break;
                    case "plumbing":
                        form.plumbingVendorName = name;
                        form.plumbingVendorNumber = phone;
                        break;
                    case "pest control":
                        form.pestControlVendorName = name;
                        form.pestControlVendorNumber = phone;
                        break;
                    case "lawn care":
                        form.lawnCareVendorName = name;
                        form.lawnCareVendorNumber = phone;
                        break;
                    case "other":
                        form.otherVendorName = name;
                        form.otherVendorNumber = phone;
                        break;
                }
            }
        }
    });
};

// Function to load data for the selected building
const loadBuildingData = () => {
    if (!building.value) return;

    // Set building info
    buildingInfo.id = building.value.id;
    buildingInfo.name = building.value.name;
    buildingInfo.abbreviation = building.value.abbreviation;
    buildingInfo.address.address = building.value.address?.address ?? "";
    buildingInfo.address.addressCont =
        building.value.address?.addressCont ?? "";
    buildingInfo.address.city = building.value.address?.city ?? "";
    buildingInfo.address.country = building.value.address?.country ?? "";
    buildingInfo.address.postalCode = building.value.address?.postalCode ?? "";
    buildingInfo.address.stateRegion =
        building.value.address?.stateRegion ?? "";

    // Handle amenities data - implode from array to individual form fields
    if (building.value.amenities && Array.isArray(building.value.amenities)) {
        const amenities = building.value.amenities.map((a) =>
            String(a || "").toLowerCase(),
        );

        // Reset amenity fields
        form.communityPool = "";
        form.park = "";
        form.playGround = "";
        form.tennisCourt = "";
        // Set amenity fields based on amenities array
        if (
            amenities.some((a) => a.includes("pool") || a.includes("swimming"))
        ) {
            form.communityPool = "Yes";
        }
        if (amenities.some((a) => a.includes("park"))) {
            form.park = "Yes";
        }
        if (
            amenities.some(
                (a) => a.includes("playground") || a.includes("play ground"),
            )
        ) {
            form.playGround = "Yes";
        }
        if (
            amenities.some((a) => a.includes("tennis") || a.includes("court"))
        ) {
            form.tennisCourt = "Yes";
        }
    } else if (building.value.customFields) {
        // Check if amenities are stored in custom fields like "Neighborhood Ammenity Access"
        const neighborhoodAmenities = building.value.customFields.find(
            (field) => field.fieldName === "Neighborhood Ammenity Access",
        );
        const tenantToContactNeighborhoodAmenities =
            building.value.customFields.find(
                (field) =>
                    field.fieldName ===
                    "Contact Info for Neighborhood Amenities",
            );

        // Populate the contact info field if it exists
        if (
            tenantToContactNeighborhoodAmenities?.value &&
            tenantToContactNeighborhoodAmenities.value !== "Not Completed" &&
            tenantToContactNeighborhoodAmenities.value !== "Not Provided"
        ) {
            form.tenantToContactNeighborhoodAmenities =
                tenantToContactNeighborhoodAmenities.value;
        }

        if (
            neighborhoodAmenities?.value &&
            neighborhoodAmenities.value !== "Not Provided"
        ) {
            const amenitiesText = String(
                neighborhoodAmenities.value || "",
            ).toLowerCase();

            // Reset all amenity fields first
            form.communityPool = "No";
            form.park = "No";
            form.playGround = "No";
            form.tennisCourt = "No";
            form.tennisCourt = "No";

            // Parse the concatenated amenities and set individual fields
            if (amenitiesText.includes("community pool"))
                form.communityPool = "Yes";
            if (amenitiesText.includes("park")) form.park = "Yes";
            if (amenitiesText.includes("playground")) form.playGround = "Yes";
            if (amenitiesText.includes("tennis court"))
                form.tennisCourt = "Yes";
        } else {
            // Set all to "No" if no amenities are provided
            form.communityPool = "No";
            form.park = "No";
            form.playGround = "No";
            form.tennisCourt = "No";
        }
    }

    // Store custom fields and create ID mapping
    if (building.value.customFields) {
        customFieldsData.value = building.value.customFields;

        // Clear previous mapping
        customFieldsMap.value = {};

        // Create a map of custom field names to their data (including IDs)
        building.value.customFields.forEach((field) => {
            customFieldsMap.value[field.fieldName] = {
                definitionID: field.definitionID,
                dataType: field.dataType,
                value: field.value,
                fieldName: field.fieldName,
            };
        });

        // Pre-populate form with existing values
        populateFormFromCustomFields();

        // Override pet fields with native API values only if petsAllowed has been explicitly set
        if (building.value.petsAllowed === true) {
            if (building.value.petDogAllowed !== undefined) {
                form.dogsAllowed = building.value.petDogAllowed ? "Yes" : "No";
            }
            if (building.value.petCatAllowed !== undefined) {
                form.catsAllowed = building.value.petCatAllowed ? "Yes" : "No";
            }
            if (building.value.petOtherAllowed !== undefined) {
                form.petOtherAllowed = building.value.petOtherAllowed
                    ? "Yes"
                    : "No";
            }
        } else if (building.value.petsAllowed === false) {
            form.dogsAllowed = "No";
            form.catsAllowed = "No";
            form.petOtherAllowed = "No";
        }

        // Parse maintenanceNotice built-in field for vendor information
        if (
            building.value.maintenanceNotice &&
            building.value.maintenanceNotice.trim() !== ""
        ) {
            parseMaintenanceNotice(building.value.maintenanceNotice);
        }

        console.log("Custom fields mapped:", customFieldsMap.value);
        toast({
            title: "Property Selected!",
            description: "You can now update the property information.",
        });

        // Mark search section as completed
        markSectionCompleted("search");
    }

    console.info("Selected property:", building.value);
};

// Function to prepare custom fields for update (only fields from our form)
const prepareCustomFieldsForUpdate = () => {
    const fieldSetDTOS = [];
    const fieldsToUpdate = {};

    Object.entries(FORM_FIELD_TO_CUSTOM_FIELD_MAPPING).forEach(
        ([formField, customFieldName]) => {
            const customField = customFieldsMap.value[customFieldName];
            const formValue = form[formField];

            // Skip fields that are handled separately to avoid conflicts
            if (
                customFieldName === "Pet Restrictions" ||
                customFieldName === "Owner Pet Prefences" ||
                customFieldName === ALARM_SYSTEM_CUSTOM_FIELD
            ) {
                return;
            }

            // Map Owner to "By Owner " (with trailing space) for Debris Removal
            // PropertyWare API requires the trailing space for this field to work correctly
            if (customFieldName === "Debris Removal" && formValue === "Owner") {
                fieldsToUpdate[customFieldName] = "By Owner ";
                return; // Skip the normal mapping logic below
            }

            // Skip empty fields - don't update them
            if (!formValue || formValue === "") {
                return;
            }

            // Debug logging for change detection
            console.log(`🔍 Checking field: ${customFieldName}`);
            console.log(`  Form value: "${formValue}"`);
            console.log(`  PropertyWare value: "${customField?.value}"`);
            console.log(`  Values match: ${formValue === customField?.value}`);
            console.log(
                `  Will include: ${
                    customField && formValue && formValue !== customField?.value
                }`,
            );

            // Only include if:
            // 1. The custom field exists in Propertyware
            // 2. The form has a value for this field
            // 3. The value is different from what's in Propertyware
            if (customField && formValue && formValue !== customField.value) {
                fieldsToUpdate[customFieldName] = formValue;
            }
        },
    );

    // Special handling for complex fields

    // Neighborhood Ammenity Access - concatenate the 4 amenity fields
    if (customFieldsMap.value["Neighborhood Ammenity Access"]) {
        const amenities = [];
        if (form.communityPool === "Yes") amenities.push("Community Pool");
        if (form.park === "Yes") amenities.push("Park");
        if (form.playGround === "Yes") amenities.push("Playground");
        if (form.tennisCourt === "Yes") amenities.push("Tennis Court");

        const amenityValue =
            amenities.length > 0 ? amenities.join(", ") : "Not Provided";
        fieldsToUpdate["Neighborhood Ammenity Access"] = amenityValue;
    }

    // Owner Pet Prefences - always update to replace existing data completely
    if (customFieldsMap.value["Owner Pet Prefences"]) {
        const petPrefs = [];

        // Handle explicit pet preferences
        if (form.dogsAllowed === "Yes") {
            let dogText = "Dogs allowed";
            if (form.dogsMaxWeight) {
                dogText += ` (max weight: ${form.dogsMaxWeight} lbs)`;
            }
            petPrefs.push(dogText);
        } else if (form.dogsAllowed === "No") {
            petPrefs.push("No dogs allowed");
        }

        if (form.catsAllowed === "Yes") {
            let catText = "Cats allowed";
            if (form.catRestrictions) {
                catText += ` (${form.catRestrictions})`;
            }
            petPrefs.push(catText);
        } else if (form.catsAllowed === "No") {
            petPrefs.push("No cats allowed");
        }

        // If both dogs and cats are explicitly set to No, use single statement
        if (form.dogsAllowed === "No" && form.catsAllowed === "No") {
            petPrefs.length = 0; // Clear individual "No" statements
            petPrefs.push("No pets allowed");
        }

        // Add any other pet restrictions (but don't duplicate existing data)
        // Only add if it's genuinely new content, not just the existing stored value
        if (
            form.otherPetsRestriction &&
            form.otherPetsRestriction !== "Not Completed" &&
            form.otherPetsRestriction !==
                customFieldsMap.value["Owner Pet Prefences"]?.value &&
            !petPrefs.some(
                (pref) =>
                    form.otherPetsRestriction.includes(pref) ||
                    pref.includes(form.otherPetsRestriction),
            )
        ) {
            petPrefs.push(form.otherPetsRestriction);
        }

        // Always update - if no preferences set, clear the field
        const petValue =
            petPrefs.length > 0 ? petPrefs.join(", ") : "Not Completed";
        fieldsToUpdate["Owner Pet Prefences"] = petValue;
    }

    // Pet Restrictions - always update to replace existing data completely
    if (customFieldsMap.value["Pet Restrictions"]) {
        // Only use otherPetsRestriction if it's different from the stored value and not empty
        if (
            form.otherPetsRestriction &&
            form.otherPetsRestriction !== "Not Completed"
        ) {
            fieldsToUpdate["Pet Restrictions"] = form.otherPetsRestriction;
        } else {
            // Clear the field if no restrictions are set
            fieldsToUpdate["Pet Restrictions"] = "Not Completed";
        }
    }

    // Re-Key & Code Work Responsibility - update both Property Re-Key and Code Work fields
    if (
        form.reKey &&
        (customFieldsMap.value["Property Re-Key"] ||
            customFieldsMap.value["Code Work"])
    ) {
        if (form.reKey === "Management") {
            if (customFieldsMap.value["Property Re-Key"]) {
                fieldsToUpdate["Property Re-Key"] = "Re-Key By Management";
            }
            if (customFieldsMap.value["Code Work"]) {
                fieldsToUpdate["Code Work"] = "By Management";
            }
        } else if (form.reKey === "Owner") {
            if (customFieldsMap.value["Property Re-Key"]) {
                fieldsToUpdate["Property Re-Key"] = "Re-Key completed by Owner";
            }
            if (customFieldsMap.value["Code Work"]) {
                fieldsToUpdate["Code Work"] = "By Owner";
            }
        }
    }

    // Alarm System - the code and every alarm answer belong to the
    // Property Information "Alarm System Code" field
    if (customFieldsMap.value[ALARM_SYSTEM_CUSTOM_FIELD]) {
        const alarmValue = buildAlarmSystemCodeValue();

        if (
            alarmValue !== null &&
            alarmValue !== customFieldsMap.value[ALARM_SYSTEM_CUSTOM_FIELD].value
        ) {
            fieldsToUpdate[ALARM_SYSTEM_CUSTOM_FIELD] = alarmValue;
        }
    }

    // Gated community + gate code belong to the single
    // "Gated Community? Gate Code?" field
    if (customFieldsMap.value[GATED_COMMUNITY_CUSTOM_FIELD]) {
        const gatedValue = buildGatedCommunityValue();

        if (
            gatedValue !== null &&
            gatedValue !==
                customFieldsMap.value[GATED_COMMUNITY_CUSTOM_FIELD].value
        ) {
            fieldsToUpdate[GATED_COMMUNITY_CUSTOM_FIELD] = gatedValue;
        }
    }

    // Sprinkler Yes/No belongs to the "Yard Features" picklist
    if (customFieldsMap.value[SPRINKLER_CUSTOM_FIELD]) {
        const sprinklerValue = buildSprinklerSystemValue();

        if (
            sprinklerValue !== null &&
            sprinklerValue !== customFieldsMap.value[SPRINKLER_CUSTOM_FIELD].value
        ) {
            fieldsToUpdate[SPRINKLER_CUSTOM_FIELD] = sprinklerValue;
        }
    }

    // Fireplace type is written as-is to the "Fireplace" text field
    if (
        form.fireplace &&
        customFieldsMap.value[FIREPLACE_CUSTOM_FIELD] &&
        form.fireplace !== customFieldsMap.value[FIREPLACE_CUSTOM_FIELD].value
    ) {
        fieldsToUpdate[FIREPLACE_CUSTOM_FIELD] = form.fireplace;
    }

    // HOA utilities: the list (or "None") is written as-is to the
    // "Utilities Handled by HOA" text field
    if (customFieldsMap.value[HOA_UTILITIES_CUSTOM_FIELD]) {
        const hoaUtilitiesValue = buildHoaUtilitiesValue();

        if (
            hoaUtilitiesValue !== null &&
            hoaUtilitiesValue !==
                customFieldsMap.value[HOA_UTILITIES_CUSTOM_FIELD].value
        ) {
            fieldsToUpdate[HOA_UTILITIES_CUSTOM_FIELD] = hoaUtilitiesValue;
        }
    }

    // Garage Remotes_Garage Code - save garage door remote value
    if (
        form.garageDoorRemote &&
        customFieldsMap.value["Garage Remotes_Garage Code"]
    ) {
        fieldsToUpdate["Garage Remotes_Garage Code"] = form.garageDoorRemote;
    }

    // Pool Service - needs special value format
    if (form.swimmingPool && customFieldsMap.value["Pool Service"]) {
        const poolValue =
            form.swimmingPool === "No"
                ? "No Pool"
                : form.poolService === "Yes"
                  ? "Required Contract"
                  : "Cared By Owner";
        fieldsToUpdate["Pool Service"] = poolValue;
    }

    // HVAC Plan - needs special value format
    if (form.hvacMaintenancePlan && customFieldsMap.value["HVAC Plan"]) {
        const hvacValue =
            form.hvacMaintenancePlan === "Yes"
                ? "On our AC Plan"
                : "Opted out HVAC Plan";
        fieldsToUpdate["HVAC Plan"] = hvacValue;
    }

    // Home Warranty - combine multiple fields
    if (
        form.homeWarranty === "Yes" &&
        form.homeWarrantyCompanyName &&
        customFieldsMap.value["Home Warranty"]
    ) {
        const warrantyValue = `${form.homeWarrantyCompanyName} - ${
            form.homeWarrantyServiceNumber || "N/A"
        }`;
        fieldsToUpdate["Home Warranty"] = warrantyValue;
    }

    // Maintenance Notice - concatenate vendor names and phone numbers, and warranty info
    // This will be handled as a built-in field, not a custom field
    const notices = [];

    // Add warranty information first if available
    if (
        form.homeWarranty === "Yes" &&
        form.homeWarrantyCompanyName &&
        form.homeWarrantyContactNumber
    ) {
        notices.push(
            `Warranty: ${form.homeWarrantyCompanyName} ${form.homeWarrantyContactNumber}`,
        );
    }

    // Add vendor information
    if (form.hvacVendorName && form.hvacVendorNumber) {
        notices.push(`HVAC: ${form.hvacVendorName} ${form.hvacVendorNumber}`);
    }
    if (form.electricVendorName && form.electricVendorNumber) {
        notices.push(
            `Electric: ${form.electricVendorName} ${form.electricVendorNumber}`,
        );
    }
    if (form.plumbingVendorName && form.plumbingVendorNumber) {
        notices.push(
            `Plumbing: ${form.plumbingVendorName} ${form.plumbingVendorNumber}`,
        );
    }
    if (form.pestControlVendorName && form.pestControlVendorNumber) {
        notices.push(
            `Pest Control: ${form.pestControlVendorName} ${form.pestControlVendorNumber}`,
        );
    }
    if (form.lawnCareVendorName && form.lawnCareVendorNumber) {
        notices.push(
            `Lawn Care: ${form.lawnCareVendorName} ${form.lawnCareVendorNumber}`,
        );
    }
    if (form.otherVendorName && form.otherVendorNumber) {
        notices.push(
            `Other: ${form.otherVendorName} ${form.otherVendorNumber}`,
        );
    }

    // Store the maintenance notice data to be sent as built-in field
    const maintenanceNoticeValue = notices.length > 0 ? notices.join("; ") : "";

    // Included Appliances - concatenate all included appliances
    if (customFieldsMap.value["Included Appliances"]) {
        const includedAppliances = [];
        if (form.refrigerator === "Yes")
            includedAppliances.push("Refrigerator");
        if (form.microwave === "Yes") includedAppliances.push("Microwave");
        if (form.washingMachine === "Yes")
            includedAppliances.push("Washing Machine");
        if (form.dryer === "Yes") includedAppliances.push("Dryer");
        if (form.waterSoftener === "Yes")
            includedAppliances.push("Water Softener");
        if (form.dishWasherModelYear && form.dishWasherModelYear !== "")
            includedAppliances.push("Dishwasher");

        if (includedAppliances.length > 0) {
            fieldsToUpdate["Included Appliances"] =
                includedAppliances.join(", ");
        }
    }

    // HVAC Filter Information - concatenate location and size for each filter
    const hvacFilters = [];
    for (let i = 1; i <= 4; i++) {
        const location = form[`hvacFilterLocation${i}`];
        const size = form[`hvacFilterSize${i}`];

        if (location && location.trim() !== "" && size && size.trim() !== "") {
            hvacFilters.push(`Filter ${i}: ${location} - Size: ${size}`);
        }
    }

    // Store concatenated HVAC filter information in a single custom field
    if (
        hvacFilters.length > 0 &&
        customFieldsMap.value["HVAC Filter Location Information"]
    ) {
        fieldsToUpdate["HVAC Filter Location Information"] =
            hvacFilters.join("; ");
    }

    // Convert to Propertyware API format with proper value mapping
    Object.entries(fieldsToUpdate).forEach(([fieldName, value]) => {
        if (customFieldsMap.value[fieldName]) {
            // Map form values to PropertyWare accepted values
            let mappedValue = value;

            // Marketing Stage fields: Cleaning Service, Debris Removal, Lawn Care During Marketing, Utilities
            const marketingFields = [
                "Lawn Care During Marketing",
                "Cleaning Service",
                "Debris Removal",
                "Utilities",
            ];

            // Pre Move In fields: Carpet Cleaning, Final Clean
            const preMoveInFields = ["Carpet Cleaning", "Final Clean"];

            // Map "Management" to "By Management" for marketing and pre-move-in fields
            if (
                (marketingFields.includes(fieldName) ||
                    preMoveInFields.includes(fieldName)) &&
                value === "Management"
            ) {
                mappedValue = "By Management";
            }

            // Map "Owner" to "By Owner" for marketing and pre-move-in fields
            // Exception: Debris Removal needs "By Owner " (with trailing space)
            if (
                (marketingFields.includes(fieldName) ||
                    preMoveInFields.includes(fieldName)) &&
                value === "Owner" &&
                fieldName !== "Debris Removal"
            ) {
                mappedValue = "By Owner";
            }

            if (fieldName === "Utilities") {
                if (value === "Management") {
                    mappedValue = "By Managment";
                } else if (value === "Owner") {
                    mappedValue = "By Owner";
                }
            }
            // Property Re-Key mapping
            if (fieldName === "Property Re-Key") {
                if (value === "Management") {
                    mappedValue = "Re-Key By Management";
                } else if (value === "Owner") {
                    mappedValue = "Re-Key completed by Owner";
                }
            }

            // Yard Care During Lease mapping
            if (fieldName === "Yard Care During Lease") {
                if (value === "Tenant") {
                    mappedValue = "By Tenant";
                } else if (value === "Owner") {
                    mappedValue = "By Owner";
                }
            }

            // Service Provided mapping
            if (fieldName === "Service Provided") {
                if (value === "Lease Only") {
                    mappedValue = "Lease Only";
                } else if (value === "Property Management") {
                    mappedValue = "Property Management";
                }
            }

            fieldSetDTOS.push({
                name: fieldName,
                value: mappedValue.toString(), // Ensure value is string
            });
        }
    });

    return { fieldSetDTOS, maintenanceNoticeValue };
};

// Submit function
const submitForm = async () => {
    // Validate signature
    const signatureData = signature1.value?.save("image/png");
    if (
        !signatureData ||
        signatureData ===
            "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=="
    ) {
        toast({
            variant: "destructive",
            title: "Signature Required",
            description: "Please provide your signature before submitting.",
        });
        return;
    }

    // Prepare only the custom fields that need updating
    const { fieldSetDTOS, maintenanceNoticeValue } =
        prepareCustomFieldsForUpdate();

    console.log("🔍 Submission Debug:");
    console.log("fieldSetDTOS length:", fieldSetDTOS.length);
    console.log("fieldSetDTOS:", fieldSetDTOS);

    loading.value = true;

    try {
        // Prepare the request body in Propertyware's expected format
        const requestBody = {
            entityId: buildingInfo.id,
            fieldSetDTOS: fieldSetDTOS,
        };

        // Submit to your Laravel endpoint
        const response = await axios.post(
            `/api/buildings/${buildingInfo.id}/update-custom-fields`,
            {
                propertywareData: requestBody,
                formData: form,
                signature: signatureData,
                ownerName: fullName.value,
                maintenanceNotice: maintenanceNoticeValue,
            },
        );

        toast({
            title: "Success!",
            description: `Your property information has been updated successfully.`,
        });

        console.log("Updated fields:", fieldSetDTOS);

        // Mark signature section as completed
        markSectionCompleted("signature");

        // Clear localStorage after successful submission
        localStorage.removeItem("buildingOnboardingForm");

        redirectCountdown.value = 10; // Reset countdown
        showThankYou.value = true;
        startCountdown();
    } catch (error) {
        console.error("Submission error:", error);

        // Log detailed error information
        if (error.response) {
            console.error("Error response:", error.response.data);
            console.error("Error status:", error.response.status);
        }

        const errorData = error.response?.data;
        let errorMessage =
            errorData?.message ||
            "Unable to update building information. Please try again.";

        // Surface field-level validation errors (e.g. required fields) when present
        if (errorData?.errors && typeof errorData.errors === "object") {
            const fieldMessages = Object.values(errorData.errors)
                .flat()
                .filter(Boolean);
            if (fieldMessages.length) {
                errorMessage = `Please fix the following: ${fieldMessages.join(" ")}`;
            }
        }

        toast({
            variant: "destructive",
            title: "Submission Failed",
            description: errorData?.details
                ? `${errorMessage} (Details: ${errorData.details})`
                : errorMessage,
        });
    } finally {
        loading.value = false;
    }
};

const startCountdown = () => {
    // Clear any existing timer first
    if (countdownTimer) {
        clearInterval(countdownTimer);
    }

    countdownTimer = setInterval(() => {
        redirectCountdown.value--;
        if (redirectCountdown.value <= 0) {
            clearInterval(countdownTimer);
            countdownTimer = null;
            // Only redirect if the thank you modal is still showing
            if (showThankYou.value) {
                redirectToHandbook();
            }
        }
    }, 1000);
};

const redirectToHandbook = () => {
    // Clean up timer before redirect
    if (countdownTimer) {
        clearInterval(countdownTimer);
        countdownTimer = null;
    }
    // Replace this URL with your actual owner handbook URL
    window.location.href = "https://heyzine.com/flip-book/30dc55160e.html";
};
</script>

<template>
    <Head title="Property Onboarding | Easy Setup Process" />
    <div
        class="min-h-screen bg-gradient-to-br from-blue-50 via-white to-green-50"
    >
        <!-- Modern Header -->
        <header
            class="sticky top-0 z-40 bg-white/90 backdrop-blur-md shadow-sm border-b"
        >
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-16">
                    <div class="flex items-center gap-4">
                        <img
                            :src="$page.props.logo"
                            alt="Logo"
                            class="h-10 w-auto"
                        />
                    </div>
                    <div class="flex items-center gap-4">
                        <Badge
                            variant="secondary"
                            class="hidden sm:inline-flex"
                        >
                            <Clock class="w-4 h-4 mr-1" />
                            Estimated time: 15-20 minutes
                        </Badge>
                        <Button variant="ghost" size="icon" as-child>
                            <a href="tel:+12812488018"
                                ><MessageCircle class="h-5 w-5"
                            /></a>
                        </Button>
                    </div>
                </div>
            </div>
        </header>

        <!-- Floating Toggle Button -->
        <Button
            @click="toggleSidebar"
            :class="[
                'fixed top-32 z-50 rounded-r-lg transition-all duration-300 shadow-lg',
                isSidebarOpen ? 'left-80' : 'left-0',
            ]"
            size="sm"
            variant="outline"
        >
            <component
                :is="isSidebarOpen ? ChevronLeft : ChevronRight"
                class="h-4 w-4"
            />
            <span class="ml-1 hidden sm:inline"
                >{{ isSidebarOpen ? "Hide" : "Show" }} Steps</span
            >
        </Button>

        <!-- Backdrop for mobile -->
        <div
            v-if="isSidebarOpen"
            @click="closeSidebar"
            class="fixed inset-0 bg-black bg-opacity-50 z-40 xl:hidden"
        />

        <!-- Floating Sidebar Navigation -->
        <aside
            :class="[
                'fixed left-0 top-0 bottom-0 z-50 w-80 transform transition-transform duration-300 ease-in-out',
                isSidebarOpen ? 'translate-x-0' : '-translate-x-full',
            ]"
        >
            <div
                class="h-full bg-white border-r border-gray-200 shadow-xl overflow-y-auto"
            >
                <div class="p-6">
                    <!-- Mobile Close Button -->
                    <div class="flex justify-end mb-4 xl:hidden">
                        <Button
                            @click="toggleSidebar"
                            variant="ghost"
                            size="icon"
                        >
                            <X class="h-5 w-5" />
                        </Button>
                    </div>

                    <!-- Progress Section -->
                    <div class="mb-8">
                        <h3 class="text-sm font-semibold text-gray-900 mb-4">
                            Overall Progress
                        </h3>
                        <div class="space-y-2">
                            <div class="flex justify-between text-sm">
                                <span class="text-primary"
                                    >{{ Math.round(progress) }}% Complete</span
                                >
                            </div>
                            <Progress
                                variant=""
                                :model-value="progress"
                                class="h-2 text-primary"
                            />
                        </div>
                    </div>

                    <!-- Steps Navigation -->
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 mb-4">
                            Onboarding Steps
                        </h3>
                        <nav class="space-y-2">
                            <button
                                v-for="(section, index) in sections"
                                :key="section.id"
                                @click="
                                    () => {
                                        navigateToSection(section.id);
                                        closeSidebar();
                                    }
                                "
                                :class="[
                                    'w-full flex items-center justify-between px-4 py-3 rounded-lg text-sm font-medium transition-all duration-200 group',
                                    currentSection === section.id
                                        ? 'bg-blue-50 text-blue-700 border-l-4 border-blue-500'
                                        : completedSections.includes(section.id)
                                          ? 'bg-green-50 text-green-700 hover:bg-green-100'
                                          : 'bg-white text-gray-600 hover:bg-gray-50 hover:text-gray-900',
                                ]"
                            >
                                <div class="flex items-center">
                                    <div
                                        :class="[
                                            'flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center mr-3',
                                            currentSection === section.id
                                                ? 'bg-blue-200'
                                                : completedSections.includes(
                                                        section.id,
                                                    )
                                                  ? 'bg-green-200'
                                                  : 'bg-gray-200',
                                        ]"
                                    >
                                        <CheckCircle
                                            v-if="
                                                completedSections.includes(
                                                    section.id,
                                                )
                                            "
                                            class="h-5 w-5 text-green-600"
                                        />
                                        <component
                                            v-else
                                            :is="section.icon"
                                            :class="[
                                                'h-4 w-4',
                                                currentSection === section.id
                                                    ? 'text-blue-600'
                                                    : 'text-gray-500',
                                            ]"
                                        />
                                    </div>
                                    <div class="text-left">
                                        <div class="font-medium">
                                            {{ section.title }}
                                        </div>
                                        <div
                                            v-if="currentSection === section.id"
                                            class="text-xs text-blue-600 mt-0.5"
                                        >
                                            Currently editing
                                        </div>
                                        <div
                                            v-else-if="
                                                completedSections.includes(
                                                    section.id,
                                                )
                                            "
                                            class="text-xs text-green-600 mt-0.5"
                                        >
                                            Completed
                                        </div>
                                    </div>
                                </div>
                                <ChevronRight
                                    :class="[
                                        'h-4 w-4 transition-transform group-hover:translate-x-1',
                                        currentSection === section.id
                                            ? 'text-blue-500'
                                            : completedSections.includes(
                                                    section.id,
                                                )
                                              ? 'text-green-500'
                                              : 'text-gray-400',
                                    ]"
                                />
                            </button>
                        </nav>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Mobile Progress Bar (shown on small screens) -->
        <div
            class="xl:hidden fixed top-16 left-0 right-0 bg-white border-b z-10"
        >
            <div class="px-4 py-3">
                <div class="flex justify-between text-sm mb-2">
                    <span class="font-medium">Progress</span>
                    <span class="text-gray-600"
                        >{{ Math.round(progress) }}%</span
                    >
                </div>
                <Progress :model-value="progress" class="h-2" />
            </div>
        </div>

        <!-- Main Content Area -->
        <div :class="{ 'xl:ml-80': isSidebarOpen }">
            <div
                class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8 pt-20 xl:pt-8"
            >
                <!-- Main Content -->
                <main class="space-y-8">
                    <!-- Hero Section -->
                    <div class="text-center mb-8">
                        <h1
                            class="text-3xl font-bold text-gray-900 sm:text-4xl"
                        >
                            Welcome to Property Onboarding
                        </h1>
                        <p class="mt-3 text-lg text-gray-600 max-w-2xl mx-auto">
                            We'll guide you through a simple process to set up
                            your property for management. Your responses help us
                            provide the best service for your investment.
                        </p>
                        <div
                            class="mt-4 inline-flex items-center text-sm text-green-600 bg-green-50 px-3 py-1.5 border rounded font-semibold"
                        >
                            Your progress will be automatically saved in the
                            browser you are currently using for this form. If
                            you change devices or browsers, you will not see
                            your saved progress. For example if you start this
                            on your PC and later come back to it on your iPhone,
                            you will not see your saved progress. If you come
                            back to your PC, you will see your saved progress.
                        </div>
                    </div>

                    <FindProperty
                        :buildings="buildings"
                        :buildingInfo="buildingInfo"
                        v-model:full-name="fullName"
                        v-model:contact-number="contactNumber"
                        v-model:property-name="propertyName"
                        v-model:selectedBuilding-id="selectedBuildingId"
                        v-model:loadBuilding-data="loadBuildingData"
                        v-model:loading="loading"
                        @search="searchProperty"
                    />

                    <PreparetionProperty
                        v-if="buildingInfo.id"
                        v-model:form="form"
                        :completedSections="completedSections"
                        @sectionComplete="markSectionCompleted"
                    />

                    <PropertyPriorToMarket
                        v-if="buildingInfo.id"
                        v-model:form="form"
                        :completedSections="completedSections"
                        @sectionComplete="markSectionCompleted"
                    />

                    <ReKeyCodeWork
                        v-if="buildingInfo.id"
                        v-model:form="form"
                        :completedSections="completedSections"
                        @sectionComplete="markSectionCompleted"
                    />

                    <TenantServiceRequest
                        v-if="buildingInfo.id"
                        v-model:form="form"
                        :completedSections="completedSections"
                        @sectionComplete="markSectionCompleted"
                    />

                    <PetPolicy
                        v-if="buildingInfo.id"
                        v-model:form="form"
                        :completedSections="completedSections"
                        @sectionComplete="markSectionCompleted"
                    />

                    <Amenities
                        v-if="buildingInfo.id"
                        v-model:form="form"
                        :completedSections="completedSections"
                        @sectionComplete="markSectionCompleted"
                    />

                    <HVACMaintenance
                        v-if="buildingInfo.id"
                        v-model:form="form"
                        :completedSections="completedSections"
                        @sectionComplete="markSectionCompleted"
                    />

                    <Vendors
                        v-if="buildingInfo.id"
                        v-model:form="form"
                        :completedSections="completedSections"
                        @sectionComplete="markSectionCompleted"
                    />

                    <FloodHistory
                        v-if="buildingInfo.id"
                        v-model:form="form"
                        :completedSections="completedSections"
                        @sectionComplete="markSectionCompleted"
                    />

                    <!-- Preferred Vendors Section -->

                    <W9Form
                        v-model:form="form"
                        v-if="buildingInfo.id"
                        :completedSections="completedSections"
                        @sectionComplete="markSectionCompleted"
                    />

                    <!-- Final Submit Section -->
                    <section id="signature" v-if="buildingInfo.id">
                        <Card>
                            <CardHeader>
                                <div class="flex items-center gap-3">
                                    <div class="p-2 bg-green-100 rounded-lg">
                                        <CheckCircle
                                            class="h-6 w-6 text-green-600"
                                        />
                                    </div>
                                    <div>
                                        <CardTitle
                                            >Final Details &
                                            Signature</CardTitle
                                        >
                                        <CardDescription>
                                            Almost done! Add any additional
                                            information and sign
                                        </CardDescription>
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent class="space-y-6">
                                <div class="space-y-2">
                                    <Alert class="mb-4">
                                        <Info class="h-4 w-4" />
                                        <AlertDescription>
                                            If there is anything not Covered
                                            above, please let us know in the
                                            space below. This would also include
                                            any repairs or bids that you would
                                            like us to have completed for you.
                                        </AlertDescription>
                                    </Alert>
                                    <label class="text-sm font-medium"
                                        >Additional Information</label
                                    >
                                    <Textarea
                                        v-model="form.otherComments"
                                        placeholder="Any other details we should know about your property?"
                                        class="min-h-[100px]"
                                    />
                                </div>
                                <div class="space-y-2">
                                    <label class="text-sm font-medium"
                                        >Owner Preferred Communication</label
                                    >
                                    <Select
                                        v-model="form.preferredCommunication"
                                    >
                                        <SelectTrigger>
                                            <SelectValue
                                                placeholder="Select your preferred line of communication"
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="Text Message"
                                                >Text Message</SelectItem
                                            >
                                            <SelectItem value="Email"
                                                >Email</SelectItem
                                            >
                                            <SelectItem value="Phone Call"
                                                >Phone Call</SelectItem
                                            >
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div class="flex gap-x-2">
                                    <input
                                        type="checkbox"
                                        id="terms"
                                        class="p-2"
                                        v-model="form.e_1099_consent"
                                    />
                                    <label
                                        for="terms"
                                        class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70"
                                    >
                                        I hereby consent to receive my IRS Form
                                        1099 electronically via the email
                                        address provided to TexasRenters.com,
                                        LLC. I understand that no paper copy
                                        will be mailed unless I revoke this
                                        consent in writing.
                                    </label>
                                </div>

                                <div class="border-t pt-6">
                                    <h3 class="font-semibold mb-4">
                                        Electronic Signature
                                    </h3>
                                    <p class="text-sm text-gray-600 mb-4">
                                        Please sign below to authorize and
                                        verify the information provided.
                                    </p>

                                    <div
                                        class="border rounded-lg overflow-hidden"
                                    >
                                        <Vue3Signature
                                            ref="signature1"
                                            :h="'250px'"
                                            :sigOption="state.option"
                                            :disabled="state.disabled"
                                            class="w-full"
                                            style="
                                                background-color: transparent;
                                            "
                                        />
                                    </div>

                                    <div class="flex gap-2 mt-4">
                                        <Button
                                            @click="clear"
                                            variant="outline"
                                            size="sm"
                                            >Clear Signature</Button
                                        >
                                        <Button
                                            @click="undo"
                                            variant="outline"
                                            size="sm"
                                            >Undo</Button
                                        >
                                    </div>
                                </div>

                                <div class="border-t pt-6">
                                    <Button
                                        @click="submitForm"
                                        :disabled="loading || !building"
                                        size="lg"
                                        class="w-full"
                                    >
                                        <Loader
                                            class="w-5 h-5 mr-2 animate-spin"
                                            v-if="loading"
                                        />
                                        <CheckCircle
                                            class="w-5 h-5 mr-2"
                                            v-else
                                        />
                                        {{
                                            loading
                                                ? "Submitting..."
                                                : "Complete Property Setup"
                                        }}
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    </section>

                    <Footer />
                </main>
            </div>
        </div>

        <!-- Modern Footer -->
    </div>

    <!-- Thank You Overlay -->
    <div
        v-if="showThankYou"
        class="fixed inset-0 z-50 flex items-center justify-center"
    >
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm"></div>

        <!-- Content -->
        <div
            class="relative bg-white p-8 max-w-md w-full mx-4 shadow-2xl transform transition-all duration-500 scale-100"
        >
            <div class="text-center">
                <!-- Success Icon -->
                <div
                    class="mx-auto w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mb-6"
                >
                    <CheckCircle class="w-12 h-12 text-green-600" />
                </div>

                <!-- Thank You Message -->
                <h2 class="text-3xl font-bold text-gray-900 mb-4">
                    Thank You!
                </h2>
                <p class="text-lg text-gray-600 mb-6">
                    Your property information has been successfully submitted.
                    We appreciate you taking the time to complete this
                    onboarding process.
                </p>

                <!-- Countdown Message -->
                <div class="bg-blue-50 rounded-lg p-4 mb-6">
                    <p class="text-sm text-blue-900">
                        Redirecting to your Owner Handbook in
                        <span class="font-bold text-2xl mx-1">{{
                            redirectCountdown
                        }}</span>
                        seconds...
                    </p>
                </div>
                <div class="flex gap-3">
                    <Button
                        @click="
                            showThankYou = false;
                            if (countdownTimer) {
                                window.clearInterval(countdownTimer);
                                countdownTimer = null;
                            }
                        "
                        class="w-full"
                        size="lg"
                        variant="secondary"
                    >
                        Close
                    </Button>
                    <Button
                        @click="redirectToHandbook"
                        class="w-full"
                        size="lg"
                    >
                        Go to Owner Handbook Now
                    </Button>
                </div>
            </div>
        </div>
    </div>

    <Toaster />
</template>

<style scoped>
/* Add smooth scrolling for better UX */
html {
    scroll-behavior: smooth;
}

/* Custom animation for progress sections */
.section-transition {
    transition: all 0.3s ease;
}

/* Make signature canvas more visible */
:deep(.vue3-signature) {
    border: 2px dashed #e5e7eb;
    border-radius: 0.5rem;
}

/* Improve print styles */
@media print {
    .no-print {
        display: none;
    }
}
</style>
