<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { DownloadIcon, FileIcon, Trash2Icon, UploadIcon } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Field, FieldLabel, FieldDescription } from '@/components/ui/field';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { Project } from '@/types';
const props = defineProps<{ project: Project }>();
const upload = useForm({ revision: props.project.revision, files: [] as File[] });
const removal = useForm({ revision: props.project.revision });
const removing = ref<{ id: string; name: string } | null>(null);
const preview = ref<NonNullable<Project['assets']>[number] | null>(null);
const failedPreviews = ref<string[]>([]);
const pickerKey = ref(0);
const selectedType = ref('');
const assets = computed(() => (props.project.assets ?? []).map(file => ({ ...file, type: assetType(file.mime_type) })));
const typeFilters = computed(() => ['Images', 'Documents', 'Audio', 'Video', 'Archives', 'Other'].map(type => ({ type, count: assets.value.filter(file => file.type === type).length })));
const filteredAssets = computed(() => selectedType.value ? assets.value.filter(file => file.type === selectedType.value) : assets.value);
const size = (bytes: number) => bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.ceil(bytes / 1024))} KB`;
function assetType(mime: string | null) {
    if (mime?.startsWith('image/')) return 'Images';
    if (mime?.startsWith('audio/')) return 'Audio';
    if (mime?.startsWith('video/')) return 'Video';
    if (mime?.startsWith('text/') || /^application\/(pdf|rtf|json|xml|msword|vnd\.ms-|vnd\.openxmlformats-officedocument\.|vnd\.oasis\.opendocument\.)/.test(mime ?? '')) return 'Documents';
    if (/^application\/(zip|gzip|x-gzip|x-tar|x-7z-compressed|x-rar-compressed|vnd\.rar|x-bzip2|x-xz)$/.test(mime ?? '')) return 'Archives';
    return 'Other';
}
function submit() {
    upload.revision = props.project.revision;
    upload.post(`/projects/${props.project.id}/assets`, { preserveScroll: true, errorBag: 'assets', onSuccess: () => { upload.reset('files'); pickerKey.value++; } });
}
function remove() {
    if (!removing.value) return;
    removal.revision = props.project.revision;
    removal.delete(`/projects/${props.project.id}/assets/${removing.value.id}`, { preserveScroll: true, errorBag: 'assets', onSuccess: () => { removing.value = null; } });
}
</script>

<template>
    <section class="space-y-4 rounded-lg border p-5" aria-labelledby="assets-title">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="assets-title" class="text-lg font-semibold">Assets</h2>
            <NativeSelect v-if="assets.length || selectedType" v-model="selectedType" aria-label="Filter assets by type">
                <NativeSelectOption value="">All types ({{ assets.length }})</NativeSelectOption>
                <NativeSelectOption v-for="filter in typeFilters" :key="filter.type" :value="filter.type">{{ filter.type }} ({{ filter.count }})</NativeSelectOption>
            </NativeSelect>
        </div>
        <Alert v-if="upload.hasErrors || removal.hasErrors" variant="destructive"><AlertDescription><p v-for="(error, key) in { ...upload.errors, ...removal.errors }" :key="key">{{ error }}</p><Button v-if="upload.errors.revision || removal.errors.revision" variant="outline" @click="router.reload()">Reload project</Button></AlertDescription></Alert>
        <p class="sr-only" role="status">Showing {{ filteredAssets.length }} of {{ assets.length }} assets.</p>
        <ul v-if="filteredAssets.length" class="divide-y rounded-lg border">
            <li v-for="file in filteredAssets" :key="file.id" class="flex items-center gap-3 p-3">
                <button v-if="file.preview_url && !failedPreviews.includes(file.id)" type="button" class="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-md border bg-muted/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring" :aria-label="`Preview ${file.name}`" @click="preview = file">
                    <img :src="file.preview_url" alt="" loading="lazy" class="size-full object-contain" @error="failedPreviews.push(file.id)" />
                </button>
                <FileIcon v-else class="size-5 shrink-0 text-muted-foreground" aria-hidden="true" />
                <button v-if="file.preview_url && !failedPreviews.includes(file.id)" type="button" class="min-w-0 flex-1 truncate text-left text-sm font-medium underline-offset-4 hover:underline" @click="preview = file">{{ file.name }}</button>
                <a v-else :href="file.url" download class="min-w-0 flex-1 truncate text-sm font-medium underline-offset-4 hover:underline">{{ file.name }}</a>
                <span class="shrink-0 text-xs text-muted-foreground">{{ size(file.size) }}</span>
                <Button as-child variant="ghost" size="icon-sm"><a :href="file.url" download :aria-label="`Download ${file.name}`"><DownloadIcon aria-hidden="true" /></a></Button>
                <Button variant="ghost" size="icon-sm" :aria-label="`Remove ${file.name}`" :disabled="upload.processing || removal.processing" @click="removing = file"><Trash2Icon aria-hidden="true" /></Button>
            </li>
        </ul>
        <div v-else-if="assets.length" class="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
            <p>No assets match this type.</p><Button variant="link" size="sm" @click="selectedType = ''">Show all assets</Button>
        </div>
        <p v-else class="text-sm text-muted-foreground">Keep designs, screenshots, documents, and other project files here.</p>
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="submit">
            <Field class="min-w-52 flex-1"><FieldLabel for="asset-files">Add files</FieldLabel><Input id="asset-files" :key="pickerKey" type="file" multiple :disabled="upload.processing || removal.processing" @change="upload.files = Array.from(($event.target as HTMLInputElement).files ?? [])" /><FieldDescription>Up to 10 MB per file. Files are copied into Orbit.</FieldDescription></Field>
            <Button type="submit" variant="outline" :disabled="!upload.files.length || upload.processing || removal.processing"><UploadIcon aria-hidden="true" />{{ upload.processing ? `Uploading ${upload.progress?.percentage ?? 0}%` : 'Upload' }}</Button>
        </form>
    </section>
    <Dialog :open="!!preview" @update:open="value => { if (!value) preview = null; }">
        <DialogContent v-if="preview" class="max-h-[90dvh] overflow-y-auto sm:max-w-4xl">
            <DialogHeader><DialogTitle class="break-all pr-8">{{ preview.name }}</DialogTitle><DialogDescription>{{ size(preview.size) }}</DialogDescription></DialogHeader>
            <img v-if="preview.preview_url && !failedPreviews.includes(preview.id)" :src="preview.preview_url" :alt="preview.name" class="mx-auto max-h-[65dvh] max-w-full object-contain" @error="failedPreviews.push(preview.id)" />
            <p v-else class="text-sm text-muted-foreground" role="status">This image could not be previewed. You can download it instead.</p>
            <DialogFooter><Button as-child variant="outline"><a :href="preview.url" download><DownloadIcon aria-hidden="true" />Download</a></Button></DialogFooter>
        </DialogContent>
    </Dialog>
    <Dialog :open="!!removing" @update:open="value => { if (!value && !removal.processing) removing = null; }">
        <DialogContent><DialogHeader><DialogTitle>Remove {{ removing?.name }}?</DialogTitle><DialogDescription>This deletes the stored copy from Orbit. Your original file stays on disk.</DialogDescription></DialogHeader><DialogFooter><Button variant="outline" :disabled="removal.processing" @click="removing = null">Cancel</Button><Button variant="destructive" :disabled="removal.processing" @click="remove">Remove file</Button></DialogFooter></DialogContent>
    </Dialog>
</template>
