<script setup>
import { ref, computed, watch } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import axios from "axios";
import { useToast } from "@/Components/ui/toast/use-toast";
import {
    BrainCircuit,
    FlaskConical,
    CheckCircle2,
    XCircle,
    Loader2,
    RotateCcw,
    Save,
    Trash2,
    KeyRound,
    ScanEye,
} from "lucide-vue-next";
import { Button } from "@/Components/ui/button";
import { Badge } from "@/Components/ui/badge";
import { Input } from "@/Components/ui/input";
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    CardDescription,
} from "@/Components/ui/card";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/Components/ui/select";
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from "@/Components/ui/alert-dialog";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    providers: Array,
    current: Object,
    vision: Object,
});

const { toast } = useToast();

const PROVIDER_LABELS = {
    anthropic: "Anthropic (Claude)",
    azure: "Azure OpenAI",
    deepseek: "DeepSeek",
    gemini: "Google Gemini",
    groq: "Groq",
    mistral: "Mistral",
    ollama: "Ollama (self-hosted)",
    openai: "OpenAI (GPT)",
    openrouter: "OpenRouter",
    xai: "xAI (Grok)",
};

const providerLabel = (key) => PROVIDER_LABELS[key] ?? key;

// Server state, replaced from responses after each save.
const current = ref({ ...props.current });
const providerList = ref([...(props.providers ?? [])]);

// ---- Change AI engine form ----
const DEFAULT_MODEL = "__default__";
const CUSTOM_MODEL = "__custom__";

const selectedProvider = ref(current.value.override_provider ?? undefined);
const modelChoice = ref(current.value.override_model ?? DEFAULT_MODEL);
const customModel = ref("");

const selectedProviderMeta = computed(() =>
    providerList.value.find((provider) => provider.key === selectedProvider.value),
);

const suggestions = computed(() => selectedProviderMeta.value?.suggestions ?? []);

// A saved override model that is not in the suggestion list renders as Custom.
if (
    current.value.override_model &&
    !(providerList.value.find((p) => p.key === selectedProvider.value)?.suggestions ?? []).includes(
        current.value.override_model,
    )
) {
    modelChoice.value = CUSTOM_MODEL;
    customModel.value = current.value.override_model;
}

watch(selectedProvider, () => {
    modelChoice.value = DEFAULT_MODEL;
    customModel.value = "";
    testResult.value = null;
});
watch([modelChoice, customModel], () => {
    testResult.value = null;
});

const modelToSend = computed(() => {
    if (modelChoice.value === DEFAULT_MODEL) return null;
    if (modelChoice.value === CUSTOM_MODEL) return customModel.value.trim() || null;
    return modelChoice.value;
});

const formValid = computed(
    () => !!selectedProvider.value && (modelChoice.value !== CUSTOM_MODEL || !!customModel.value.trim()),
);

// ---- Test ----
const testing = ref(false);
const testResult = ref(null);

const testConnection = async () => {
    if (!formValid.value) return;
    testing.value = true;
    testResult.value = null;
    try {
        const { data } = await axios.post(route("it-tools.ai-settings.test"), {
            provider: selectedProvider.value,
            model: modelToSend.value,
        });
        testResult.value = data;
    } catch (error) {
        testResult.value = {
            ok: false,
            error: error.response?.data?.message ?? error.message,
        };
    } finally {
        testing.value = false;
    }
};

// ---- Save / reset ----
const saving = ref(false);
const confirmingReset = ref(false);

const saveEngine = async () => {
    if (!formValid.value) return;
    saving.value = true;
    try {
        const { data } = await axios.put(route("it-tools.ai-settings.update"), {
            provider: selectedProvider.value,
            model: modelToSend.value,
        });
        current.value = data.current;
        toast({
            title: "AI engine updated",
            description: `Now running ${providerLabel(data.current.effective_provider)} — ${data.current.effective_model}.`,
        });
    } catch (error) {
        toast({
            title: "Could not save",
            description: error.response?.data?.message ?? error.message,
            variant: "destructive",
        });
    } finally {
        saving.value = false;
    }
};

const resetToEnv = async () => {
    saving.value = true;
    confirmingReset.value = false;
    try {
        const { data } = await axios.put(route("it-tools.ai-settings.update"), {
            provider: null,
            model: null,
        });
        current.value = data.current;
        selectedProvider.value = undefined;
        modelChoice.value = DEFAULT_MODEL;
        customModel.value = "";
        testResult.value = null;
        toast({
            title: "Back to environment default",
            description: `Now running ${providerLabel(data.current.effective_provider)} — ${data.current.effective_model}.`,
        });
    } catch (error) {
        toast({
            title: "Could not reset",
            description: error.response?.data?.message ?? error.message,
            variant: "destructive",
        });
    } finally {
        saving.value = false;
    }
};

// ---- API keys ----
const keyInputs = ref({});
const savingKeyFor = ref(null);
const confirmingKeyRemoval = ref(null);

const saveKey = async (provider) => {
    const key = (keyInputs.value[provider.key] ?? "").trim();
    if (!key) return;
    savingKeyFor.value = provider.key;
    try {
        const { data } = await axios.put(route("it-tools.ai-settings.keys"), {
            provider: provider.key,
            key,
        });
        providerList.value = data.providers;
        current.value = data.current;
        keyInputs.value[provider.key] = "";
        toast({ title: `${providerLabel(provider.key)} key saved` });
    } catch (error) {
        toast({
            title: "Could not save key",
            description: error.response?.data?.message ?? error.message,
            variant: "destructive",
        });
    } finally {
        savingKeyFor.value = null;
    }
};

const removeKey = async () => {
    const provider = confirmingKeyRemoval.value;
    if (!provider) return;
    savingKeyFor.value = provider.key;
    confirmingKeyRemoval.value = null;
    try {
        const { data } = await axios.delete(
            route("it-tools.ai-settings.keys.destroy", provider.key),
        );
        providerList.value = data.providers;
        current.value = data.current;
        toast({ title: `${providerLabel(provider.key)} key removed` });
    } catch (error) {
        toast({
            title: "Could not remove key",
            description: error.response?.data?.message ?? error.message,
            variant: "destructive",
        });
    } finally {
        savingKeyFor.value = null;
    }
};
</script>

<template>
    <div class="p-4 md:p-6 space-y-6 max-w-5xl">
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-semibold">
                <BrainCircuit class="w-6 h-6" /> AI Settings
            </h1>
            <p class="text-sm text-muted-foreground">
                Choose which AI provider and model power the work order classifier,
                vendor picker, HOA notice reader, board summaries and inbox reports.
                Changes take effect immediately — no deploy needed.
            </p>
        </div>

        <!-- Currently running -->
        <Card>
            <CardHeader>
                <div class="flex items-center justify-between">
                    <div>
                        <CardTitle>Currently running</CardTitle>
                        <CardDescription>
                            What every AI feature uses right now.
                        </CardDescription>
                    </div>
                    <Badge
                        :class="
                            current.ready
                                ? 'bg-green-100 text-green-800 hover:bg-green-100'
                                : 'bg-red-100 text-red-800 hover:bg-red-100'
                        "
                    >
                        {{ current.ready ? "Ready" : "Not ready — missing API key" }}
                    </Badge>
                </div>
            </CardHeader>
            <CardContent class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                    <div>
                        <div class="text-muted-foreground">Provider</div>
                        <div class="font-medium">
                            {{ providerLabel(current.effective_provider) }}
                        </div>
                    </div>
                    <div>
                        <div class="text-muted-foreground">Model</div>
                        <div class="font-medium">{{ current.effective_model }}</div>
                    </div>
                    <div>
                        <div class="text-muted-foreground">Source</div>
                        <div class="font-medium">
                            {{
                                current.override_provider
                                    ? "Set on this page"
                                    : "Environment default"
                            }}
                        </div>
                    </div>
                </div>

                <div
                    v-if="current.override_ignored"
                    class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800"
                >
                    A provider chosen on this page no longer has an API key, so the
                    system fell back to the environment default. Re-add the key below or
                    pick another provider.
                </div>

                <p class="flex items-center gap-2 text-xs text-muted-foreground">
                    <ScanEye class="w-4 h-4 shrink-0" />
                    Scanned HOA notices (photos and image-only PDFs) always use OpenAI
                    vision — model: {{ vision.model }}
                    <Badge
                        :class="
                            vision.ready
                                ? 'bg-green-100 text-green-800 hover:bg-green-100'
                                : 'bg-red-100 text-red-800 hover:bg-red-100'
                        "
                    >
                        {{ vision.ready ? "ready" : "no OpenAI key" }}
                    </Badge>
                </p>
            </CardContent>
        </Card>

        <!-- Change AI engine -->
        <Card>
            <CardHeader>
                <CardTitle>Change AI engine</CardTitle>
                <CardDescription>
                    Pick a provider and model, test it, then save. Providers without an
                    API key are grayed out — add their key below to unlock them.
                </CardDescription>
            </CardHeader>
            <CardContent class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="space-y-1">
                        <div class="text-sm font-medium">Provider</div>
                        <Select v-model="selectedProvider">
                            <SelectTrigger>
                                <SelectValue placeholder="Choose a provider…" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="provider in providerList"
                                    :key="provider.key"
                                    :value="provider.key"
                                    :disabled="!provider.configured"
                                >
                                    {{ providerLabel(provider.key) }}
                                    <template v-if="provider.is_env_default"> (env default)</template>
                                    <template v-if="!provider.configured"> — no API key</template>
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="space-y-1">
                        <div class="text-sm font-medium">Model</div>
                        <Select v-model="modelChoice" :disabled="!selectedProvider">
                            <SelectTrigger>
                                <SelectValue placeholder="Provider default" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="DEFAULT_MODEL">
                                    Provider default<template v-if="suggestions.length"> ({{ suggestions[0] }})</template>
                                </SelectItem>
                                <SelectItem
                                    v-for="model in suggestions"
                                    :key="model"
                                    :value="model"
                                >
                                    {{ model }}
                                </SelectItem>
                                <SelectItem :value="CUSTOM_MODEL">Custom…</SelectItem>
                            </SelectContent>
                        </Select>
                        <Input
                            v-if="modelChoice === CUSTOM_MODEL"
                            v-model="customModel"
                            placeholder="Exact model name, e.g. gpt-5.4-mini"
                            class="mt-2"
                        />
                        <p
                            v-if="selectedProvider === 'azure'"
                            class="text-xs text-muted-foreground"
                        >
                            For Azure, the model is your deployment name.
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        variant="outline"
                        :disabled="!formValid || testing"
                        @click="testConnection"
                    >
                        <Loader2 v-if="testing" class="w-4 h-4 mr-2 animate-spin" />
                        <FlaskConical v-else class="w-4 h-4 mr-2" />
                        Test
                    </Button>
                    <Button :disabled="!formValid || saving" @click="saveEngine">
                        <Loader2 v-if="saving" class="w-4 h-4 mr-2 animate-spin" />
                        <Save v-else class="w-4 h-4 mr-2" />
                        Save
                    </Button>
                    <Button
                        v-if="current.override_provider"
                        variant="ghost"
                        :disabled="saving"
                        @click="confirmingReset = true"
                    >
                        <RotateCcw class="w-4 h-4 mr-2" /> Reset to environment default
                    </Button>
                </div>

                <div
                    v-if="testResult"
                    class="rounded-md border p-3 text-sm"
                    :class="
                        testResult.ok
                            ? 'border-green-200 bg-green-50 text-green-800'
                            : 'border-red-200 bg-red-50 text-red-800'
                    "
                >
                    <div class="flex items-center gap-2">
                        <CheckCircle2 v-if="testResult.ok" class="w-4 h-4 shrink-0" />
                        <XCircle v-else class="w-4 h-4 shrink-0" />
                        <span v-if="testResult.ok">
                            Working — {{ testResult.model }} answered in
                            {{ testResult.latency_ms }} ms.
                        </span>
                        <span v-else>{{ testResult.error }}</span>
                    </div>
                </div>
                <p
                    v-else-if="formValid"
                    class="text-xs text-muted-foreground"
                >
                    Tip: hit Test first — it runs one tiny live request against your
                    choice without changing anything.
                </p>
            </CardContent>
        </Card>

        <!-- API keys -->
        <Card>
            <CardHeader>
                <CardTitle class="flex items-center gap-2">
                    <KeyRound class="w-4 h-4" /> Provider API keys
                </CardTitle>
                <CardDescription>
                    Keys saved here are stored encrypted and win over the server's
                    environment key for the same provider. Keys set in the environment
                    can only be changed by IT.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div class="divide-y">
                    <div
                        v-for="provider in providerList"
                        :key="provider.key"
                        class="py-3 grid grid-cols-1 md:grid-cols-[180px_120px_1fr_auto] items-center gap-2"
                    >
                        <div class="text-sm font-medium">
                            {{ providerLabel(provider.key) }}
                        </div>
                        <div>
                            <Badge
                                v-if="provider.key_source === 'database'"
                                class="bg-blue-100 text-blue-800 hover:bg-blue-100"
                                :title="provider.masked_key"
                            >
                                saved {{ provider.masked_key }}
                            </Badge>
                            <Badge
                                v-else-if="provider.key_source === 'env'"
                                class="bg-green-100 text-green-800 hover:bg-green-100"
                            >
                                environment
                            </Badge>
                            <Badge v-else variant="outline">no key</Badge>
                        </div>
                        <Input
                            v-model="keyInputs[provider.key]"
                            type="password"
                            autocomplete="off"
                            :placeholder="
                                provider.key_source
                                    ? 'Paste a new key to replace…'
                                    : 'Paste API key…'
                            "
                        />
                        <div class="flex gap-2 justify-end">
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="
                                    savingKeyFor === provider.key ||
                                    !(keyInputs[provider.key] ?? '').trim()
                                "
                                @click="saveKey(provider)"
                            >
                                <Loader2
                                    v-if="savingKeyFor === provider.key"
                                    class="w-4 h-4 mr-1 animate-spin"
                                />
                                <Save v-else class="w-4 h-4 mr-1" />
                                Save
                            </Button>
                            <Button
                                v-if="provider.key_source === 'database'"
                                variant="ghost"
                                size="sm"
                                :disabled="savingKeyFor === provider.key"
                                @click="confirmingKeyRemoval = provider"
                            >
                                <Trash2 class="w-4 h-4 mr-1" /> Remove
                            </Button>
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>

        <!-- Reset confirmation -->
        <AlertDialog
            :open="confirmingReset"
            @update:open="(open) => !open && (confirmingReset = false)"
        >
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Reset to the environment default?</AlertDialogTitle>
                    <AlertDialogDescription>
                        The provider and model chosen on this page will be cleared and
                        every AI feature goes back to the server's configured default.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel @click="confirmingReset = false">Cancel</AlertDialogCancel>
                    <AlertDialogAction @click="resetToEnv">Reset</AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>

        <!-- Key removal confirmation -->
        <AlertDialog
            :open="!!confirmingKeyRemoval"
            @update:open="(open) => !open && (confirmingKeyRemoval = null)"
        >
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Remove the {{ providerLabel(confirmingKeyRemoval?.key ?? "") }} key?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        If the current AI engine relies on this key, the system falls
                        back to the environment default provider automatically.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel @click="confirmingKeyRemoval = null">Cancel</AlertDialogCancel>
                    <AlertDialogAction @click="removeKey">Remove</AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    </div>
</template>
