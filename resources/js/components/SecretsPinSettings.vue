<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';
import { useHttp } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import SecretPinInput from '@/components/SecretPinInput.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';

const props = defineProps<{ native: boolean; pinSet: boolean }>();
const emit = defineEmits<{ saved: [revision: number] }>();
const pinSet = ref(props.pinSet);
const form = useHttp<{ current_pin: string; pin: string; pin_confirmation: string }, { revision?: number }>({ current_pin: '', pin: '', pin_confirmation: '' });
const error = ref('');

function clearPin() {
    form.current_pin = '';
    form.pin = '';
    form.pin_confirmation = '';
    form.defaults('current_pin', '');
    form.defaults('pin', '');
    form.defaults('pin_confirmation', '');
}
async function savePin() {
    if (!props.native || form.processing) return;
    error.value = '';
    form.clearErrors();
    try {
        const options = { onHttpException: (response: { data: string }) => { error.value = JSON.parse(response.data).message ?? 'Your PIN could not be saved.'; } };
        const result = pinSet.value ? await form.put('/secrets/pin', options) : await form.post('/secrets/pin', options);
        if (!result) return;
        if (typeof result.revision === 'number') emit('saved', result.revision);
        toast.success(pinSet.value ? 'PIN changed. Secrets are locked.' : 'PIN saved.');
        pinSet.value = true;
    } catch {
        if (!form.hasErrors && !error.value) error.value = 'Your PIN could not be saved. Try again.';
    } finally {
        clearPin();
    }
}
onBeforeUnmount(() => { form.cancel(); clearPin(); });
</script>

<template>
    <section class="grid gap-4" aria-labelledby="settings-pin-title">
        <div>
            <h3 id="settings-pin-title" class="text-sm font-semibold">Secrets PIN</h3>
            <p class="mt-1 text-sm leading-6 text-muted-foreground">One PIN unlocks secrets in every project.</p>
        </div>
        <Alert v-if="!native"><AlertDescription>Open the desktop app to manage your PIN.</AlertDescription></Alert>
        <form v-else class="grid w-full max-w-md justify-items-start gap-4" @submit.prevent="savePin">
            <Field v-if="pinSet" class="w-auto gap-2" :data-invalid="!!form.errors.current_pin">
                <FieldLabel for="settings-current-pin">Current PIN</FieldLabel>
                <SecretPinInput id="settings-current-pin" v-model="form.current_pin" :disabled="form.processing" :invalid="!!form.errors.current_pin" aria-describedby="settings-current-pin-error" />
                <FieldError v-if="form.errors.current_pin" id="settings-current-pin-error">{{ form.errors.current_pin }}</FieldError>
            </Field>
            <div class="flex max-w-full flex-wrap items-start gap-x-6 gap-y-4">
                <Field class="w-auto gap-2" :data-invalid="!!form.errors.pin">
                    <FieldLabel for="settings-new-pin">{{ pinSet ? 'New PIN' : 'PIN' }}</FieldLabel>
                    <SecretPinInput id="settings-new-pin" v-model="form.pin" :disabled="form.processing" :invalid="!!form.errors.pin" aria-describedby="settings-new-pin-error" />
                    <FieldError v-if="form.errors.pin" id="settings-new-pin-error">{{ form.errors.pin }}</FieldError>
                </Field>
                <Field class="w-auto gap-2" :data-invalid="!!form.errors.pin_confirmation">
                    <FieldLabel for="settings-confirm-pin">Confirm {{ pinSet ? 'new PIN' : 'PIN' }}</FieldLabel>
                    <SecretPinInput id="settings-confirm-pin" v-model="form.pin_confirmation" :disabled="form.processing" :invalid="!!form.errors.pin_confirmation" aria-describedby="settings-confirm-pin-error" />
                    <FieldError v-if="form.errors.pin_confirmation" id="settings-confirm-pin-error">{{ form.errors.pin_confirmation }}</FieldError>
                </Field>
            </div>
            <Alert v-if="error" variant="destructive"><AlertDescription>{{ error }}</AlertDescription></Alert>
            <Button type="submit" :disabled="form.processing || form.pin.length !== 4 || form.pin_confirmation.length !== 4 || (pinSet && form.current_pin.length !== 4)">{{ form.processing ? 'Saving…' : pinSet ? 'Change PIN' : 'Save PIN' }}</Button>
        </form>
    </section>
</template>
