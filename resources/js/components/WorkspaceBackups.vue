<script setup lang="ts">
import TextTransition from '@/components/TextTransition.vue';
import SecretPinInput from '@/components/SecretPinInput.vue';
import { toast } from 'vue-sonner';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Link, router, useHttp, usePage } from '@inertiajs/vue3';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

type Preview = { destination: string; exists: boolean } | null;
type RestorePreview = { source: string; created_at: string; projects: number; tasks: number; secrets: number; includes_secrets: boolean; project_defaults: { columns: { name: string; color: string | null }[] } | null; project_statuses: { names: string[] } } | null;
type BackupPreferences = { folder: string | null; last_export_at: string | null; last_export_path: string | null };
type BackupMetadata = { revision: number; backups: BackupPreferences };
const props = defineProps<{ native: boolean; pinSet?: boolean | null; preferencesRevision?: number; prepareRestore?: () => Promise<boolean> }>();
const emit = defineEmits<{ saved: [revision: number]; restored: [] }>();
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
const folderDepartureOpen = ref(false);
const page = usePage();
let pendingNavigation: string | null = null;
let disposed = false;
const rememberedFolder = router.restore('settings:backups:folder') as { folder: string | null; revision: number; baseline: string | null } | null;
const folderDirty = computed(() => folderForm.isDirty);
watch(() => [folderForm.folder, folderForm.revision], () => {
    if (!preferencesReady.value) return;
    router.remember(folderDirty.value ? { folder: folderForm.folder, revision: folderForm.revision, baseline: backupPreferences.value.folder } : null, 'settings:backups:folder');
});
function invalidateRestore() {
    restorePreview.value = null;
    restoreApply.confirm = false;
    restoreApply.password = '';
    restoreApplyPassword.value = '';
}
watch(() => props.preferencesRevision, (value, previous) => {
    if (value !== previous && restorePreview.value) { invalidateRestore(); error.value = 'Settings changed. Preview the backup again before replacing the workspace.'; }
});
function discardFolder() {
    folderForm.folder = backupPreferences.value.folder;
    folderForm.defaults();
    folderForm.clearErrors();
    router.remember(null, 'settings:backups:folder');
    resumeDeparture();
}
function resumeDeparture() {
    folderDepartureOpen.value = false;
    const destination = pendingNavigation;
    pendingNavigation = null;
    if (destination) router.visit(destination);
}
function keepFolder() { folderDepartureOpen.value = false; pendingNavigation = null; }
const stopNavigationGuard = router.on('before', event => {
    if (event.detail.visit.method !== 'get' || (!folderDirty.value && !folderForm.processing)) return;
    const current = new URL(page.url ?? '/', 'https://orbit.local');
    const target = new URL(String(event.detail.visit.url), current);
    if (target.pathname === current.pathname && target.search === current.search) return;
    pendingNavigation = String(event.detail.visit.url);
    folderDepartureOpen.value = true;
    return false;
});
if (typeof window !== 'undefined') {
    const warnUnsaved = (event: BeforeUnloadEvent) => { if (folderDirty.value || folderForm.processing) { event.preventDefault(); event.returnValue = ''; } };
    window.addEventListener('beforeunload', warnUnsaved);
    onBeforeUnmount(() => window.removeEventListener('beforeunload', warnUnsaved));
}

async function loadPreferences(reviewDraft = false) {
    if (metadata.processing || folderForm.processing) return;
    const draft = folderForm.folder;
    try {
        const result = await metadata.get('/backups/preferences');
        if (!result || disposed) return;
        backupPreferences.value = result.backups;
        folderForm.folder = result.backups.folder;
        folderForm.revision = result.revision;
        folderForm.defaults();
        folderForm.clearErrors();
        if (reviewDraft) {
            folderForm.folder = draft;
        } else if (rememberedFolder) {
            folderForm.folder = rememberedFolder.folder;
            if (rememberedFolder.baseline !== result.backups.folder) {
                folderForm.revision = rememberedFolder.revision;
                folderForm.setError('revision', 'The saved folder changed. Review the saved folder or discard this draft before saving.');
            }
        }
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
    if (folderForm.processing || folderPicker.processing || folderForm.errors.revision) return;
    const submittedFolder = folderForm.folder;
    error.value = '';
    try {
        const result = await folderForm.put('/settings/backups', { onHttpException: response => { error.value = JSON.parse(response.data).message ?? 'Backup preferences could not be saved.'; } });
        if (!result) return;
        folderForm.revision = result.preferences.revision;
        backupPreferences.value.folder = submittedFolder;
        folderForm.defaults({ revision: result.preferences.revision, folder: submittedFolder });
        if (!folderForm.isDirty) { router.remember(null, 'settings:backups:folder'); resumeDeparture(); }
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
    if (!destination.value || form.processing || (form.include_secrets && props.pinSet !== true)) return;
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
    if (restore.processing || restoreApply.processing) return;
    invalidateRestore();
    restoreApply.clearErrors();
    restore.clearErrors();
    error.value = '';
    if (props.prepareRestore && !await props.prepareRestore()) return;
    restore.password = restorePassword.value;
    restorePassword.value = '';
    try {
        const result = await restore.post('/backups/restore/preview');
        if (!result || disposed) return;
        restorePreview.value = result.preview;
    } catch {
        if (!restore.hasErrors) error.value = 'The backup could not be unlocked. Check its password and try again.';
    } finally {
        restore.password = '';
    }
}
async function applyRestore() {
    if (!restorePreview.value || restore.processing || restoreApply.processing) return;
    error.value = '';
    restoreApply.password = restoreApplyPassword.value;
    restoreApplyPassword.value = '';
    try {
        const result = await restoreApply.post('/backups/restore');
        if (!result) { error.value = Object.values(restoreApply.errors).flat().join(' ') || 'Restore failed. Preview the backup again.'; return; }
        invalidateRestore();
        router.reload({ onSuccess: () => emit('restored') });
    } catch {
        if (!restoreApply.hasErrors) error.value = 'The backup could not be restored. Preview it again and retry.';
    } finally {
        invalidateRestore();
        restoreApply.password = '';
    }
}
onMounted(loadPreferences);
onBeforeUnmount(() => { disposed = true; stopNavigationGuard(); metadata.cancel(); folderPicker.cancel(); folderForm.cancel(); preview.cancel(); form.cancel(); pinForm.cancel(); restore.cancel(); restoreApply.cancel(); pinForm.pin = ''; clearPassword(); restore.password = ''; restorePassword.value = ''; restoreApply.password = ''; restoreApplyPassword.value = ''; });
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
                        :disabled="!preferencesReady || folderForm.processing || folderPicker.processing || metadata.processing || !!folderForm.errors.revision || !folderForm.isDirty">
                        <TextTransition :text="folderForm.processing ? 'Saving…' : 'Save'" />
                    </Button>
                </div>
                <Alert v-if="folderForm.errors.revision">
                    <AlertDescription>
                        {{ folderForm.errors.revision }}
                        <div class="mt-2 flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="metadata.processing || folderForm.processing"
                                @click="loadPreferences(true)">
                                Review saved folder
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                :disabled="metadata.processing || folderForm.processing"
                                @click="discardFolder">
                                Discard draft
                            </Button>
                        </div>
                    </AlertDescription>
                </Alert>
                <FieldDescription
                    v-if="folderDirty"
                    class="break-all">
                    Saved folder: {{ backupPreferences.folder ?? 'Choose a destination for each export' }}
                </FieldDescription>
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
                <p
                    v-if="form.include_secrets && pinSet !== true"
                    class="text-sm text-muted-foreground">
                    {{ pinSet === false ? 'Set up a secrets PIN before including secrets.' : 'PIN status is unavailable. Reopen Security to check it.' }} <Link
                        href="/settings?section=security"
                        class="underline">
                        Open Security
                    </Link>
                </p>
                <Field
                    v-if="form.include_secrets && pinSet === true"
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
                        aria-describedby="backup-password-help"
                        variant="filled"
                        type="password"
                        autocomplete="new-password"
                        :spellcheck="false"
                        minlength="12"
                        maxlength="4096"
                        required
                        :disabled="!native || form.processing" /><FieldDescription id="backup-password-help">
                            Use at least 12 characters. Keep this password: it is required to restore the backup.
                        </FieldDescription><FieldError v-if="form.errors.password">
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
                        :disabled="!native || !destination || pinForm.processing || form.processing || (form.include_secrets && pinSet !== true)">
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
                            aria-describedby="restore-password-help"
                            variant="filled"
                            type="password"
                            autocomplete="current-password"
                            :spellcheck="false"
                            minlength="12"
                            maxlength="4096"
                            required
                            :disabled="!native || restore.processing || restoreApply.processing" /><FieldDescription id="restore-password-help">
                                Enter the password used when exporting this backup, at least 12 characters. This is separate from your Secrets PIN.
                            </FieldDescription><FieldError v-if="restore.errors.password">
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
                            :disabled="!native || restore.processing || restoreApply.processing">
                            <TextTransition :text="restore.processing ? 'Validating…' : error ? 'Preview again' : 'Choose backup and preview'" />
                        </Button>
                    </div>
                </form>
                <FieldDescription
                    v-if="restorePreview"
                    class="mt-6">
                    <span class="block break-all">Backup: {{ restorePreview.source }}</span>
                    Created {{ new Date(restorePreview.created_at).toLocaleString() }} · {{ restorePreview.projects }} projects · {{ restorePreview.tasks }} cards · {{ restorePreview.secrets }} secrets {{ restorePreview.includes_secrets ? 'included' : 'not included' }}.
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
                <Alert
                    v-if="restorePreview"
                    class="mt-6">
                    <AlertDescription>All provider connections will be removed and must be reconnected. Backups never include provider tokens. {{ restorePreview.includes_secrets ? 'Current secrets will be replaced with the secrets in this backup.' : 'This backup excludes secrets: all current secrets will be removed.' }}</AlertDescription>
                </Alert>
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
                        :disabled="restoreApply.processing" />I understand this replaces the current workspace, and removes all provider connections.</label>
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
            <p
                v-if="folderDirty"
                role="status"
                class="text-sm text-muted-foreground">
                Unsaved backup folder
            </p>
            <FieldError v-if="folderForm.errors.revision">
                {{ folderForm.errors.revision }}
            </FieldError>
            <Button
                v-if="folderDirty"
                variant="outline"
                :disabled="folderForm.processing"
                @click="discardFolder">
                Discard folder changes
            </Button>
            <Dialog
                :open="folderDepartureOpen"
                @update:open="open => { if (!open) keepFolder(); }">
                <DialogContent>
                    <DialogHeader><DialogTitle>Unsaved backup folder</DialogTitle><DialogDescription>Save or discard your preferred folder before leaving.</DialogDescription></DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            :disabled="folderForm.processing"
                            @click="keepFolder">
                            Stay
                        </Button>
                        <Button
                            variant="destructive"
                            :disabled="folderForm.processing"
                            @click="discardFolder">
                            Discard changes
                        </Button>
                        <Button
                            :disabled="folderForm.processing || !!folderForm.errors.revision"
                            @click="saveFolder">
                            Save folder
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </CardContent>
    </Card>
</template>
