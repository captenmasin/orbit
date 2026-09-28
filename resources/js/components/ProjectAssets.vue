<script setup lang="ts">
import ChoiceSelect from '@/components/ChoiceSelect.vue';
import FilterSelect from '@/components/FilterSelect.vue';
import TextTransition from '@/components/TextTransition.vue';
import { toast } from 'vue-sonner';
import type { Project } from '@/types';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { router, useForm } from '@inertiajs/vue3';
import { computed, onScopeDispose, ref, watch } from 'vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Field, FieldLabel, FieldError } from '@/components/ui/field';
import { ContextMenu, ContextMenuContent, ContextMenuItem, ContextMenuTrigger } from '@/components/ui/context-menu';
import { DropdownMenuContent, DropdownMenuItem, DropdownMenuPortal, DropdownMenuRoot, DropdownMenuTrigger } from 'reka-ui';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ArrowLeftIcon, ChevronRightIcon, DownloadIcon, EllipsisVerticalIcon, FileArchiveIcon, FileAudioIcon, FileCode2Icon, FileIcon, FileTextIcon, FileVideoIcon, FolderIcon, FolderPlusIcon, LayoutGridIcon, ListIcon, PencilIcon, SearchIcon, Trash2Icon, UploadIcon } from '@lucide/vue';
const props = defineProps<{ project: Project }>();
type AssetFolder = NonNullable<Project['asset_folders']>[number];
type AssetSelection = { type: 'file' | 'folder'; id: string };
const upload = useForm({ revision: props.project.revision, files: [] as File[], paths: [] as string[], directories: [] as string[], folder_id: null as string | null });
const movement = useForm({ revision: props.project.revision, folder_id: null as string | null, name: undefined as string | undefined });
const bulkMovement = useForm({ revision: props.project.revision, folder_id: null as string | null, items: [] as AssetSelection[] });
const bulkRemoval = useForm({ revision: props.project.revision, items: [] as AssetSelection[] });
const folderForm = useForm({ revision: props.project.revision, name: '', parent_id: null as string | null });
const moveOpen = ref(false);
const removeOpen = ref(false);
const moveDestination = ref('');
const folderEditorOpen = ref(false);
const editingFolder = ref<AssetFolder | null>(null);
const renamingFile = ref<NonNullable<Project['assets']>[number] | null>(null);
const fileName = ref('');
const preview = ref<NonNullable<Project['assets']>[number] | null>(null);
const failedPreviews = ref<string[]>([]);
const uploadPicker = ref<HTMLInputElement | null>(null);
const selectedType = ref('');
const selectedFolder = ref('');
const search = ref('');
const sortBy = ref('default');
const view = ref<'list' | 'tiles'>('tiles');
const selectedKeys = ref<string[]>([]);
const selectionAnchor = ref<string | null>(null);
const canvas = ref<HTMLElement | null>(null);
const marquee = ref<{ x: number; y: number; width: number; height: number } | null>(null);
let marqueeStart: { x: number; y: number; clientX: number; clientY: number; base: string[] } | null = null;
const dropTarget = ref<string | null>(null);
const readingDrop = ref(false);
let mounted = true;
onScopeDispose(() => { mounted = false; readingDrop.value = false; upload.cancel(); });
const folders = computed(() => [...(props.project.asset_folders ?? [])].sort((a, b) => a.name.localeCompare(b.name)));
const folderTree = computed(() => {
    const children = new Map<string, AssetFolder[]>();
    for (const folder of folders.value) {
        const parentId = folder.parent_id ?? '';
        children.set(parentId, [...(children.get(parentId) ?? []), folder]);
    }
    const tree: { folder: AssetFolder; depth: number; path: string }[] = [];
    const seen = new Set<string>();
    function visit(parentId: string, depth: number, parentPath: string) {
        for (const folder of children.get(parentId) ?? []) {
            if (seen.has(folder.id)) continue;
            seen.add(folder.id);
            const path = parentPath ? `${parentPath} / ${folder.name}` : folder.name;
            tree.push({ folder, depth, path });
            visit(folder.id, depth + 1, path);
        }
    }
    visit('', 0, '');
    return tree;
});
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
const filteredAssets = computed(() => {
    const query = search.value.trim().toLocaleLowerCase();
    const files = folderAssets.value.filter(file => (!selectedType.value || file.type === selectedType.value) && (!query || file.name.toLocaleLowerCase().includes(query)));
    if (sortBy.value === 'name') return [...files].sort((a, b) => a.name.localeCompare(b.name));
    if (sortBy.value === 'size') return [...files].sort((a, b) => b.size - a.size);
    return files;
});
const visibleKeys = computed(() => [...childFolders.value.map(folder => `folder:${folder.id}`), ...filteredAssets.value.map(file => `file:${file.id}`)]);
const selectedItems = computed<AssetSelection[]>(() => selectedKeys.value.map(key => {
    const [type, id] = key.split(':');
    return { type: type as AssetSelection['type'], id };
}));
const moveDestinations = computed(() => {
    const selectedFolders = new Set(selectedItems.value.filter(item => item.type === 'folder').map(item => item.id));
    return folderTree.value.filter(node => {
        let folderId: string | null = node.folder.id;
        while (folderId) {
            if (selectedFolders.has(folderId)) return false;
            folderId = folders.value.find(folder => folder.id === folderId)?.parent_id ?? null;
        }
        return true;
    });
});
const parentOptions = computed(() => [{ value: '', label: 'Assets' }, ...folderTree.value.filter(node => {
    let id: string | null = node.folder.id;
    while (id) {
        if (id === editingFolder.value?.id) return false;
        id = folders.value.find(folder => folder.id === id)?.parent_id ?? null;
    }
    return true;
}).map(node => ({ value: node.folder.id, label: node.path }))]);
const removalLabel = computed(() => selectedItems.value.every(item => item.type === 'file') ? 'Delete files' : selectedItems.value.every(item => item.type === 'folder') ? 'Remove folders, keep contents' : 'Delete files and remove folders');
const removalNames = computed(() => selectedItems.value.map(item => ({ ...item, name: item.type === 'folder' ? folderTree.value.find(node => node.folder.id === item.id)?.path : assets.value.find(file => file.id === item.id)?.name })));
const menuItemClass = 'flex cursor-default select-none items-center rounded-sm px-2 py-1.5 text-sm outline-none data-highlighted:bg-accent data-highlighted:text-accent-foreground data-disabled:pointer-events-none data-disabled:opacity-50';
const busy = computed(() => readingDrop.value || upload.processing || bulkRemoval.processing || bulkMovement.processing || movement.processing || folderForm.processing);
const errors = computed(() => ({ ...upload.errors, ...bulkRemoval.errors, ...bulkMovement.errors, ...movement.errors, ...folderForm.errors }));
watch(selectedFolder, () => { selectedType.value = ''; search.value = ''; clearSelection(); });
watch([search, selectedType], clearSelection);
watch(folders, () => { if (selectedFolder.value && !currentFolder.value) selectedFolder.value = ''; });
watch([folders, assets], () => {
    const available = new Set([...folders.value.map(folder => `folder:${folder.id}`), ...assets.value.map(file => `file:${file.id}`)]);
    selectedKeys.value = selectedKeys.value.filter(key => available.has(key));
});
const size = (bytes: number) => bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.ceil(bytes / 1024))} KB`;
const selectionKey = (type: AssetSelection['type'], id: string) => `${type}:${id}`;
function clearSelection() { selectedKeys.value = []; selectionAnchor.value = null; }
function selectItem(event: Pick<MouseEvent, 'metaKey' | 'ctrlKey' | 'shiftKey'>, key: string) {
    if (event.shiftKey && selectionAnchor.value && visibleKeys.value.includes(selectionAnchor.value)) {
        const start = visibleKeys.value.indexOf(selectionAnchor.value);
        const end = visibleKeys.value.indexOf(key);
        if (end !== -1) selectedKeys.value = visibleKeys.value.slice(Math.min(start, end), Math.max(start, end) + 1);
    } else if (event.metaKey || event.ctrlKey) {
        selectedKeys.value = selectedKeys.value.includes(key) ? selectedKeys.value.filter(item => item !== key) : [...selectedKeys.value, key];
        selectionAnchor.value = key;
    } else {
        selectedKeys.value = [key];
        selectionAnchor.value = key;
    }
}
function selectForMenu(key: string) {
    if (!selectedKeys.value.includes(key)) selectedKeys.value = [key];
    selectionAnchor.value = key;
}
function openItem(type: AssetSelection['type'], id: string) {
    if (type === 'folder') { selectedFolder.value = id; return; }
    const file = assets.value.find(item => item.id === id);
    if (!file) return;
    if (file.preview_url && !failedPreviews.value.includes(id)) preview.value = file;
    else window.location.assign(file.url);
}
function onItemKeydown(event: KeyboardEvent, key: string) {
    if (event.key === 'Enter') {
        event.preventDefault();
        const [type, id] = key.split(':');
        openItem(type as AssetSelection['type'], id);
        return;
    }
    if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) return;
    const buttons = [...(canvas.value?.querySelectorAll<HTMLButtonElement>('[data-asset-select]') ?? [])];
    const index = buttons.findIndex(button => button.dataset.assetSelect === key);
    if (index === -1) return;
    let next: HTMLButtonElement | undefined;
    if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
        next = buttons[index + (event.key === 'ArrowLeft' ? -1 : 1)];
    } else {
        const rect = buttons[index].getBoundingClientRect();
        const centerX = (rect.left + rect.right) / 2;
        const centerY = (rect.top + rect.bottom) / 2;
        next = buttons.filter(button => {
            const bounds = button.getBoundingClientRect();
            const y = (bounds.top + bounds.bottom) / 2;
            return event.key === 'ArrowUp' ? y < centerY - 1 : y > centerY + 1;
        }).sort((first, second) => {
            const score = (button: HTMLButtonElement) => {
                const bounds = button.getBoundingClientRect();
                return Math.abs((bounds.top + bounds.bottom) / 2 - centerY) * 2 + Math.abs((bounds.left + bounds.right) / 2 - centerX);
            };
            return score(first) - score(second);
        })[0];
    }
    if (!next || next === buttons[index]) return;
    event.preventDefault();
    next.focus();
    selectItem({ metaKey: false, ctrlKey: false, shiftKey: event.shiftKey }, next.dataset.assetSelect!);
}
function onExplorerKeydown(event: KeyboardEvent) {
    if ((event.target as HTMLElement).closest('input, textarea, [role="combobox"]')) return;
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'a') {
        event.preventDefault();
        selectedKeys.value = [...visibleKeys.value];
        selectionAnchor.value = visibleKeys.value[0] ?? null;
    } else if (event.key === 'Escape') clearSelection();
    else if (event.key === 'Delete' || event.key === 'Backspace') {
        if (selectedKeys.value.length) { event.preventDefault(); removeOpen.value = true; }
    } else if (event.key === 'F2' && selectedKeys.value.length === 1) {
        event.preventDefault();
        renameSelected();
    }
}
function canvasPointerDown(event: PointerEvent) {
    if (event.button !== 0 || event.pointerType !== 'mouse' || (event.target as Element).closest('[data-asset-item], button, a, input')) return;
    const element = event.currentTarget as HTMLElement;
    const bounds = element.getBoundingClientRect();
    marqueeStart = { x: event.clientX - bounds.left, y: event.clientY - bounds.top, clientX: event.clientX, clientY: event.clientY, base: event.metaKey || event.ctrlKey || event.shiftKey ? [...selectedKeys.value] : [] };
    element.setPointerCapture(event.pointerId);
    event.preventDefault();
}
function canvasPointerMove(event: PointerEvent) {
    if (!marqueeStart) return;
    if (!marquee.value && Math.hypot(event.clientX - marqueeStart.clientX, event.clientY - marqueeStart.clientY) < 4) return;
    const element = event.currentTarget as HTMLElement;
    const bounds = element.getBoundingClientRect();
    const x = event.clientX - bounds.left;
    const y = event.clientY - bounds.top;
    marquee.value = { x: Math.min(marqueeStart.x, x), y: Math.min(marqueeStart.y, y), width: Math.abs(x - marqueeStart.x), height: Math.abs(y - marqueeStart.y) };
    const area = { left: Math.min(marqueeStart.clientX, event.clientX), right: Math.max(marqueeStart.clientX, event.clientX), top: Math.min(marqueeStart.clientY, event.clientY), bottom: Math.max(marqueeStart.clientY, event.clientY) };
    const hits = [...element.querySelectorAll<HTMLElement>('[data-asset-item]')].filter(item => {
        const rect = item.getBoundingClientRect();
        return rect.left < area.right && rect.right > area.left && rect.top < area.bottom && rect.bottom > area.top;
    }).map(item => item.dataset.assetItem!);
    selectedKeys.value = [...marqueeStart.base, ...hits.filter(key => !marqueeStart!.base.includes(key))];
}
function canvasPointerUp() {
    if (!marqueeStart) return;
    if (!marquee.value && !marqueeStart.base.length) clearSelection();
    marqueeStart = null;
    marquee.value = null;
}
function assetType(mime: string | null) {
    if (mime?.startsWith('image/')) return 'Images';
    if (mime?.startsWith('audio/')) return 'Audio';
    if (mime?.startsWith('video/')) return 'Video';
    if (mime?.startsWith('text/') || /^application\/(pdf|rtf|json|xml|msword|vnd\.ms-|vnd\.openxmlformats-officedocument\.|vnd\.oasis\.opendocument\.)/.test(mime ?? '')) return 'Documents';
    if (/^application\/(zip|gzip|x-gzip|x-tar|x-7z-compressed|x-rar-compressed|vnd\.rar|x-bzip2|x-xz)$/.test(mime ?? '')) return 'Archives';
    return 'Other';
}
function fileIcon(mime: string | null) {
    if (mime?.startsWith('audio/')) return FileAudioIcon;
    if (mime?.startsWith('video/')) return FileVideoIcon;
    if (/^application\/(zip|gzip|x-gzip|x-tar|x-7z-compressed|x-rar-compressed|vnd\.rar|x-bzip2|x-xz)$/.test(mime ?? '')) return FileArchiveIcon;
    if (mime?.startsWith('text/') || mime?.includes('json') || mime?.includes('xml')) return FileCode2Icon;
    if (mime?.includes('pdf') || mime?.includes('document') || mime?.includes('word')) return FileTextIcon;
    return FileIcon;
}
function submit(folderId = selectedFolder.value) {
    upload.revision = props.project.revision;
    upload.folder_id = folderId || null;
    upload.post(`/projects/${props.project.id}/assets`, { preserveScroll: true, errorBag: 'assets', onSuccess: () => upload.reset('files', 'paths', 'directories') });
}
function selectUploadFiles(event: Event) {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    input.value = '';
    if (!files.length || busy.value) return;
    upload.files = files;
    upload.paths = [];
    upload.directories = [];
    submit();
}
function startDrag(event: DragEvent, kind: 'file' | 'folder', id: string) {
    if (!event.dataTransfer || busy.value) return;
    const key = selectionKey(kind, id);
    if (!selectedKeys.value.includes(key)) selectedKeys.value = [key];
    const items = selectedItems.value;
    event.dataTransfer.setData('application/x-orbit-asset', JSON.stringify({ projectId: props.project.id, items }));
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
        try { moveDroppedItems(internal, folderId); }
        catch { toast.error('This asset could not be moved.'); }
        return;
    }
    await uploadDroppedFiles(transfer, folderId);
}
function moveDroppedItems(internal: string, folderId: string) {

    const payload = JSON.parse(internal) as { projectId: string; items?: AssetSelection[]; kind?: string; id?: string };
    if (payload.projectId !== props.project.id) return;
    const items = payload.items ?? (payload.kind && payload.id ? [{ type: payload.kind as AssetSelection['type'], id: payload.id }] : []);
    if (folderId === selectedFolder.value || items.some(item => item.type === 'folder' && item.id === folderId)) return;
    if (items.length > 1) {
        bulkMovement.revision = props.project.revision;
        bulkMovement.folder_id = folderId || null;
        bulkMovement.items = items;
        bulkMovement.put(`/projects/${props.project.id}/assets/move`, { preserveScroll: true, errorBag: 'assets', onSuccess: clearSelection });
    } else if (items[0]?.type === 'file') {
        const file = assets.value.find(asset => asset.id === items[0].id);
        if (file && (file.folder_id ?? '') !== folderId) move(file, folderId);
    } else if (items[0]?.type === 'folder') {
        const folder = folders.value.find(candidate => candidate.id === items[0].id);
        if (folder && (folder.parent_id ?? '') !== folderId) {
            moveDroppedFolder(folder, folderId);
        }
    }

}
function moveDroppedFolder(folder: AssetFolder, folderId: string) {
    let ancestor = folderId;
    while (ancestor) {
        if (ancestor === folder.id) return;
        ancestor = folders.value.find(candidate => candidate.id === ancestor)?.parent_id ?? '';
    }
    folderForm.revision = props.project.revision;
    folderForm.name = folder.name;
    folderForm.parent_id = folderId || null;
    folderForm.put(`/projects/${props.project.id}/asset-folders/${folder.id}`, { preserveScroll: true, errorBag: 'assets', onSuccess: clearSelection });
}
async function uploadDroppedFiles(transfer: DataTransfer, folderId: string) {
    const items = Array.from(transfer.items).filter(item => item.kind === 'file');
    const entries = items.map(item => item.webkitGetAsEntry()).filter((entry): entry is FileSystemEntry => entry !== null);
    const fallbackFiles = Array.from(transfer.files);
    if (!entries.length && !fallbackFiles.length) return;
    readingDrop.value = true;
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
            toast.error('A project can store up to 100 files and 100 folders.');
            return;
        }
        if (!mounted) return;
        upload.files = files;
        upload.paths = paths;
        upload.directories = directories;
        submit(folderId);
    } catch {
        toast.error('The dropped folder could not be read. Try again.');
    } finally {
        readingDrop.value = false;
    }
}
function moveSelection() {
    if (!selectedItems.value.length || moveDestination.value === selectedFolder.value) return;
    bulkMovement.revision = props.project.revision;
    bulkMovement.folder_id = moveDestination.value || null;
    bulkMovement.items = selectedItems.value;
    bulkMovement.put(`/projects/${props.project.id}/assets/move`, { preserveScroll: true, errorBag: 'assets', onSuccess: () => { moveOpen.value = false; clearSelection(); } });
}
function removeSelection() {
    if (!selectedItems.value.length) return;
    bulkRemoval.revision = props.project.revision;
    bulkRemoval.items = selectedItems.value;
    bulkRemoval.delete(`/projects/${props.project.id}/assets/selection`, { preserveScroll: true, errorBag: 'assets', onSuccess: () => { removeOpen.value = false; clearSelection(); } });
}
function renameSelected() {
    if (selectedItems.value.length !== 1) return;
    const item = selectedItems.value[0];
    if (item.type === 'folder') {
        const folder = folders.value.find(candidate => candidate.id === item.id);
        if (folder) editFolder(folder);
    } else {
        const file = assets.value.find(candidate => candidate.id === item.id);
        if (file) { renamingFile.value = file; fileName.value = file.name; }
    }
}
function showMove(key?: string) {
    if (key) selectForMenu(key);
    moveDestination.value = selectedFolder.value;
    moveOpen.value = true;
}
function showRemove(key?: string) {
    if (key) selectForMenu(key);
    removeOpen.value = true;
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
    movement.put(`/projects/${props.project.id}/assets/${file.id}`, { preserveScroll: true, errorBag: 'assets', onSuccess: clearSelection });
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
    <div>
        <section
            class="-m-3 space-y-5 p-3"
            :class="dropTarget === (selectedFolder || '__root__') ? 'rounded-xl bg-primary/5' : ''"
            aria-labelledby="assets-title"
            @dragover="allowDrop($event, selectedFolder)"
            @dragleave="leaveDrop"
            @drop.prevent="dropOn($event, selectedFolder)"
            @keydown="onExplorerKeydown">
            <input
                ref="uploadPicker"
                type="file"
                multiple
                class="hidden"
                :disabled="busy"
                @change="selectUploadFiles">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2
                        id="assets-title"
                        class="text-xl font-normal tracking-[-0.025em]">
                        Assets
                    </h2>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        variant="outline"
                        :disabled="busy"
                        @click="editFolder()">
                        <FolderPlusIcon aria-hidden="true" />New folder
                    </Button><Button
                        :disabled="busy"
                        @click="uploadPicker?.click()">
                        <UploadIcon aria-hidden="true" /><TextTransition :text="readingDrop ? 'Reading folder…' : upload.processing ? 'Uploading' : 'Upload files'" /><span
                            v-if="upload.processing"
                            class="tabular-nums">{{ upload.progress?.percentage ?? 0 }}%</span>
                    </Button>
                </div>
            </div>
            <p class="text-xs text-muted-foreground">
                Up to 10 MB per file; 100 files and 100 folders per project.
            </p>
            <p
                v-if="readingDrop"
                role="status"
                class="text-sm text-muted-foreground">
                Reading folder…
            </p>
            <Alert
                v-if="Object.keys(errors).length"
                variant="destructive">
                <AlertDescription>
                    <p
                        v-for="(error, key) in errors"
                        :key="key">
                        {{ error }}
                    </p><Button
                        v-if="errors.revision"
                        variant="outline"
                        @click="router.reload()">
                        Reload project
                    </Button>
                </AlertDescription>
            </Alert>
            <div
                v-if="currentFolder"
                class="flex flex-wrap items-center gap-3">
                <div class="flex min-w-0 flex-wrap items-center gap-2">
                    <Button
                        variant="ghost"
                        size="sm"
                        :disabled="busy"
                        @click="selectedFolder = currentFolder.parent_id ?? ''">
                        <ArrowLeftIcon aria-hidden="true" />Back to parent folder
                    </Button>
                    <nav
                        aria-label="Current asset folder"
                        class="flex min-w-0 flex-wrap items-center gap-2 text-sm">
                        <Button
                            variant="link"
                            size="sm"
                            class="px-0"
                            :class="dropTarget === '__root__' ? 'rounded bg-primary/10' : ''"
                            @click="selectedFolder = ''"
                            @dragover.stop="allowDrop($event, '')"
                            @dragleave.stop="leaveDrop"
                            @drop.stop.prevent="dropOn($event, '')">
                            Assets
                        </Button>
                        <template
                            v-for="(folder, index) in breadcrumbs"
                            :key="folder.id">
                            <ChevronRightIcon
                                class="size-4 text-muted-foreground"
                                aria-hidden="true" /><Button
                                    v-if="index < breadcrumbs.length - 1"
                                    variant="link"
                                    size="sm"
                                    class="px-0"
                                    @click="selectedFolder = folder.id"
                                    @dragover.stop="allowDrop($event, folder.id)"
                                    @dragleave.stop="leaveDrop"
                                    @drop.stop.prevent="dropOn($event, folder.id)">
                                    {{ folder.name }}
                                </Button><span
                                v-else
                                class="min-w-0 truncate font-medium"
                                aria-current="page">{{ folder.name }}</span>
                        </template>
                    </nav>
                </div>
            </div>
            <div class="grid">
                <div
                    role="search"
                    class="col-start-1 row-start-1 flex flex-wrap items-center gap-2.5"
                    :class="selectedKeys.length ? 'invisible' : ''"
                    :inert="selectedKeys.length > 0"
                    aria-label="Filter assets">
                    <div class="relative min-w-[240px] max-w-[28rem] flex-[1_1_20rem]">
                        <label
                            for="asset-search"
                            class="sr-only">Search files in this folder</label><SearchIcon
                                class="pointer-events-none absolute top-1/2 left-3.5 size-3.5 -translate-y-1/2 text-muted-foreground"
                                aria-hidden="true" /><Input
                                    id="asset-search"
                                    v-model="search"
                                    placeholder="Search files"
                                    maxlength="255"
                                    class="h-9 rounded-full border-0 bg-muted pl-9 text-[13px] shadow-none focus-visible:ring-2 focus-visible:ring-ring/50 md:text-[13px]" />
                    </div>
                    <div
                        v-if="folderAssets.length || selectedType"
                        class="min-w-[155px] flex-1 sm:flex-none">
                        <label
                            for="asset-type"
                            class="sr-only">Type</label><FilterSelect
                                id="asset-type"
                                label="Type"
                                :model-value="selectedType"
                                :options="typeFilters.map(filter => ({ value: filter.type, label: filter.type + ' (' + filter.count + ')' }))"
                                :all-label="'All types (' + folderAssets.length + ')'"
                                @update:model-value="selectedType = $event" />
                    </div>
                    <div class="min-w-[155px] flex-1 sm:flex-none">
                        <label
                            for="asset-sort"
                            class="sr-only">Sort</label><FilterSelect
                                id="asset-sort"
                                label="Sort"
                                :model-value="sortBy === 'default' ? '' : sortBy"
                                :options="[{ value: 'name', label: 'Name A–Z' }, { value: 'size', label: 'Largest first' }]"
                                all-label="Upload order"
                                @update:model-value="sortBy = $event || 'default'" />
                    </div>
                    <div
                        class="ml-auto flex rounded-full bg-muted p-0.5"
                        role="group"
                        aria-label="File view">
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            class="rounded-full"
                            :class="view === 'list' ? 'bg-background shadow-sm hover:bg-background dark:hover:bg-background' : ''"
                            :aria-pressed="view === 'list'"
                            aria-label="List view"
                            @click="view = 'list'">
                            <ListIcon aria-hidden="true" />
                        </Button><Button
                            variant="ghost"
                            size="icon-sm"
                            class="rounded-full"
                            :class="view === 'tiles' ? 'bg-background shadow-sm hover:bg-background dark:hover:bg-background' : ''"
                            :aria-pressed="view === 'tiles'"
                            aria-label="Tile view"
                            @click="view = 'tiles'">
                            <LayoutGridIcon aria-hidden="true" />
                        </Button>
                    </div>
                </div>
                <div
                    class="col-start-1 row-start-1 flex flex-wrap items-center gap-2 rounded-xl border border-primary/30 bg-primary/5 px-3 py-2 text-sm"
                    :class="selectedKeys.length ? '' : 'invisible'"
                    :inert="selectedKeys.length === 0"
                    role="status">
                    <span class="mr-auto font-medium">{{ selectedKeys.length }} selected</span>
                    <Button
                        v-if="selectedItems.length === 1"
                        variant="outline"
                        size="sm"
                        @click="renameSelected">
                        <PencilIcon aria-hidden="true" />Rename
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        @click="showMove()">
                        <FolderIcon aria-hidden="true" />Move
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        @click="showRemove()">
                        <Trash2Icon aria-hidden="true" />{{ removalLabel }}
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        @click="clearSelection">
                        Clear
                    </Button>
                </div>
            </div>
            <p
                class="sr-only"
                role="status">
                Showing {{ filteredAssets.length }} of {{ folderAssets.length }} files in {{ currentFolder?.name ?? 'Assets' }}.
            </p>
            <div
                ref="canvas"
                class="relative min-h-48 space-y-4 pb-24"
                @pointerdown="canvasPointerDown"
                @pointermove="canvasPointerMove"
                @pointerup="canvasPointerUp"
                @pointercancel="canvasPointerUp">
                <ul
                    v-if="childFolders.length"
                    :class="view === 'tiles' ? 'grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5' : 'grid gap-2'"
                    aria-label="Folders">
                    <ContextMenu
                        v-for="folder in childFolders"
                        :key="folder.id">
                        <ContextMenuTrigger as-child>
                            <li
                                :data-asset-item="selectionKey('folder', folder.id)"
                                draggable="true"
                                class="group relative min-w-0 rounded-xl border border-black/8 bg-muted/40 transition-colors dark:border-white/10"
                                :class="[selectedKeys.includes(selectionKey('folder', folder.id)) ? 'ring-2 ring-ring' : 'hover:bg-muted/70', dropTarget === folder.id ? 'border-primary bg-primary/10' : '']"
                                @dragstart="startDrag($event, 'folder', folder.id)"
                                @dragend="dropTarget = null"
                                @dragover.stop="allowDrop($event, folder.id)"
                                @dragleave.stop="leaveDrop"
                                @drop.stop.prevent="dropOn($event, folder.id)"
                                @contextmenu="selectForMenu(selectionKey('folder', folder.id))">
                                <button
                                    type="button"
                                    :data-asset-select="selectionKey('folder', folder.id)"
                                    class="flex h-12 w-full min-w-0 items-center gap-3 px-3 pr-11 text-left text-[13px] font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                    :aria-label="'Select ' + folder.name + ' folder; press Enter to open'"
                                    :aria-pressed="selectedKeys.includes(selectionKey('folder', folder.id))"
                                    @click="selectItem($event, selectionKey('folder', folder.id))"
                                    @dblclick="openItem('folder', folder.id)"
                                    @keydown="onItemKeydown($event, selectionKey('folder', folder.id))">
                                    <FolderIcon
                                        class="size-5 shrink-0 fill-current text-muted-foreground"
                                        aria-hidden="true" /><span class="truncate">{{ folder.name }}</span>
                                </button>
                                <DropdownMenuRoot>
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon-sm"
                                            class="absolute top-1/2 right-2 -translate-y-1/2"
                                            :aria-label="'Actions for ' + folder.name"
                                            @click.stop="selectForMenu(selectionKey('folder', folder.id))">
                                            <EllipsisVerticalIcon aria-hidden="true" />
                                        </Button>
                                    </DropdownMenuTrigger><DropdownMenuPortal>
                                        <DropdownMenuContent
                                            align="end"
                                            :side-offset="4"
                                            class="t-dropdown z-50 min-w-40 rounded-md border bg-popover p-1 text-popover-foreground shadow-md">
                                            <DropdownMenuItem
                                                :class="menuItemClass"
                                                @select="openItem('folder', folder.id)">
                                                Open
                                            </DropdownMenuItem><DropdownMenuItem
                                                :class="menuItemClass"
                                                :disabled="selectedKeys.length !== 1"
                                                @select="editFolder(folder)">
                                                Rename
                                            </DropdownMenuItem><DropdownMenuItem
                                                :class="menuItemClass"
                                                @select="showMove(selectionKey('folder', folder.id))">
                                                Move
                                            </DropdownMenuItem><DropdownMenuItem
                                                :class="menuItemClass"
                                                @select="showRemove(selectionKey('folder', folder.id))">
                                                {{ removalLabel }}
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenuPortal>
                                </DropdownMenuRoot>
                            </li>
                        </ContextMenuTrigger>
                        <ContextMenuContent>
                            <ContextMenuItem @select="openItem('folder', folder.id)">
                                Open
                            </ContextMenuItem><ContextMenuItem
                                :disabled="selectedKeys.length !== 1"
                                @select="editFolder(folder)">
                                Rename
                            </ContextMenuItem><ContextMenuItem @select="showMove(selectionKey('folder', folder.id))">
                                Move
                            </ContextMenuItem><ContextMenuItem
                                variant="destructive"
                                @select="showRemove(selectionKey('folder', folder.id))">
                                {{ removalLabel }}
                            </ContextMenuItem>
                        </ContextMenuContent>
                    </ContextMenu>
                </ul>
                <ul
                    v-if="filteredAssets.length"
                    :class="view === 'tiles' ? 'grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5' : 'grid gap-2'"
                    aria-label="Files">
                    <ContextMenu
                        v-for="file in filteredAssets"
                        :key="file.id">
                        <ContextMenuTrigger as-child>
                            <li
                                :data-asset-item="selectionKey('file', file.id)"
                                draggable="true"
                                class="group relative min-w-0 overflow-hidden rounded-xl border border-black/8 bg-background transition-colors dark:border-white/10"
                                :class="selectedKeys.includes(selectionKey('file', file.id)) ? 'ring-2 ring-ring bg-primary/5' : 'hover:bg-muted/40'"
                                @dragstart="startDrag($event, 'file', file.id)"
                                @dragend="dropTarget = null"
                                @contextmenu="selectForMenu(selectionKey('file', file.id))">
                                <button
                                    type="button"
                                    :data-asset-select="selectionKey('file', file.id)"
                                    class="flex h-full w-full min-w-0 text-left focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-ring"
                                    :class="view === 'tiles' ? 'flex-col' : 'items-center'"
                                    :aria-label="'Select ' + file.name + '; press Enter to open'"
                                    :aria-pressed="selectedKeys.includes(selectionKey('file', file.id))"
                                    @click="selectItem($event, selectionKey('file', file.id))"
                                    @dblclick="openItem('file', file.id)"
                                    @keydown="onItemKeydown($event, selectionKey('file', file.id))">
                                    <span
                                        class="flex w-full min-w-0 items-center gap-2 px-3 pr-11 text-[13px] font-medium"
                                        :class="view === 'tiles' ? 'h-14' : 'h-16'"><component
                                            :is="fileIcon(file.mime_type)"
                                            class="size-4 shrink-0 text-muted-foreground"
                                            aria-hidden="true" /><span class="truncate">{{ file.name }}</span></span>
                                    <span
                                        class="flex shrink-0 items-center justify-center overflow-hidden bg-muted/40"
                                        :class="view === 'tiles' ? 'aspect-[4/3] w-full p-2' : 'order-first ml-2 size-12 rounded-md'"><img
                                            v-if="file.preview_url && !failedPreviews.includes(file.id)"
                                            :src="file.preview_url"
                                            alt=""
                                            loading="lazy"
                                            class="size-full object-contain"
                                            @error="failedPreviews.push(file.id)"><component
                                                :is="fileIcon(file.mime_type)"
                                                v-else
                                                class="text-muted-foreground stroke-[1.25]"
                                                :class="view === 'tiles' ? 'size-16 sm:size-20' : 'size-6'"
                                                aria-hidden="true" /></span>
                                    <span
                                        v-if="view === 'list'"
                                        class="ml-auto shrink-0 pr-12 text-xs text-muted-foreground">{{ size(file.size) }}</span>
                                </button>
                                <DropdownMenuRoot>
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon-sm"
                                            class="absolute top-2 right-2"
                                            :aria-label="'Actions for ' + file.name"
                                            @click.stop="selectForMenu(selectionKey('file', file.id))">
                                            <EllipsisVerticalIcon aria-hidden="true" />
                                        </Button>
                                    </DropdownMenuTrigger><DropdownMenuPortal>
                                        <DropdownMenuContent
                                            align="end"
                                            :side-offset="4"
                                            class="t-dropdown z-50 min-w-40 rounded-md border bg-popover p-1 text-popover-foreground shadow-md">
                                            <DropdownMenuItem
                                                v-if="file.preview_url"
                                                :class="menuItemClass"
                                                @select="preview = file">
                                                Preview
                                            </DropdownMenuItem><DropdownMenuItem
                                                as-child
                                                :class="menuItemClass">
                                                <a
                                                    :href="file.url"
                                                    download>Download</a>
                                            </DropdownMenuItem><DropdownMenuItem
                                                :class="menuItemClass"
                                                :disabled="selectedKeys.length !== 1"
                                                @select="renamingFile = file; fileName = file.name">
                                                Rename
                                            </DropdownMenuItem><DropdownMenuItem
                                                :class="menuItemClass"
                                                @select="showMove(selectionKey('file', file.id))">
                                                Move
                                            </DropdownMenuItem><DropdownMenuItem
                                                :class="menuItemClass"
                                                @select="showRemove(selectionKey('file', file.id))">
                                                {{ removalLabel }}
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenuPortal>
                                </DropdownMenuRoot>
                            </li>
                        </ContextMenuTrigger>
                        <ContextMenuContent>
                            <ContextMenuItem
                                v-if="file.preview_url"
                                @select="preview = file">
                                Preview
                            </ContextMenuItem><ContextMenuItem as-child>
                                <a
                                    :href="file.url"
                                    download>Download</a>
                            </ContextMenuItem><ContextMenuItem
                                :disabled="selectedKeys.length !== 1"
                                @select="renamingFile = file; fileName = file.name">
                                Rename
                            </ContextMenuItem><ContextMenuItem @select="showMove(selectionKey('file', file.id))">
                                Move
                            </ContextMenuItem><ContextMenuItem
                                variant="destructive"
                                @select="showRemove(selectionKey('file', file.id))">
                                {{ removalLabel }}
                            </ContextMenuItem>
                        </ContextMenuContent>
                    </ContextMenu>
                </ul>
                <div
                    v-else-if="search || selectedType"
                    class="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                    <p>No files match these filters in this folder.</p><Button
                        variant="link"
                        size="sm"
                        @click="search = ''; selectedType = ''">
                        Clear filters
                    </Button>
                </div>
                <div
                    v-else-if="!childFolders.length"
                    class="flex min-h-48 flex-col items-center justify-center rounded-xl border border-dashed border-black/10 px-6 py-12 text-center dark:border-white/10">
                    <FolderIcon
                        class="size-8 text-muted-foreground"
                        aria-hidden="true" /><p class="mt-3 text-sm font-medium">
                            {{ currentFolder ? 'This folder is empty' : 'No assets yet' }}
                        </p><p class="mt-1 text-sm text-muted-foreground">
                        {{ currentFolder ? 'Upload files here or move existing assets into this folder.' : 'Add designs, screenshots, documents, or other project files.' }}
                    </p><Button
                        variant="outline"
                        class="mt-4"
                        :disabled="busy"
                        @click="uploadPicker?.click()">
                        <UploadIcon aria-hidden="true" />Upload files
                    </Button>
                </div>
                <div
                    v-if="marquee"
                    class="pointer-events-none absolute z-10 border border-primary bg-primary/10"
                    :style="{ left: marquee.x + 'px', top: marquee.y + 'px', width: marquee.width + 'px', height: marquee.height + 'px' }"
                    aria-hidden="true" />
            </div>
        </section>
        <Dialog
            :open="folderEditorOpen"
            @update:open="value => { if (!folderForm.processing) folderEditorOpen = value; }">
            <DialogContent :aria-describedby="undefined">
                <DialogHeader><DialogTitle>{{ editingFolder ? 'Rename folder' : 'New asset folder' }}</DialogTitle></DialogHeader>
                <form
                    class="space-y-4"
                    @submit.prevent="saveFolder">
                    <Field>
                        <FieldLabel for="asset-folder-name">
                            Folder name
                        </FieldLabel><Input
                            id="asset-folder-name"
                            v-model="folderForm.name"
                            placeholder="logo"
                            maxlength="100"
                            required
                            :aria-invalid="!!folderForm.errors.name" /><FieldError v-if="folderForm.errors.name">
                                {{ folderForm.errors.name }}
                            </FieldError>
                    </Field><Field>
                        <FieldLabel for="asset-folder-parent">
                            Inside
                        </FieldLabel><ChoiceSelect
                            id="asset-folder-parent"
                            :model-value="folderForm.parent_id ?? ''"
                            :options="parentOptions"
                            @update:model-value="folderForm.parent_id = $event || null" /><FieldError v-if="folderForm.errors.parent_id">
                                {{ folderForm.errors.parent_id }}
                            </FieldError>
                    </Field><FieldError v-if="folderForm.errors.revision">
                        {{ folderForm.errors.revision }}
                    </FieldError><DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="folderForm.processing"
                            @click="folderEditorOpen = false">
                            Cancel
                        </Button><Button
                            type="submit"
                            :disabled="folderForm.processing">
                            <TextTransition :text="folderForm.processing ? 'Saving…' : 'Save folder'" />
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
        <Dialog
            :open="!!renamingFile"
            @update:open="value => { if (!value && !movement.processing) renamingFile = null; }">
            <DialogContent>
                <DialogHeader><DialogTitle>Rename file</DialogTitle><DialogDescription>Change the name shown in Orbit and used for downloads.</DialogDescription></DialogHeader><form
                    class="space-y-4"
                    @submit.prevent="renameFile">
                    <Field>
                        <FieldLabel for="asset-file-name">
                            File name
                        </FieldLabel><Input
                            id="asset-file-name"
                            v-model="fileName"
                            maxlength="255"
                            required /><FieldError v-if="movement.errors.name">
                                {{ movement.errors.name }}
                            </FieldError>
                    </Field><DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="renamingFile = null">
                            Cancel
                        </Button><Button
                            type="submit"
                            :disabled="movement.processing">
                            Rename
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
        <Dialog
            :open="!!preview"
            @update:open="value => { if (!value) preview = null; }">
            <DialogContent
                v-if="preview"
                class="max-h-[90dvh] overflow-y-auto sm:max-w-4xl">
                <DialogHeader>
                    <DialogTitle class="break-all pr-8">
                        {{ preview.name }}
                    </DialogTitle><DialogDescription>{{ size(preview.size) }}</DialogDescription>
                </DialogHeader>
                <img
                    v-if="preview.preview_url && !failedPreviews.includes(preview.id)"
                    :src="preview.preview_url"
                    :alt="preview.name"
                    class="mx-auto max-h-[65dvh] max-w-full object-contain"
                    @error="failedPreviews.push(preview.id)">
                <p
                    v-else
                    class="text-sm text-muted-foreground"
                    role="status">
                    This image could not be previewed. You can download it instead.
                </p>
                <DialogFooter>
                    <Button
                        as-child
                        variant="outline">
                        <a
                            :href="preview.url"
                            download><DownloadIcon aria-hidden="true" />Download</a>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
        <Dialog
            :open="moveOpen"
            @update:open="value => { if (!bulkMovement.processing) moveOpen = value; }">
            <DialogContent :aria-describedby="undefined">
                <DialogHeader><DialogTitle>Move {{ selectedItems.length }} {{ selectedItems.length === 1 ? 'item' : 'items' }}</DialogTitle></DialogHeader>
                <Field>
                    <FieldLabel for="asset-move-destination">
                        Move to
                    </FieldLabel><ChoiceSelect
                        id="asset-move-destination"
                        :model-value="moveDestination"
                        :options="[{ value: '', label: 'Assets' }, ...moveDestinations.map(node => ({ value: node.folder.id, label: node.path }))]"
                        @update:model-value="moveDestination = $event" />
                </Field>
                <FieldError
                    v-for="(error, key) in bulkMovement.errors"
                    :key="key">
                    {{ error }}
                </FieldError>
                <DialogFooter>
                    <Button
                        variant="outline"
                        :disabled="bulkMovement.processing"
                        @click="moveOpen = false">
                        Cancel
                    </Button><Button
                        :disabled="bulkMovement.processing || moveDestination === selectedFolder"
                        @click="moveSelection">
                        Move
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
        <Dialog
            :open="removeOpen"
            @update:open="value => { if (!bulkRemoval.processing) removeOpen = value; }">
            <DialogContent>
                <DialogHeader><DialogTitle>{{ removalLabel }}?</DialogTitle><DialogDescription>Selected files are deleted, including files explicitly selected inside a selected folder. Other contents survive and move to the nearest remaining parent.</DialogDescription></DialogHeader><ul class="max-h-56 overflow-y-auto space-y-1 text-sm">
                    <li
                        v-for="item in removalNames"
                        :key="`${item.type}:${item.id}`"
                        class="break-all">
                        {{ item.type === 'file' ? 'File' : 'Folder' }}: {{ item.name }}
                    </li>
                </ul>
                <FieldError
                    v-for="(error, key) in bulkRemoval.errors"
                    :key="key">
                    {{ error }}
                </FieldError>
                <DialogFooter>
                    <Button
                        variant="outline"
                        :disabled="bulkRemoval.processing"
                        @click="removeOpen = false">
                        Cancel
                    </Button><Button
                        variant="destructive"
                        :disabled="bulkRemoval.processing"
                        @click="removeSelection">
                        {{ bulkRemoval.processing ? 'Working…' : removalLabel }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
