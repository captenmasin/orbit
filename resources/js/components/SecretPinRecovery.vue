<script setup lang="ts">
import SecretPinInput from '@/components/SecretPinInput.vue';
import SecretRecoveryCode from '@/components/SecretRecoveryCode.vue';
import { useHttp } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { computed, onBeforeUnmount, ref } from 'vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';

type Method = 'touch_id' | 'recovery_code' | 'reset';
const emit = defineEmits<{ saved: [revision: number] }>();
const props = defineProps<{ disabled?: boolean }>();
const open = ref(false);
const method = ref<Method | null>(null);
const error = ref('');
const recoveryCode = ref('');
const status = useHttp<Record<string, never>, { touch_id_available: boolean; recovery_code_set: boolean }>({});
const form = useHttp<{ method: string; recovery_code: string; confirmation: string; pin: string; pin_confirmation: string }, { revision: number; recovery_code: string }>({ method: '', recovery_code: '', confirmation: '', pin: '', pin_confirmation: '' });
const ready = computed(() => !!method.value && !form.processing && form.pin.length === 4 && form.pin_confirmation.length === 4
    && (method.value !== 'reset' || form.confirmation === 'DELETE ALL SECRETS')
    && (method.value !== 'recovery_code' || !!form.recovery_code.trim()));
let disposed = false;

function clearInputs() {
    for (const field of ['recovery_code', 'confirmation', 'pin', 'pin_confirmation'] as const) {
        form[field] = '';
        form.defaults(field, '');
    }
    form.response = null;
}
async function loadStatus() {
    error.value = '';
    try { await status.get('/secrets/recovery/status'); }
    catch { if (!disposed && open.value) error.value = 'Recovery options could not be loaded. Try again.'; }
}
function changeOpen(value: boolean) {
    if (props.disabled || form.processing || recoveryCode.value) return;
    open.value = value;
    method.value = null;
    error.value = '';
    form.clearErrors();
    clearInputs();
    status.response = null;
    if (value) void loadStatus();
    else status.cancel();
}
function chooseMethod(value: Method | null) {
    if (form.processing) return;
    clearInputs();
    form.clearErrors();
    error.value = '';
    method.value = value;
}
async function recover() {
    if (!ready.value || props.disabled || !open.value) return;
    error.value = '';
    form.clearErrors();
    form.method = method.value ?? '';
    try {
        const result = await form.post(method.value === 'reset' ? '/secrets/reset' : '/secrets/recover', {
            onHttpException: response => { error.value = JSON.parse(response.data).message ?? 'Your PIN could not be reset. Try again.'; },
        });
        if (!result || disposed) return;
        recoveryCode.value = result.recovery_code;
        emit('saved', result.revision);
    } catch { if (!disposed && !form.hasErrors && !error.value) error.value = 'Your PIN could not be reset. Try again.'; }
    finally { clearInputs(); }
}
function finish() {
    recoveryCode.value = '';
    changeOpen(false);
}
onBeforeUnmount(() => { disposed = true; status.cancel(); form.cancel(); clearInputs(); recoveryCode.value = ''; });
</script>

<template>
    <Dialog
        :open="open"
        @update:open="changeOpen">
        <DialogTrigger as-child>
            <Button
                type="button"
                variant="link"
                :disabled="disabled">
                Forgot PIN?
            </Button>
        </DialogTrigger>
        <DialogContent
            class="max-h-[calc(100dvh-2rem)] overflow-y-auto text-left sm:max-w-xl"
            :show-close-button="!form.processing && !recoveryCode"
            @interact-outside="event => { if (form.processing || recoveryCode) event.preventDefault(); }"
            @escape-key-down="event => { if (form.processing || recoveryCode) event.preventDefault(); }">
            <DialogHeader>
                <DialogTitle>{{ recoveryCode ? 'Your new PIN is ready' : method === 'reset' ? 'Reset all project secrets?' : 'Recover your PIN' }}</DialogTitle>
                <DialogDescription>{{ recoveryCode ? 'Secrets are locked. Use your new PIN the next time you unlock them.' : method === 'reset' ? 'This deletes saved secrets from every project in this workspace. Projects, documents, tasks and connection credentials stay intact.' : 'Verify with Touch ID or your saved recovery code to choose a new PIN and keep your secrets.' }}</DialogDescription>
            </DialogHeader>
            <SecretRecoveryCode
                v-if="recoveryCode"
                :code="recoveryCode"
                @saved="finish" />
            <template v-else>
                <p
                    v-if="status.processing"
                    role="status"
                    class="text-sm text-muted-foreground">
                    Loading recovery options…
                </p>
                <div
                    v-else-if="!method && status.response"
                    class="grid gap-3">
                    <Button
                        v-if="status.response.touch_id_available"
                        type="button"
                        @click="chooseMethod('touch_id')">
                        Use Touch ID
                    </Button>
                    <Button
                        v-if="status.response.recovery_code_set"
                        type="button"
                        variant="outline"
                        @click="chooseMethod('recovery_code')">
                        Use recovery code
                    </Button>
                    <p
                        v-if="!status.response.touch_id_available"
                        class="text-sm text-muted-foreground">
                        Touch ID is unavailable on this device.
                    </p>
                    <p
                        v-if="!status.response.recovery_code_set"
                        class="text-sm text-muted-foreground">
                        No recovery code has been set up. If you remember your PIN, change it in Security settings to create one.
                    </p>
                    <div class="grid gap-3 border-t pt-4">
                        <p class="text-sm text-muted-foreground">
                            Can't use either option? You can delete your saved project secrets and start again. Existing backups and exported files are unaffected.
                        </p>
                        <Button
                            type="button"
                            variant="outline"
                            @click="chooseMethod('reset')">
                            Reset secrets…
                        </Button>
                    </div>
                </div>
                <form
                    v-else-if="method"
                    class="grid gap-4"
                    @submit.prevent="recover">
                    <Field v-if="method === 'recovery_code'">
                        <FieldLabel for="pin-recovery-code">
                            Recovery code
                        </FieldLabel>
                        <Input
                            id="pin-recovery-code"
                            v-model="form.recovery_code"
                            type="password"
                            autocomplete="off"
                            spellcheck="false"
                            maxlength="100"
                            required
                            :disabled="form.processing"
                            :aria-invalid="!!form.errors.recovery_code"
                            aria-describedby="pin-recovery-code-error" />
                        <FieldError
                            v-if="form.errors.recovery_code"
                            id="pin-recovery-code-error">
                            {{ form.errors.recovery_code }}
                        </FieldError>
                    </Field>
                    <Field v-if="method === 'reset'">
                        <FieldLabel for="pin-reset-confirmation">
                            Type DELETE ALL SECRETS to confirm
                        </FieldLabel>
                        <Input
                            id="pin-reset-confirmation"
                            v-model="form.confirmation"
                            autocomplete="off"
                            spellcheck="false"
                            required
                            :disabled="form.processing"
                            :aria-invalid="!!form.errors.confirmation"
                            aria-describedby="pin-reset-confirmation-error" />
                        <FieldError
                            v-if="form.errors.confirmation"
                            id="pin-reset-confirmation-error">
                            {{ form.errors.confirmation }}
                        </FieldError>
                    </Field>
                    <div class="flex flex-wrap gap-6">
                        <Field class="w-auto">
                            <FieldLabel for="recovery-new-pin">
                                New PIN
                            </FieldLabel><SecretPinInput
                                id="recovery-new-pin"
                                v-model="form.pin"
                                :disabled="form.processing"
                                :invalid="!!form.errors.pin"
                                aria-describedby="recovery-new-pin-error" /><FieldError
                                    v-if="form.errors.pin"
                                    id="recovery-new-pin-error">
                                    {{ form.errors.pin }}
                                </FieldError>
                        </Field>
                        <Field class="w-auto">
                            <FieldLabel for="recovery-confirm-pin">
                                Confirm new PIN
                            </FieldLabel><SecretPinInput
                                id="recovery-confirm-pin"
                                v-model="form.pin_confirmation"
                                :disabled="form.processing"
                                :invalid="!!form.errors.pin_confirmation"
                                aria-describedby="recovery-confirm-pin-error" /><FieldError
                                    v-if="form.errors.pin_confirmation"
                                    id="recovery-confirm-pin-error">
                                    {{ form.errors.pin_confirmation }}
                                </FieldError>
                        </Field>
                    </div>
                    <FieldError v-if="form.errors.method">
                        {{ form.errors.method }}
                    </FieldError>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="form.processing"
                            @click="chooseMethod(null)">
                            Back
                        </Button><Button
                            type="submit"
                            :variant="method === 'reset' ? 'destructive' : 'default'"
                            :disabled="!ready">
                            {{ form.processing ? (method === 'touch_id' ? 'Waiting for Touch ID…' : 'Resetting…') : method === 'reset' ? 'Delete all secrets and reset PIN' : method === 'touch_id' ? 'Verify with Touch ID' : 'Reset PIN' }}
                        </Button>
                    </DialogFooter>
                </form>
                <Alert
                    v-if="error"
                    variant="destructive">
                    <AlertDescription>{{ error }}</AlertDescription>
                </Alert>
                <Button
                    v-if="!status.processing && !status.response"
                    type="button"
                    variant="outline"
                    @click="loadStatus">
                    Try again
                </Button>
            </template>
        </DialogContent>
    </Dialog>
</template>
