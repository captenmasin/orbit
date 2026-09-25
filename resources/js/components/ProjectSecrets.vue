<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router, useHttp } from '@inertiajs/vue3';
import { useDocumentVisibility, useWindowFocus } from '@vueuse/core';
import { toast } from 'vue-sonner';
import OpenTargetButton from '@/components/OpenTargetButton.vue';
import SecretPinInput from '@/components/SecretPinInput.vue';
import { CopyIcon, LockKeyholeIcon, PencilIcon, PlusIcon, SearchIcon, Trash2Icon } from '@lucide/vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { ContextMenu, ContextMenuContent, ContextMenuItem, ContextMenuTrigger } from '@/components/ui/context-menu';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import ChoiceSelect from '@/components/ChoiceSelect.vue';
import type { Project, ProjectSecret } from '@/types';

const props = defineProps<{ project: Project; native: boolean; targetSecretId?: string | null }>();
const query = ref('');
const environment = ref('');
const service = ref('');
const metadataOnly = ref(false);
const editorOpen = ref(false);
const pasteOpen = ref(false);
const bulkOpen = ref(false);
const selectedIds = ref<string[]>([]);
const bulkField = ref<'environment' | 'service'>('environment');
const bulkEnvironment = ref('Default');
const bulkService = ref('');
const formEnvironmentChoice = ref('Default');
const importOpen = ref(false);
const exportOpen = ref(false);
const editing = ref<ProjectSecret | null>(null);
const removing = ref<ProjectSecret | null>(null);
const unlocked = ref(false);
const vaultLoading = ref(true);
const unlocking = ref(false);
const pinSet = ref(false);
const values = ref<Record<string, string>>({});
const pinForm = useHttp({ pin: '', pin_confirmation: '' });
const error = ref('');
const notice = ref('');
let vaultTimer: ReturnType<typeof setTimeout> | undefined;
let clipboardTimer: ReturnType<typeof setTimeout> | undefined;
const visible = useDocumentVisibility();
const focused = useWindowFocus();
const form = useHttp({ environment: 'Default', name: '', value: '', service: '', description: '', management_url: '', project_revision: 1, revision: 1 });
const pasteForm = useHttp({ environment: 'Default', service: '', entries: '', project_revision: 1 });
const bulkForm = useHttp({ project_revision: 1, secrets: [] as { id: string; revision: number }[], environment: undefined as string | undefined, service: undefined as string | undefined });
const importPreviewForm = useHttp<{ environment: string; entries: string }, { preview: { source: string; entries: { name: string; collision: boolean }[] } | null }>({ environment: 'Default', entries: '' });
const importForm = useHttp<{ environment: string; project_revision: number; entries: string }, { saved: boolean; imported: number; skipped: number }>({ environment: 'Default', project_revision: 1, entries: '' });
const exportPreviewForm = useHttp<{ environment: string; names: string[] }, { preview: { destination: string; exists: boolean } | null }>({ environment: 'Default', names: [] });
const exportForm = useHttp({ environment: 'Default', names: [] as string[], project_revision: 1, overwrite: false });
const removal = useHttp({ project_revision: 1, revision: 1 });
const clipboard = useHttp({ revision: 1 });
const importPreview = ref<{ source: string; entries: { name: string; collision: boolean }[] } | null>(null);
const exportPreview = ref<{ destination: string; exists: boolean } | null>(null);
const exportEnvironment = ref('Default');
const exportNames = ref<string[]>([]);
const secrets = computed(() => props.project.secrets ?? []);
const environments = computed(() => [...new Set(secrets.value.map(secret => secret.environment))].sort((a, b) => a.localeCompare(b)));
const environmentOptions = computed(() => [...new Set(['Default', ...environments.value])]);
const services = computed(() => [...new Set(secrets.value.map(secret => secret.service).filter((value): value is string => !!value))].sort((a, b) => a.localeCompare(b)));
const rows = computed(() => secrets.value.filter(secret => (!environment.value || secret.environment === environment.value)
    && (!service.value || secret.service === service.value)
    && `${secret.name} ${secret.environment} ${secret.service ?? ''} ${secret.description ?? ''} ${secret.management_url ?? ''}`.toLowerCase().includes(query.value.toLowerCase())));
const groups = computed(() => {
    const grouped = new Map<string, ProjectSecret[]>();
    for (const secret of rows.value) {
        const label = secret.service || 'Uncategorized';
        if (!grouped.has(label)) grouped.set(label, []);
        grouped.get(label)!.push(secret);
    }
    return [...grouped].map(([label, secrets]) => ({ label, secrets }));
});

function reload() {
    router.reload({ only: ['selectedProject'] });
}
async function loadValues() {
    const response = await fetch(`/projects/${props.project.id}/secrets/values`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (!response.ok) throw new Error('Secret values could not be loaded.');
    const body = await response.json() as { values: Record<string, string> };
    values.value = body.values;
}
function lockVault() {
    const wasUnlocked = unlocked.value;
    if (vaultTimer) clearTimeout(vaultTimer);
    vaultTimer = undefined;
    values.value = {};
    unlocked.value = false;
    form.value = '';
    editorOpen.value = false;
    if (wasUnlocked) void fetch('/secrets/lock', { method: 'POST', credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': decodeURIComponent(document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '') } });
}
async function refreshVaultStatus() {
    try {
        const response = await fetch('/secrets/unlock/status', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!response.ok) throw new Error();
        const status = await response.json() as { pin_set: boolean; unlocked: boolean };
        pinSet.value = status.pin_set;
        if (status.unlocked) { await loadValues(); unlocked.value = true; vaultTimer = setTimeout(lockVault, 300000); }
    } catch { error.value = 'The secret vault could not be loaded.'; }
    finally { vaultLoading.value = false; }
}
async function unlockVault() {
    unlocking.value = true;
    error.value = '';
    try {
        const result = await pinForm.post(pinSet.value ? '/secrets/unlock' : '/secrets/pin', { onHttpException: response => { error.value = JSON.parse(response.data).message ?? 'PIN unlock failed.'; } });
        if (!result) { error.value = Object.values(pinForm.errors).flat().join(' '); return; }
        pinSet.value = true;
        await loadValues();
        unlocked.value = true;
        vaultTimer = setTimeout(lockVault, 300000);
        if (visible.value !== 'visible' || !focused.value) lockVault();
    } catch (cause) { if (!error.value) error.value = cause instanceof Error ? cause.message : 'PIN unlock failed.'; }
    finally { pinForm.pin = ''; pinForm.pin_confirmation = ''; pinForm.defaults('pin', ''); pinForm.defaults('pin_confirmation', ''); unlocking.value = false; }
}
function closeEditor() {
    editorOpen.value = false;
    editing.value = null;
    form.value = '';
    form.clearErrors();
}
function closePaste() {
    pasteOpen.value = false;
    pasteForm.entries = '';
    pasteForm.clearErrors();
}
function closeImport() {
    importOpen.value = false;
    importPreview.value = null;
    importPreviewForm.clearErrors();
    importForm.clearErrors();
}
function lockIfPickerLeftUnfocused() {
    if (!focused.value) setTimeout(() => {
        if (!focused.value && !importPreviewForm.processing && !exportPreviewForm.processing && unlocked.value) lockVault();
    }, 500);
}
function openImport() {
    closeImport();
    importPreviewForm.environment = environment.value || 'Default';
    error.value = '';
    importOpen.value = true;
}
function openExport() {
    exportEnvironment.value = environment.value || environments.value[0] || 'Default';
    exportNames.value = secrets.value.filter(secret => secret.environment === exportEnvironment.value).map(secret => secret.name);
    exportPreview.value = null;
    exportPreviewForm.clearErrors();
    exportForm.clearErrors();
    exportOpen.value = true;
}
function closeExport() {
    exportOpen.value = false;
    exportPreview.value = null;
    exportForm.overwrite = false;
    exportPreviewForm.clearErrors();
    exportForm.clearErrors();
}
function toggleExport(name: string) {
    exportNames.value = exportNames.value.includes(name) ? exportNames.value.filter(item => item !== name) : [...exportNames.value, name];
}
function changeExportEnvironment() {
    exportNames.value = secrets.value.filter(secret => secret.environment === exportEnvironment.value).map(secret => secret.name);
    exportPreview.value = null;
    exportForm.overwrite = false;
}
function edit(secret: ProjectSecret | null = null, metadata = false) {
    editing.value = secret;
    metadataOnly.value = metadata;
    Object.assign(form, { environment: secret?.environment ?? (environment.value || 'Default'), name: secret?.name ?? '', value: '', service: secret?.service ?? '', description: secret?.description ?? '', management_url: secret?.management_url ?? '', project_revision: props.project.revision, revision: secret?.revision ?? 1 });
    formEnvironmentChoice.value = environmentOptions.value.includes(form.environment) ? form.environment : '__new__';
    form.clearErrors();
    error.value = '';
    editorOpen.value = true;
}
function chooseFormEnvironment(value: string) {
    formEnvironmentChoice.value = value;
    form.environment = value === '__new__' ? '' : value;
}
function toggleSelected(id: string) {
    selectedIds.value = selectedIds.value.includes(id) ? selectedIds.value.filter(item => item !== id) : [...selectedIds.value, id];
}
function toggleVisible() {
    const visibleIds = rows.value.map(secret => secret.id);
    selectedIds.value = visibleIds.every(id => selectedIds.value.includes(id))
        ? selectedIds.value.filter(id => !visibleIds.includes(id))
        : [...new Set([...selectedIds.value, ...visibleIds])];
}
function openBulk(field: 'environment' | 'service') {
    bulkField.value = field;
    bulkEnvironment.value = environment.value || 'Default';
    bulkService.value = service.value;
    bulkForm.clearErrors();
    bulkOpen.value = true;
}
async function saveBulk() {
    bulkForm.project_revision = props.project.revision;
    bulkForm.secrets = secrets.value.filter(secret => selectedIds.value.includes(secret.id)).map(secret => ({ id: secret.id, revision: secret.revision }));
    bulkForm.environment = bulkField.value === 'environment' ? bulkEnvironment.value : undefined;
    bulkForm.service = bulkField.value === 'service' ? bulkService.value : undefined;
    try {
        const result = await bulkForm.put(`/projects/${props.project.id}/secrets/bulk`);
        if (!result) return;
        bulkOpen.value = false;
        selectedIds.value = [];
        reload();
    } catch { if (!bulkForm.hasErrors) toast.error('The selected secrets could not be updated. Reload and try again.'); }
}
async function save() {
    error.value = '';
    form.project_revision = props.project.revision;
    if (editing.value) form.revision = editing.value.revision;
    try {
        const result = editing.value ? await form.put(`/projects/${props.project.id}/secrets/${editing.value.id}${metadataOnly.value ? '/metadata' : ''}`) : await form.post(`/projects/${props.project.id}/secrets`);
        if (!result) { error.value = Object.values(form.errors).flat().join(' '); return; }
        closeEditor();
        reload();
    } catch {
        if (!form.hasErrors) error.value = 'The secret could not be saved. Try again.';
    } finally {
        form.value = '';
        form.defaults('value', '');
    }
}
async function paste() {
    error.value = '';
    pasteForm.project_revision = props.project.revision;
    try {
        const result = await pasteForm.post(`/projects/${props.project.id}/secrets/paste`);
        if (!result) { error.value = Object.values(pasteForm.errors).flat().join(' '); return; }
        closePaste();
        reload();
    } catch {
        if (!pasteForm.hasErrors) error.value = 'The entries could not be saved. Try again.';
    } finally {
        pasteForm.entries = '';
        pasteForm.defaults('entries', '');
    }
}
async function previewImport() {
    error.value = '';
    try {
        const result = await importPreviewForm.post(`/projects/${props.project.id}/secrets/import/preview`);
        if (!result) { error.value = Object.values(importPreviewForm.errors).flat().join(' '); return; }
        importPreview.value = result.preview;
    } catch {
        if (!importPreviewForm.hasErrors) error.value = 'The .env file could not be previewed. Try again.';
    } finally {
        lockIfPickerLeftUnfocused();
    }
}
async function importEntries() {
    if (!importPreview.value) return;
    error.value = '';
    importForm.environment = importPreviewForm.environment;
    importForm.project_revision = props.project.revision;
    try {
        const result = await importForm.post(`/projects/${props.project.id}/secrets/import`, { onHttpException: response => {
            if (response.status === 423) {
                closeImport();
                lockVault();
                error.value = 'Secrets locked. Unlock with your PIN, then preview the file again.';
            }
        } });
        if (!result) { error.value = Object.values(importForm.errors).flat().join(' '); return; }
        environment.value = importForm.environment.trim();
        service.value = '';
        query.value = '';
        notice.value = `${result.imported} ${result.imported === 1 ? 'secret' : 'secrets'} imported. ${result.skipped} existing ${result.skipped === 1 ? 'entry' : 'entries'} skipped.`;
        closeImport();
        reload();
    } catch {
        if (!importForm.hasErrors && !error.value) error.value = 'The .env entries could not be imported. Preview the file again and retry.';
    }
}
async function previewExport() {
    error.value = '';
    exportPreviewForm.environment = exportEnvironment.value;
    exportPreviewForm.names = exportNames.value;
    try {
        const result = await exportPreviewForm.post(`/projects/${props.project.id}/secrets/export/preview`);
        if (!result) { error.value = Object.values(exportPreviewForm.errors).flat().join(' '); return; }
        exportPreview.value = result.preview;
    } catch {
        if (!exportPreviewForm.hasErrors) error.value = 'The export destination could not be previewed. Try again.';
    } finally {
        lockIfPickerLeftUnfocused();
    }
}
async function exportEntries() {
    if (!exportPreview.value) return;
    error.value = '';
    Object.assign(exportForm, { environment: exportEnvironment.value, names: exportNames.value, project_revision: props.project.revision });
    try {
        const result = await exportForm.post(`/projects/${props.project.id}/secrets/export`);
        if (!result) { error.value = Object.values(exportForm.errors).flat().join(' '); return; }
        closeExport();
        notice.value = 'Secrets exported as plaintext .env entries.';
    } catch {
        if (!exportForm.hasErrors) error.value = 'The .env file could not be exported. Choose the destination again and retry.';
    }
}
async function remove() {
    if (!removing.value) return;
    removal.project_revision = props.project.revision;
    removal.revision = removing.value.revision;
    try {
        const result = await removal.delete(`/projects/${props.project.id}/secrets/${removing.value.id}`);
        if (!result) { toast.error(String(Object.values(removal.errors)[0] ?? 'The secret could not be removed. Reload and try again.')); return; }
        removing.value = null;
        reload();
    } catch {
        toast.error('The secret could not be removed. Reload and try again.');
    }
}
async function clearCopied(secret: ProjectSecret) {
    clipboard.revision = secret.revision;
    try { await clipboard.delete(`/projects/${props.project.id}/secrets/${secret.id}/copy`); } catch { /* A newer clipboard value must remain untouched. */ }
}
async function copy(secret: ProjectSecret) {
    error.value = '';
    notice.value = '';
    clipboard.revision = secret.revision;
    try {
        const result = await clipboard.post(`/projects/${props.project.id}/secrets/${secret.id}/copy`);
        if (!result) { toast.error(String(Object.values(clipboard.errors)[0] ?? 'The secret could not be copied. Try again.')); return; }
        notice.value = `${secret.name} copied. Orbit will clear it in 30 seconds if it remains unchanged.`;
        if (clipboardTimer) clearTimeout(clipboardTimer);
        clipboardTimer = setTimeout(() => { void clearCopied(secret); }, 30000);
    } catch {
        toast.error('The secret could not be copied. Try again.');
    }
}
watch(() => props.targetSecretId, id => {
    if (!id) return;
    const secret = secrets.value.find(item => item.id === id);
    if (secret) edit(secret, true);
    else toast.error('This secret no longer exists in this project.');
}, { immediate: true });
watch([visible, focused], () => {
    if (unlocked.value && !unlocking.value && (visible.value !== 'visible' || (!focused.value && !importPreviewForm.processing && !exportPreviewForm.processing))) lockVault();
});
watch(secrets, () => { if (unlocked.value) void loadValues().catch(() => lockVault()); });
onMounted(() => { if (props.native) void refreshVaultStatus(); else vaultLoading.value = false; });
onBeforeUnmount(() => {
    pinForm.cancel();
    form.cancel();
    pasteForm.cancel();
    bulkForm.cancel();
    importPreviewForm.cancel();
    importForm.cancel();
    exportPreviewForm.cancel();
    exportForm.cancel();
    removal.cancel();
    lockVault();
    form.value = '';
    pasteForm.entries = '';
});
</script>

<template>
    <div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div><h2 class="text-xl font-semibold tracking-[-0.025em]">Secrets</h2><p class="mt-1 text-sm leading-6 text-muted-foreground">Store service credentials and the context you need to use them.</p></div>
        <Button v-if="unlocked" variant="outline" size="sm" @click="lockVault"><LockKeyholeIcon aria-hidden="true" />Lock secrets</Button>
    </div>
    <section v-if="!unlocked" class="grid gap-6 rounded-[1.25rem] border border-black/8 bg-neutral-50 p-6 dark:border-white/10 dark:bg-neutral-800 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-center lg:p-8" aria-labelledby="secret-vault-title">
        <div><span class="flex size-12 items-center justify-center rounded-2xl bg-background text-muted-foreground dark:bg-[#303030]"><LockKeyholeIcon class="size-5" aria-hidden="true" /></span><h3 id="secret-vault-title" class="mt-5 text-lg font-semibold tracking-[-0.02em]">Secrets are locked</h3><p class="mt-1 max-w-md text-sm leading-6 text-muted-foreground">{{ pinSet ? 'Enter your PIN to see secret values.' : 'Set a four digit PIN to lock your secrets.' }}</p></div>
        <form v-if="native" class="grid gap-3 rounded-2xl border border-black/10 bg-background p-5 dark:border-white/10" @submit.prevent="unlockVault">
            <Field><FieldLabel for="secret-pin">PIN</FieldLabel><SecretPinInput id="secret-pin" v-model="pinForm.pin" :disabled="vaultLoading || unlocking" :invalid="!!pinForm.errors.pin" /></Field>
            <Field v-if="!pinSet"><FieldLabel for="secret-pin-confirmation">Confirm PIN</FieldLabel><SecretPinInput id="secret-pin-confirmation" v-model="pinForm.pin_confirmation" :disabled="vaultLoading || unlocking" :invalid="!!pinForm.errors.pin_confirmation" /></Field>
            <Alert v-if="error" variant="destructive" class="text-left"><AlertDescription>{{ error }}</AlertDescription></Alert>
            <Button type="submit" :disabled="vaultLoading || unlocking || pinForm.pin.length !== 4 || (!pinSet && pinForm.pin_confirmation.length !== 4)">{{ vaultLoading ? 'Loading…' : unlocking ? 'Unlocking…' : pinSet ? 'Unlock secrets' : 'Set PIN and unlock' }}</Button>
        </form>
        <p v-if="!native" class="text-sm text-muted-foreground">Open the desktop app to unlock secrets.</p>
    </section>
    <div v-else class="space-y-5">
        <div class="flex flex-wrap gap-2"><Button :disabled="!native" @click="edit()"><PlusIcon aria-hidden="true" />Add secret</Button><Button variant="outline" :disabled="!native" @click="pasteForm.environment = environment || 'Default'; pasteForm.service = service; pasteOpen = true">Paste .env</Button><Button variant="outline" :disabled="!native" @click="openImport">Import .env</Button><Button variant="outline" :disabled="!native || !secrets.length" @click="openExport">Export .env</Button></div>
        <div class="flex flex-wrap items-end gap-3 border-b pb-4">
            <Field class="min-w-52 flex-[1_1_16rem]"><FieldLabel for="secret-search" class="sr-only">Search secrets</FieldLabel><div class="relative"><SearchIcon class="pointer-events-none absolute top-1/2 left-3.5 size-3.5 -translate-y-1/2 text-muted-foreground" aria-hidden="true" /><Input id="secret-search" v-model="query" placeholder="Search secrets" maxlength="255" class="h-9 rounded-full border-0 bg-muted pl-9 text-[13px] shadow-none focus-visible:ring-2 focus-visible:ring-ring/50 md:text-[13px]" /></div></Field>
            <Field class="min-w-40"><FieldLabel for="secret-environment">Environment</FieldLabel><ChoiceSelect id="secret-environment" v-model="environment" :options="[{ value: '', label: 'All environments' }, ...environments]" /></Field>
            <Field class="min-w-40"><FieldLabel for="secret-service">Service</FieldLabel><ChoiceSelect id="secret-service" v-model="service" :options="[{ value: '', label: 'All services' }, ...services]" /></Field>
        </div>
        <div v-if="selectedIds.length" class="flex flex-wrap items-center gap-2 rounded-xl border border-black/8 bg-neutral-50 p-3 text-sm dark:border-white/10 dark:bg-neutral-800"><span class="font-medium">{{ selectedIds.length }} selected</span><Button size="sm" variant="outline" @click="openBulk('environment')">Change environment</Button><Button size="sm" variant="outline" @click="openBulk('service')">Change category</Button><Button size="sm" variant="ghost" @click="selectedIds = []">Clear</Button></div>
        <p v-if="!native" class="text-sm text-muted-foreground">Manage secrets in the desktop app to use macOS credential storage.</p>
        <Alert v-if="error" variant="destructive"><AlertDescription>{{ error }}</AlertDescription></Alert>
        <p v-if="notice" role="status" class="text-sm text-muted-foreground">{{ notice }}</p>
        <div v-if="rows.length" class="overflow-x-auto rounded-[1.25rem] border border-black/8 bg-background dark:border-white/10"><Table>
            <TableHeader><TableRow><TableHead><Checkbox aria-label="Select visible secrets" :model-value="rows.length > 0 && rows.every(secret => selectedIds.includes(secret.id))" @update:model-value="toggleVisible" /></TableHead><TableHead>Name</TableHead><TableHead>Environment</TableHead><TableHead>Value</TableHead><TableHead>Context</TableHead><TableHead>Updated</TableHead><TableHead><span class="sr-only">Actions</span></TableHead></TableRow></TableHeader>
            <TableBody v-for="group in groups" :key="group.label"><TableRow><TableCell colspan="7" class="bg-muted/50"><h3 class="font-semibold">{{ group.label }}</h3></TableCell></TableRow><ContextMenu v-for="secret in group.secrets" :key="secret.id"><ContextMenuTrigger as-child><TableRow>
                <TableCell><Checkbox :aria-label="`Select ${secret.name} in ${secret.environment}`" :model-value="selectedIds.includes(secret.id)" @update:model-value="toggleSelected(secret.id)" /></TableCell>
                <TableCell class="font-medium break-all">{{ secret.name }}</TableCell><TableCell>{{ secret.environment }}</TableCell><TableCell class="max-w-72"><code class="whitespace-pre-wrap break-all text-xs">{{ values[secret.id] ?? '…' }}</code></TableCell><TableCell class="max-w-72 whitespace-normal"><p v-if="secret.description" class="text-muted-foreground">{{ secret.description }}</p><OpenTargetButton v-if="secret.management_url" :project-id="project.id" kind="secrets" :id="secret.id" :native="native" :href="secret.management_url" label="Manage service" /></TableCell><TableCell>{{ new Date(secret.updated_at).toLocaleString() }}</TableCell>
                <TableCell><div class="flex items-center gap-1"><Button size="icon-sm" variant="ghost" :aria-label="`Edit context for ${secret.name}`" @click="edit(secret, true)"><PencilIcon aria-hidden="true" /></Button><Button size="icon-sm" variant="outline" :aria-label="`Copy ${secret.name}`" :disabled="!native || clipboard.processing" @click="copy(secret)"><CopyIcon aria-hidden="true" /></Button><Button size="icon-sm" variant="ghost" :aria-label="`Replace value for ${secret.name}`" :disabled="!native" @click="edit(secret)"><PencilIcon aria-hidden="true" /></Button><Button size="icon-sm" variant="ghost" :aria-label="`Delete ${secret.name}`" :disabled="!native" @click="removing = secret"><Trash2Icon aria-hidden="true" /></Button></div></TableCell>
            </TableRow></ContextMenuTrigger><ContextMenuContent><ContextMenuItem @select="toggleSelected(secret.id)">{{ selectedIds.includes(secret.id) ? 'Deselect' : 'Select' }}</ContextMenuItem><ContextMenuItem @select="edit(secret, true)">Edit context</ContextMenuItem><ContextMenuItem :disabled="!native || clipboard.processing" @select="copy(secret)">Copy value</ContextMenuItem><ContextMenuItem :disabled="!native" @select="edit(secret)">Replace value</ContextMenuItem><ContextMenuItem :disabled="!native" variant="destructive" @select="removing = secret">Delete</ContextMenuItem></ContextMenuContent></ContextMenu></TableBody>
        </Table></div>
        <div v-else class="rounded-[1.25rem] border border-dashed border-black/10 px-6 py-14 text-center dark:border-white/10"><h3 class="text-base font-semibold">{{ secrets.length ? 'No matching secrets' : 'No secrets yet' }}</h3><p class="mt-1 text-sm text-muted-foreground">{{ secrets.length ? 'Try another search or change the filters.' : 'Add a secret to keep a credential with this project.' }}</p><Button v-if="!secrets.length && native" variant="outline" class="mt-5" @click="edit()"><PlusIcon aria-hidden="true" />Add secret</Button></div>
    </div>

    <Dialog :open="editorOpen" @update:open="open => { if (!open && !form.processing) closeEditor(); }"><DialogContent class="max-h-[85vh] overflow-y-auto"><DialogHeader><DialogTitle>{{ metadataOnly ? `Context for ${editing?.name}` : editing ? `Replace ${editing.name}` : 'Add secret' }}</DialogTitle><DialogDescription>{{ metadataOnly ? 'This context is not secret. Editing it does not reveal or replace the stored value.' : editing ? 'This replaces the stored value. Name and environment stay unchanged.' : 'Values are encrypted with macOS credential storage.' }}</DialogDescription></DialogHeader>
        <form class="grid gap-4" @submit.prevent="save">
            <template v-if="!editing"><Field :data-invalid="!!form.errors.environment"><FieldLabel for="secret-editor-environment">Environment</FieldLabel><ChoiceSelect id="secret-editor-environment" :model-value="formEnvironmentChoice" :options="[...environmentOptions, { value: '__new__', label: 'New…' }]" :aria-invalid="!!form.errors.environment" @update:model-value="chooseFormEnvironment" /><Input v-if="formEnvironmentChoice === '__new__'" v-model="form.environment" aria-label="New environment name" maxlength="100" required :aria-invalid="!!form.errors.environment" /><FieldError v-if="form.errors.environment">{{ form.errors.environment }}</FieldError></Field><Field :data-invalid="!!form.errors.name"><FieldLabel for="secret-editor-name">Name</FieldLabel><Input id="secret-editor-name" v-model="form.name" maxlength="255" pattern="[A-Za-z_][A-Za-z0-9_]*" required :aria-invalid="!!form.errors.name" /><FieldError v-if="form.errors.name">{{ form.errors.name }}</FieldError></Field></template>
            <template v-if="!editing || metadataOnly">
                <Field :data-invalid="!!form.errors.service"><FieldLabel for="secret-editor-service">Service / category</FieldLabel><Input id="secret-editor-service" v-model="form.service" maxlength="100" placeholder="Stripe, Cloudflare, Slack…" :aria-invalid="!!form.errors.service" /><FieldError v-if="form.errors.service">{{ form.errors.service }}</FieldError></Field>
                <Field :data-invalid="!!form.errors.description"><FieldLabel for="secret-editor-description">Description (non-secret context)</FieldLabel><Textarea id="secret-editor-description" v-model="form.description" maxlength="2000" :rows="3" :aria-invalid="!!form.errors.description" /><FieldError v-if="form.errors.description">{{ form.errors.description }}</FieldError></Field>
                <Field :data-invalid="!!form.errors.management_url"><FieldLabel for="secret-editor-url">Management URL</FieldLabel><Input id="secret-editor-url" v-model="form.management_url" maxlength="2048" :aria-invalid="!!form.errors.management_url" /><FieldError v-if="form.errors.management_url">{{ form.errors.management_url }}</FieldError></Field>
            </template>
            <Field v-if="!metadataOnly" :data-invalid="!!form.errors.value"><FieldLabel for="secret-editor-value">Value</FieldLabel><Textarea id="secret-editor-value" v-model="form.value" autocomplete="off" :spellcheck="false" :rows="6" :aria-invalid="!!form.errors.value" /><FieldError v-if="form.errors.value">{{ form.errors.value }}</FieldError></Field>
            <Alert v-if="error" variant="destructive"><AlertDescription>{{ error }}</AlertDescription></Alert>
            <DialogFooter><Button type="button" variant="outline" :disabled="form.processing" @click="closeEditor">Cancel</Button><Button type="submit" :disabled="form.processing">{{ form.processing ? 'Saving…' : metadataOnly ? 'Save context' : 'Save secret' }}</Button></DialogFooter>
        </form>
    </DialogContent></Dialog>
    <Dialog :open="pasteOpen" @update:open="open => { if (!open && !pasteForm.processing) closePaste(); }"><DialogContent><DialogHeader><DialogTitle>Paste .env entries</DialogTitle><DialogDescription>Supports comments, optional export, quoted values, and multiline quoted values. Expressions remain literal.</DialogDescription></DialogHeader>
        <form class="grid gap-4" @submit.prevent="paste">
            <Field :data-invalid="!!pasteForm.errors.environment"><FieldLabel for="secret-paste-environment">Environment</FieldLabel><Input id="secret-paste-environment" v-model="pasteForm.environment" maxlength="100" required :aria-invalid="!!pasteForm.errors.environment" /><FieldError v-if="pasteForm.errors.environment">{{ pasteForm.errors.environment }}</FieldError></Field>
            <Field :data-invalid="!!pasteForm.errors.service"><FieldLabel for="secret-paste-service">Service / category</FieldLabel><Input id="secret-paste-service" v-model="pasteForm.service" list="secret-categories" maxlength="100" placeholder="Optional" :aria-invalid="!!pasteForm.errors.service" /><FieldError v-if="pasteForm.errors.service">{{ pasteForm.errors.service }}</FieldError></Field>
            <Field :data-invalid="!!pasteForm.errors.entries"><FieldLabel for="secret-paste-entries">Entries</FieldLabel><Textarea id="secret-paste-entries" v-model="pasteForm.entries" autocomplete="off" :spellcheck="false" :rows="8" :aria-invalid="!!pasteForm.errors.entries" placeholder="APP_LOCALE=en&#10;APP_FALLBACK_LOCALE=en&#10;APP_FAKER_LOCALE=en_US" /><FieldError v-if="pasteForm.errors.entries">{{ pasteForm.errors.entries }}</FieldError></Field>
            <Alert v-if="error" variant="destructive"><AlertDescription>{{ error }}</AlertDescription></Alert>
            <DialogFooter><Button type="button" variant="outline" :disabled="pasteForm.processing" @click="closePaste">Cancel</Button><Button type="submit" :disabled="pasteForm.processing">{{ pasteForm.processing ? 'Saving…' : 'Save entries' }}</Button></DialogFooter>
        </form>
    </DialogContent></Dialog>
    <datalist id="secret-categories"><option v-for="item in services" :key="item" :value="item" /></datalist>
    <Dialog :open="bulkOpen" @update:open="open => { if (!open && !bulkForm.processing) bulkOpen = false; }"><DialogContent><DialogHeader><DialogTitle>Update {{ selectedIds.length }} secrets</DialogTitle><DialogDescription>Changes apply to all selected secrets in this project.</DialogDescription></DialogHeader><form class="space-y-4" @submit.prevent="saveBulk"><Field v-if="bulkField === 'environment'"><FieldLabel for="secret-bulk-environment">Environment</FieldLabel><Input id="secret-bulk-environment" v-model="bulkEnvironment" list="secret-environments" maxlength="100" required /><FieldError v-if="bulkForm.errors.environment">{{ bulkForm.errors.environment }}</FieldError></Field><Field v-else><FieldLabel for="secret-bulk-service">Service / category</FieldLabel><Input id="secret-bulk-service" v-model="bulkService" list="secret-categories" maxlength="100" placeholder="Leave empty to clear category" /><FieldError v-if="bulkForm.errors.service">{{ bulkForm.errors.service }}</FieldError></Field><FieldError v-if="bulkForm.errors.secrets">{{ bulkForm.errors.secrets }}</FieldError><DialogFooter><Button type="button" variant="outline" @click="bulkOpen = false">Cancel</Button><Button type="submit" :disabled="bulkForm.processing">Update secrets</Button></DialogFooter></form></DialogContent></Dialog>
    <datalist id="secret-environments"><option v-for="item in environmentOptions" :key="item" :value="item" /></datalist>
    <Dialog :open="importOpen" @update:open="open => { if (!open && !importPreviewForm.processing && !importForm.processing) closeImport(); }"><DialogContent class="max-h-[85vh] overflow-y-auto"><DialogHeader><DialogTitle>Import .env file</DialogTitle><DialogDescription>Orbit previews names only. Existing names are skipped; selected values never enter this page.</DialogDescription></DialogHeader>
        <form class="grid gap-4" @submit.prevent="importPreview ? importEntries() : previewImport()">
            <Field :data-invalid="!!importPreviewForm.errors.environment"><FieldLabel for="secret-import-environment">Environment</FieldLabel><Input id="secret-import-environment" v-model="importPreviewForm.environment" maxlength="100" required :disabled="!!importPreview" :aria-invalid="!!importPreviewForm.errors.environment" /><FieldError v-if="importPreviewForm.errors.environment">{{ importPreviewForm.errors.environment }}</FieldError></Field>
            <template v-if="importPreview"><p class="text-sm text-muted-foreground">{{ importPreview.source }} · {{ importPreview.entries.length }} entries</p><div class="max-h-64 overflow-y-auto rounded-md border"><div v-for="entry in importPreview.entries" :key="entry.name" class="flex items-center justify-between gap-4 border-b px-3 py-2 text-sm last:border-0"><span class="break-all font-medium">{{ entry.name }}</span><Badge v-if="entry.collision" variant="secondary">Existing · skip</Badge><Badge v-else variant="outline">Import</Badge></div></div><FieldError v-if="importForm.errors.entries">{{ importForm.errors.entries }}</FieldError></template>
            <FieldError v-if="importPreviewForm.errors.entries">{{ importPreviewForm.errors.entries }}</FieldError>
            <Button v-if="importPreview" type="button" variant="outline" :disabled="importForm.processing" @click="importPreview = null; importForm.clearErrors(); error = ''">Choose another file</Button>
            <Alert v-if="error" variant="destructive"><AlertDescription>{{ error }}</AlertDescription></Alert>
            <DialogFooter><Button type="button" variant="outline" :disabled="importPreviewForm.processing || importForm.processing" @click="closeImport">Cancel</Button><Button type="submit" :disabled="importPreviewForm.processing || importForm.processing || (!!importPreview && !importPreview.entries.some(entry => !entry.collision))">{{ importPreview ? (importForm.processing ? 'Importing…' : 'Import new entries') : (importPreviewForm.processing ? 'Opening…' : 'Choose file') }}</Button></DialogFooter>
        </form>
    </DialogContent></Dialog>
    <Dialog :open="exportOpen" @update:open="open => { if (!open && !exportPreviewForm.processing && !exportForm.processing) closeExport(); }"><DialogContent class="max-h-[85vh] overflow-y-auto"><DialogHeader><DialogTitle>Export .env file</DialogTitle><DialogDescription>Export writes plaintext values to the selected file. Choose only the entries you need.</DialogDescription></DialogHeader>
        <form class="grid gap-4" @submit.prevent="exportPreview ? exportEntries() : previewExport()">
            <Field><FieldLabel for="secret-export-environment">Environment</FieldLabel><ChoiceSelect id="secret-export-environment" :model-value="exportEnvironment" :options="environments" :disabled="!!exportPreview" @update:model-value="value => { exportEnvironment = value; changeExportEnvironment(); }" /></Field>
            <Field :data-invalid="!!exportPreviewForm.errors.names"><FieldLabel>Secrets</FieldLabel><div class="max-h-64 overflow-y-auto rounded-md border"><label v-for="secret in secrets.filter(item => item.environment === exportEnvironment)" :key="secret.id" class="flex items-center gap-3 border-b px-3 py-2 text-sm last:border-0"><Checkbox :model-value="exportNames.includes(secret.name)" :disabled="!!exportPreview" @update:model-value="toggleExport(secret.name)" /><span class="break-all font-medium">{{ secret.name }}</span></label></div><FieldError v-if="exportPreviewForm.errors.names">{{ exportPreviewForm.errors.names }}</FieldError><FieldError v-if="exportForm.errors.names">{{ exportForm.errors.names }}</FieldError></Field>
            <template v-if="exportPreview"><Alert><AlertDescription>Destination: {{ exportPreview.destination }}<template v-if="exportPreview.exists"> already exists.</template></AlertDescription></Alert><Field v-if="exportPreview.exists" :data-invalid="!!exportForm.errors.overwrite"><label class="flex items-center gap-3 text-sm"><Checkbox v-model="exportForm.overwrite" />Replace the existing file</label><FieldError v-if="exportForm.errors.overwrite">{{ exportForm.errors.overwrite }}</FieldError></Field></template>
            <Alert v-if="error" variant="destructive"><AlertDescription>{{ error }}</AlertDescription></Alert>
            <DialogFooter><Button type="button" variant="outline" :disabled="exportPreviewForm.processing || exportForm.processing" @click="closeExport">Cancel</Button><Button type="submit" :disabled="exportPreviewForm.processing || exportForm.processing || !exportNames.length || (!!exportPreview && exportPreview.exists && !exportForm.overwrite)">{{ exportPreview ? (exportForm.processing ? 'Exporting…' : 'Export plaintext .env') : (exportPreviewForm.processing ? 'Opening…' : 'Choose destination') }}</Button></DialogFooter>
        </form>
    </DialogContent></Dialog>
    <Dialog :open="!!removing" @update:open="open => { if (!open) removing = null; }"><DialogContent><DialogHeader><DialogTitle>Delete {{ removing?.name }}?</DialogTitle><DialogDescription>This permanently removes the secret from the {{ removing?.environment }} environment.</DialogDescription></DialogHeader><DialogFooter><Button variant="outline" @click="removing = null">Cancel</Button><Button variant="destructive" :disabled="removal.processing" @click="remove">Delete secret</Button></DialogFooter></DialogContent></Dialog>
    </div>
</template>
