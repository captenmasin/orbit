<script setup lang="ts">
import { REGEXP_ONLY_DIGITS } from 'vue-input-otp';
import { InputOTP, InputOTPGroup, InputOTPSlot } from '@/components/ui/input-otp';

defineProps<{ id: string; disabled?: boolean; invalid?: boolean }>();
const pin = defineModel<string>({ default: '' });
</script>

<template>
    <InputOTP :id="id" v-model="pin" :maxlength="4" :pattern="REGEXP_ONLY_DIGITS" type="password" inputmode="numeric" autocomplete="off" required :disabled="disabled" :aria-invalid="invalid || undefined">
        <InputOTPGroup>
            <InputOTPSlot v-for="index in 4" :key="index" :index="index - 1" mask />
        </InputOTPGroup>
    </InputOTP>
</template>
