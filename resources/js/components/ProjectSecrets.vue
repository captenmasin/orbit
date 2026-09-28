<script setup lang="ts">
import ChoiceSelect from '@/components/ChoiceSelect.vue';
import FilterSelect from '@/components/FilterSelect.vue';
import TextTransition from '@/components/TextTransition.vue';
import SecretPinInput from '@/components/SecretPinInput.vue';
import OpenTargetButton from '@/components/OpenTargetButton.vue';
import SecretPinRecovery from '@/components/SecretPinRecovery.vue';
import SecretDescription from '@/components/SecretDescription.vue';
import { toast } from 'vue-sonner';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Textarea } from '@/components/ui/textarea';
import type { Project, ProjectSecret } from '@/types';
import { Link, router, useHttp } from '@inertiajs/vue3';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { useDocumentVisibility, useWindowFocus } from '@vueuse/core';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ContextMenuSeparator, DropdownMenuContent, DropdownMenuItem, DropdownMenuPortal, DropdownMenuRoot, DropdownMenuTrigger } from 'reka-ui';
import { ContextMenu, ContextMenuContent, ContextMenuItem, ContextMenuTrigger, contextMenuContentClass, contextMenuItemClass } from '@/components/ui/context-menu';
import { CheckIcon, ChevronDownIcon, CopyIcon, EllipsisIcon, EyeIcon, EyeOffIcon, LockKeyholeIcon, PencilIcon, PlusIcon, SearchIcon, Trash2Icon } from '@lucide/vue';

const props = defineProps<{ project: Project; native: boolean; targetSecretId?: string | null }>();
const query = ref('');
const environment = ref('');
const service = ref('');
const metadataOnly = ref(false);
const editorOpen = ref(false);
const editorReady = ref(false);
const pasteOpen = ref(false);
const bulkOpen = ref(false);
const bulkRemoveOpen = ref(false);
const selectedIds = ref<string[]>([]);
const collapsedServices = ref<string[]>([]);
const activeSecretId = ref('');
const lastActiveSecret = ref<ProjectSecret | null>(null);
const description = ref<{ flush: () => Promise<boolean>; dirty: boolean; saving: boolean } | null>(null);
const valueRevealed = ref(false);
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
const pinForm = useHttp<{ pin: string }, { unlocked: boolean; unlocked_until: number }>({ pin: '' });
const error = ref('');
const unlockedUntil = ref(0);
const vaultRequests = new AbortController();
let vaultTimer: ReturnType<typeof setTimeout> | undefined;
let vaultVersion = 0;
let vaultWarningTimer: ReturnType<typeof setTimeout> | undefined;
const vaultNotice = ref('');
const visible = useDocumentVisibility();
const focused = useWindowFocus();
const form = useHttp({ environment: 'Default', name: '', value: '', service: '', description: '', management_url: '', project_revision: 1, revision: 1 });
const editorValue = useHttp<{ revision: number }, { value: string }>({ revision: 1 });
const pasteForm = useHttp({ environment: 'Default', service: '', entries: '', project_revision: 1 });
const bulkForm = useHttp({ project_revision: 1, secrets: [] as { id: string; revision: number }[], environment: undefined as string | undefined, service: undefined as string | undefined });
const importPreviewForm = useHttp<{ environment: string; entries: string }, { preview: { source: string; entries: { name: string; collision: boolean }[] } | null }>({ environment: 'Default', entries: '' });
const importForm = useHttp<{ environment: string; project_revision: number; entries: string }, { saved: boolean; imported: number; skipped: number }>({ environment: 'Default', project_revision: 1, entries: '' });
const exportPreviewForm = useHttp<{ environment: string; names: string[] }, { preview: { destination: string; exists: boolean } | null }>({ environment: 'Default', names: [] });
const exportForm = useHttp({ environment: 'Default', names: [] as string[], project_revision: 1, overwrite: false });
const removal = useHttp({ project_revision: 1, revision: 1 });
const bulkRemoval = useHttp<{ project_revision: number; secrets: { id: string; revision: number }[] }, { removed: boolean; deleted: number }>({ project_revision: 1, secrets: [] });
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
const serviceGroups = computed(() => {
    const groups = new Map<string, ProjectSecret[]>();
    for (const secret of rows.value) {
        const name = secret.service?.trim() ?? '';
        if (!groups.has(name)) groups.set(name, []);
        groups.get(name)!.push(secret);
    }
    return [...groups].sort(([a], [b]) => !a ? 1 : !b ? -1 : a.localeCompare(b))
        .map(([service, secrets]) => ({ service, secrets }));
});
const selectAllState = computed<boolean | 'indeterminate'>(() => {
    const selected = rows.value.filter(secret => selectedIds.value.includes(secret.id)).length;
    return selected === 0 ? false : selected === rows.value.length ? true : 'indeterminate';
});
const activeSecret = computed(() => description.value?.dirty || description.value?.saving
    ? secrets.value.find(secret => secret.id === activeSecretId.value) ?? lastActiveSecret.value
    : rows.value.find(secret => secret.id === activeSecretId.value) ?? rows.value[0] ?? null);

async function flushDescription() {
    return await description.value?.flush() ?? true;
}
defineExpose({ flush: flushDescription });
async function selectSecret(id: string) {
    if (!await flushDescription()) return;
    activeSecretId.value = id;
}
async function filterSecrets(field: 'environment' | 'service' | 'query', value: string) {
    const current = field === 'environment' ? environment.value : field === 'service' ? service.value : query.value;
    if (current === value || !await flushDescription()) return;
    selectedIds.value = [];
    if (field === 'environment') environment.value = value;
    else if (field === 'service') service.value = value;
    else query.value = value;
}
function lock() {
    void flushDescription();
    lockVault();
}
async function confirmRemoval(secret: ProjectSecret) {
    if (!await flushDescription()) return;
    removing.value = secrets.value.find(item => item.id === secret.id) ?? null;
}
async function confirmBulkRemoval() {
    if (!await flushDescription()) return;
    bulkRemoval.clearErrors();
    bulkRemoval.project_revision = props.project.revision;
    bulkRemoval.secrets = secrets.value.filter(secret => selectedIds.value.includes(secret.id)).map(secret => ({ id: secret.id, revision: secret.revision }));
    if (bulkRemoval.secrets.length) bulkRemoveOpen.value = true;
}
function reload() {
    router.reload({ only: ['selectedProject'] });
}
function recoveredPin() {
    clearVault();
    closeImport();
    closeExport();
    pinForm.pin = '';
    pinForm.defaults('pin', '');
    pinForm.clearErrors();
    error.value = '';
    reload();
}
async function loadValues() {
    const sessionUntil = unlockedUntil.value;
    const version = vaultVersion;
    const response = await fetch(`/projects/${props.project.id}/secrets/values`, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: vaultRequests.signal });
    if (!response.ok) {
        if (version === vaultVersion) throw new Error('Secret values could not be loaded.');
        return;
    }
    const body = await response.json() as { values: Record<string, string> };
    if (!vaultRequests.signal.aborted && vaultVersion === version && unlockedUntil.value === sessionUntil) values.value = body.values;
}
function clearVault() {
    vaultVersion++;
    clearTimeout(vaultWarningTimer);
    if (vaultTimer) clearTimeout(vaultTimer);
    vaultTimer = undefined;
    values.value = {};
    valueRevealed.value = false;
    unlocked.value = false;
    unlockedUntil.value = 0;
    form.value = '';
    editorOpen.value = false;
    editorReady.value = false;
    editorValue.cancel();
    editorValue.response = null;
    pasteForm.entries = '';
    pasteOpen.value = false;
}
function expireVault() {
    lockVault();
    vaultNotice.value = 'Secrets locked. Unsaved secret values and pasted entries were cleared. Unlock to continue.';
}
function lockVault() {
    const wasUnlocked = unlocked.value;
    clearVault();
    if (wasUnlocked) void fetch('/secrets/lock', { method: 'POST', credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': decodeURIComponent(document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '') } });
}
function resumeVault(until: number) {
    if (vaultRequests.signal.aborted) return;
    if (vaultTimer) clearTimeout(vaultTimer);
    unlockedUntil.value = until * 1000;
    if (unlockedUntil.value <= Date.now()) { clearVault(); return; }
    unlocked.value = true;
    vaultNotice.value = '';
    clearTimeout(vaultWarningTimer);
    vaultWarningTimer = setTimeout(() => { vaultNotice.value = 'Secrets lock in one minute. Save any value edits or pasted entries before then.'; }, Math.max(0, unlockedUntil.value - Date.now() - 60000));
    vaultTimer = setTimeout(expireVault, unlockedUntil.value - Date.now());
}
function focusPin() {
    void nextTick(() => { if (!unlocked.value && !vaultRequests.signal.aborted) document.getElementById('secret-pin')?.focus(); });
}
async function refreshVaultStatus() {
    const version = vaultVersion;
    try {
        const response = await fetch('/secrets/unlock/status', { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: vaultRequests.signal });
        if (!response.ok) throw new Error();
        const status = await response.json() as { pin_set: boolean; unlocked: boolean; unlocked_until: number | null };
        if (vaultRequests.signal.aborted || version !== vaultVersion) return;
        pinSet.value = status.pin_set;
        if (status.unlocked && status.unlocked_until) { resumeVault(status.unlocked_until); if (unlocked.value) await loadValues(); }
        else clearVault();
    } catch { if (version === vaultVersion && !vaultRequests.signal.aborted) { clearVault(); error.value = 'The secret vault could not be loaded.'; } }
    finally { vaultLoading.value = false; }
}
async function unlockVault() {
    if (vaultLoading.value || unlocking.value || unlocked.value || !pinSet.value || pinForm.pin.length !== 4) return;
    unlocking.value = true;
    const version = vaultVersion;
    error.value = '';
    try {
        const result = await pinForm.post('/secrets/unlock', { onHttpException: response => { error.value = JSON.parse(response.data).message ?? 'PIN unlock failed.'; } });
        if (!result) { error.value = Object.values(pinForm.errors).flat().join(' '); return; }
        if (vaultRequests.signal.aborted || version !== vaultVersion) return;
        pinSet.value = true;
        resumeVault(result.unlocked_until);
        if (unlocked.value) await loadValues();
    } catch (cause) { if (version === vaultVersion) { clearVault(); if (!error.value) error.value = cause instanceof Error ? cause.message : 'PIN unlock failed.'; } }
    finally { pinForm.pin = ''; pinForm.defaults('pin', ''); unlocking.value = false; focusPin(); }
}
function closeEditor() {
    editorValue.cancel();
    editorValue.response = null;
    editorOpen.value = false;
    editorReady.value = false;
    editing.value = null;
    form.value = '';
    form.clearErrors();
}
function closePaste() {
    pasteOpen.value = false;
    pasteForm.entries = '';
    pasteForm.clearErrors();
}
async function openPaste() {
    if (description.value && !await flushDescription()) return;
    pasteForm.environment = environment.value || 'Default';
    pasteForm.service = service.value;
    pasteOpen.value = true;
}
function closeImport() {
    importOpen.value = false;
    importPreview.value = null;
    importPreviewForm.clearErrors();
    importForm.clearErrors();
}
async function openImport() {
    if (description.value && !await flushDescription()) return;
    closeImport();
    importPreviewForm.environment = environment.value || 'Default';
    error.value = '';
    importOpen.value = true;
}
async function openExport() {
    if (description.value && !await flushDescription()) return;
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
async function edit(secret: ProjectSecret | null = null, metadata = false) {
    if (description.value && !await flushDescription()) return;
    if (secret) secret = secrets.value.find(item => item.id === secret?.id) ?? secret;
    editorValue.cancel();
    editorValue.response = null;
    editorReady.value = !secret || metadata;
    editing.value = secret;
    metadataOnly.value = metadata;
    Object.assign(form, { environment: secret?.environment ?? (environment.value || 'Default'), name: secret?.name ?? '', value: '', service: secret?.service ?? '', description: secret?.description ?? '', management_url: secret?.management_url ?? '', project_revision: props.project.revision, revision: secret?.revision ?? 1 });
    formEnvironmentChoice.value = environmentOptions.value.includes(form.environment) ? form.environment : '__new__';
    form.clearErrors();
    error.value = '';
    editorOpen.value = true;
    if (!secret || metadata) return;
    editorValue.revision = secret.revision;
    editorValue.clearErrors();
    try {
        const result = await editorValue.get(`/projects/${props.project.id}/secrets/${secret.id}/value`);
        if (!editorOpen.value || !unlocked.value || editing.value?.id !== secret.id) return;
        if (!result) { error.value = 'The secret could not be loaded. Close the editor and try again.'; return; }
        form.value = result.value;
        editorReady.value = true;
    } catch {
        if (editorOpen.value && unlocked.value && editing.value?.id === secret.id) error.value = 'The secret could not be loaded. Close the editor and try again.';
    } finally {
        editorValue.response = null;
    }
}
function chooseFormEnvironment(value: string) {
    formEnvironmentChoice.value = value;
    form.environment = value === '__new__' ? '' : value;
}
function toggleSelected(id: string) {
    selectedIds.value = selectedIds.value.includes(id) ? selectedIds.value.filter(item => item !== id) : [...selectedIds.value, id];
}
function toggleServiceGroup(service: string, open: boolean) {
    collapsedServices.value = open ? collapsedServices.value.filter(name => name !== service)
        : [...new Set([...collapsedServices.value, service])];
}
function toggleVisible() {
    const visibleIds = rows.value.map(secret => secret.id);
    selectedIds.value = visibleIds.every(id => selectedIds.value.includes(id))
        ? selectedIds.value.filter(id => !visibleIds.includes(id))
        : [...new Set([...selectedIds.value, ...visibleIds])];
}
async function openBulk(field: 'environment' | 'service') {
    if (description.value && !await flushDescription()) return;
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
    if (editing.value && !metadataOnly.value && !editorReady.value) return;
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
        if (!editorOpen.value) form.value = '';
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
        toast.success(`${result.imported} ${result.imported === 1 ? 'secret' : 'secrets'} imported. ${result.skipped} existing ${result.skipped === 1 ? 'entry' : 'entries'} skipped.`);
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
        toast.success('Secrets exported.');
    } catch {
        if (!exportForm.hasErrors) error.value = 'The .env file could not be exported. Choose the destination again and retry.';
    } finally {
        exportPreview.value = null;
        exportForm.overwrite = false;
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
async function removeSelected() {
    if (!bulkRemoveOpen.value || bulkRemoval.processing || !bulkRemoval.secrets.length) return;
    try {
        const result = await bulkRemoval.delete(`/projects/${props.project.id}/secrets/bulk`);
        if (!result) { toast.error(String(Object.values(bulkRemoval.errors)[0] ?? 'The selected secrets could not be deleted. Reload and try again.')); return; }
        for (const secret of bulkRemoval.secrets) delete values.value[secret.id];
        valueRevealed.value = false;
        bulkRemoveOpen.value = false;
        selectedIds.value = [];
        toast.success(`${result.deleted} ${result.deleted === 1 ? 'secret' : 'secrets'} deleted.`);
        reload();
    } catch {
        toast.error('The selected secrets could not be deleted. Reload and try again.');
    }
}
async function copy(secret: ProjectSecret) {
    if (description.value && !await flushDescription()) return;
    secret = secrets.value.find(item => item.id === secret.id) ?? secret;
    error.value = '';
    clipboard.revision = secret.revision;
    try {
        const result = await clipboard.post(`/projects/${props.project.id}/secrets/${secret.id}/copy`);
        if (!result) { toast.error(String(Object.values(clipboard.errors)[0] ?? 'The secret could not be copied. Try again.')); return; }
        toast.success(`${secret.name} copied to clipboard.`);
    } catch {
        toast.error('The secret could not be copied. Try again.');
    }
}
watch(activeSecret, secret => { lastActiveSecret.value = secret; }, { immediate: true, flush: 'sync' });
watch(query, () => { collapsedServices.value = []; });
watch(() => activeSecret.value?.id, id => { activeSecretId.value = id ?? ''; valueRevealed.value = false; }, { immediate: true, flush: 'sync' });
watch(() => props.targetSecretId, id => {
    if (!id) return;
    const secret = secrets.value.find(item => item.id === id);
    if (secret) edit(secret, true);
    else toast.error('This secret no longer exists in this project.');
}, { immediate: true });
watch([visible, focused], () => {
    if (unlocked.value && visible.value === 'visible' && focused.value) {
        if (unlockedUntil.value <= Date.now()) expireVault();
        else void refreshVaultStatus();
    }
});
watch(() => pinForm.pin, pin => {
    if (!pin) return;
    error.value = '';
    pinForm.clearErrors();
    void unlockVault();
});
watch(secrets, () => {
    selectedIds.value = selectedIds.value.filter(id => secrets.value.some(secret => secret.id === id));
    if (unlocked.value) void loadValues().catch(() => lockVault());
});
onMounted(async () => { if (props.native) { await refreshVaultStatus(); focusPin(); } else vaultLoading.value = false; });
onBeforeUnmount(() => {
    vaultRequests.abort();
    pinForm.cancel();
    form.cancel();
    editorValue.cancel();
    pasteForm.cancel();
    bulkForm.cancel();
    importPreviewForm.cancel();
    importForm.cancel();
    exportPreviewForm.cancel();
    exportForm.cancel();
    removal.cancel();
    bulkRemoval.cancel();
    clearVault();
    form.value = '';
    pasteForm.entries = '';
});
</script>

<template>
    <div class="space-y-4">
        <p
            v-if="vaultNotice"
            role="status"
            class="text-sm text-muted-foreground">
            {{ vaultNotice }}
        </p>
        <div
            v-if="unlocked"
            class="flex flex-wrap items-start justify-between gap-4">
            <h2 class="text-xl font-normal tracking-[-0.025em]">
                Secrets
            </h2>
            <div class="flex flex-wrap items-center gap-2">
                <Button
                    variant="ghost"
                    size="sm"
                    @click="lock">
                    <LockKeyholeIcon aria-hidden="true" />Lock
                </Button>
                <DropdownMenuRoot>
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="!native">
                            Import / export<ChevronDownIcon aria-hidden="true" />
                        </Button>
                    </DropdownMenuTrigger><DropdownMenuPortal>
                        <DropdownMenuContent
                            align="end"
                            :side-offset="4"
                            :class="contextMenuContentClass">
                            <DropdownMenuItem
                                :class="contextMenuItemClass"
                                @select="openPaste">
                                Paste .env entries
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                :class="contextMenuItemClass"
                                @select="openImport">
                                Import .env file
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                :class="contextMenuItemClass"
                                :disabled="!secrets.length"
                                @select="openExport">
                                Export .env file
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenuPortal>
                </DropdownMenuRoot>
                <Button
                    :disabled="!native"
                    @click="edit()">
                    <PlusIcon aria-hidden="true" />Add secret
                </Button>
            </div>
        </div>
        <section
            v-if="!unlocked"
            class="flex min-h-96 flex-col items-center justify-center px-4 py-12 text-center"
            aria-labelledby="secret-vault-title"
            :aria-busy="vaultLoading || unlocking">
            <span class="mb-5 flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground"><LockKeyholeIcon
                class="size-5"
                aria-hidden="true" /></span>
            <h2
                id="secret-vault-title"
                class="text-xl font-normal tracking-[-0.025em]">
                {{ vaultLoading || !native || (!pinSet && error) ? 'Secrets are locked' : pinSet ? 'Enter your PIN' : 'Set up your PIN' }}
            </h2>
            <p
                v-if="!native || vaultLoading || (!pinSet && !error)"
                class="mt-2 text-sm leading-6 text-muted-foreground">
                {{ !native ? 'Open the desktop app to unlock secrets.' : vaultLoading ? 'Loading vault…' : 'Set a shared PIN in Orbit settings to get started.' }}
            </p>
            <Button
                v-if="native && !vaultLoading && !pinSet && !error"
                as-child
                variant="outline"
                class="mt-6">
                <Link href="/settings?section=security">
                    Set PIN in settings
                </Link>
            </Button>
            <form
                v-if="native && !vaultLoading && pinSet"
                class="mt-6 grid w-full max-w-xs justify-items-center gap-4"
                @submit.prevent="unlockVault">
                <Field class="w-auto">
                    <FieldLabel
                        for="secret-pin"
                        class="sr-only">
                        PIN
                    </FieldLabel><SecretPinInput
                        id="secret-pin"
                        v-model="pinForm.pin"
                        size="lg"
                        :disabled="unlocking"
                        :invalid="!!pinForm.errors.pin"
                        aria-describedby="secret-pin-status" />
                </Field>
            </form>
            <div
                v-if="native && !vaultLoading"
                id="secret-pin-status"
                class="mt-4 min-h-6 text-sm">
                <p
                    v-if="error"
                    role="alert"
                    class="text-destructive">
                    {{ error }}
                </p><p
                    v-else
                    role="status"
                    class="text-muted-foreground">
                    <TextTransition
                        :text="unlocking ? 'Unlocking…' : ''"
                        shimmer />
                </p>
            </div>
            <SecretPinRecovery
                v-if="native && !vaultLoading && pinSet"
                :disabled="unlocking"
                @saved="recoveredPin" />
        </section>
        <div
            v-show="unlocked"
            class="space-y-4">
            <div class="grid">
                <div
                    role="search"
                    class="col-start-1 row-start-1 flex flex-wrap items-center gap-2"
                    :class="selectedIds.length ? 'invisible' : ''"
                    :inert="selectedIds.length > 0"
                    aria-label="Filter secrets">
                    <div class="min-w-[155px] flex-1 sm:flex-none">
                        <label
                            for="secret-environment"
                            class="sr-only">Environment</label><FilterSelect
                                id="secret-environment"
                                :model-value="environment"
                                label="Environment"
                                :options="environments"
                                all-label="All environments"
                                @update:model-value="filterSecrets('environment', $event)" />
                    </div>
                    <div class="min-w-[155px] flex-1 sm:flex-none">
                        <label
                            for="secret-service"
                            class="sr-only">Service</label><FilterSelect
                                id="secret-service"
                                :model-value="service"
                                label="Service"
                                :options="services"
                                all-label="All services"
                                @update:model-value="filterSecrets('service', $event)" />
                    </div>
                    <Field class="w-full sm:w-64">
                        <FieldLabel
                            for="secret-search"
                            class="sr-only">
                            Search secrets
                        </FieldLabel><div class="relative">
                            <SearchIcon
                                class="pointer-events-none absolute top-1/2 left-3.5 size-3.5 -translate-y-1/2 text-muted-foreground"
                                aria-hidden="true" /><Input
                                    id="secret-search"
                                    :model-value="query"
                                    placeholder="Search secrets…"
                                    maxlength="255"
                                    class="h-9 rounded-full border-0 bg-muted pl-9 text-[13px] shadow-none focus-visible:ring-2 focus-visible:ring-ring/50 md:text-[13px]"
                                    @update:model-value="filterSecrets('query', String($event))" />
                        </div>
                    </Field>
                </div>
                <div
                    class="col-start-1 row-start-1 flex flex-wrap items-center gap-2 rounded-xl border bg-muted/50 px-6 p-3 text-sm"
                    :class="selectedIds.length ? '' : 'invisible'"
                    :inert="selectedIds.length === 0"
                    role="status">
                    <span class="font-medium">{{ selectedIds.length }} selected</span><Button
                        size="sm"
                        variant="outline"
                        @click="openBulk('environment')">
                        Change environment
                    </Button><Button
                        size="sm"
                        variant="outline"
                        @click="openBulk('service')">
                        Change service
                    </Button><Button
                        size="sm"
                        variant="destructive"
                        :disabled="!native || bulkRemoval.processing"
                        @click="confirmBulkRemoval">
                        <Trash2Icon aria-hidden="true" />Delete
                    </Button><Button
                        size="sm"
                        variant="ghost"
                        @click="selectedIds = []">
                        Clear
                    </Button>
                </div>
            </div>
            <p
                v-if="!native"
                class="text-sm text-muted-foreground">
                Manage secrets in the desktop app to use secure system credential storage.
            </p>
            <Alert
                v-if="error"
                variant="destructive">
                <AlertDescription>{{ error }}</AlertDescription>
            </Alert>
            <div class="grid overflow-hidden rounded-xl border bg-background lg:grid-cols-[minmax(0,1fr)_minmax(0,3fr)]">
                <div class="min-w-0 border-b lg:border-r lg:border-b-0">
                    <label
                        for="secret-select-all"
                        class="flex min-h-11 items-center gap-3 border-b px-4 text-sm">
                        <Checkbox
                            id="secret-select-all"
                            aria-label="Select all secrets"
                            :model-value="selectAllState"
                            :disabled="!rows.length"
                            @update:model-value="toggleVisible" />
                        <span>Select all</span>
                    </label>
                    <div
                        v-if="rows.length"
                        class="max-h-64 overflow-y-auto lg:max-h-[36rem]">
                        <Collapsible
                            v-for="group in serviceGroups"
                            :key="group.service"
                            class="group/service border-b last:border-b-0"
                            :open="!collapsedServices.includes(group.service)"
                            @update:open="toggleServiceGroup(group.service, $event)">
                            <CollapsibleTrigger as-child>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    class="h-10 w-full justify-start rounded-none border-0 px-4 text-left aria-expanded:bg-transparent">
                                    <ChevronDownIcon
                                        class="size-3.5 transition-transform group-data-[state=closed]/service:-rotate-90"
                                        aria-hidden="true" /><span class="min-w-0 truncate">{{ group.service || 'Unassigned' }}</span><span class="ml-auto text-xs font-normal text-muted-foreground">{{ group.secrets.length }}</span>
                                </Button>
                            </CollapsibleTrigger>
                            <CollapsibleContent
                                as="ul"
                                class="pb-4"
                                :aria-label="`${group.service || 'Unassigned'} secrets`">
                                <ContextMenu
                                    v-for="secret in group.secrets"
                                    :key="secret.id">
                                    <ContextMenuTrigger as-child>
                                        <li
                                            class="flex min-h-14 items-center gap-3 px-4 transition-colors"
                                            :class="activeSecret?.id === secret.id ? 'bg-secondary text-secondary-foreground hover:bg-secondary/80' : 'hover:bg-muted/50'">
                                            <Checkbox
                                                :aria-label="`Select ${secret.name} in ${secret.environment}`"
                                                :model-value="selectedIds.includes(secret.id)"
                                                @update:model-value="toggleSelected(secret.id)" />
                                            <button
                                                type="button"
                                                class="min-w-0 flex-1 rounded-sm py-2 text-left outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                                                :aria-pressed="activeSecret?.id === secret.id"
                                                aria-controls="secret-details"
                                                @click="selectSecret(secret.id)">
                                                <span
                                                    class="block truncate text-[13px] font-medium"
                                                    :title="secret.name">{{ secret.name }}</span><span class="mt-1 block truncate text-xs text-muted-foreground">{{ secret.environment }}</span>
                                            </button>
                                            <Button
                                                size="icon-sm"
                                                variant="ghost"
                                                :aria-label="`Copy ${secret.name}`"
                                                :disabled="!native || clipboard.processing"
                                                @click="copy(secret)">
                                                <CopyIcon aria-hidden="true" />
                                            </Button>
                                        </li>
                                    </ContextMenuTrigger><ContextMenuContent class="w-56">
                                        <ContextMenuItem @select="toggleSelected(secret.id)">
                                            <CheckIcon aria-hidden="true" />{{ selectedIds.includes(secret.id) ? 'Deselect' : 'Select' }}
                                        </ContextMenuItem><ContextMenuItem
                                            :disabled="!native"
                                            @select="edit(secret)">
                                            <PencilIcon aria-hidden="true" />Edit secret
                                        </ContextMenuItem><ContextMenuItem
                                            :disabled="!native || clipboard.processing"
                                            @select="copy(secret)">
                                            <CopyIcon aria-hidden="true" />Copy value
                                        </ContextMenuItem>
                                        <ContextMenuSeparator class="mx-2.5 my-1.5 h-px bg-border/80" />
                                        <ContextMenuItem
                                            :disabled="!native"
                                            variant="destructive"
                                            @select="confirmRemoval(secret)">
                                            <Trash2Icon aria-hidden="true" />Delete secret
                                        </ContextMenuItem>
                                    </ContextMenuContent>
                                </ContextMenu>
                            </CollapsibleContent>
                        </Collapsible>
                    </div>
                    <p
                        v-else
                        class="px-4 py-6 text-center text-sm text-muted-foreground">
                        {{ secrets.length ? 'No matching secrets' : 'No secrets yet' }}
                    </p>
                </div>
                <section
                    v-if="activeSecret"
                    id="secret-details"
                    aria-labelledby="secret-detail-title"
                    class="min-w-0 space-y-6 p-6 lg:min-h-[32rem]">
                    <div class="flex items-start justify-between gap-4">
                        <h3
                            id="secret-detail-title"
                            class="min-w-0 text-xl font-semibold break-all">
                            {{ activeSecret.name }}
                        </h3>
                        <DropdownMenuRoot>
                            <DropdownMenuTrigger as-child>
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    :aria-label="`Actions for ${activeSecret.name}`">
                                    <EllipsisIcon aria-hidden="true" />
                                </Button>
                            </DropdownMenuTrigger><DropdownMenuPortal>
                                <DropdownMenuContent
                                    align="end"
                                    :side-offset="4"
                                    :class="contextMenuContentClass"
                                    class="w-56">
                                    <ContextMenuItem
                                        :disabled="!native"
                                        @select="edit(activeSecret)">
                                        <PencilIcon aria-hidden="true" />Edit secret
                                    </ContextMenuItem>
                                    <ContextMenuSeparator class="mx-2.5 my-1.5 h-px bg-border/80" />
                                    <ContextMenuItem
                                        variant="destructive"
                                        :disabled="!native"
                                        @select="confirmRemoval(activeSecret)">
                                        <Trash2Icon aria-hidden="true" />Delete secret
                                    </ContextMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenuPortal>
                        </DropdownMenuRoot>
                    </div>
                    <div class="space-y-3 rounded-lg bg-muted p-4">
                        <h4 class="text-sm font-medium">
                            Value
                        </h4>
                        <div class="flex flex-wrap items-center gap-3">
                            <code
                                v-if="valueRevealed"
                                id="secret-value"
                                class="min-w-0 flex-[1_1_12rem] whitespace-pre-wrap break-all text-sm">{{ values[activeSecret.id] ?? '…' }}</code><span
                                    v-else
                                    id="secret-value"
                                    class="min-w-0 flex-[1_1_12rem] overflow-hidden text-xl tracking-wider text-muted-foreground"
                                    aria-label="Value hidden">••••••••••••••••••••</span>
                            <div class="flex flex-wrap items-center gap-2">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    :disabled="values[activeSecret.id] === undefined"
                                    :aria-pressed="valueRevealed"
                                    aria-controls="secret-value"
                                    @click="valueRevealed = !valueRevealed">
                                    <span
                                        class="t-icon-swap"
                                        :data-state="valueRevealed ? 'b' : 'a'"
                                        aria-hidden="true"><EyeIcon
                                            class="t-icon"
                                            data-icon="a" /><EyeOffIcon
                                                class="t-icon"
                                                data-icon="b" /></span><TextTransition :text="valueRevealed ? 'Hide' : 'Reveal'" />
                                </Button><Button
                                    size="sm"
                                    :disabled="!native || clipboard.processing"
                                    @click="copy(activeSecret)">
                                    <CopyIcon aria-hidden="true" />Copy value
                                </Button>
                            </div>
                        </div>
                    </div>
                    <SecretDescription
                        ref="description"
                        :key="activeSecret.id"
                        :project="project"
                        :secret="activeSecret"
                        :native="native && unlocked" />
                    <dl class="grid gap-4 text-sm sm:grid-cols-3">
                        <div class="min-w-0">
                            <dt class="text-xs text-muted-foreground">
                                Environment
                            </dt><dd class="mt-2 wrap-anywhere">
                                {{ activeSecret.environment }}
                            </dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-xs text-muted-foreground">
                                Service
                            </dt><dd class="mt-2 wrap-anywhere">
                                {{ activeSecret.service || 'Unassigned' }}
                            </dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-xs text-muted-foreground">
                                Updated
                            </dt><dd class="mt-2">
                                {{ new Date(activeSecret.updated_at).toLocaleString() }}
                            </dd>
                        </div>
                    </dl>
                    <OpenTargetButton
                        v-if="activeSecret.management_url"
                        :id="activeSecret.id"
                        :project-id="project.id"
                        kind="secrets"
                        :native="native"
                        :href="activeSecret.management_url"
                        :label="activeSecret.service ? `Manage in ${activeSecret.service}` : 'Manage service'" />
                </section>
                <div
                    v-else
                    class="flex min-h-80 flex-col items-center justify-center px-6 py-14 text-center lg:min-h-[32rem]">
                    <h3 class="text-sm font-normal">
                        {{ secrets.length ? 'No matching secrets' : 'No secrets yet' }}
                    </h3><p class="mt-1 text-sm text-muted-foreground">
                        {{ secrets.length ? 'Try another search or change the filters.' : 'Add a secret to keep a credential with this project.' }}
                    </p><Button
                        v-if="!secrets.length && native"
                        variant="outline"
                        class="mt-5"
                        @click="edit()">
                        <PlusIcon aria-hidden="true" />Add secret
                    </Button>
                </div>
            </div>
        </div>

        <Dialog
            :open="editorOpen"
            @update:open="open => { if (!open && !form.processing) closeEditor(); }">
            <DialogContent
                :aria-describedby="undefined"
                class="max-h-[calc(100dvh-2rem)] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle class="pr-6 break-all">
                        {{ metadataOnly ? `Context for ${editing?.name}` : editing ? 'Edit secret' : 'Add secret' }}
                    </DialogTitle>
                </DialogHeader>
                <form
                    class="grid grid-cols-1 gap-4"
                    @submit.prevent="save">
                    <template v-if="!metadataOnly">
                        <Field :data-invalid="!!form.errors.name">
                            <FieldLabel for="secret-editor-name">
                                Name
                            </FieldLabel>
                            <Input
                                id="secret-editor-name"
                                v-model="form.name"
                                variant="filled"
                                maxlength="255"
                                pattern="[A-Za-z_][A-Za-z0-9_]*"
                                placeholder="API_KEY"
                                required
                                :aria-invalid="!!form.errors.name" />
                            <FieldError v-if="form.errors.name">
                                {{ form.errors.name }}
                            </FieldError>
                        </Field>
                        <Field :data-invalid="!!form.errors.value">
                            <FieldLabel for="secret-editor-value">
                                Value
                            </FieldLabel>
                            <Textarea
                                id="secret-editor-value"
                                v-model="form.value"
                                variant="filled"
                                autocomplete="off"
                                :spellcheck="false"
                                :rows="4"
                                class="max-h-64 min-h-28 resize-y font-mono"
                                :disabled="!editorReady"
                                :placeholder="editorValue.processing ? 'Loading value…' : 'Paste the secret value…'"
                                :aria-invalid="!!form.errors.value" />
                            <FieldError v-if="form.errors.value">
                                {{ form.errors.value }}
                            </FieldError>
                        </Field>
                    </template>
                    <div
                        class="grid grid-cols-1 gap-4"
                        :class="!metadataOnly ? 'sm:grid-cols-2' : undefined">
                        <Field
                            v-if="!metadataOnly"
                            :data-invalid="!!form.errors.environment">
                            <FieldLabel for="secret-editor-environment">
                                Environment
                            </FieldLabel>
                            <ChoiceSelect
                                id="secret-editor-environment"
                                variant="filled"
                                class="min-w-0 w-full"
                                :model-value="formEnvironmentChoice"
                                :options="[...environmentOptions, { value: '__new__', label: 'New…' }]"
                                :aria-invalid="!!form.errors.environment"
                                @update:model-value="chooseFormEnvironment" />
                            <Input
                                v-if="formEnvironmentChoice === '__new__'"
                                v-model="form.environment"
                                variant="filled"
                                aria-label="New environment name"
                                placeholder="Environment name"
                                maxlength="100"
                                required
                                :aria-invalid="!!form.errors.environment" />
                            <FieldError v-if="form.errors.environment">
                                {{ form.errors.environment }}
                            </FieldError>
                        </Field>
                        <Field :data-invalid="!!form.errors.service">
                            <FieldLabel for="secret-editor-service">
                                Service
                            </FieldLabel>
                            <Input
                                id="secret-editor-service"
                                v-model="form.service"
                                variant="filled"
                                list="secret-categories"
                                maxlength="100"
                                placeholder="Optional"
                                :aria-invalid="!!form.errors.service" />
                            <FieldError v-if="form.errors.service">
                                {{ form.errors.service }}
                            </FieldError>
                        </Field>
                    </div>
                    <Field :data-invalid="!!form.errors.management_url">
                        <FieldLabel for="secret-editor-url">
                            Management URL
                        </FieldLabel>
                        <Input
                            id="secret-editor-url"
                            v-model="form.management_url"
                            variant="filled"
                            maxlength="2048"
                            placeholder="https://… (optional)"
                            :aria-invalid="!!form.errors.management_url" />
                        <FieldError v-if="form.errors.management_url">
                            {{ form.errors.management_url }}
                        </FieldError>
                    </Field>
                    <Alert
                        v-if="error"
                        variant="destructive">
                        <AlertDescription>{{ error }}</AlertDescription>
                    </Alert>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="form.processing"
                            @click="closeEditor">
                            Cancel
                        </Button><Button
                            type="submit"
                            :disabled="form.processing || !editorReady">
                            <TextTransition :text="form.processing ? 'Saving…' : metadataOnly ? 'Save context' : 'Save secret'" />
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
        <Dialog
            :open="pasteOpen"
            @update:open="open => { if (!open && !pasteForm.processing) closePaste(); }">
            <DialogContent class="max-h-[calc(100dvh-2rem)] overflow-y-auto">
                <DialogHeader><DialogTitle>Paste .env entries</DialogTitle><DialogDescription>Supports comments, optional export, quoted values, and multiline quoted values. Expressions remain literal.</DialogDescription></DialogHeader>
                <form
                    class="grid gap-4"
                    @submit.prevent="paste">
                    <Field :data-invalid="!!pasteForm.errors.environment">
                        <FieldLabel for="secret-paste-environment">
                            Environment
                        </FieldLabel><Input
                            id="secret-paste-environment"
                            v-model="pasteForm.environment"
                            maxlength="100"
                            required
                            :aria-invalid="!!pasteForm.errors.environment" /><FieldError v-if="pasteForm.errors.environment">
                                {{ pasteForm.errors.environment }}
                            </FieldError>
                    </Field>
                    <Field :data-invalid="!!pasteForm.errors.service">
                        <FieldLabel for="secret-paste-service">
                            Service
                        </FieldLabel><Input
                            id="secret-paste-service"
                            v-model="pasteForm.service"
                            list="secret-categories"
                            maxlength="100"
                            placeholder="Optional"
                            :aria-invalid="!!pasteForm.errors.service" /><FieldError v-if="pasteForm.errors.service">
                                {{ pasteForm.errors.service }}
                            </FieldError>
                    </Field>
                    <Field :data-invalid="!!pasteForm.errors.entries">
                        <FieldLabel for="secret-paste-entries">
                            Entries
                        </FieldLabel><Textarea
                            id="secret-paste-entries"
                            v-model="pasteForm.entries"
                            autocomplete="off"
                            :spellcheck="false"
                            :rows="8"
                            :aria-invalid="!!pasteForm.errors.entries"
                            placeholder="APP_LOCALE=en&#10;APP_FALLBACK_LOCALE=en&#10;APP_FAKER_LOCALE=en_US" /><FieldError v-if="pasteForm.errors.entries">
                                {{ pasteForm.errors.entries }}
                            </FieldError>
                    </Field>
                    <Alert
                        v-if="error"
                        variant="destructive">
                        <AlertDescription>{{ error }}</AlertDescription>
                    </Alert>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="pasteForm.processing"
                            @click="closePaste">
                            Cancel
                        </Button><Button
                            type="submit"
                            :disabled="pasteForm.processing">
                            <TextTransition :text="pasteForm.processing ? 'Saving…' : 'Save entries'" />
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
        <datalist id="secret-categories">
            <option
                v-for="item in services"
                :key="item"
                :value="item" />
        </datalist>
        <Dialog
            :open="bulkOpen"
            @update:open="open => { if (!open && !bulkForm.processing) bulkOpen = false; }">
            <DialogContent>
                <DialogHeader><DialogTitle>Update {{ selectedIds.length }} secrets</DialogTitle><DialogDescription>Changes apply to all selected secrets in this project.</DialogDescription></DialogHeader><form
                    class="space-y-4"
                    @submit.prevent="saveBulk">
                    <Field v-if="bulkField === 'environment'">
                        <FieldLabel for="secret-bulk-environment">
                            Environment
                        </FieldLabel><Input
                            id="secret-bulk-environment"
                            v-model="bulkEnvironment"
                            list="secret-environments"
                            maxlength="100"
                            required /><FieldError v-if="bulkForm.errors.environment">
                                {{ bulkForm.errors.environment }}
                            </FieldError>
                    </Field><Field v-else>
                        <FieldLabel for="secret-bulk-service">
                            Service
                        </FieldLabel><Input
                            id="secret-bulk-service"
                            v-model="bulkService"
                            list="secret-categories"
                            maxlength="100"
                            placeholder="Leave empty to clear service" /><FieldError v-if="bulkForm.errors.service">
                                {{ bulkForm.errors.service }}
                            </FieldError>
                    </Field><FieldError v-if="bulkForm.errors.secrets">
                        {{ bulkForm.errors.secrets }}
                    </FieldError><DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="bulkOpen = false">
                            Cancel
                        </Button><Button
                            type="submit"
                            :disabled="bulkForm.processing">
                            Update secrets
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
        <datalist id="secret-environments">
            <option
                v-for="item in environmentOptions"
                :key="item"
                :value="item" />
        </datalist>
        <Dialog
            :open="importOpen"
            @update:open="open => { if (!open && !importPreviewForm.processing && !importForm.processing) closeImport(); }">
            <DialogContent class="max-h-[85vh] overflow-y-auto">
                <DialogHeader><DialogTitle>Import .env file</DialogTitle><DialogDescription>Orbit previews names only. Existing names are skipped; selected values never enter this page.</DialogDescription></DialogHeader>
                <form
                    class="grid gap-4"
                    @submit.prevent="importPreview ? importEntries() : previewImport()">
                    <Field :data-invalid="!!importPreviewForm.errors.environment">
                        <FieldLabel for="secret-import-environment">
                            Environment
                        </FieldLabel><Input
                            id="secret-import-environment"
                            v-model="importPreviewForm.environment"
                            maxlength="100"
                            required
                            :disabled="!!importPreview"
                            :aria-invalid="!!importPreviewForm.errors.environment" /><FieldError v-if="importPreviewForm.errors.environment">
                                {{ importPreviewForm.errors.environment }}
                            </FieldError>
                    </Field>
                    <template v-if="importPreview">
                        <p class="text-sm text-muted-foreground">
                            {{ importPreview.source }} · {{ importPreview.entries.length }} entries
                        </p><div class="max-h-64 overflow-y-auto rounded-md border">
                            <div
                                v-for="entry in importPreview.entries"
                                :key="entry.name"
                                class="flex items-center justify-between gap-4 border-b px-3 py-2 text-sm last:border-0">
                                <span class="break-all font-medium">{{ entry.name }}</span><Badge
                                    v-if="entry.collision"
                                    variant="secondary">
                                    Existing · skip
                                </Badge><Badge
                                    v-else
                                    variant="outline">
                                    Import
                                </Badge>
                            </div>
                        </div><FieldError v-if="importForm.errors.entries">
                            {{ importForm.errors.entries }}
                        </FieldError>
                    </template>
                    <FieldError v-if="importPreviewForm.errors.entries">
                        {{ importPreviewForm.errors.entries }}
                    </FieldError>
                    <Button
                        v-if="importPreview"
                        type="button"
                        variant="outline"
                        :disabled="importForm.processing"
                        @click="importPreview = null; importForm.clearErrors(); error = ''">
                        Choose another file
                    </Button>
                    <Alert
                        v-if="error"
                        variant="destructive">
                        <AlertDescription>{{ error }}</AlertDescription>
                    </Alert>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="importPreviewForm.processing || importForm.processing"
                            @click="closeImport">
                            Cancel
                        </Button><Button
                            type="submit"
                            :disabled="importPreviewForm.processing || importForm.processing || (!!importPreview && !importPreview.entries.some(entry => !entry.collision))">
                            <TextTransition :text="importPreview ? (importForm.processing ? 'Importing…' : 'Import new entries') : (importPreviewForm.processing ? 'Opening…' : 'Choose file')" />
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
        <Dialog
            :open="exportOpen"
            @update:open="open => { if (!open && !exportPreviewForm.processing && !exportForm.processing) closeExport(); }">
            <DialogContent class="max-h-[85vh] overflow-y-auto">
                <DialogHeader><DialogTitle>Export .env file</DialogTitle><DialogDescription>Export writes plaintext values to the selected file. Choose only the entries you need.</DialogDescription></DialogHeader>
                <form
                    class="grid gap-4"
                    @submit.prevent="exportPreview ? exportEntries() : previewExport()">
                    <Field>
                        <FieldLabel for="secret-export-environment">
                            Environment
                        </FieldLabel><ChoiceSelect
                            id="secret-export-environment"
                            :model-value="exportEnvironment"
                            :options="environments"
                            :disabled="!!exportPreview"
                            @update:model-value="value => { exportEnvironment = value; changeExportEnvironment(); }" />
                    </Field>
                    <Field :data-invalid="!!exportPreviewForm.errors.names">
                        <FieldLabel>Secrets</FieldLabel><div class="max-h-64 overflow-y-auto rounded-md border">
                            <label
                                v-for="secret in secrets.filter(item => item.environment === exportEnvironment)"
                                :key="secret.id"
                                class="flex items-center gap-3 border-b px-3 py-2 text-sm last:border-0"><Checkbox
                                    :model-value="exportNames.includes(secret.name)"
                                    :disabled="!!exportPreview"
                                    @update:model-value="toggleExport(secret.name)" /><span class="break-all font-medium">{{ secret.name }}</span></label>
                        </div><FieldError v-if="exportPreviewForm.errors.names">
                            {{ exportPreviewForm.errors.names }}
                        </FieldError><FieldError v-if="exportForm.errors.names">
                            {{ exportForm.errors.names }}
                        </FieldError>
                    </Field>
                    <template v-if="exportPreview">
                        <Alert>
                            <AlertDescription>
                                <span class="break-all">Destination: {{ exportPreview.destination }}</span><template v-if="exportPreview.exists">
                                    already exists.
                                </template>
                            </AlertDescription>
                        </Alert><Field
                            v-if="exportPreview.exists"
                            :data-invalid="!!exportForm.errors.overwrite">
                            <label class="flex items-center gap-3 text-sm"><Checkbox v-model="exportForm.overwrite" />Replace the existing file</label><FieldError v-if="exportForm.errors.overwrite">
                                {{ exportForm.errors.overwrite }}
                            </FieldError>
                        </Field>
                    </template>
                    <Alert
                        v-if="error"
                        variant="destructive">
                        <AlertDescription>{{ error }}</AlertDescription>
                    </Alert>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="exportPreviewForm.processing || exportForm.processing"
                            @click="closeExport">
                            Cancel
                        </Button><Button
                            type="submit"
                            :disabled="exportPreviewForm.processing || exportForm.processing || !exportNames.length || (!!exportPreview && exportPreview.exists && !exportForm.overwrite)">
                            <TextTransition :text="exportPreview ? (exportForm.processing ? 'Exporting…' : 'Export plaintext .env') : (exportPreviewForm.processing ? 'Opening…' : (error ? 'Preview again / change destination' : 'Choose destination'))" />
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
        <Dialog
            :open="bulkRemoveOpen"
            @update:open="open => { if (!open && !bulkRemoval.processing) bulkRemoveOpen = false; }">
            <DialogContent>
                <DialogHeader><DialogTitle>Delete {{ bulkRemoval.secrets.length }} selected {{ bulkRemoval.secrets.length === 1 ? 'secret' : 'secrets' }}?</DialogTitle><DialogDescription>This permanently removes the selected secrets from this project.</DialogDescription></DialogHeader><DialogFooter>
                    <Button
                        variant="outline"
                        :disabled="bulkRemoval.processing"
                        @click="bulkRemoveOpen = false">
                        Cancel
                    </Button><Button
                        variant="destructive"
                        :disabled="bulkRemoval.processing || !unlocked"
                        @click="removeSelected">
                        <TextTransition :text="bulkRemoval.processing ? 'Deleting…' : 'Delete selected secrets'" />
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
        <Dialog
            :open="!!removing"
            @update:open="open => { if (!open) removing = null; }">
            <DialogContent>
                <DialogHeader><DialogTitle>Delete {{ removing?.name }}?</DialogTitle><DialogDescription>This permanently removes the secret from the {{ removing?.environment }} environment.</DialogDescription></DialogHeader><DialogFooter>
                    <Button
                        variant="outline"
                        @click="removing = null">
                        Cancel
                    </Button><Button
                        variant="destructive"
                        :disabled="removal.processing"
                        @click="remove">
                        Delete secret
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
