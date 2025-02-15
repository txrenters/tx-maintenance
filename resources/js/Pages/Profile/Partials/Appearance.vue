<script setup>
import { ref } from "vue";
import { useColorMode } from "@vueuse/core";
import FormSection from "@/Components/FormSection.vue";

// Pass { disableTransition: false } to enable transitions
const mode = useColorMode({
  disableTransition: false,
});
</script>

<template>
  <FormSection @submitted="updateProfileInformation">
    <template #title> Appearance </template>

    <template #description>
      Customize the appearance of the app. Automatically switch between day and night
      themes.
    </template>

    <template #form>
      <form class="col-span-12" @submit="onSubmit">
        <FormField type="radio" name="theme">
          <FormItem class="space-y-1">
            <FormLabel>Theme</FormLabel>
            <FormDescription> Select the theme for the dashboard. </FormDescription>
            <FormMessage />

            <RadioGroup
              class="grid max-w-md grid-cols-2 gap-8 pt-2"
              v-bind="componentField"
            >
              <FormItem>
                <FormLabel class="[&:has([data-state=checked])>div]:border-primary">
                  <FormControl>
                    <RadioGroupItem @click="mode = 'light'" class="sr-only" />
                  </FormControl>
                  <div
                    class="items-center rounded-md border-2 border-muted p-1 hover:border-accent"
                  >
                    <div class="space-y-2 rounded-sm bg-[#ecedef] p-2">
                      <div class="space-y-2 rounded-md bg-white p-2 shadow-sm">
                        <div class="h-2 w-20 rounded-lg bg-[#ecedef]" />
                        <div class="h-2 w-[100px] rounded-lg bg-[#ecedef]" />
                      </div>
                      <div
                        class="flex items-center space-x-2 rounded-md bg-white p-2 shadow-sm"
                      >
                        <div class="h-4 w-4 rounded-full bg-[#ecedef]" />
                        <div class="h-2 w-[100px] rounded-lg bg-[#ecedef]" />
                      </div>
                      <div
                        class="flex items-center space-x-2 rounded-md bg-white p-2 shadow-sm"
                      >
                        <div class="h-4 w-4 rounded-full bg-[#ecedef]" />
                        <div class="h-2 w-[100px] rounded-lg bg-[#ecedef]" />
                      </div>
                    </div>
                  </div>
                  <span class="block w-full p-2 text-center font-normal"> Light </span>
                </FormLabel>
              </FormItem>
              <FormItem>
                <FormLabel class="[&:has([data-state=checked])>div]:border-primary">
                  <FormControl>
                    <RadioGroupItem @click="mode = 'dark'" class="sr-only" />
                  </FormControl>
                  <div
                    class="items-center rounded-md border-2 border-muted bg-popover p-1 hover:bg-accent hover:text-accent-foreground"
                  >
                    <div class="space-y-2 rounded-sm bg-slate-950 p-2">
                      <div class="space-y-2 rounded-md bg-slate-800 p-2 shadow-sm">
                        <div class="h-2 w-20 rounded-lg bg-slate-400" />
                        <div class="h-2 w-[100px] rounded-lg bg-slate-400" />
                      </div>
                      <div
                        class="flex items-center space-x-2 rounded-md bg-slate-800 p-2 shadow-sm"
                      >
                        <div class="h-4 w-4 rounded-full bg-slate-400" />
                        <div class="h-2 w-[100px] rounded-lg bg-slate-400" />
                      </div>
                      <div
                        class="flex items-center space-x-2 rounded-md bg-slate-800 p-2 shadow-sm"
                      >
                        <div class="h-4 w-4 rounded-full bg-slate-400" />
                        <div class="h-2 w-[100px] rounded-lg bg-slate-400" />
                      </div>
                    </div>
                  </div>
                  <span class="block w-full p-2 text-center font-normal"> Dark </span>
                </FormLabel>
              </FormItem>
            </RadioGroup>
          </FormItem>
        </FormField>
      </form>
    </template>
  </FormSection>
</template>
