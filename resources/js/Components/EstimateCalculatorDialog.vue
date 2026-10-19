<script setup>
// The THMP estimate calculator: labor hours at the office's hourly rate plus
// materials with sales tax and an optional trip / maintenance fee (team
// proposal, 2026-10-19). "Use this total" hands the figures to the Update
// Work Order Details form; nothing is saved from here.
import { computed, ref, watch } from "vue";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/Components/ui/dialog";
import { Separator } from "@/Components/ui/separator";

const props = defineProps({
    open: { type: Boolean, default: false },
    rates: {
        type: Object,
        default: () => ({ labor_rate: 85, material_tax_rate: 0.0825 }),
    },
});

const emit = defineEmits(["update:open", "use-total"]);

const laborRate = computed(() => Number(props.rates?.labor_rate ?? 85));
const taxRate = computed(() => Number(props.rates?.material_tax_rate ?? 0.0825));

const calc = ref({ hours: "", materials: "", feeApplies: false, fee: "" });

const toMoney = (n) => Math.round((Number(n) || 0) * 100) / 100;
const laborCost = computed(() =>
    toMoney((Number(calc.value.hours) || 0) * laborRate.value)
);
const materialsWithTax = computed(() =>
    toMoney((Number(calc.value.materials) || 0) * (1 + taxRate.value))
);
const feeAmount = computed(() =>
    calc.value.feeApplies ? toMoney(calc.value.fee) : 0
);
const total = computed(() =>
    toMoney(laborCost.value + materialsWithTax.value + feeAmount.value)
);
const hasInput = computed(() => total.value > 0);

const money = (n) =>
    Number(n).toLocaleString("en-US", { style: "currency", currency: "USD" });
const taxPercent = computed(() =>
    (taxRate.value * 100).toLocaleString("en-US", { maximumFractionDigits: 3 })
);

// A fresh calculator every time it opens: an old job's numbers must never
// slip into a new estimate.
watch(
    () => props.open,
    (open) => {
        if (open) {
            calc.value = { hours: "", materials: "", feeApplies: false, fee: "" };
        }
    }
);

const useTotal = () => {
    const hours = Number(calc.value.hours) || 0;
    emit("use-total", {
        cost: total.value.toFixed(2),
        // Time Estimate is whole hours on the work order: round up, so a
        // 2.5-hour job never shows as 2.
        hours: hours > 0 ? Math.ceil(hours) : null,
    });
    emit("update:open", false);
};
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent
            class="sm:max-w-[460px] grid-rows-[auto_minmax(0,1fr)_auto] p-0 max-h-[95dvh]"
        >
            <DialogHeader class="p-6 pb-0 text-left">
                <DialogTitle>Estimate calculator</DialogTitle>
                <DialogDescription>
                    Labor at {{ money(laborRate) }} an hour, materials with
                    {{ taxPercent }}% sales tax, plus a trip or maintenance fee
                    when one applies.
                </DialogDescription>
            </DialogHeader>
            <Separator />
            <div class="px-6 space-y-4 overflow-y-auto">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <Label>Labor hours</Label>
                        <Input
                            v-model="calc.hours"
                            type="number"
                            inputmode="decimal"
                            min="0"
                            step="0.25"
                            placeholder="0"
                            class="mt-1"
                            data-testid="calc-hours"
                        />
                    </div>
                    <div>
                        <Label>Materials ($, before tax)</Label>
                        <Input
                            v-model="calc.materials"
                            type="number"
                            inputmode="decimal"
                            min="0"
                            step="0.01"
                            placeholder="0.00"
                            class="mt-1"
                            data-testid="calc-materials"
                        />
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <label
                        class="flex items-center gap-2 text-sm min-h-[44px] shrink-0 cursor-pointer"
                    >
                        <input
                            v-model="calc.feeApplies"
                            type="checkbox"
                            class="h-5 w-5 rounded border-input"
                            data-testid="calc-fee-applies"
                        />
                        Trip / maintenance fee
                    </label>
                    <Input
                        v-if="calc.feeApplies"
                        v-model="calc.fee"
                        type="number"
                        inputmode="decimal"
                        min="0"
                        step="0.01"
                        placeholder="0.00"
                        aria-label="Fee amount"
                        data-testid="calc-fee"
                    />
                </div>
                <dl class="text-sm space-y-1" data-testid="calc-breakdown">
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted-foreground">
                            Labor ({{ calc.hours || 0 }} h &times;
                            {{ money(laborRate) }})
                        </dt>
                        <dd>{{ money(laborCost) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted-foreground">
                            Materials incl. {{ taxPercent }}% tax
                        </dt>
                        <dd>{{ money(materialsWithTax) }}</dd>
                    </div>
                    <div
                        v-if="calc.feeApplies"
                        class="flex justify-between gap-3"
                    >
                        <dt class="text-muted-foreground">
                            Trip / maintenance fee
                        </dt>
                        <dd>{{ money(feeAmount) }}</dd>
                    </div>
                    <div
                        class="flex justify-between gap-3 font-semibold border-t pt-1"
                    >
                        <dt>Total</dt>
                        <dd data-testid="calc-total">{{ money(total) }}</dd>
                    </div>
                </dl>
            </div>
            <DialogFooter class="p-6 pt-0">
                <Button
                    type="button"
                    :disabled="!hasInput"
                    data-testid="calc-use-total"
                    @click="useTotal"
                >
                    Use this total
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
