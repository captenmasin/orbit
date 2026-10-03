<script setup lang="ts">
import SecretPinInput from '@/components/SecretPinInput.vue';
import SecretPinRecovery from '@/components/SecretPinRecovery.vue';
import SecretRecoveryCode from '@/components/SecretRecoveryCode.vue';
import { toast } from 'vue-sonner';
import { useHttp } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';

const props = defineProps<{ native: boolean; pinSet: boolean }>();
const emit = defineEmits<{ saved: [revision: number] }>();
const pinSet = ref(props.pinSet);
const open = ref(false);
const form = useHttp<{ current_pin: string; pin: string; pin_confirmation: string }, { revision?: number; recovery_code: string }>({ current_pin: '', pin: '', pin_confirmation: '' });
const error = ref('');
const recoveryCode = ref('');
let disposed = false;

function clearPin() {
    form.current_pin = '';
    form.pin = '';
    form.pin_confirmation = '';
    form.defaults('current_pin', '');
    form.defaults('pin', '');
    form.defaults('pin_confirmation', '');
    form.response = null;
}
function changeOpen(value: boolean) {
    if (!props.native || form.processing || recoveryCode.value) return;
    open.value = value;
    clearPin(); form.clearErrors(); error.value = '';
}
function finishRecovery() { recoveryCode.value = ''; changeOpen(false); }
async function savePin() {
    if (!props.native || form.processing || recoveryCode.value) return;
    error.value = '';
    form.clearErrors();
    try {
        const options = { onHttpException: (response: { data: string }) => { error.value = JSON.parse(response.data).message ?? 'Your PIN could not be saved.'; } };
        const result = pinSet.value ? await form.put('/secrets/pin', options) : await form.post('/secrets/pin', options);
        if (!result || disposed) return;
        recoveryCode.value = result.recovery_code;
        if (typeof result.revision === 'number') emit('saved', result.revision);
        toast.success(pinSet.value ? 'PIN changed. Secrets are locked.' : 'PIN saved.');
        pinSet.value = true;
    } catch {
        if (!form.hasErrors && !error.value) error.value = 'Your PIN could not be saved. Try again.';
    } finally {
        clearPin();
    }
}
function recovered(revision: number) { clearPin(); error.value = ''; emit('saved', revision); }
onBeforeUnmount(() => { disposed = true; form.cancel(); clearPin(); recoveryCode.value = ''; });
</script>

<template>
    <section
        class="rounded-xl border px-4 py-3"
        aria-labelledby="settings-pin-title">
        <Dialog
            :open="open"
            @update:open="changeOpen">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="min-w-0 space-y-1">
                    <h3
                        id="settings-pin-title"
                        class="text-sm font-medium">
                        Secrets PIN
                    </h3>
                    <p class="text-xs text-muted-foreground">
                        {{ !native ? 'Manage your PIN in the desktop app.' : pinSet ? 'PIN enabled for every project.' : 'Set up a PIN to unlock project secrets.' }}
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    <SecretPinRecovery
                        v-if="native && pinSet"
                        :disabled="form.processing || !!recoveryCode"
                        @saved="recovered" />
                    <DialogTrigger as-child>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="!native || form.processing || !!recoveryCode">
                            {{ pinSet ? 'Change PIN' : 'Set up PIN' }}
                        </Button>
                    </DialogTrigger>
                </div>
            </div>
            <DialogContent
                class="max-h-[calc(100dvh-2rem)] overflow-y-auto"
                :show-close-button="!form.processing && !recoveryCode"
                @interact-outside="event => { if (form.processing || recoveryCode) event.preventDefault(); }"
                @escape-key-down="event => { if (form.processing || recoveryCode) event.preventDefault(); }">
                <DialogHeader>
                    <DialogTitle>{{ recoveryCode ? 'Your PIN is ready' : pinSet ? 'Change PIN' : 'Set up secrets PIN' }}</DialogTitle>
                    <DialogDescription>One four-digit PIN unlocks secrets in every project.</DialogDescription>
                </DialogHeader>
                <SecretRecoveryCode
                    v-if="recoveryCode"
                    :code="recoveryCode"
                    @saved="finishRecovery" />
                <form
                    v-else
                    class="grid gap-4"
                    @submit.prevent="savePin">
                    <Field
                        v-if="pinSet"
                        :data-invalid="!!form.errors.current_pin">
                        <FieldLabel for="settings-current-pin">
                            Current PIN
                        </FieldLabel>
                        <SecretPinInput
                            id="settings-current-pin"
                            v-model="form.current_pin"
                            :disabled="form.processing"
                            :invalid="!!form.errors.current_pin"
                            aria-describedby="settings-current-pin-error" />
                        <FieldError
                            v-if="form.errors.current_pin"
                            id="settings-current-pin-error">
                            {{ form.errors.current_pin }}
                        </FieldError>
                    </Field>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <Field :data-invalid="!!form.errors.pin">
                            <FieldLabel for="settings-new-pin">
                                {{ pinSet ? 'New PIN' : 'PIN' }}
                            </FieldLabel>
                            <SecretPinInput
                                id="settings-new-pin"
                                v-model="form.pin"
                                :disabled="form.processing"
                                :invalid="!!form.errors.pin"
                                aria-describedby="settings-new-pin-error" />
                            <FieldError
                                v-if="form.errors.pin"
                                id="settings-new-pin-error">
                                {{ form.errors.pin }}
                            </FieldError>
                        </Field>
                        <Field :data-invalid="!!form.errors.pin_confirmation">
                            <FieldLabel for="settings-confirm-pin">
                                Confirm {{ pinSet ? 'new PIN' : 'PIN' }}
                            </FieldLabel>
                            <SecretPinInput
                                id="settings-confirm-pin"
                                v-model="form.pin_confirmation"
                                :disabled="form.processing"
                                :invalid="!!form.errors.pin_confirmation"
                                aria-describedby="settings-confirm-pin-error" />
                            <FieldError
                                v-if="form.errors.pin_confirmation"
                                id="settings-confirm-pin-error">
                                {{ form.errors.pin_confirmation }}
                            </FieldError>
                        </Field>
                    </div>
                    <Alert
                        v-if="error"
                        variant="destructive">
                        <AlertDescription>{{ error }}</AlertDescription>
                    </Alert>
                    <p class="text-sm text-muted-foreground">
                        {{ pinSet ? 'Changing your PIN locks secrets and replaces your recovery code.' : 'Keep the recovery code shown after setup somewhere safe.' }}
                    </p>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="form.processing"
                            @click="changeOpen(false)">
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            :disabled="form.processing || form.pin.length !== 4 || form.pin_confirmation.length !== 4 || (pinSet && form.current_pin.length !== 4)">
                            {{ form.processing ? 'Saving…' : pinSet ? 'Change PIN' : 'Save PIN' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </section>
</template>
