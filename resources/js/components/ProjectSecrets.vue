<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { router, useHttp } from '@inertiajs/vue3';
import { useDocumentVisibility, useWindowFocus } from '@vueuse/core';
import { CopyIcon, EyeIcon, PencilIcon, PlusIcon, Trash2Icon } from '@lucide/vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import type { Project, ProjectSecret } from '@/types';

const props = defineProps<{ project: Project; native: boolean }>();
const query = ref('');
const environment = ref('');
const editorOpen = ref(false);
const pasteOpen = ref(false);
const importOpen = ref(false);
const exportOpen = ref(false);
const editing = ref<ProjectSecret | null>(null);
const removing = ref<ProjectSecret | null>(null);
const revealed = ref<{ secret: ProjectSecret; value: string } | null>(null);
const error = ref('');
const notice = ref('');
let revealTimer: ReturnType<typeof setTimeout> | undefined;
let clipboardTimer: ReturnType<typeof setTimeout> | undefined;
const visible = useDocumentVisibility();
const focused = useWindowFocus();
const form = useHttp({ environment: 'Default', name: '', value: '', project_revision: 1, revision: 1 });
const pasteForm = useHttp({ environment: 'Default', entries: '', project_revision: 1 });
const importPreviewForm = useHttp<{ environment: string; entries: string }, { preview: { source: string; entries: { name: string; collision: boolean }[] } | null }>({ environment: 'Default', entries: '' });
const importForm = useHttp({ environment: 'Default', project_revision: 1, entries: '' });
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
const rows = computed(() => secrets.value.filter(secret => (!environment.value || secret.environment === environment.value)
    && `${secret.name} ${secret.environment}`.toLowerCase().includes(query.value.toLowerCase())));

function reload() {
    router.reload({ only: ['selectedProject'] });
}
function clearReveal() {
    if (revealTimer) clearTimeout(revealTimer);
    revealTimer = undefined;
    revealed.value = null;
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
function edit(secret: ProjectSecret | null = null) {
    editing.value = secret;
    Object.assign(form, { environment: secret?.environment ?? (environment.value || 'Default'), name: secret?.name ?? '', value: '', project_revision: props.project.revision, revision: secret?.revision ?? 1 });
    form.clearErrors();
    error.value = '';
    editorOpen.value = true;
}
async function save() {
    error.value = '';
    form.project_revision = props.project.revision;
    if (editing.value) form.revision = editing.value.revision;
    try {
        if (editing.value) await form.put(`/projects/${props.project.id}/secrets/${editing.value.id}`);
        else await form.post(`/projects/${props.project.id}/secrets`);
        closeEditor();
        reload();
    } catch {
        if (!form.hasErrors) error.value = 'The secret could not be saved. Try again.';
    } finally {
        form.value = '';
    }
}
async function paste() {
    error.value = '';
    pasteForm.project_revision = props.project.revision;
    try {
        await pasteForm.post(`/projects/${props.project.id}/secrets/paste`);
        closePaste();
        reload();
    } catch {
        if (!pasteForm.hasErrors) error.value = 'The entries could not be saved. Try again.';
    } finally {
        pasteForm.entries = '';
    }
}
async function previewImport() {
    error.value = '';
    importPreviewForm.environment = environment.value || 'Default';
    try {
        const result = await importPreviewForm.post(`/projects/${props.project.id}/secrets/import/preview`);
        importPreview.value = result.preview;
    } catch {
        if (!importPreviewForm.hasErrors) error.value = 'The .env file could not be previewed. Try again.';
    }
}
async function importEntries() {
    if (!importPreview.value) return;
    error.value = '';
    importForm.environment = importPreviewForm.environment;
    importForm.project_revision = props.project.revision;
    try {
        await importForm.post(`/projects/${props.project.id}/secrets/import`);
        closeImport();
        reload();
    } catch {
        if (!importForm.hasErrors) error.value = 'The .env entries could not be imported. Preview the file again and retry.';
    }
}
async function previewExport() {
    error.value = '';
    exportPreviewForm.environment = exportEnvironment.value;
    exportPreviewForm.names = exportNames.value;
    try {
        const result = await exportPreviewForm.post(`/projects/${props.project.id}/secrets/export/preview`);
        exportPreview.value = result.preview;
    } catch {
        if (!exportPreviewForm.hasErrors) error.value = 'The export destination could not be previewed. Try again.';
    }
}
async function exportEntries() {
    if (!exportPreview.value) return;
    error.value = '';
    Object.assign(exportForm, { environment: exportEnvironment.value, names: exportNames.value, project_revision: props.project.revision });
    try {
        await exportForm.post(`/projects/${props.project.id}/secrets/export`);
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
        await removal.delete(`/projects/${props.project.id}/secrets/${removing.value.id}`);
        removing.value = null;
        reload();
    } catch {
        error.value = 'The secret could not be removed. Reload and try again.';
    }
}
async function reveal(secret: ProjectSecret) {
    error.value = '';
    clearReveal();
    try {
        const response = await fetch(`/projects/${props.project.id}/secrets/${secret.id}/value?revision=${secret.revision}`, { headers: { Accept: 'application/json' } });
        const body = await response.json() as { value?: string; message?: string };
        if (!response.ok || typeof body.value !== 'string') throw new Error(body.message);
        revealed.value = { secret, value: body.value };
        revealTimer = setTimeout(clearReveal, 30000);
    } catch {
        error.value = 'The secret could not be revealed. Try again.';
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
        await clipboard.post(`/projects/${props.project.id}/secrets/${secret.id}/copy`);
        notice.value = `${secret.name} copied. Orbit will clear it in 30 seconds if it remains unchanged.`;
        if (clipboardTimer) clearTimeout(clipboardTimer);
        clipboardTimer = setTimeout(() => { void clearCopied(secret); }, 30000);
    } catch {
        error.value = 'The secret could not be copied. Try again.';
    }
}
watch([visible, focused], () => { if (visible.value !== 'visible' || !focused.value) clearReveal(); });
onBeforeUnmount(() => {
    form.cancel();
    pasteForm.cancel();
    importPreviewForm.cancel();
    importForm.cancel();
    exportPreviewForm.cancel();
    exportForm.cancel();
    removal.cancel();
    clearReveal();
    form.value = '';
    pasteForm.entries = '';
});
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-end gap-3">
            <Field class="min-w-48 flex-1"><FieldLabel for="secret-search">Search</FieldLabel><Input id="secret-search" v-model="query" placeholder="Name or environment" maxlength="255" /></Field>
            <Field class="min-w-40"><FieldLabel for="secret-environment">Environment</FieldLabel><NativeSelect id="secret-environment" v-model="environment"><NativeSelectOption value="">All environments</NativeSelectOption><NativeSelectOption v-for="item in environments" :key="item" :value="item">{{ item }}</NativeSelectOption></NativeSelect></Field>
            <div class="flex flex-wrap gap-2"><Button :disabled="!native" @click="edit()"><PlusIcon aria-hidden="true" />Add secret</Button><Button variant="outline" :disabled="!native" @click="pasteOpen = true">Paste .env</Button><Button variant="outline" :disabled="!native" @click="importOpen = true">Import .env</Button><Button variant="outline" :disabled="!native || !secrets.length" @click="openExport">Export .env</Button></div>
        </div>
        <p v-if="!native" class="text-sm text-muted-foreground">Manage secrets in the desktop app to use macOS credential storage.</p>
        <Alert v-if="error" variant="destructive"><AlertDescription>{{ error }}</AlertDescription></Alert>
        <p aria-live="polite" class="sr-only">{{ notice }}</p>
        <Table v-if="rows.length">
            <TableHeader><TableRow><TableHead>Name</TableHead><TableHead>Environment</TableHead><TableHead>Updated</TableHead><TableHead><span class="sr-only">Actions</span></TableHead></TableRow></TableHeader>
            <TableBody><TableRow v-for="secret in rows" :key="secret.id">
                <TableCell class="font-medium break-all">{{ secret.name }}</TableCell><TableCell>{{ secret.environment }}</TableCell><TableCell>{{ new Date(secret.updated_at).toLocaleString() }}</TableCell>
                <TableCell><div class="flex flex-wrap gap-2"><Button size="sm" variant="outline" :disabled="!native" @click="reveal(secret)"><EyeIcon aria-hidden="true" />Reveal</Button><Button size="sm" variant="outline" :disabled="!native || clipboard.processing" @click="copy(secret)"><CopyIcon aria-hidden="true" />Copy</Button><Button size="sm" variant="ghost" :disabled="!native" @click="edit(secret)"><PencilIcon aria-hidden="true" />Replace value</Button><Button size="sm" variant="ghost" :disabled="!native" @click="removing = secret"><Trash2Icon aria-hidden="true" />Delete</Button></div></TableCell>
            </TableRow></TableBody>
        </Table>
        <p v-else class="text-sm text-muted-foreground">{{ secrets.length ? 'No secrets match these filters.' : 'No secrets saved yet.' }}</p>
    </div>

    <Dialog :open="editorOpen" @update:open="open => { if (!open && !form.processing) closeEditor(); }"><DialogContent><DialogHeader><DialogTitle>{{ editing ? `Replace ${editing.name}` : 'Add secret' }}</DialogTitle><DialogDescription>{{ editing ? 'This replaces the stored value. Name and environment stay unchanged.' : 'Values are encrypted with macOS credential storage.' }}</DialogDescription></DialogHeader>
        <form class="grid gap-4" @submit.prevent="save">
            <template v-if="!editing"><Field :data-invalid="!!form.errors.environment"><FieldLabel for="secret-editor-environment">Environment</FieldLabel><Input id="secret-editor-environment" v-model="form.environment" maxlength="100" required :aria-invalid="!!form.errors.environment" /><FieldError v-if="form.errors.environment">{{ form.errors.environment }}</FieldError></Field><Field :data-invalid="!!form.errors.name"><FieldLabel for="secret-editor-name">Name</FieldLabel><Input id="secret-editor-name" v-model="form.name" maxlength="255" pattern="[A-Za-z_][A-Za-z0-9_]*" required :aria-invalid="!!form.errors.name" /><FieldError v-if="form.errors.name">{{ form.errors.name }}</FieldError></Field></template>
            <Field :data-invalid="!!form.errors.value"><FieldLabel for="secret-editor-value">Value</FieldLabel><Textarea id="secret-editor-value" v-model="form.value" autocomplete="off" :spellcheck="false" :rows="6" :aria-invalid="!!form.errors.value" /><FieldError v-if="form.errors.value">{{ form.errors.value }}</FieldError></Field>
            <Alert v-if="error" variant="destructive"><AlertDescription>{{ error }}</AlertDescription></Alert>
            <DialogFooter><Button type="button" variant="outline" :disabled="form.processing" @click="closeEditor">Cancel</Button><Button type="submit" :disabled="form.processing">{{ form.processing ? 'Saving…' : 'Save secret' }}</Button></DialogFooter>
        </form>
    </DialogContent></Dialog>
    <Dialog :open="pasteOpen" @update:open="open => { if (!open && !pasteForm.processing) closePaste(); }"><DialogContent><DialogHeader><DialogTitle>Paste .env entries</DialogTitle><DialogDescription>Supports comments, optional export, quoted values, and multiline quoted values. Expressions remain literal.</DialogDescription></DialogHeader>
        <form class="grid gap-4" @submit.prevent="paste">
            <Field :data-invalid="!!pasteForm.errors.environment"><FieldLabel for="secret-paste-environment">Environment</FieldLabel><Input id="secret-paste-environment" v-model="pasteForm.environment" maxlength="100" required :aria-invalid="!!pasteForm.errors.environment" /><FieldError v-if="pasteForm.errors.environment">{{ pasteForm.errors.environment }}</FieldError></Field>
            <Field :data-invalid="!!pasteForm.errors.entries"><FieldLabel for="secret-paste-entries">Entries</FieldLabel><Textarea id="secret-paste-entries" v-model="pasteForm.entries" autocomplete="off" :spellcheck="false" :rows="8" :aria-invalid="!!pasteForm.errors.entries" placeholder="APP_LOCALE=en&#10;APP_FALLBACK_LOCALE=en&#10;APP_FAKER_LOCALE=en_US" /><FieldError v-if="pasteForm.errors.entries">{{ pasteForm.errors.entries }}</FieldError></Field>
            <Alert v-if="error" variant="destructive"><AlertDescription>{{ error }}</AlertDescription></Alert>
            <DialogFooter><Button type="button" variant="outline" :disabled="pasteForm.processing" @click="closePaste">Cancel</Button><Button type="submit" :disabled="pasteForm.processing">{{ pasteForm.processing ? 'Saving…' : 'Save entries' }}</Button></DialogFooter>
        </form>
    </DialogContent></Dialog>
    <Dialog :open="importOpen" @update:open="open => { if (!open && !importPreviewForm.processing && !importForm.processing) closeImport(); }"><DialogContent class="max-h-[85vh] overflow-y-auto"><DialogHeader><DialogTitle>Import .env file</DialogTitle><DialogDescription>Orbit previews names only. Existing names are skipped; selected values never enter this page.</DialogDescription></DialogHeader>
        <form class="grid gap-4" @submit.prevent="importPreview ? importEntries() : previewImport()">
            <Field :data-invalid="!!importPreviewForm.errors.environment"><FieldLabel for="secret-import-environment">Environment</FieldLabel><Input id="secret-import-environment" v-model="importPreviewForm.environment" maxlength="100" required :disabled="!!importPreview" :aria-invalid="!!importPreviewForm.errors.environment" /><FieldError v-if="importPreviewForm.errors.environment">{{ importPreviewForm.errors.environment }}</FieldError></Field>
            <template v-if="importPreview"><p class="text-sm text-muted-foreground">{{ importPreview.source }} · {{ importPreview.entries.length }} entries</p><div class="max-h-64 overflow-y-auto rounded-md border"><div v-for="entry in importPreview.entries" :key="entry.name" class="flex items-center justify-between gap-4 border-b px-3 py-2 text-sm last:border-0"><span class="break-all font-medium">{{ entry.name }}</span><Badge v-if="entry.collision" variant="secondary">Existing · skip</Badge><Badge v-else variant="outline">Import</Badge></div></div><FieldError v-if="importForm.errors.entries">{{ importForm.errors.entries }}</FieldError></template>
            <FieldError v-if="importPreviewForm.errors.entries">{{ importPreviewForm.errors.entries }}</FieldError>
            <Alert v-if="error" variant="destructive"><AlertDescription>{{ error }}</AlertDescription></Alert>
            <DialogFooter><Button type="button" variant="outline" :disabled="importPreviewForm.processing || importForm.processing" @click="closeImport">Cancel</Button><Button type="submit" :disabled="importPreviewForm.processing || importForm.processing || (!!importPreview && !importPreview.entries.some(entry => !entry.collision))">{{ importPreview ? (importForm.processing ? 'Importing…' : 'Import new entries') : (importPreviewForm.processing ? 'Opening…' : 'Choose file') }}</Button></DialogFooter>
        </form>
    </DialogContent></Dialog>
    <Dialog :open="exportOpen" @update:open="open => { if (!open && !exportPreviewForm.processing && !exportForm.processing) closeExport(); }"><DialogContent class="max-h-[85vh] overflow-y-auto"><DialogHeader><DialogTitle>Export .env file</DialogTitle><DialogDescription>Export writes plaintext values to the selected file. Choose only the entries you need.</DialogDescription></DialogHeader>
        <form class="grid gap-4" @submit.prevent="exportPreview ? exportEntries() : previewExport()">
            <Field><FieldLabel for="secret-export-environment">Environment</FieldLabel><NativeSelect id="secret-export-environment" v-model="exportEnvironment" :disabled="!!exportPreview" @change="changeExportEnvironment"><NativeSelectOption v-for="item in environments" :key="item" :value="item">{{ item }}</NativeSelectOption></NativeSelect></Field>
            <Field :data-invalid="!!exportPreviewForm.errors.names"><FieldLabel>Secrets</FieldLabel><div class="max-h-64 overflow-y-auto rounded-md border"><label v-for="secret in secrets.filter(item => item.environment === exportEnvironment)" :key="secret.id" class="flex items-center gap-3 border-b px-3 py-2 text-sm last:border-0"><input type="checkbox" :checked="exportNames.includes(secret.name)" :disabled="!!exportPreview" @change="toggleExport(secret.name)"><span class="break-all font-medium">{{ secret.name }}</span></label></div><FieldError v-if="exportPreviewForm.errors.names">{{ exportPreviewForm.errors.names }}</FieldError><FieldError v-if="exportForm.errors.names">{{ exportForm.errors.names }}</FieldError></Field>
            <template v-if="exportPreview"><Alert><AlertDescription>Destination: {{ exportPreview.destination }}<template v-if="exportPreview.exists"> already exists.</template></AlertDescription></Alert><Field v-if="exportPreview.exists" :data-invalid="!!exportForm.errors.overwrite"><label class="flex items-center gap-3 text-sm"><input v-model="exportForm.overwrite" type="checkbox">Replace the existing file</label><FieldError v-if="exportForm.errors.overwrite">{{ exportForm.errors.overwrite }}</FieldError></Field></template>
            <Alert v-if="error" variant="destructive"><AlertDescription>{{ error }}</AlertDescription></Alert>
            <DialogFooter><Button type="button" variant="outline" :disabled="exportPreviewForm.processing || exportForm.processing" @click="closeExport">Cancel</Button><Button type="submit" :disabled="exportPreviewForm.processing || exportForm.processing || !exportNames.length || (!!exportPreview && exportPreview.exists && !exportForm.overwrite)">{{ exportPreview ? (exportForm.processing ? 'Exporting…' : 'Export plaintext .env') : (exportPreviewForm.processing ? 'Opening…' : 'Choose destination') }}</Button></DialogFooter>
        </form>
    </DialogContent></Dialog>
    <Dialog :open="!!removing" @update:open="open => { if (!open) removing = null; }"><DialogContent><DialogHeader><DialogTitle>Delete {{ removing?.name }}?</DialogTitle><DialogDescription>This permanently removes the secret from the {{ removing?.environment }} environment.</DialogDescription></DialogHeader><DialogFooter><Button variant="outline" @click="removing = null">Cancel</Button><Button variant="destructive" :disabled="removal.processing" @click="remove">Delete secret</Button></DialogFooter></DialogContent></Dialog>
    <Dialog :open="!!revealed" @update:open="open => { if (!open) clearReveal(); }"><DialogContent><DialogHeader><DialogTitle>{{ revealed?.secret.name }}</DialogTitle><DialogDescription>This value closes automatically when Orbit loses focus or after 30 seconds.</DialogDescription></DialogHeader><Textarea :model-value="revealed?.value" readonly :rows="8" autocomplete="off" :spellcheck="false" /><DialogFooter><Button @click="clearReveal">Close</Button></DialogFooter></DialogContent></Dialog>
</template>
