<script setup lang="ts">
import { computed } from 'vue';
import { CheckIcon, ChevronDownIcon } from '@lucide/vue';
import { SelectContent, SelectItem, SelectItemIndicator, SelectItemText, SelectPortal, SelectRoot, SelectTrigger, SelectValue, SelectViewport } from 'reka-ui';

const props = defineProps<{ id: string; label: string; modelValue: string; options: string[]; allLabel: string }>();
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
const allValue = '__orbit_all__';
const selected = computed({
    get: () => props.modelValue || allValue,
    set: value => emit('update:modelValue', value === allValue ? '' : String(value)),
});
</script>

<template>
    <SelectRoot v-model="selected">
        <SelectTrigger :id="id" class="flex h-9 w-full items-center justify-between gap-2 rounded-full px-3.5 text-left text-[13px] outline-none transition-colors hover:bg-neutral-200 focus-visible:ring-2 focus-visible:ring-ring/50 dark:hover:bg-neutral-700" :class="modelValue ? 'bg-neutral-200 text-neutral-700 dark:bg-neutral-700 dark:text-neutral-100' : 'bg-muted text-foreground'">
            <span class="shrink-0 text-muted-foreground" aria-hidden="true">{{ label }}</span><span v-if="modelValue" class="min-w-0 flex-1 truncate font-medium"><SelectValue /></span><ChevronDownIcon class="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
        </SelectTrigger>
        <SelectPortal>
            <SelectContent position="popper" class="z-50 min-w-(--reka-select-trigger-width) overflow-hidden rounded-2xl bg-popover p-1.5 text-popover-foreground shadow-xl ring-1 ring-black/5 dark:ring-white/10">
                <SelectViewport class="max-h-72">
                    <SelectItem :value="allValue" class="relative flex h-8 cursor-default items-center rounded-xl px-3 pr-9 text-[13px] outline-none data-[highlighted]:bg-neutral-100 dark:data-[highlighted]:bg-neutral-700">
                        <SelectItemText>{{ allLabel }}</SelectItemText><SelectItemIndicator class="absolute right-2"><CheckIcon class="size-4" aria-hidden="true" /></SelectItemIndicator>
                    </SelectItem>
                    <SelectItem v-for="option in options" :key="option" :value="option" class="relative flex h-8 cursor-default items-center rounded-xl px-3 pr-9 text-[13px] outline-none data-[highlighted]:bg-neutral-100 dark:data-[highlighted]:bg-neutral-700">
                        <SelectItemText>{{ option }}</SelectItemText><SelectItemIndicator class="absolute right-2"><CheckIcon class="size-4" aria-hidden="true" /></SelectItemIndicator>
                    </SelectItem>
                </SelectViewport>
            </SelectContent>
        </SelectPortal>
    </SelectRoot>
</template>
