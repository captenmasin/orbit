<script setup lang="ts">
import { computed } from 'vue';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

type ChoiceOption = { value: string; label: string; disabled?: boolean; dotClass?: string };
type Choice = string | ChoiceOption;

const props = defineProps<{ modelValue: string; options: Choice[]; disabled?: boolean; name?: string; placeholder?: string; variant?: 'default' | 'filled' }>();
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
defineOptions({ inheritAttrs: false });

const emptyValue = '__orbit_empty_choice__';
const choices = computed<ChoiceOption[]>(() => props.options.map(option => typeof option === 'string' ? { value: option, label: option, disabled: false } : option));
const emptyChoice = computed(() => choices.value.find(option => option.value === ''));
const selectedChoice = computed(() => choices.value.find(option => option.value === props.modelValue));
const selected = computed({
    get: () => props.modelValue || (emptyChoice.value && !emptyChoice.value.disabled ? emptyValue : undefined),
    set: value => emit('update:modelValue', value === emptyValue ? '' : String(value ?? '')),
});
</script>

<template>
    <Select
        v-model="selected"
        :disabled="disabled"
        :name="name">
        <SelectTrigger
            v-bind="$attrs"
            :variant="variant"
            :disabled="disabled">
            <SelectValue :placeholder="placeholder ?? (emptyChoice?.disabled ? emptyChoice.label : 'Select…')">
                <template
                    v-if="selectedChoice?.dotClass"
                    #default>
                    <span
                        class="size-2 shrink-0 rounded-full"
                        :class="selectedChoice.dotClass"
                        aria-hidden="true" />{{ selectedChoice.label }}
                </template>
            </SelectValue>
        </SelectTrigger>
        <SelectContent>
            <SelectItem
                v-for="option in choices.filter(item => item.value !== '' || !item.disabled)"
                :key="option.value"
                :value="option.value === '' ? emptyValue : option.value"
                :disabled="option.disabled"
                :text-value="option.label">
                <span
                    v-if="option.dotClass"
                    class="size-2 shrink-0 rounded-full"
                    :class="option.dotClass"
                    aria-hidden="true" />{{ option.label }}
            </SelectItem>
        </SelectContent>
    </Select>
</template>
