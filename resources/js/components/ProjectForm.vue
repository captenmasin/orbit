<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router, useForm, useHttp } from '@inertiajs/vue3';
import { useDocumentVisibility, useIntervalFn, useObjectUrl, useSessionStorage, useWindowFocus } from '@vueuse/core';
import { ArrowDownIcon, ArrowUpIcon, FolderPlusIcon, PlusIcon, Trash2Icon } from '@lucide/vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import OpenTargetButton from '@/components/OpenTargetButton.vue';
import ProjectIcon from '@/components/ProjectIcon.vue';
import ProjectBoard from '@/components/ProjectBoard.vue';
import ProjectDependencies from '@/components/ProjectDependencies.vue';
import type { FolderPreview, Project, ProjectFolder, ProjectLink, Repository } from '@/types';

const props = defineProps<{ project?: Project; scanFolders?: ProjectFolder[]; statuses: string[]; native: boolean; message?: string | null }>();
const formId = props.project?.id ?? 'create';
const tab = props.project ? useSessionStorage(`project:${formId}:tab`, 'overview', { flush: 'sync' }) : ref('overview');
const tagsText = ref(props.project?.tags.map(tag => tag.name).join(', ') ?? '');
function values() {
    return {
        name: props.project?.name ?? '', description: props.project?.description ?? '',
        status: props.project?.status ?? props.statuses[0]!,
        icon_type: props.project?.icon_type ?? 'initials', icon_emoji: props.project?.icon_emoji ?? '',
        icon_file: null as File | null, tags: props.project?.tags.map(tag => tag.name) ?? [],
        repositories: (props.project?.repositories ?? []).map(repo => ({ ...repo })) as Repository[],
        folders: (props.project?.folders ?? []).map(folder => ({ ...folder })) as ProjectFolder[],
        links: (props.project?.links ?? []).map(link => ({ ...link })) as ProjectLink[],
        ...(props.project ? { revision: props.project.revision } : {}),
    };
}
const form = useForm(values());
watch(() => props.project?.revision, () => {
    if (!form.isDirty && tagsText.value === (props.project?.tags.map(tag => tag.name).join(', ') ?? '')) {
        form.defaults(values());
        form.reset();
    }
});
const imagePreview = useObjectUrl(computed(() => form.icon_file));
const preview = ref<FolderPreview | null>(null);
const previewOpen = ref(false);
const removeOpen = ref(false);
const relinking = ref<string | null>(null);
const inspection = useHttp<{ path: string }, { folder: FolderPreview | null }>({ path: '' });
const date = (value?: string | null) => value ? new Date(value).toLocaleString() : 'No known commit';
const scan = useHttp({ kind: 'all' as 'all' | 'folder' | 'root', id: null as string | null, only_stale: false });
const scanError = ref('');
const visible = useDocumentVisibility();
const focused = useWindowFocus();
const active = computed(() => visible.value === 'visible' && focused.value);
const pending = computed(() => (props.scanFolders ?? []).some(folder => [folder, ...(folder.package_roots ?? [])].some(target => ['Queued', 'Scanning'].includes(target.scan_state ?? ''))));
const latestCommit = computed(() => [...(props.scanFolders ?? [])].filter(folder => folder.last_commit_at).sort((a, b) => b.last_commit_at!.localeCompare(a.last_commit_at!))[0]);
const folderScan = (folder: ProjectFolder) => props.scanFolders?.find(saved => saved.id === folder.id && saved.path === folder.path) ?? folder;
let lastAutomaticCheck = 0;
let reloadingInspection = false;
function reloadInspection() {
    if (reloadingInspection) return;
    reloadingInspection = true;
    router.reload({ only: ['inspection'], onFinish: () => { reloadingInspection = false; } });
}
async function refresh(kind: 'all' | 'folder' | 'root' = 'all', id: string | null = null, onlyStale = false) {
    if (!props.project || scan.processing) return;
    Object.assign(scan, { kind, id, only_stale: onlyStale });
    scanError.value = '';
    try {
        await scan.post(`/projects/${props.project.id}/inspection`);
        reloadInspection();
    } catch { scanError.value = 'Inspection could not be queued. Try refreshing again.'; }
}
function checkInspection() {
    if (!props.project || !active.value) return;
    if (Date.now() - lastAutomaticCheck >= 60000) {
        lastAutomaticCheck = Date.now();
        void refresh('all', null, true);
    } else if (pending.value) reloadInspection();
}
useIntervalFn(checkInspection, 2000);
watch(active, () => { lastAutomaticCheck = 0; checkInspection(); }, { immediate: true });

function submit() {
    form.tags = tagsText.value.split(',').map(tag => tag.trim()).filter(Boolean);
    form.transform(data => ({ ...data, _method: props.project ? 'put' : 'post' })).post(props.project ? `/projects/${props.project.id}` : '/projects', {
        preserveScroll: true,
        errorBag: props.project ? 'editProject' : 'createProject',
        onSuccess: () => {
            if (props.project) {
                form.defaults(values());
                form.reset();
                tagsText.value = props.project.tags.map(tag => tag.name).join(', ');
            }
        },
        onError: errors => {
            const key = Object.keys(errors)[0] ?? '';
            tab.value = key.startsWith('links') ? 'links' : key.startsWith('repositories') || key.startsWith('folders') ? 'repositories' : 'overview';
        },
    });
}
function archive() {
    form.status = props.project?.status === 'Archived' ? (props.project.previous_status ?? 'Idea') : 'Archived';
    submit();
}
function removeProject() {
    if (!props.project) return;
    form.transform(data => ({ revision: data.revision })).delete(`/projects/${props.project.id}`, {
        errorBag: 'editProject',
        onError: () => { tab.value = 'overview'; },
        onFinish: () => { removeOpen.value = false; },
    });
}
async function reviewFolder(id: string | null = null, picker = false) {
    relinking.value = id;
    if (picker) inspection.path = '';
    try {
        const result = await inspection.post('/folders/inspect');
        preview.value = result.folder;
        previewOpen.value = !!result.folder;
    } catch {
        if (!inspection.hasErrors) inspection.setError('path', 'The folder could not be inspected. Try again.');
    }
}
function useFolder() {
    if (!preview.value) return;
    const folder = preview.value;
    if (form.folders.some(item => item.path === folder.path && item.id !== relinking.value)) {
        inspection.setError('path', 'This folder is already linked.');
        previewOpen.value = false;
        return;
    }
    const current = form.folders.find(item => item.id === relinking.value);
    let repositoryId = current?.repository_id ?? null;
    if (!current && folder.remote_url) {
        const key = (url: string) => url.replace(/^\w+@([^:]+):/, 'https://$1/').replace(/^ssh:\/\/(?:[^@/]+@)?/, 'https://').replace(/\.git\/?$/, '').replace(/\/$/, '');
        const existing = form.repositories.find(repo => key(repo.remote_url) === key(folder.remote_url!));
        repositoryId = existing?.id ?? crypto.randomUUID();
        if (!existing) form.repositories.push({ id: repositoryId, name: folder.name, remote_url: folder.remote_url });
    }
    const data: ProjectFolder = {
        id: current?.id ?? crypto.randomUUID(), path: folder.path, repository_id: repositoryId,
        git_state: folder.git_state, branch: folder.branch, last_commit_at: folder.last_commit_at,
        last_commit_hash: folder.last_commit_hash, scanned_at: folder.scanned_at, availability: 'Available',
    };
    if (current) form.folders.splice(form.folders.indexOf(current), 1, data);
    else form.folders.push(data);
    if (!props.project) {
        if (!form.name) form.name = folder.name;
        if (!form.description) form.description = folder.description ?? '';
    }
    inspection.path = '';
    inspection.clearErrors();
    previewOpen.value = false;
}
function removeRepository(id: string) {
    form.repositories = form.repositories.filter(repo => repo.id !== id);
    form.folders.forEach(folder => { if (folder.repository_id === id) folder.repository_id = null; });
    form.clearErrors();
}
function moveLink(index: number, direction: number) {
    const [link] = form.links.splice(index, 1);
    form.links.splice(index + direction, 0, link!);
    form.clearErrors();
}
function addRepository() {
    form.repositories.push({ id: crypto.randomUUID(), name: '', remote_url: '' });
}
function addLink() {
    form.links.push({ id: crypto.randomUUID(), label: '', url: '', category: '', icon: '' });
}
</script>

<template>
    <Tabs v-model="tab">
        <TabsList aria-label="Project sections">
            <TabsTrigger value="overview">Overview</TabsTrigger>
            <TabsTrigger v-if="project" value="board">Board</TabsTrigger>
            <TabsTrigger v-if="project" value="dependencies">Dependencies</TabsTrigger>
            <TabsTrigger value="repositories">Repositories</TabsTrigger>
            <TabsTrigger value="links">Links</TabsTrigger>
        </TabsList>
        <TabsContent v-if="project" value="board"><ProjectBoard :project="project" /></TabsContent>
        <Alert v-if="scanError" variant="destructive"><AlertDescription>{{ scanError }}</AlertDescription></Alert>
        <TabsContent v-if="project" value="dependencies"><ProjectDependencies :project="project" :folders="scanFolders ?? []" :native="native" :busy="scan.processing" @refresh="refresh" @changed="reloadInspection" /></TabsContent>
    <form v-show="tab !== 'board' && tab !== 'dependencies'" class="w-full max-w-3xl" novalidate @submit.prevent="submit">
        <FieldGroup>
            <Alert v-if="form.hasErrors" variant="destructive" role="alert">
                <AlertDescription>
                    <ul class="list-disc pl-4">
                        <li v-for="(error, key) in form.errors" :id="`${formId}-${key}-error`" :key="key">{{ error }}</li>
                    </ul>
                    <div v-if="form.errors.revision"><Button type="button" variant="outline" @click="router.get(`/projects/${project!.id}`)">Reload project</Button></div>
                </AlertDescription>
            </Alert>
                <TabsContent value="overview">
                    <FieldGroup>
                        <Field v-if="!project">
                            <div class="flex flex-wrap gap-2">
                                <Input v-if="!native" v-model="inspection.path" aria-label="Folder path" placeholder="/absolute/path/to/project" />
                                <Button type="button" variant="outline" :disabled="inspection.processing" @click="reviewFolder(null, native)"><FolderPlusIcon aria-hidden="true" />{{ inspection.processing ? 'Reading folder…' : 'Start from a folder' }}</Button>
                            </div>
                            <FieldError v-if="inspection.errors.path" role="alert">{{ inspection.errors.path }}</FieldError>
                        </Field>
                        <Field :data-invalid="!!form.errors.name">
                            <FieldLabel :for="`${formId}-name`">Name</FieldLabel>
                            <Input :id="`${formId}-name`" v-model="form.name" name="name" maxlength="255" :autofocus="!project" :aria-invalid="!!form.errors.name" :aria-describedby="form.errors.name ? `${formId}-name-error` : undefined" />
                        </Field>
                        <Field :data-invalid="!!form.errors.description">
                            <FieldLabel :for="`${formId}-description`">Description (optional)</FieldLabel>
                            <Textarea :id="`${formId}-description`" v-model="form.description" name="description" maxlength="10000" :rows="4" :aria-invalid="!!form.errors.description" :aria-describedby="form.errors.description ? `${formId}-description-error` : undefined" />
                        </Field>
                        <Field :data-invalid="!!form.errors.status">
                            <FieldLabel :for="`${formId}-status`">Status</FieldLabel>
                            <NativeSelect :id="`${formId}-status`" v-model="form.status" name="status" :aria-invalid="!!form.errors.status">
                                <NativeSelectOption v-for="status in statuses" :key="status" :value="status">{{ status }}</NativeSelectOption>
                            </NativeSelect>
                        </Field>
                        <FieldSet>
                            <FieldLegend>Icon</FieldLegend>
                            <div class="flex items-center gap-4">
                                <ProjectIcon :name="form.name" :type="form.icon_type" :emoji="form.icon_emoji" :image="imagePreview ?? project?.icon_url" size="lg" />
                                <NativeSelect v-model="form.icon_type" aria-label="Icon type">
                                    <NativeSelectOption value="initials">Initials</NativeSelectOption>
                                    <NativeSelectOption value="emoji">Emoji</NativeSelectOption>
                                    <NativeSelectOption value="image">Image</NativeSelectOption>
                                </NativeSelect>
                            </div>
                            <Field v-if="form.icon_type === 'emoji'" :data-invalid="!!form.errors.icon_emoji">
                                <FieldLabel :for="`${formId}-emoji`">Emoji</FieldLabel>
                                <Input :id="`${formId}-emoji`" v-model="form.icon_emoji" maxlength="32" :aria-invalid="!!form.errors.icon_emoji" placeholder="🪐" />
                            </Field>
                            <Field v-if="form.icon_type === 'image'" :data-invalid="!!form.errors.icon_file">
                                <FieldLabel :for="`${formId}-image`">Image</FieldLabel>
                                <Input :id="`${formId}-image`" type="file" accept="image/png,image/jpeg,image/gif,image/webp" :aria-invalid="!!form.errors.icon_file" @change="form.icon_file = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                                <FieldDescription>PNG, JPEG, GIF or WebP. Up to 2 MB and 2048 × 2048 pixels.</FieldDescription>
                            </Field>
                        </FieldSet>
                        <Field :data-invalid="!!form.errors.tags">
                            <FieldLabel :for="`${formId}-tags`">Tags</FieldLabel>
                            <Input :id="`${formId}-tags`" v-model="tagsText" name="tags" placeholder="php, vue, personal" />
                            <FieldDescription>Separate tags with commas.</FieldDescription>
                        </Field>
                        <FieldDescription v-if="latestCommit">Latest known local commit: {{ date(latestCommit.last_commit_at) }} · {{ latestCommit.branch ?? latestCommit.git_state }} · {{ latestCommit.path }}<template v-if="latestCommit.commit_subject"><br>{{ latestCommit.commit_subject }}</template></FieldDescription>
                    </FieldGroup>
                </TabsContent>
                <TabsContent value="repositories">
                    <FieldGroup>
                        <FieldSet>
                            <FieldLegend>Repositories</FieldLegend>
                            <FieldDescription v-if="!form.repositories.length">No repositories linked.</FieldDescription>
                            <FieldGroup v-for="(repo, index) in form.repositories" :key="repo.id">
                                <Field>
                                    <FieldLabel :for="`${repo.id}-name`">Repository name</FieldLabel>
                                    <Input :id="`${repo.id}-name`" v-model="repo.name" maxlength="255" :aria-invalid="!!form.errors[`repositories.${index}.name`]" />
                                </Field>
                                <Field>
                                    <FieldLabel :for="`${repo.id}-url`">Remote URL</FieldLabel>
                                    <Input :id="`${repo.id}-url`" v-model="repo.remote_url" placeholder="https://github.com/owner/repository.git" maxlength="2048" :aria-invalid="!!form.errors[`repositories.${index}.remote_url`]" />
                                </Field>
                                <div class="flex flex-wrap gap-2">
                                    <OpenTargetButton v-if="project?.repositories.some(saved => saved.id === repo.id)" :project-id="project.id" kind="repositories" :id="repo.id" :native="native" :href="repo.web_url" label="Open repository" />
                                    <Button type="button" variant="ghost" size="sm" @click="removeRepository(repo.id)"><Trash2Icon aria-hidden="true" />Remove repository</Button>
                                </div>
                            </FieldGroup>
                            <div><Button type="button" variant="outline" @click="addRepository"><PlusIcon aria-hidden="true" />Add repository</Button></div>
                        </FieldSet>
                        <FieldSet>
                            <FieldLegend>Folders</FieldLegend>
                            <FieldDescription v-if="!form.folders.length">No folders linked.</FieldDescription>
                            <FieldGroup v-for="(folder, index) in form.folders" :key="folder.id">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="break-all font-medium">{{ folder.path }}</span>
                                    <Badge :variant="folder.availability === 'Available' ? 'secondary' : 'destructive'">{{ folder.availability }}</Badge>
                                </div>
                                <FieldDescription>{{ folderScan(folder).git_state }}<template v-if="folderScan(folder).branch"> · {{ folderScan(folder).branch }}</template><template v-if="folderScan(folder).last_commit_at"> · {{ date(folderScan(folder).last_commit_at) }} · {{ folderScan(folder).last_commit_hash?.slice(0, 8) }}</template></FieldDescription>
                                <FieldDescription v-if="folderScan(folder).commit_subject">{{ folderScan(folder).commit_subject }}</FieldDescription>
                                <FieldDescription v-if="folderScan(folder).git_root" class="break-all">Git root: {{ folderScan(folder).git_root }}<template v-if="folderScan(folder).git_remote"><br>Remote: {{ folderScan(folder).git_remote }}</template></FieldDescription>
                                <div v-if="folderScan(folder).scan_state" class="flex flex-wrap items-center gap-2">
                                    <Badge variant="secondary">{{ folderScan(folder).scan_state }}</Badge>
                                    <span v-if="folderScan(folder).scan_error" class="text-sm text-destructive">{{ folderScan(folder).scan_error }}. Previous results retained.</span>
                                    <span v-if="folderScan(folder).scanned_at" class="text-sm text-muted-foreground">Scanned {{ date(folderScan(folder).scanned_at) }}</span>
                                    <Button type="button" size="sm" variant="outline" :disabled="scan.processing || ['Queued', 'Scanning'].includes(folderScan(folder).scan_state!)" @click="refresh('folder', folder.id)">Refresh Git</Button>
                                </div>
                                <Field>
                                    <FieldLabel :for="`${folder.id}-repository`">Repository</FieldLabel>
                                    <NativeSelect :id="`${folder.id}-repository`" :model-value="folder.repository_id ?? ''" :aria-invalid="!!form.errors[`folders.${index}.repository_id`]" @update:model-value="folder.repository_id = String($event) || null">
                                        <NativeSelectOption value="">No repository</NativeSelectOption>
                                        <NativeSelectOption v-for="repo in form.repositories" :key="repo.id" :value="repo.id">{{ repo.name || 'Unnamed repository' }}</NativeSelectOption>
                                    </NativeSelect>
                                </Field>
                                <div class="flex flex-wrap gap-2">
                                    <OpenTargetButton v-if="project?.folders.some(saved => saved.id === folder.id)" :project-id="project.id" kind="folders" :id="folder.id" :native="native" label="Open folder" />
                                    <Button type="button" variant="outline" size="sm" :disabled="inspection.processing" @click="reviewFolder(folder.id, native)">Relink folder</Button>
                                    <Button type="button" variant="ghost" size="sm" @click="form.folders.splice(index, 1); form.clearErrors()"><Trash2Icon aria-hidden="true" />Remove folder</Button>
                                </div>
                            </FieldGroup>
                            <Field>
                                <Input v-if="!native" v-model="inspection.path" aria-label="Folder path" placeholder="Absolute path to add or relink" />
                                <div><Button type="button" variant="outline" :disabled="inspection.processing" @click="reviewFolder(null, native)"><FolderPlusIcon aria-hidden="true" />{{ inspection.processing ? 'Reading folder…' : 'Add folder' }}</Button></div>
                                <FieldError v-if="inspection.errors.path" role="alert">{{ inspection.errors.path }}</FieldError>
                                <FieldDescription>Removing a folder or repository only unlinks it from this project.</FieldDescription>
                            </Field>
                        </FieldSet>
                    </FieldGroup>
                </TabsContent>
                <TabsContent value="links">
                    <FieldGroup>
                        <FieldDescription v-if="!form.links.length">No links added.</FieldDescription>
                        <FieldSet v-for="(link, index) in form.links" :key="link.id">
                            <FieldLegend>{{ link.label || 'New link' }}</FieldLegend>
                            <FieldGroup>
                                <Field>
                                    <FieldLabel :for="`${link.id}-label`">Label</FieldLabel>
                                    <Input :id="`${link.id}-label`" v-model="link.label" maxlength="255" :aria-invalid="!!form.errors[`links.${index}.label`]" />
                                </Field>
                                <Field>
                                    <FieldLabel :for="`${link.id}-url`">URL</FieldLabel>
                                    <Input :id="`${link.id}-url`" v-model="link.url" maxlength="2048" :aria-invalid="!!form.errors[`links.${index}.url`]" placeholder="https://example.com" />
                                </Field>
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <Field>
                                        <FieldLabel :for="`${link.id}-category`">Category (optional)</FieldLabel>
                                        <Input :id="`${link.id}-category`" :model-value="link.category ?? ''" list="link-categories" maxlength="50" @update:model-value="link.category = String($event)" />
                                    </Field>
                                    <Field>
                                        <FieldLabel :for="`${link.id}-icon`">Emoji (optional)</FieldLabel>
                                        <Input :id="`${link.id}-icon`" :model-value="link.icon ?? ''" maxlength="32" @update:model-value="link.icon = String($event)" />
                                    </Field>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <OpenTargetButton v-if="project?.links.some(saved => saved.id === link.id)" :project-id="project.id" kind="links" :id="link.id" :native="native" :href="project.links.find(saved => saved.id === link.id)?.url" :label="link.icon ? `${link.icon} Open link` : 'Open link'" />
                                    <Button type="button" variant="outline" size="icon-sm" :aria-label="`Move ${link.label || 'link'} up`" :disabled="index === 0" @click="moveLink(index, -1)"><ArrowUpIcon aria-hidden="true" /></Button>
                                    <Button type="button" variant="outline" size="icon-sm" :aria-label="`Move ${link.label || 'link'} down`" :disabled="index === form.links.length - 1" @click="moveLink(index, 1)"><ArrowDownIcon aria-hidden="true" /></Button>
                                    <Button type="button" variant="ghost" size="sm" @click="form.links.splice(index, 1); form.clearErrors()"><Trash2Icon aria-hidden="true" />Remove link</Button>
                                </div>
                            </FieldGroup>
                        </FieldSet>
                        <div><Button type="button" variant="outline" @click="addLink"><PlusIcon aria-hidden="true" />Add link</Button></div>
                        <datalist id="link-categories"><option v-for="category in ['Website', 'Social', 'Analytics', 'Inbox', 'Documentation', 'Hosting']" :key="category" :value="category" /></datalist>
                    </FieldGroup>
                </TabsContent>
            <Field orientation="horizontal">
                <Button type="submit" :disabled="form.processing || inspection.processing">{{ form.processing ? 'Saving…' : (project ? 'Save changes' : 'Create project') }}</Button>
                <Button v-if="!project" as-child variant="outline"><Link href="/">Cancel</Link></Button>
                <Button v-else type="button" variant="outline" :disabled="form.processing" @click="archive">{{ project.status === 'Archived' ? 'Restore project' : 'Archive project' }}</Button>
                <Button v-if="project" type="button" variant="destructive" :disabled="form.processing" @click="removeOpen = true">Remove project</Button>
            </Field>
            <FieldDescription v-if="project && message && !form.processing && !form.isDirty" role="status">{{ message }}</FieldDescription>
        </FieldGroup>
    </form>
    </Tabs>
    <Dialog v-model:open="previewOpen">
        <DialogContent v-if="preview">
            <DialogHeader><DialogTitle>{{ relinking ? 'Relink folder' : 'Review folder' }}</DialogTitle><DialogDescription>Review the detected metadata before adding it to your project.</DialogDescription></DialogHeader>
            <div class="max-h-[50vh] overflow-y-auto">
                <dl class="grid gap-2 text-sm">
                    <dt class="font-medium">Folder</dt><dd class="break-all">{{ preview.path }}</dd>
                    <dt class="font-medium">Name</dt><dd>{{ preview.name }}</dd>
                    <template v-if="preview.description"><dt class="font-medium">Description</dt><dd>{{ preview.description }}</dd></template>
                    <template v-if="preview.remote_url"><dt class="font-medium">Remote</dt><dd class="break-all">{{ preview.remote_url }}</dd></template>
                    <dt class="font-medium">Git</dt><dd>{{ preview.git_state }}<template v-if="preview.branch"> · {{ preview.branch }}</template></dd>
                    <template v-if="preview.last_commit_at"><dt class="font-medium">Local HEAD commit</dt><dd>{{ date(preview.last_commit_at) }}</dd></template>
                </dl>
                <Alert v-for="warning in preview.warnings" :key="warning"><AlertDescription>{{ warning }}</AlertDescription></Alert>
            </div>
            <DialogFooter><Button type="button" variant="outline" @click="previewOpen = false">Cancel</Button><Button type="button" @click="useFolder">Use folder</Button></DialogFooter>
        </DialogContent>
    </Dialog>
    <Dialog v-model:open="removeOpen">
        <DialogContent>
            <DialogHeader><DialogTitle>Remove {{ project?.name }}?</DialogTitle><DialogDescription>This removes the project and its saved metadata from Orbit. Source folders and repositories stay on disk. This cannot be undone.</DialogDescription></DialogHeader>
            <DialogFooter><Button type="button" variant="outline" :disabled="form.processing" @click="removeOpen = false">Cancel</Button><Button type="button" variant="destructive" :disabled="form.processing" @click="removeProject">Remove project</Button></DialogFooter>
        </DialogContent>
    </Dialog>
</template>
