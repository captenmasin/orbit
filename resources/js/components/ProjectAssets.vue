<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { ChevronRightIcon, DownloadIcon, FileIcon, FolderIcon, FolderPlusIcon, PencilIcon, Trash2Icon, UploadIcon } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Field, FieldLabel, FieldDescription, FieldError } from '@/components/ui/field';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ContextMenuContent, ContextMenuItem, ContextMenuPortal, ContextMenuRoot, ContextMenuTrigger } from 'reka-ui';
import type { Project } from '@/types';
const props = defineProps<{ project: Project }>();
type AssetFolder = NonNullable<Project['asset_folders']>[number];
const upload = useForm({ revision: props.project.revision, files: [] as File[], paths: [] as string[], directories: [] as string[], folder_id: null as string | null });
const removal = useForm({ revision: props.project.revision });
const movement = useForm({ revision: props.project.revision, folder_id: null as string | null, name: undefined as string | undefined });
const folderForm = useForm({ revision: props.project.revision, name: '', parent_id: null as string | null });
const folderEditorOpen = ref(false);
const editingFolder = ref<AssetFolder | null>(null);
const renamingFile = ref<NonNullable<Project['assets']>[number] | null>(null);
const fileName = ref('');
const removing = ref<{ id: string; name: string; kind: 'file' | 'folder' } | null>(null);
const preview = ref<NonNullable<Project['assets']>[number] | null>(null);
const failedPreviews = ref<string[]>([]);
const pickerKey = ref(0);
const selectedType = ref('');
const selectedFolder = ref('');
const dropTarget = ref<string | null>(null);
const dropError = ref('');
const readingDrop = ref(false);
const folders = computed(() => [...(props.project.asset_folders ?? [])].sort((a, b) => a.name.localeCompare(b.name)));
const currentFolder = computed(() => folders.value.find(folder => folder.id === selectedFolder.value));
const childFolders = computed(() => folders.value.filter(folder => (folder.parent_id ?? '') === selectedFolder.value));
const breadcrumbs = computed(() => {
    const path: AssetFolder[] = [];
    let folder = currentFolder.value;
    while (folder && !path.some(item => item.id === folder!.id)) {
        path.unshift(folder);
        folder = folders.value.find(item => item.id === folder!.parent_id);
    }
    return path;
});
const assets = computed(() => (props.project.assets ?? []).map(file => ({ ...file, type: assetType(file.mime_type) })));
const folderAssets = computed(() => assets.value.filter(file => (file.folder_id ?? '') === selectedFolder.value));
const typeFilters = computed(() => ['Images', 'Documents', 'Audio', 'Video', 'Archives', 'Other'].map(type => ({ type, count: folderAssets.value.filter(file => file.type === type).length })));
const filteredAssets = computed(() => selectedType.value ? folderAssets.value.filter(file => file.type === selectedType.value) : folderAssets.value);
const busy = computed(() => readingDrop.value || upload.processing || removal.processing || movement.processing || folderForm.processing);
const errors = computed(() => ({ ...upload.errors, ...removal.errors, ...movement.errors, ...folderForm.errors }));
watch(selectedFolder, () => { selectedType.value = ''; });
watch(folders, () => { if (selectedFolder.value && !currentFolder.value) selectedFolder.value = ''; });
const size = (bytes: number) => bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.ceil(bytes / 1024))} KB`;
function assetType(mime: string | null) {
    if (mime?.startsWith('image/')) return 'Images';
    if (mime?.startsWith('audio/')) return 'Audio';
    if (mime?.startsWith('video/')) return 'Video';
    if (mime?.startsWith('text/') || /^application\/(pdf|rtf|json|xml|msword|vnd\.ms-|vnd\.openxmlformats-officedocument\.|vnd\.oasis\.opendocument\.)/.test(mime ?? '')) return 'Documents';
    if (/^application\/(zip|gzip|x-gzip|x-tar|x-7z-compressed|x-rar-compressed|vnd\.rar|x-bzip2|x-xz)$/.test(mime ?? '')) return 'Archives';
    return 'Other';
}
function submit(folderId = selectedFolder.value) {
    upload.revision = props.project.revision;
    upload.folder_id = folderId || null;
    upload.post(`/projects/${props.project.id}/assets`, { preserveScroll: true, errorBag: 'assets', onSuccess: () => { upload.reset('files', 'paths', 'directories'); pickerKey.value++; } });
}
function startDrag(event: DragEvent, kind: 'file' | 'folder', id: string) {
    if (!event.dataTransfer || busy.value) return;
    event.dataTransfer.setData('application/x-orbit-asset', JSON.stringify({ projectId: props.project.id, kind, id }));
    event.dataTransfer.effectAllowed = 'move';
}
function allowDrop(event: DragEvent, folderId: string) {
    if (busy.value || !event.dataTransfer || !Array.from(event.dataTransfer.types).some(type => type === 'Files' || type === 'application/x-orbit-asset')) return;
    event.preventDefault();
    event.dataTransfer.dropEffect = Array.from(event.dataTransfer.types).includes('application/x-orbit-asset') ? 'move' : 'copy';
    dropTarget.value = folderId || '__root__';
}
function leaveDrop(event: DragEvent) {
    if (!(event.currentTarget as Element).contains(event.relatedTarget as Node | null)) dropTarget.value = null;
}
async function readEntry(entry: FileSystemEntry, parent: string, files: File[], paths: string[], directories: string[]): Promise<void> {
    const path = parent ? `${parent}/${entry.name}` : entry.name;
    if (entry.isDirectory) {
        directories.push(path);
        const reader = (entry as FileSystemDirectoryEntry).createReader();
        while (true) {
            const entries = await new Promise<FileSystemEntry[]>((resolve, reject) => reader.readEntries(resolve, reject));
            if (!entries.length) break;
            for (const child of entries) await readEntry(child, path, files, paths, directories);
        }
    } else {
        const file = await new Promise<File>((resolve, reject) => (entry as FileSystemFileEntry).file(resolve, reject));
        files.push(file);
        paths.push(path);
    }
}
async function dropOn(event: DragEvent, folderId: string) {
    dropTarget.value = null;
    const transfer = event.dataTransfer;
    if (!transfer || busy.value) return;
    const internal = transfer.getData('application/x-orbit-asset');
    if (internal) {
        try {
            const item = JSON.parse(internal) as { projectId: string; kind: string; id: string };
            if (item.projectId !== props.project.id) return;
            if (item.kind === 'file') {
                const file = assets.value.find(asset => asset.id === item.id);
                if (file && (file.folder_id ?? '') !== folderId) move(file, folderId);
            } else if (item.kind === 'folder') {
                const folder = folders.value.find(candidate => candidate.id === item.id);
                if (folder && (folder.parent_id ?? '') !== folderId) {
                    let ancestor = folderId;
                    while (ancestor) {
                        if (ancestor === folder.id) return;
                        ancestor = folders.value.find(candidate => candidate.id === ancestor)?.parent_id ?? '';
                    }
                    folderForm.revision = props.project.revision;
                    folderForm.name = folder.name;
                    folderForm.parent_id = folderId || null;
                    folderForm.put(`/projects/${props.project.id}/asset-folders/${folder.id}`, { preserveScroll: true, errorBag: 'assets' });
                }
            }
        } catch {
            dropError.value = 'This asset could not be moved.';
        }
        return;
    }
    const items = Array.from(transfer.items).filter(item => item.kind === 'file');
    const entries = items.map(item => item.webkitGetAsEntry()).filter((entry): entry is FileSystemEntry => entry !== null);
    const fallbackFiles = Array.from(transfer.files);
    if (!entries.length && !fallbackFiles.length) return;
    readingDrop.value = true;
    dropError.value = '';
    try {
        const files: File[] = [];
        const paths: string[] = [];
        const directories: string[] = [];
        if (entries.length === items.length) {
            for (const entry of entries) await readEntry(entry, '', files, paths, directories);
        } else {
            files.push(...fallbackFiles);
        }
        if (files.length > 100 || directories.length > 100) {
            dropError.value = 'A project can store up to 100 files and 100 folders.';
            return;
        }
        upload.files = files;
        upload.paths = paths;
        upload.directories = directories;
        submit(folderId);
    } catch {
        dropError.value = 'The dropped folder could not be read. Try again.';
    } finally {
        readingDrop.value = false;
    }
}
function remove() {
    if (!removing.value) return;
    removal.revision = props.project.revision;
    removal.delete(`/projects/${props.project.id}/${removing.value.kind === 'folder' ? 'asset-folders' : 'assets'}/${removing.value.id}`, { preserveScroll: true, errorBag: 'assets', onSuccess: () => { removing.value = null; } });
}
function editFolder(folder: AssetFolder | null = null) {
    editingFolder.value = folder;
    folderForm.name = folder?.name ?? '';
    folderForm.parent_id = folder?.parent_id ?? (selectedFolder.value || null);
    folderForm.clearErrors();
    folderEditorOpen.value = true;
}
function saveFolder() {
    folderForm.revision = props.project.revision;
    const options = { preserveScroll: true, errorBag: 'assets', onSuccess: () => { folderEditorOpen.value = false; } };
    if (editingFolder.value) folderForm.put(`/projects/${props.project.id}/asset-folders/${editingFolder.value.id}`, options);
    else folderForm.post(`/projects/${props.project.id}/asset-folders`, options);
}
function move(file: { id: string }, folderId: string) {
    movement.revision = props.project.revision;
    movement.folder_id = folderId || null;
    movement.name = undefined;
    movement.put(`/projects/${props.project.id}/assets/${file.id}`, { preserveScroll: true, errorBag: 'assets' });
}
function renameFile() {
    if (!renamingFile.value) return;
    movement.revision = props.project.revision;
    movement.folder_id = renamingFile.value.folder_id;
    movement.name = fileName.value;
    movement.put(`/projects/${props.project.id}/assets/${renamingFile.value.id}`, { preserveScroll: true, errorBag: 'assets', onSuccess: () => { renamingFile.value = null; movement.name = undefined; } });
}
</script>

<template>
    <section class="space-y-4 rounded-lg border p-5" :class="dropTarget === (selectedFolder || '__root__') ? 'border-primary bg-primary/5' : ''" aria-labelledby="assets-title" @dragover="allowDrop($event, selectedFolder)" @dragleave="leaveDrop" @drop.prevent="dropOn($event, selectedFolder)">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="assets-title" class="text-lg font-semibold">Assets</h2>
            <div class="flex flex-wrap items-center gap-2">
                <NativeSelect v-if="folderAssets.length || selectedType" v-model="selectedType" aria-label="Filter assets by type">
                    <NativeSelectOption value="">All types ({{ folderAssets.length }})</NativeSelectOption>
                    <NativeSelectOption v-for="filter in typeFilters" :key="filter.type" :value="filter.type">{{ filter.type }} ({{ filter.count }})</NativeSelectOption>
                </NativeSelect>
                <Button variant="outline" :disabled="busy" @click="editFolder()"><FolderPlusIcon aria-hidden="true" />New folder</Button>
            </div>
        </div>
        <Alert v-if="Object.keys(errors).length" variant="destructive"><AlertDescription><p v-for="(error, key) in errors" :key="key">{{ error }}</p><Button v-if="errors.revision" variant="outline" @click="router.reload()">Reload project</Button></AlertDescription></Alert>
        <Alert v-if="dropError" variant="destructive"><AlertDescription>{{ dropError }}</AlertDescription></Alert>
        <nav v-if="folders.length" aria-label="Asset folders" class="flex flex-wrap items-center gap-2 text-sm">
            <Button variant="link" size="sm" class="px-0" :class="dropTarget === '__root__' ? 'rounded bg-primary/10' : ''" :disabled="busy" :aria-current="!currentFolder ? 'page' : undefined" @click="selectedFolder = ''" @dragover.stop="allowDrop($event, '')" @dragleave.stop="leaveDrop" @drop.stop.prevent="dropOn($event, '')">Assets</Button>
            <template v-for="(folder, index) in breadcrumbs" :key="folder.id"><ChevronRightIcon class="size-4 text-muted-foreground" aria-hidden="true" /><Button v-if="index < breadcrumbs.length - 1" variant="link" size="sm" class="px-0" :class="dropTarget === folder.id ? 'rounded bg-primary/10' : ''" @click="selectedFolder = folder.id" @dragover.stop="allowDrop($event, folder.id)" @dragleave.stop="leaveDrop" @drop.stop.prevent="dropOn($event, folder.id)">{{ folder.name }}</Button><span v-else class="min-w-0 truncate font-medium" aria-current="page">{{ folder.name }}</span></template>
        </nav>
        <ul v-if="childFolders.length" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" aria-label="Folders">
            <ContextMenuRoot v-for="folder in childFolders" :key="folder.id">
            <ContextMenuTrigger as-child><li draggable="true" class="flex items-center gap-1 rounded-lg border p-2" :class="dropTarget === folder.id ? 'border-primary bg-primary/10' : ''" @dragstart="startDrag($event, 'folder', folder.id)" @dragend="dropTarget = null" @dragover.stop="allowDrop($event, folder.id)" @dragleave.stop="leaveDrop" @drop.stop.prevent="dropOn($event, folder.id)">
                <Button variant="ghost" class="h-auto min-w-0 flex-1 justify-start py-3" :disabled="busy" @click="selectedFolder = folder.id"><FolderIcon class="shrink-0 text-muted-foreground" aria-hidden="true" /><span class="truncate">{{ folder.name }}</span><span class="ml-auto text-xs text-muted-foreground">{{ assets.filter(file => file.folder_id === folder.id).length }}</span></Button>
                <Button variant="ghost" size="icon-sm" :aria-label="`Rename ${folder.name}`" :disabled="busy" @click="editFolder(folder)"><PencilIcon aria-hidden="true" /></Button>
                <Button variant="ghost" size="icon-sm" :aria-label="`Remove folder ${folder.name}`" :disabled="busy" @click="removing = { ...folder, kind: 'folder' }"><Trash2Icon aria-hidden="true" /></Button>
            </li></ContextMenuTrigger>
            <ContextMenuPortal><ContextMenuContent class="z-50 min-w-40 rounded-md border bg-popover p-1 text-sm text-popover-foreground shadow-md"><ContextMenuItem class="cursor-default rounded px-2 py-1.5 outline-none focus:bg-accent" @select="selectedFolder = folder.id">Open</ContextMenuItem><ContextMenuItem class="cursor-default rounded px-2 py-1.5 outline-none focus:bg-accent" @select="editFolder(folder)">Rename</ContextMenuItem><ContextMenuItem class="cursor-default rounded px-2 py-1.5 text-destructive outline-none focus:bg-accent" @select="removing = { ...folder, kind: 'folder' }">Remove</ContextMenuItem></ContextMenuContent></ContextMenuPortal>
            </ContextMenuRoot>
        </ul>
        <p class="sr-only" role="status">Showing {{ filteredAssets.length }} of {{ folderAssets.length }} assets in {{ currentFolder?.name ?? 'Assets' }}.</p>
        <ul v-if="filteredAssets.length" class="divide-y rounded-lg border">
            <ContextMenuRoot v-for="file in filteredAssets" :key="file.id">
            <ContextMenuTrigger as-child><li draggable="true" class="flex flex-wrap items-center gap-3 p-3" @dragstart="startDrag($event, 'file', file.id)" @dragend="dropTarget = null">
                <button v-if="file.preview_url && !failedPreviews.includes(file.id)" type="button" class="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-md border bg-muted/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring" :aria-label="`Preview ${file.name}`" @click="preview = file">
                    <img :src="file.preview_url" alt="" loading="lazy" class="size-full object-contain" @error="failedPreviews.push(file.id)" />
                </button>
                <FileIcon v-else class="size-5 shrink-0 text-muted-foreground" aria-hidden="true" />
                <button v-if="file.preview_url && !failedPreviews.includes(file.id)" type="button" class="min-w-0 flex-1 truncate text-left text-sm font-medium underline-offset-4 hover:underline" @click="preview = file">{{ file.name }}</button>
                <a v-else :href="file.url" download class="min-w-0 flex-1 truncate text-sm font-medium underline-offset-4 hover:underline">{{ file.name }}</a>
                <span class="shrink-0 text-xs text-muted-foreground">{{ size(file.size) }}</span>
                <Button as-child variant="ghost" size="icon-sm"><a :href="file.url" download :aria-label="`Download ${file.name}`"><DownloadIcon aria-hidden="true" /></a></Button>
                <Button variant="ghost" size="icon-sm" :aria-label="`Rename ${file.name}`" :disabled="busy" @click="renamingFile = file; fileName = file.name"><PencilIcon aria-hidden="true" /></Button>
                <NativeSelect v-if="folders.length" :model-value="file.folder_id ?? ''" class="max-w-40" :aria-label="`Move ${file.name} to folder`" :disabled="busy" @update:model-value="move(file, String($event))"><NativeSelectOption value="">Assets</NativeSelectOption><NativeSelectOption v-for="folder in folders" :key="folder.id" :value="folder.id">{{ folder.name }}</NativeSelectOption></NativeSelect>
                <Button variant="ghost" size="icon-sm" :aria-label="`Remove ${file.name}`" :disabled="busy" @click="removing = { ...file, kind: 'file' }"><Trash2Icon aria-hidden="true" /></Button>
            </li></ContextMenuTrigger>
            <ContextMenuPortal><ContextMenuContent class="z-50 min-w-40 rounded-md border bg-popover p-1 text-sm text-popover-foreground shadow-md"><ContextMenuItem v-if="file.preview_url" class="cursor-default rounded px-2 py-1.5 outline-none focus:bg-accent" @select="preview = file">Preview</ContextMenuItem><ContextMenuItem as-child><a :href="file.url" download class="block cursor-default rounded px-2 py-1.5 outline-none focus:bg-accent">Download</a></ContextMenuItem><ContextMenuItem class="cursor-default rounded px-2 py-1.5 outline-none focus:bg-accent" @select="renamingFile = file; fileName = file.name">Rename</ContextMenuItem><ContextMenuItem class="cursor-default rounded px-2 py-1.5 text-destructive outline-none focus:bg-accent" @select="removing = { ...file, kind: 'file' }">Remove</ContextMenuItem></ContextMenuContent></ContextMenuPortal>
            </ContextMenuRoot>
        </ul>
        <div v-else-if="selectedType" class="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
            <p>No assets match this type.</p><Button variant="link" size="sm" @click="selectedType = ''">Show all types</Button>
        </div>
        <p v-else-if="!childFolders.length" class="text-sm text-muted-foreground">{{ currentFolder ? 'This folder is empty. Upload files here or move existing assets into it.' : 'Keep designs, screenshots, documents, and other project files here.' }}</p>
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="submit()">
            <Field class="min-w-52 flex-1"><FieldLabel for="asset-files">{{ currentFolder ? `Add files to ${currentFolder.name}` : 'Add files' }}</FieldLabel><Input id="asset-files" :key="pickerKey" type="file" multiple :disabled="busy" @change="upload.files = Array.from(($event.target as HTMLInputElement).files ?? []); upload.paths = []; upload.directories = []" /><FieldDescription>Drop files or folders here, or choose files. Up to 100 files per project, 10 MB each. Files are copied into Orbit.</FieldDescription></Field>
            <Button type="submit" variant="outline" :disabled="!upload.files.length || busy"><UploadIcon aria-hidden="true" />{{ upload.processing ? `Uploading ${upload.progress?.percentage ?? 0}%` : 'Upload' }}</Button>
        </form>
    </section>
    <Dialog :open="folderEditorOpen" @update:open="value => { if (!folderForm.processing) folderEditorOpen = value; }"><DialogContent><DialogHeader><DialogTitle>{{ editingFolder ? 'Rename folder' : 'New asset folder' }}</DialogTitle><DialogDescription>Organize project assets into folders such as logos or screenshots.</DialogDescription></DialogHeader>
        <form class="space-y-4" @submit.prevent="saveFolder"><Field><FieldLabel for="asset-folder-name">Folder name</FieldLabel><Input id="asset-folder-name" v-model="folderForm.name" placeholder="logo" maxlength="100" required :aria-invalid="!!folderForm.errors.name" /><FieldError v-if="folderForm.errors.name">{{ folderForm.errors.name }}</FieldError></Field><Field><FieldLabel for="asset-folder-parent">Inside</FieldLabel><NativeSelect id="asset-folder-parent" v-model="folderForm.parent_id"><NativeSelectOption value="">Assets</NativeSelectOption><NativeSelectOption v-for="folder in folders.filter(item => item.id !== editingFolder?.id)" :key="folder.id" :value="folder.id">{{ folder.name }}</NativeSelectOption></NativeSelect><FieldError v-if="folderForm.errors.parent_id">{{ folderForm.errors.parent_id }}</FieldError></Field><FieldError v-if="folderForm.errors.revision">{{ folderForm.errors.revision }}</FieldError><DialogFooter><Button type="button" variant="outline" :disabled="folderForm.processing" @click="folderEditorOpen = false">Cancel</Button><Button type="submit" :disabled="folderForm.processing">{{ folderForm.processing ? 'Saving…' : 'Save folder' }}</Button></DialogFooter></form>
    </DialogContent></Dialog>
    <Dialog :open="!!renamingFile" @update:open="value => { if (!value && !movement.processing) renamingFile = null; }"><DialogContent><DialogHeader><DialogTitle>Rename file</DialogTitle><DialogDescription>Change the name shown in Orbit and used for downloads.</DialogDescription></DialogHeader><form class="space-y-4" @submit.prevent="renameFile"><Field><FieldLabel for="asset-file-name">File name</FieldLabel><Input id="asset-file-name" v-model="fileName" maxlength="255" required /><FieldError v-if="movement.errors.name">{{ movement.errors.name }}</FieldError></Field><DialogFooter><Button type="button" variant="outline" @click="renamingFile = null">Cancel</Button><Button type="submit" :disabled="movement.processing">Rename</Button></DialogFooter></form></DialogContent></Dialog>
    <Dialog :open="!!preview" @update:open="value => { if (!value) preview = null; }">
        <DialogContent v-if="preview" class="max-h-[90dvh] overflow-y-auto sm:max-w-4xl">
            <DialogHeader><DialogTitle class="break-all pr-8">{{ preview.name }}</DialogTitle><DialogDescription>{{ size(preview.size) }}</DialogDescription></DialogHeader>
            <img v-if="preview.preview_url && !failedPreviews.includes(preview.id)" :src="preview.preview_url" :alt="preview.name" class="mx-auto max-h-[65dvh] max-w-full object-contain" @error="failedPreviews.push(preview.id)" />
            <p v-else class="text-sm text-muted-foreground" role="status">This image could not be previewed. You can download it instead.</p>
            <DialogFooter><Button as-child variant="outline"><a :href="preview.url" download><DownloadIcon aria-hidden="true" />Download</a></Button></DialogFooter>
        </DialogContent>
    </Dialog>
    <Dialog :open="!!removing" @update:open="value => { if (!value && !removal.processing) removing = null; }">
        <DialogContent><DialogHeader><DialogTitle>Remove {{ removing?.name }}?</DialogTitle><DialogDescription>{{ removing?.kind === 'folder' ? 'Files and subfolders will move into the parent folder. No files will be deleted.' : 'This deletes the stored copy from Orbit. Your original file stays on disk.' }}</DialogDescription></DialogHeader><FieldError v-for="(error, key) in removal.errors" :key="key">{{ error }}</FieldError><DialogFooter><Button variant="outline" :disabled="removal.processing" @click="removing = null">Cancel</Button><Button variant="destructive" :disabled="removal.processing" @click="remove">Remove {{ removing?.kind }}</Button></DialogFooter></DialogContent>
    </Dialog>
</template>
