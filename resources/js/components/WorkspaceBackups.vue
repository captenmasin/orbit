<script setup lang="ts">
import TextTransition from '@/components/TextTransition.vue';
import SecretPinInput from '@/components/SecretPinInput.vue';
import { toast } from 'vue-sonner';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { router, useHttp } from '@inertiajs/vue3';
import { Checkbox } from '@/components/ui/checkbox';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';

type Preview = { destination: string; exists: boolean } | null;
type RestorePreview = { created_at: string; projects: number; tasks: number; secrets: number; includes_secrets: boolean; project_defaults: { columns: { name: string; color: string | null }[] } | null; project_statuses: { names: string[] } } | null;
type BackupPreferences = { folder: string | null; last_export_at: string | null; last_export_path: string | null };
type BackupMetadata = { revision: number; backups: BackupPreferences };
const props = defineProps<{ native: boolean }>();
const emit = defineEmits<{ saved: [revision: number] }>();
const metadata = useHttp<Record<string, never>, BackupMetadata>({});
const folderPicker = useHttp<Record<string, never>, { folder: string | null }>({});
const folderForm = useHttp<{ revision: number; folder: string | null }, { preferences: { revision: number } }>({ revision: 1, folder: null });
const preferencesReady = ref(false);
const backupPreferences = ref<BackupPreferences>({ folder: null, last_export_at: null, last_export_path: null });
const preview = useHttp<Record<string, never>, { preview: Preview; folder_unavailable?: boolean }>({});
const form = useHttp<{ include_secrets: boolean; password: string; password_confirmation: string; overwrite: boolean; backup: string }, Partial<BackupMetadata>>({ include_secrets: false, password: '', password_confirmation: '', overwrite: false, backup: '' });
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

async function loadPreferences() {
    try {
        const result = await metadata.get('/backups/preferences');
        if (!result) return;
        backupPreferences.value = result.backups;
        folderForm.folder = result.backups.folder;
        folderForm.revision = result.revision;
        folderForm.defaults();
        preferencesReady.value = true;
        emit('saved', result.revision);
    } catch {
        toast.error('Backup preferences could not be loaded. Reload and try again.');
    }
}
async function chooseFolder() {
    try {
        const result = await folderPicker.post('/backups/folder');
        if (result?.folder) folderForm.folder = result.folder;
    } catch {
        if (!folderPicker.hasErrors) toast.error('The folder picker could not open. Try again.');
    }
}
async function saveFolder() {
    error.value = '';
    try {
        const result = await folderForm.put('/settings/backups', { onHttpException: response => { error.value = JSON.parse(response.data).message ?? 'Backup preferences could not be saved.'; } });
        if (!result) return;
        folderForm.revision = result.preferences.revision;
        backupPreferences.value.folder = folderForm.folder;
        folderForm.defaults();
        emit('saved', result.preferences.revision);
        toast.success('Backup preferences saved.');
    } catch {
        if (!folderForm.hasErrors) toast.error('Backup preferences could not be saved. Try again.');
    }
}
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
        if (!result) { toast.error(String(Object.values(preview.errors)[0] ?? 'The backup destination could not be selected. Try again.')); return; }
        destination.value = result.preview;
        form.overwrite = false;
        if (result.folder_unavailable) toast('The preferred folder is unavailable. This export uses your selected destination.');
    } catch {
        if (!preview.hasErrors) toast.error('The backup destination could not be selected. Try again.');
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
        if (result.backups && result.revision) {
            backupPreferences.value = result.backups;
            folderForm.revision = result.revision;
            emit('saved', result.revision);
        }
        toast.success('Backup exported.');
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
onMounted(loadPreferences);
onBeforeUnmount(() => { metadata.cancel(); folderPicker.cancel(); folderForm.cancel(); preview.cancel(); form.cancel(); pinForm.cancel(); restore.cancel(); restoreApply.cancel(); pinForm.pin = ''; clearPassword(); restore.password = ''; restorePassword.value = ''; restoreApply.password = ''; restoreApplyPassword.value = ''; });
</script>

<template>
    <Card
        as="section"
        aria-labelledby="workspace-backups-title">
        <CardHeader>
            <h2
                id="workspace-backups-title"
                class="text-sm font-normal">
                Backups &amp; restore
            </h2>
        </CardHeader>
        <CardContent class="gap-6">
            <FieldDescription>Create a password-protected Orbit backup of your workspace and board defaults.</FieldDescription>
            <Alert v-if="!native">
                <AlertDescription>Create backups in the desktop app.</AlertDescription>
            </Alert>
            <Alert
                v-if="error"
                variant="destructive">
                <AlertDescription>{{ error }}</AlertDescription>
            </Alert>
            <form
                class="grid gap-6"
                @submit.prevent="saveFolder">
                <Field :data-invalid="!!folderForm.errors.folder || !!folderPicker.errors.folder">
                    <FieldLabel for="backup-folder">
                        Preferred backup folder
                    </FieldLabel><Input
                        id="backup-folder"
                        variant="filled"
                        :model-value="folderForm.folder ?? ''"
                        readonly
                        placeholder="Choose a destination for each export" /><FieldError v-if="folderForm.errors.folder || folderPicker.errors.folder">
                            {{ folderForm.errors.folder || folderPicker.errors.folder }}
                        </FieldError>
                </Field>
                <div class="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="!native || !preferencesReady || folderPicker.processing || folderForm.processing"
                        @click="chooseFolder">
                        Choose folder
                    </Button><Button
                        v-if="folderForm.folder"
                        type="button"
                        variant="ghost"
                        :disabled="folderForm.processing"
                        @click="folderForm.folder = null">
                        Clear
                    </Button><Button
                        type="submit"
                        :disabled="!preferencesReady || folderForm.processing || !folderForm.isDirty">
                        <TextTransition :text="folderForm.processing ? 'Saving…' : 'Save'" />
                    </Button>
                </div>
                <FieldDescription v-if="backupPreferences.last_export_at">
                    Last export: {{ new Date(backupPreferences.last_export_at).toLocaleString() }}<span class="block break-all">{{ backupPreferences.last_export_path }}</span>
                </FieldDescription>
                <FieldDescription v-else>
                    No successful export recorded.
                </FieldDescription>
            </form>
            <form
                class="grid gap-6"
                @submit.prevent="exportBackup">
                <label class="flex items-start gap-3 text-sm"><Checkbox
                    v-model="form.include_secrets"
                    :disabled="!native || form.processing" /><span><span class="font-medium">Include project secrets</span><span class="block text-muted-foreground">Secrets are encrypted in the backup with this password. Provider tokens are never included.</span></span></label>
                <Field
                    v-if="form.include_secrets"
                    :data-invalid="!!pinForm.errors.pin">
                    <FieldLabel for="backup-pin">
                        Secrets PIN
                    </FieldLabel><SecretPinInput
                        id="backup-pin"
                        v-model="pinForm.pin"
                        :disabled="!native || pinForm.processing || form.processing"
                        :invalid="!!pinForm.errors.pin" /><FieldError v-if="pinForm.errors.pin">
                            {{ pinForm.errors.pin }}
                        </FieldError>
                </Field>
                <Field :data-invalid="!!form.errors.password">
                    <FieldLabel for="backup-password">
                        Backup password
                    </FieldLabel><Input
                        id="backup-password"
                        v-model="password"
                        variant="filled"
                        type="password"
                        autocomplete="new-password"
                        :spellcheck="false"
                        minlength="12"
                        maxlength="4096"
                        required
                        :disabled="!native || form.processing" /><FieldError v-if="form.errors.password">
                            {{ form.errors.password }}
                        </FieldError>
                </Field>
                <Field :data-invalid="!!form.errors.password_confirmation">
                    <FieldLabel for="backup-password-confirmation">
                        Confirm password
                    </FieldLabel><Input
                        id="backup-password-confirmation"
                        v-model="confirmation"
                        variant="filled"
                        type="password"
                        autocomplete="new-password"
                        :spellcheck="false"
                        minlength="12"
                        maxlength="4096"
                        required
                        :disabled="!native || form.processing" /><FieldError v-if="form.errors.password_confirmation">
                            {{ form.errors.password_confirmation }}
                        </FieldError>
                </Field>
                <div class="flex flex-wrap items-center gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="!native || preview.processing || form.processing"
                        @click="chooseDestination">
                        <TextTransition :text="destination ? 'Choose another destination' : 'Choose destination'" />
                    </Button><span
                        v-if="destination"
                        class="text-sm text-muted-foreground">{{ destination.destination }}</span>
                </div>
                <label
                    v-if="destination?.exists"
                    class="flex items-center gap-2 text-sm"><Checkbox
                        v-model="form.overwrite"
                        :disabled="form.processing" />Replace the existing backup</label><FieldError v-if="form.errors.overwrite">
                            {{ form.errors.overwrite }}
                        </FieldError>
                <FieldError v-if="form.errors.backup">
                    {{ form.errors.backup }}
                </FieldError>
                <div>
                    <Button
                        type="submit"
                        :disabled="!native || !destination || pinForm.processing || form.processing">
                        <TextTransition :text="pinForm.processing ? 'Unlocking…' : form.processing ? 'Encrypting…' : 'Export backup'" />
                    </Button>
                </div>
            </form>
            <div class="border-t pt-6">
                <h3 class="text-sm font-normal">
                    Restore preview
                </h3><FieldDescription class="mt-1">
                    Validate an Orbit backup before restoring. This does not change your workspace.
                </FieldDescription>
                <form
                    class="mt-6 grid gap-6"
                    @submit.prevent="previewRestore">
                    <Field :data-invalid="!!restore.errors.password">
                        <FieldLabel for="restore-password">
                            Backup password
                        </FieldLabel><Input
                            id="restore-password"
                            v-model="restorePassword"
                            variant="filled"
                            type="password"
                            autocomplete="current-password"
                            :spellcheck="false"
                            minlength="12"
                            maxlength="4096"
                            required
                            :disabled="!native || restore.processing" /><FieldError v-if="restore.errors.password">
                                {{ restore.errors.password }}
                            </FieldError>
                    </Field>
                    <FieldError v-if="restore.errors.backup">
                        {{ restore.errors.backup }}
                    </FieldError>
                    <div>
                        <Button
                            type="submit"
                            variant="outline"
                            :disabled="!native || restore.processing">
                            <TextTransition :text="restore.processing ? 'Validating…' : 'Choose backup and preview'" />
                        </Button>
                    </div>
                </form>
                <FieldDescription
                    v-if="restorePreview"
                    class="mt-6">
                    Created {{ new Date(restorePreview.created_at).toLocaleString() }} · {{ restorePreview.projects }} projects · {{ restorePreview.tasks }} tasks · {{ restorePreview.secrets }} secrets {{ restorePreview.includes_secrets ? 'included' : 'not included' }}.
                </FieldDescription>
                <div
                    v-if="restorePreview?.project_defaults"
                    class="mt-3 text-sm">
                    <p class="font-medium">
                        Incoming default board columns
                    </p><ol class="mt-1 list-inside list-decimal text-muted-foreground">
                        <li
                            v-for="(column, index) in restorePreview.project_defaults.columns"
                            :key="index">
                            {{ column.name }}<span v-if="column.color"> · {{ column.color }}</span>
                        </li>
                    </ol>
                </div>
                <div
                    v-if="restorePreview?.project_statuses"
                    class="mt-3 text-sm">
                    <p class="font-medium">
                        Incoming project statuses
                    </p><p class="mt-1 text-muted-foreground">
                        {{ restorePreview.project_statuses.names.join(', ') }}, Archived
                    </p>
                </div>
                <FieldDescription
                    v-else-if="restorePreview"
                    class="mt-3">
                    This older backup keeps your current board defaults.
                </FieldDescription>
                <form
                    v-if="restorePreview"
                    class="mt-6 grid gap-6"
                    @submit.prevent="applyRestore">
                    <Field :data-invalid="!!restoreApply.errors.password">
                        <FieldLabel for="restore-apply-password">
                            Re-enter password to replace this workspace
                        </FieldLabel><Input
                            id="restore-apply-password"
                            v-model="restoreApplyPassword"
                            variant="filled"
                            type="password"
                            autocomplete="current-password"
                            :spellcheck="false"
                            minlength="12"
                            maxlength="4096"
                            required
                            :disabled="restoreApply.processing" /><FieldError v-if="restoreApply.errors.password">
                                {{ restoreApply.errors.password }}
                            </FieldError>
                    </Field>
                    <label class="flex items-center gap-2 text-sm"><Checkbox
                        v-model="restoreApply.confirm"
                        :disabled="restoreApply.processing" />I understand this replaces the current workspace, including secrets and provider connections.</label>
                    <FieldError v-if="restoreApply.errors.backup">
                        {{ restoreApply.errors.backup }}
                    </FieldError><FieldError v-if="restoreApply.errors.confirm">
                        {{ restoreApply.errors.confirm }}
                    </FieldError>
                    <div>
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="restoreApply.processing || !restoreApply.confirm">
                            <TextTransition :text="restoreApply.processing ? 'Restoring…' : 'Replace workspace'" />
                        </Button>
                    </div>
                </form>
            </div>
        </CardContent>
    </Card>
</template>
