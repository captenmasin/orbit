<script setup lang="ts">
import { computed } from 'vue';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

type Choice = string | { value: string; label: string; disabled?: boolean };

const props = defineProps<{ modelValue: string; options: Choice[]; disabled?: boolean; name?: string; placeholder?: string }>();
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
defineOptions({ inheritAttrs: false });

const emptyValue = '__orbit_empty_choice__';
const choices = computed(() => props.options.map(option => typeof option === 'string' ? { value: option, label: option, disabled: false } : option));
const emptyChoice = computed(() => choices.value.find(option => option.value === ''));
const selected = computed({
    get: () => props.modelValue || (emptyChoice.value && !emptyChoice.value.disabled ? emptyValue : undefined),
    set: value => emit('update:modelValue', value === emptyValue ? '' : String(value ?? '')),
});
</script>

<template>
    <Select v-model="selected" :disabled="disabled" :name="name">
        <SelectTrigger v-bind="$attrs" :disabled="disabled"><SelectValue :placeholder="placeholder ?? (emptyChoice?.disabled ? emptyChoice.label : 'Select…')" /></SelectTrigger>
        <SelectContent>
            <SelectItem v-for="option in choices.filter(item => item.value !== '' || !item.disabled)" :key="option.value" :value="option.value === '' ? emptyValue : option.value" :disabled="option.disabled">{{ option.label }}</SelectItem>
        </SelectContent>
    </Select>
</template>
