<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';
import { router, useHttp } from '@inertiajs/vue3';
import SecretPinInput from '@/components/SecretPinInput.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';

type Preview = { destination: string; exists: boolean } | null;
type RestorePreview = { created_at: string; projects: number; tasks: number; secrets: number; includes_secrets: boolean } | null;
const props = defineProps<{ native: boolean }>();
const preview = useHttp<Record<string, never>, { preview: Preview }>({});
const form = useHttp({ include_secrets: false, password: '', password_confirmation: '', overwrite: false, backup: '' });
const pinForm = useHttp({ pin: '' });
const restore = useHttp<{ password: string; backup: string }, { preview: RestorePreview }>({ password: '', backup: '' });
const restoreApply = useHttp({ password: '', confirm: false, backup: '' });
const destination = ref<Preview>(null);
const password = ref('');
const confirmation = ref('');
const restorePassword = ref('');
const restoreApplyPassword = ref('');
const restorePreview = ref<RestorePreview>(null);
const error = ref('');

function clearPassword() {
    password.value = '';
    confirmation.value = '';
    form.password = '';
    form.password_confirmation = '';
}
async function chooseDestination() {
    error.value = '';
    try {
        const result = await preview.post('/backups/export/preview');
        if (!result) { error.value = Object.values(preview.errors).flat().join(' '); return; }
        destination.value = result.preview;
        form.overwrite = false;
    } catch {
        if (!preview.hasErrors) error.value = 'The backup destination could not be selected. Try again.';
    }
}
async function exportBackup() {
    if (!destination.value) return;
    error.value = '';
    form.password = password.value;
    form.password_confirmation = confirmation.value;
    password.value = '';
    confirmation.value = '';
    try {
        if (form.include_secrets) {
            const unlocked = await pinForm.post('/secrets/unlock', { onHttpException: response => { error.value = JSON.parse(response.data).message ?? 'PIN unlock failed.'; } });
            if (!unlocked) return;
        }
        const result = await form.post('/backups/export');
        if (!result) return;
        destination.value = null;
        form.overwrite = false;
    } catch {
        if (!form.hasErrors && !pinForm.hasErrors && !error.value) error.value = 'The backup could not be exported. Choose the destination again and retry.';
    } finally {
        pinForm.pin = '';
        pinForm.defaults('pin', '');
        clearPassword();
    }
}
async function previewRestore() {
    error.value = '';
    restore.password = restorePassword.value;
    restorePassword.value = '';
    try {
        const result = await restore.post('/backups/restore/preview');
        if (!result) return;
        restorePreview.value = result.preview;
    } catch {
        if (!restore.hasErrors) error.value = 'The backup could not be unlocked. Check its password and try again.';
    } finally {
        restore.password = '';
    }
}
async function applyRestore() {
    if (!restorePreview.value) return;
    error.value = '';
    restoreApply.password = restoreApplyPassword.value;
    restoreApplyPassword.value = '';
    try {
        const result = await restoreApply.post('/backups/restore');
        if (!result) return;
        restorePreview.value = null;
        router.reload();
    } catch {
        if (!restoreApply.hasErrors) error.value = 'The backup could not be restored. Preview it again and retry.';
    } finally {
        restoreApply.password = '';
    }
}
onBeforeUnmount(() => { preview.cancel(); form.cancel(); pinForm.cancel(); restore.cancel(); restoreApply.cancel(); pinForm.pin = ''; clearPassword(); restore.password = ''; restorePassword.value = ''; restoreApply.password = ''; restoreApplyPassword.value = ''; });
</script>

<template>
    <div class="grid max-w-2xl gap-6">
        <div><h1 class="text-2xl font-semibold tracking-tight">Backups</h1><p class="mt-1 text-sm text-muted-foreground">Create a password-protected Orbit backup of your workspace.</p></div>
        <Alert v-if="!native"><AlertDescription>Create backups in the desktop app.</AlertDescription></Alert>
        <Alert v-if="error" variant="destructive"><AlertDescription>{{ error }}</AlertDescription></Alert>
        <form class="grid gap-4" @submit.prevent="exportBackup">
            <label class="flex items-start gap-3 text-sm"><input v-model="form.include_secrets" type="checkbox" :disabled="!native || form.processing" /><span><span class="font-medium">Include project secrets</span><span class="block text-muted-foreground">Secrets are encrypted in the backup with this password. Provider tokens are never included.</span></span></label>
            <Field v-if="form.include_secrets" :data-invalid="!!pinForm.errors.pin"><FieldLabel for="backup-pin">Secrets PIN</FieldLabel><SecretPinInput id="backup-pin" v-model="pinForm.pin" :disabled="!native || pinForm.processing || form.processing" :invalid="!!pinForm.errors.pin" /><FieldError v-if="pinForm.errors.pin">{{ pinForm.errors.pin }}</FieldError></Field>
            <Field :data-invalid="!!form.errors.password"><FieldLabel for="backup-password">Backup password</FieldLabel><Input id="backup-password" v-model="password" type="password" autocomplete="new-password" :spellcheck="false" minlength="12" maxlength="4096" required :disabled="!native || form.processing" /><FieldError v-if="form.errors.password">{{ form.errors.password }}</FieldError></Field>
            <Field :data-invalid="!!form.errors.password_confirmation"><FieldLabel for="backup-password-confirmation">Confirm password</FieldLabel><Input id="backup-password-confirmation" v-model="confirmation" type="password" autocomplete="new-password" :spellcheck="false" minlength="12" maxlength="4096" required :disabled="!native || form.processing" /><FieldError v-if="form.errors.password_confirmation">{{ form.errors.password_confirmation }}</FieldError></Field>
            <div class="flex flex-wrap items-center gap-3"><Button type="button" variant="outline" :disabled="!native || preview.processing || form.processing" @click="chooseDestination">{{ destination ? 'Choose another destination' : 'Choose destination' }}</Button><span v-if="destination" class="text-sm text-muted-foreground">{{ destination.destination }}</span></div>
            <label v-if="destination?.exists" class="flex items-center gap-2 text-sm"><input v-model="form.overwrite" type="checkbox" :disabled="form.processing" />Replace the existing backup</label><FieldError v-if="form.errors.overwrite">{{ form.errors.overwrite }}</FieldError>
            <FieldError v-if="form.errors.backup">{{ form.errors.backup }}</FieldError>
            <div><Button type="submit" :disabled="!native || !destination || pinForm.processing || form.processing">{{ pinForm.processing ? 'Unlocking…' : form.processing ? 'Encrypting…' : 'Export backup' }}</Button></div>
        </form>
        <div class="border-t pt-6"><h2 class="text-lg font-semibold">Restore preview</h2><p class="mt-1 text-sm text-muted-foreground">Validate an Orbit backup before restoring. This does not change your workspace.</p>
            <form class="mt-4 grid gap-4" @submit.prevent="previewRestore">
                <Field :data-invalid="!!restore.errors.password"><FieldLabel for="restore-password">Backup password</FieldLabel><Input id="restore-password" v-model="restorePassword" type="password" autocomplete="current-password" :spellcheck="false" minlength="12" maxlength="4096" required :disabled="!native || restore.processing" /><FieldError v-if="restore.errors.password">{{ restore.errors.password }}</FieldError></Field>
                <FieldError v-if="restore.errors.backup">{{ restore.errors.backup }}</FieldError>
                <div><Button type="submit" variant="outline" :disabled="!native || restore.processing">{{ restore.processing ? 'Validating…' : 'Choose backup and preview' }}</Button></div>
            </form>
            <p v-if="restorePreview" class="mt-4 text-sm text-muted-foreground">Created {{ new Date(restorePreview.created_at).toLocaleString() }} · {{ restorePreview.projects }} projects · {{ restorePreview.tasks }} tasks · {{ restorePreview.secrets }} secrets {{ restorePreview.includes_secrets ? 'included' : 'not included' }}.</p>
            <form v-if="restorePreview" class="mt-4 grid gap-3" @submit.prevent="applyRestore">
                <Field :data-invalid="!!restoreApply.errors.password"><FieldLabel for="restore-apply-password">Re-enter password to replace this workspace</FieldLabel><Input id="restore-apply-password" v-model="restoreApplyPassword" type="password" autocomplete="current-password" :spellcheck="false" minlength="12" maxlength="4096" required :disabled="restoreApply.processing" /><FieldError v-if="restoreApply.errors.password">{{ restoreApply.errors.password }}</FieldError></Field>
                <label class="flex items-center gap-2 text-sm"><input v-model="restoreApply.confirm" type="checkbox" :disabled="restoreApply.processing" />I understand this replaces the current workspace, including secrets and provider connections.</label>
                <FieldError v-if="restoreApply.errors.backup">{{ restoreApply.errors.backup }}</FieldError><FieldError v-if="restoreApply.errors.confirm">{{ restoreApply.errors.confirm }}</FieldError>
                <div><Button type="submit" variant="destructive" :disabled="restoreApply.processing || !restoreApply.confirm">{{ restoreApply.processing ? 'Restoring…' : 'Replace workspace' }}</Button></div>
            </form>
        </div>
    </div>
</template>
