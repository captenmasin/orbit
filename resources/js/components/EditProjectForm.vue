<script setup lang="ts">
import ChoiceSelect from '@/components/ChoiceSelect.vue';
import OpenTargetButton from '@/components/OpenTargetButton.vue';
import ProjectTagsInput from '@/components/ProjectTagsInput.vue';
import ProjectIconPicker from '@/components/ProjectIconPicker.vue';
import ConnectedRepositoryPicker from '@/components/ConnectedRepositoryPicker.vue';
import { toast } from 'vue-sonner';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { VueDraggable } from 'vue-draggable-plus';
import { Textarea } from '@/components/ui/textarea';
import { useObjectUrl, useSessionStorage } from '@vueuse/core';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { repositoryName , projectStatusColors } from '@/lib/project';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Link, router, useForm, useHttp, usePage } from '@inertiajs/vue3';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { computed, nextTick, onMounted, onScopeDispose, reactive, ref, watch } from 'vue';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field';
import type { FolderPreview, Project, ProjectFolder, ProjectLink, ProviderConnection, Repository } from '@/types';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { CheckIcon, ChevronsUpDownIcon, FolderPlusIcon, GripVerticalIcon, PlusIcon, SlidersHorizontalIcon, Trash2Icon } from '@lucide/vue';
import { AutocompleteAnchor, AutocompleteContent, AutocompleteInput, AutocompleteItem, AutocompletePortal, AutocompleteRoot, AutocompleteTrigger } from 'reka-ui';
import { Combobox, ComboboxAnchor, ComboboxEmpty, ComboboxGroup, ComboboxInput, ComboboxItem, ComboboxItemIndicator, ComboboxList, ComboboxTrigger } from '@/components/ui/combobox';

type FormRepository = Repository & { provider_full_name?: string };
type SelectedRepository = { connection_id: string; full_name: string; name: string; remote_url: string; description: string | null };
const props = defineProps<{ project: Project; statuses: string[]; native: boolean; connections?: ProviderConnection[] }>();
const formId = props.project.id;
const page = usePage<{ statusColors: Record<string, string> }>();
const statusOptions = computed(() => props.statuses.map(status => ({
    value: status, label: status,
    dotClass: projectStatusColors[page.props.statusColors?.[status] ?? 'gray']?.dotClass ?? projectStatusColors.gray!.dotClass,
})));
const noRepositoryOption = { id: '', name: 'No repository' };
const tab = useSessionStorage(`project:${formId}:edit-tab`, 'overview', { flush: 'sync' });
const requestedTab = new URLSearchParams(usePage().url.split('?')[1]).get('tab');
if (requestedTab && ['overview', 'repositories', 'links'].includes(requestedTab)) tab.value = requestedTab;
function values() {
    return {
        name: props.project.name ?? '', description: props.project.description ?? '',
        status: props.project.status ?? props.statuses[0]!,
        icon_type: props.project.icon_type ?? 'initials', icon_emoji: props.project.icon_emoji ?? '',
        icon_file: null as File | null, tags: props.project.tags.map(tag => tag.name) ?? [],
        repositories: (props.project.repositories ?? []).map(repo => ({ ...repo })) as FormRepository[],
        folders: (props.project.folders ?? []).map(folder => ({ ...folder })) as ProjectFolder[],
        links: (props.project.links ?? []).map(link => ({ ...link })) as ProjectLink[],
        revision: props.project.revision,
    };
}
const form = useForm(values());
const formElement = ref<HTMLFormElement>();
const generalErrors = computed(() => Object.fromEntries(Object.entries(form.errors).filter(([key]) => !/^(name|status|description|tags|icon_(type|emoji|file)|links\.\d+\.(label|url|category|description))$/.test(key))));
const draftKey = `project-form:${formId}`;
const recovered = router.restore(draftKey) as { data: ReturnType<typeof values>; imageSelected: boolean } | undefined;
const imageNeedsSelection = ref(!!recovered?.imageSelected);
if (recovered) {
    Object.assign(form, recovered.data, { icon_file: null });
    if (recovered.data.revision !== props.project.revision) form.setError('revision', 'This project changed. Reload saved details and review your recovered draft.');
}
watch(() => form.data(), () => {
    const { icon_file, ...data } = form.data();
    router.remember(form.isDirty ? { data: JSON.parse(JSON.stringify(data)), imageSelected: !!icon_file || imageNeedsSelection.value } : null, draftKey);
}, { deep: true, flush: 'post' });
const departureOpen = ref(false);
let destination: string | null = null;
const stopDeparture = router.on('before', event => {
    const visit = event.detail.visit;
    if (event.defaultPrevented || !form.isDirty || visit.method.toLowerCase() !== 'get') return;
    if (new URL(visit.url, 'https://orbit.local').pathname === new URL(usePage().url, 'https://orbit.local').pathname) return;
    event.preventDefault(); destination = visit.url.toString(); departureOpen.value = true;
});
function discardDraft() {
    form.reset(); form.defaults(); form.clearErrors(); imageNeedsSelection.value = false;
    router.remember(null, draftKey); departureOpen.value = false;
    const next = destination; destination = null;
    if (next) router.visit(next);
}
function reviewDraft() {
    router.reload({ only: ['selectedProject'], onSuccess: () => {
        form.defaults(values()); form.revision = props.project.revision;
        form.clearErrors('revision');
    } });
}
function warnBeforeUnload(event: BeforeUnloadEvent) {
    if (form.isDirty) { event.preventDefault(); event.returnValue = ''; }
}
if (typeof window !== 'undefined') window.addEventListener('beforeunload', warnBeforeUnload);
onMounted(() => { if (recovered) router.reload({ only: ['selectedProject'], onSuccess: () => {
    if (form.revision !== props.project.revision) form.setError('revision', 'This project changed. Reload saved details and review your recovered draft.');
} }); });
onScopeDispose(() => { stopDeparture(); if (typeof window !== 'undefined') window.removeEventListener('beforeunload', warnBeforeUnload); });
const linkDetailsOpen = reactive<Record<string, boolean>>(Object.fromEntries((props.project.links ?? []).map(link => [link.id, !!(link.category || link.description)])));
const linkAnnouncement = ref('');
watch(() => props.project.revision, () => {
    if (!form.isDirty) {
        form.defaults(values());
        form.reset();
    }
});
const imagePreview = useObjectUrl(computed(() => form.icon_file));
const removeOpen = ref(false);
const folderWarnings = reactive<Record<string, string[]>>({});
const inspection = useHttp<{ path: string }, { folder: FolderPreview | null }>({ path: '' });
const date = (value?: string | null) => value ? new Date(value).toLocaleString() : 'No known commit';

function submit() {
    if (form.processing || inspection.processing || form.errors.revision) return;
    if (form.icon_file && form.icon_file.size > 5242880) {
        form.setError('icon_file', 'Choose an image no larger than 5 MB.');
        tab.value = 'overview';
        return;
    }
    form.transform(data => ({ ...data, _method: 'put' })).post(`/projects/${props.project.id}`, {
        preserveScroll: true,
        errorBag: 'editProject',
        onSuccess: () => {
            router.remember(null, draftKey);
            form.defaults(values());
            form.reset();
        },
        onError: errors => {
            const key = Object.keys(errors)[0] ?? '';
            tab.value = key.startsWith('links') ? 'links' : key.startsWith('repositories') || key.startsWith('folders') ? 'repositories' : 'overview';
        },
        onFinish: () => {
            if (form.hasErrors) nextTick(() => formElement.value?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus());
        },
    });
}
function archive() {
    if (form.processing || inspection.processing || form.errors.revision) return;
    form.status = props.project.status === 'Archived' ? (props.project.previous_status ?? props.statuses[0]!) : 'Archived';
    submit();
}
function removeProject() {
    form.transform(data => ({ revision: data.revision })).delete(`/projects/${props.project.id}`, {
        errorBag: 'editProject',
        onError: () => { tab.value = 'overview'; },
        onFinish: () => { removeOpen.value = false; },
    });
}
const relinking = ref<string | null>(null);
const replacement = useHttp({ path: '' });
function relinkFolder(folder: ProjectFolder) {
    if (props.native) { void inspectFolder(folder.id, true); return; }
    relinking.value = folder.id; replacement.path = folder.path; replacement.clearErrors();
}
async function replaceFolder() {
    if (!relinking.value || replacement.processing) return;
    try {
        const result = await replacement.post('/folders/inspect') as { folder: FolderPreview | null } | undefined;
        if (!result?.folder) return;
        if (form.folders.some(folder => folder.path === result.folder!.path && folder.id !== relinking.value)) { replacement.setError('path', 'This folder is already linked.'); return; }
        useFolder(result.folder, relinking.value); relinking.value = null;
    } catch { if (!disposed && !replacement.hasErrors) replacement.setError('path', 'The folder could not be inspected. Check the path and try again.'); }
}
let disposed = false;
onScopeDispose(() => { disposed = true; inspection.cancel(); replacement.cancel(); });
async function inspectFolder(id: string | null = null, picker = false) {
    inspection.clearErrors();
    if (picker) inspection.path = '';
    try {
        const result = await inspection.post('/folders/inspect');
        if (result.folder) useFolder(result.folder, id);
    } catch {
        if (!disposed && !inspection.hasErrors) toast.error('The folder could not be inspected. Try again.');
    }
}
function useFolder(folder: FolderPreview, relinkingId: string | null = null) {
    if (form.folders.some(item => item.path === folder.path && item.id !== relinkingId)) {
        inspection.setError('path', 'This folder is already linked.');
        return;
    }
    const current = form.folders.find(item => item.id === relinkingId);
    let repositoryId = current?.repository_id ?? null;
    if (!current && folder.remote_url) {
        const key = (url: string) => url.replace(/^\w+@([^:]+):/, 'https://$1/').replace(/^ssh:\/\/(?:[^@/]+@)?/, 'https://').replace(/\.git\/?$/, '').replace(/\/$/, '');
        const existing = form.repositories.find(repo => key(repo.remote_url) === key(folder.remote_url!));
        repositoryId = existing?.id ?? crypto.randomUUID();
        if (!existing) form.repositories.push({ id: repositoryId, name: repositoryName(folder.remote_url) || folder.name, remote_url: folder.remote_url });
    }
    const data: ProjectFolder = {
        id: current?.id ?? crypto.randomUUID(), path: folder.path, repository_id: repositoryId,
        git_state: folder.git_state, branch: folder.branch, last_commit_at: folder.last_commit_at,
        last_commit_hash: folder.last_commit_hash, scanned_at: folder.scanned_at, availability: 'Available',
    };
    if (current) form.folders.splice(form.folders.indexOf(current), 1, data);
    else form.folders.push(data);
    folderWarnings[data.id] = folder.warnings ?? [];
    if (!relinkingId) { inspection.path = ''; inspection.clearErrors(); }
}
function removeRepository(id: string) {
    form.repositories = form.repositories.filter(repo => repo.id !== id);
    form.folders.forEach(folder => { if (folder.repository_id === id) folder.repository_id = null; });
    form.clearErrors();
}
async function moveLink(index: number, direction: number, event?: KeyboardEvent) {
    const target = index + direction;
    if (form.processing || index < 0 || index >= form.links.length || target < 0 || target >= form.links.length) return;
    const handle = event?.currentTarget as HTMLElement | undefined;
    const [link] = form.links.splice(index, 1);
    form.links.splice(target, 0, link!);
    form.clearErrors();
    linkAnnouncement.value = `${link!.label || 'Link'} moved to position ${target + 1} of ${form.links.length}.`;
    await nextTick();
    handle?.focus();
}
function updateRemote(repo: Repository, value: string) {
    const previous = repositoryName(repo.remote_url);
    if (!repo.name || repo.name === previous) repo.name = repositoryName(value);
    repo.remote_url = value;
}
function addRepository() {
    form.repositories.push({ id: crypto.randomUUID(), name: '', remote_url: '' });
}
function addConnectedRepository(repository: SelectedRepository) {
    const normalize = (url: string) => url.trim().replace(/\.git\/?$/i, '').replace(/\/$/, '').toLowerCase();
    const existing = form.repositories.find(item => normalize(item.remote_url) === normalize(repository.remote_url));
    if (existing) return existing.id;
    const id = crypto.randomUUID();
    form.repositories.push({ id, name: repository.name, remote_url: repository.remote_url, provider_connection_id: repository.connection_id, provider_full_name: repository.full_name });

    return id;
}
function addLink() {
    form.links.push({ id: crypto.randomUUID(), label: '', url: '', category: '', description: '', important: false });
}
</script>

<template>
    <Tabs
        v-model="tab"
        class="w-full gap-5">
        <TabsList
            variant="line"
            aria-label="Project sections"
            class="group-data-horizontal/tabs:h-auto max-w-full flex-wrap justify-start p-0">
            <TabsTrigger
                value="overview"
                class="h-8 flex-none rounded-none px-3 text-[13px]">
                Details
            </TabsTrigger>
            <TabsTrigger
                value="repositories"
                class="h-8 flex-none rounded-none px-3 text-[13px]">
                Sources
            </TabsTrigger>
            <TabsTrigger
                value="links"
                class="h-8 flex-none rounded-none px-3 text-[13px]">
                Links
            </TabsTrigger>
        </TabsList>
        <form
            ref="formElement"
            class="@container/project-form w-full"
            novalidate
            @submit.prevent="submit">
            <FieldGroup class="gap-6">
                <Alert
                    v-if="Object.keys(generalErrors).length"
                    variant="destructive"
                    role="alert">
                    <AlertDescription>
                        <div class="space-y-1">
                            <p
                                v-for="(error, key) in generalErrors"
                                :id="`${formId}-${key}-error`"
                                :key="key">
                                {{ error }}
                            </p>
                        </div>
                        <div v-if="form.errors.revision">
                            <Button
                                type="button"
                                variant="outline"
                                @click="reviewDraft">
                                Reload saved details and review draft
                            </Button>
                        </div>
                    </AlertDescription>
                </Alert>
                <div class="contents">
                    <TabsContent
                        value="repositories"
                        class="grid min-w-0 gap-4">
                        <div
                            class="grid items-start gap-5 lg:grid-cols-2">
                            <Card class="border border-foreground/10 retina:border-[0.5px]">
                                <CardHeader>
                                    <h2 class="text-sm font-normal tracking-[-0.025em]">
                                        Repositories
                                    </h2>
                                </CardHeader>
                                <CardContent>
                                    <FieldGroup class="gap-4">
                                        <FieldDescription>
                                            Choose from a connected account or add a remote URL.
                                        </FieldDescription>
                                        <div
                                            v-for="(repo, index) in form.repositories"
                                            :key="repo.id"
                                            class="grid gap-2 border-b pb-4">
                                            <div class="flex min-w-0 items-center justify-between gap-3">
                                                <span class="flex min-w-0 flex-wrap items-center gap-2"><span class="truncate text-sm font-normal">{{ repo.name || `Repository ${index + 1}` }}</span><Badge
                                                    v-if="repo.provider_connection_id"
                                                    variant="secondary"
                                                    class="shrink-0">{{ connections?.find(connection => connection.id === repo.provider_connection_id)?.provider === 'gitlab' ? 'GitLab' : 'GitHub' }} · {{ connections?.find(connection => connection.id === repo.provider_connection_id)?.label }}</Badge></span>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    :aria-label="`Remove ${repo.name || `repository ${index + 1}`}`"
                                                    @click="removeRepository(repo.id)">
                                                    <Trash2Icon aria-hidden="true" />
                                                </Button>
                                            </div>
                                            <FieldDescription v-if="project.repositories.some(saved => saved.id === repo.id) && repo.provider_connection_id">
                                                Connected provider. Changing this URL disconnects its provider and clears cached activity. You can reconnect it from Sources.
                                            </FieldDescription>
                                            <p
                                                v-if="repo.provider_full_name"
                                                :title="repo.remote_url"
                                                class="break-all text-xs text-muted-foreground">
                                                {{ repo.remote_url }}
                                            </p>
                                            <Field v-else>
                                                <Input
                                                    :id="`${repo.id}-url`"
                                                    :model-value="repo.remote_url"
                                                    variant="filled"
                                                    :aria-label="`Remote URL for ${repo.name || `repository ${index + 1}`}`"
                                                    placeholder="https://github.com/owner/repository.git"
                                                    maxlength="2048"
                                                    :aria-invalid="!!form.errors[`repositories.${index}.remote_url`]"
                                                    @update:model-value="updateRemote(repo, String($event))" />
                                            </Field>
                                            <FieldError
                                                v-if="form.errors[`repositories.${index}.provider_connection_id`] || form.errors[`repositories.${index}.provider_full_name`]"
                                                role="alert">
                                                {{ form.errors[`repositories.${index}.provider_connection_id`] || form.errors[`repositories.${index}.provider_full_name`] }}
                                            </FieldError>
                                            <div
                                                v-if="project.repositories.some(saved => saved.id === repo.id)"
                                                class="flex flex-wrap gap-2">
                                                <OpenTargetButton
                                                    :id="repo.id"
                                                    :project-id="project.id"
                                                    kind="repositories"
                                                    :native="native"
                                                    :href="repo.web_url"
                                                    label="Open repository" />
                                            </div>
                                        </div>
                                        <div
                                            class="flex flex-wrap gap-2">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                @click="addRepository">
                                                <PlusIcon aria-hidden="true" />Add remote URL
                                            </Button><ConnectedRepositoryPicker
                                                :connections="connections ?? []"
                                                :existing-urls="form.repositories.map(existing => existing.remote_url)"
                                                :native="native"
                                                :busy="form.processing"
                                                @select="addConnectedRepository($event)" />
                                        </div>
                                    </FieldGroup>
                                </CardContent>
                            </Card>
                            <Card class="border border-foreground/10 retina:border-[0.5px]">
                                <CardHeader>
                                    <h2 class="text-sm font-normal tracking-[-0.025em]">
                                        Local folders
                                    </h2>
                                </CardHeader>
                                <CardContent>
                                    <FieldGroup class="gap-4">
                                        <FieldDescription>
                                            Choose a local folder to link to this project.
                                        </FieldDescription>
                                        <Field
                                            class="gap-3">
                                            <FieldLabel
                                                v-if="!native"
                                                :for="`${formId}-sources-folder-path`">
                                                Folder path
                                            </FieldLabel>
                                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                                                <Input
                                                    v-if="!native"
                                                    :id="`${formId}-sources-folder-path`"
                                                    v-model="inspection.path"
                                                    variant="filled"
                                                    placeholder="/absolute/path/to/project"
                                                    :aria-invalid="!!inspection.errors.path"
                                                    :aria-describedby="inspection.errors.path ? `${formId}-sources-folder-path-error` : undefined" />
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    :size="!native ? 'input' : 'default'"
                                                    :disabled="inspection.processing"
                                                    @click="inspectFolder(null, native)">
                                                    <FolderPlusIcon aria-hidden="true" />{{ inspection.processing ? 'Reading folder…' : (native ? 'Choose folder' : 'Add folder') }}
                                                </Button>
                                            </div>
                                            <FieldError
                                                v-if="inspection.errors.path"
                                                :id="`${formId}-sources-folder-path-error`"
                                                role="alert">
                                                {{ inspection.errors.path }}
                                            </FieldError>
                                            <FieldDescription>
                                                Removing a folder or repository only unlinks it from this project.
                                            </FieldDescription>
                                        </Field>
                                        <FieldGroup
                                            v-for="(folder, index) in form.folders"
                                            :key="folder.id"
                                            class="gap-3 border-t pt-4">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span
                                                    :title="folder.path"
                                                    class="break-all font-medium">{{ folder.path }}</span>
                                                <Badge
                                                    class="shrink-0"
                                                    :variant="folder.availability === 'Available' ? 'secondary' : 'destructive'">
                                                    {{ folder.availability }}
                                                </Badge>
                                            </div>
                                            <FieldDescription>
                                                {{ folder.git_state }}<template v-if="folder.branch">
                                                    · {{ folder.branch }}
                                                </template><template v-if="folder.last_commit_at">
                                                    · {{ date(folder.last_commit_at) }} · {{ folder.last_commit_hash?.slice(0, 8) }}
                                                </template>
                                            </FieldDescription>
                                            <FieldDescription v-if="folder.commit_subject">
                                                {{ folder.commit_subject }}
                                            </FieldDescription>
                                            <FieldDescription
                                                v-if="folder.git_root"
                                                class="break-all">
                                                Git root: {{ folder.git_root }}<template v-if="folder.git_remote">
                                                    <br>Remote: {{ folder.git_remote }}
                                                </template>
                                            </FieldDescription>
                                            <div
                                                v-if="folder.scan_state"
                                                class="flex flex-wrap items-center gap-2">
                                                <Badge variant="secondary">
                                                    {{ folder.scan_state }}
                                                </Badge>
                                                <span
                                                    v-if="folder.scan_error"
                                                    class="text-sm text-destructive">{{ folder.scan_error }}. Previous results retained.</span>
                                                <span
                                                    v-if="folder.scanned_at"
                                                    class="text-sm text-muted-foreground">Scanned {{ date(folder.scanned_at) }}</span>
                                            </div>
                                            <Alert
                                                v-for="warning in folderWarnings[folder.id]"
                                                :key="warning">
                                                <AlertDescription>{{ warning }}</AlertDescription>
                                            </Alert>
                                            <div
                                                class="border-t border-border pt-4 @2xl/field-group:grid-cols-[minmax(0,1fr)_auto] @2xl/field-group:items-end">
                                                <Field class="min-w-0 gap-2">
                                                    <FieldLabel
                                                        :for="`${folder.id}-repository`">
                                                        Pair with repository
                                                    </FieldLabel>
                                                    <Combobox
                                                        :model-value="form.repositories.find(repo => repo.id === folder.repository_id) ?? noRepositoryOption"
                                                        by="id"
                                                        @update:model-value="folder.repository_id = ($event as { id: string } | null)?.id || null">
                                                        <ComboboxAnchor as-child>
                                                            <ComboboxTrigger
                                                                as-child
                                                                aria-label="Pair with repository">
                                                                <Button
                                                                    :id="`${folder.id}-repository`"
                                                                    type="button"
                                                                    variant="secondary"
                                                                    class="w-full justify-between"
                                                                    :aria-invalid="!!form.errors[`folders.${index}.repository_id`]">
                                                                    <span class="flex min-w-0 items-center gap-2"><span class="truncate">{{ form.repositories.find(repo => repo.id === folder.repository_id)?.name || 'No repository' }}</span></span>
                                                                    <ChevronsUpDownIcon class="shrink-0 opacity-50" />
                                                                </Button>
                                                            </ComboboxTrigger>
                                                        </ComboboxAnchor>
                                                        <ComboboxList>
                                                            <ComboboxInput
                                                                aria-label="Search repositories"
                                                                placeholder="Search repositories..." />
                                                            <ComboboxEmpty>No repository found.</ComboboxEmpty>
                                                            <ComboboxGroup>
                                                                <ComboboxItem
                                                                    v-for="option in [noRepositoryOption, ...form.repositories]"
                                                                    :key="option.id"
                                                                    :value="option">
                                                                    {{ option.name || 'Unnamed repository' }}
                                                                    <ComboboxItemIndicator><CheckIcon /></ComboboxItemIndicator>
                                                                </ComboboxItem>
                                                            </ComboboxGroup>
                                                        </ComboboxList>
                                                    </Combobox>
                                                </Field>
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <OpenTargetButton
                                                        v-if="project.folders.some(saved => saved.id === folder.id)"
                                                        :id="folder.id"
                                                        :project-id="project.id"
                                                        kind="folders"
                                                        :native="native"
                                                        label="Open folder"
                                                        size="input" />
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="input"
                                                        :aria-label="`Relink folder ${folder.path}`"
                                                        :disabled="inspection.processing"
                                                        @click="relinkFolder(folder)">
                                                        Relink
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="input"
                                                        :aria-label="`Remove folder ${folder.path}`"
                                                        @click="form.folders.splice(index, 1); delete folderWarnings[folder.id]; form.clearErrors()">
                                                        <Trash2Icon aria-hidden="true" />Remove
                                                    </Button>
                                                </div>
                                            </div>
                                        </FieldGroup>
                                    </FieldGroup>
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>
                    <TabsContent
                        value="overview"
                        class="min-w-0">
                        <div>
                            <Card>
                                <CardHeader>
                                    <h2 class="text-sm font-normal tracking-[-0.025em]">
                                        Project details
                                    </h2>
                                </CardHeader>
                                <CardContent>
                                    <FieldGroup class="gap-6">
                                        <div class="grid gap-6 sm:grid-cols-[minmax(0,1fr)_12rem]">
                                            <Field :data-invalid="!!form.errors.name">
                                                <FieldLabel
                                                    class="sr-only"
                                                    :for="`${formId}-name`">
                                                    Name
                                                </FieldLabel>
                                                <div class="flex items-center gap-3">
                                                    <ProjectIconPicker
                                                        :id="`${formId}-icon`"
                                                        v-model:type="form.icon_type"
                                                        v-model:emoji="form.icon_emoji"
                                                        :name="form.name"
                                                        :image="imagePreview ?? project.icon_url"
                                                        :invalid="!!(form.errors.icon_type || form.errors.icon_emoji || form.errors.icon_file)"
                                                        :aria-describedby="(form.errors.icon_type || form.errors.icon_emoji || form.errors.icon_file) ? `${formId}-icon-error` : undefined"
                                                        @update:file="form.icon_file = $event"
                                                        @update:type="form.clearErrors('icon_type', 'icon_emoji', 'icon_file')" />
                                                    <Input
                                                        :id="`${formId}-name`"
                                                        v-model="form.name"
                                                        variant="filled"
                                                        name="name"
                                                        maxlength="255"
                                                        :aria-invalid="!!form.errors.name"
                                                        :aria-describedby="form.errors.name ? `${formId}-name-error` : undefined" />
                                                </div>
                                                <FieldError
                                                    :id="`${formId}-name-error`"
                                                    :errors="[form.errors.name]" />
                                                <FieldError
                                                    v-if="form.errors.icon_type || form.errors.icon_emoji || form.errors.icon_file"
                                                    :id="`${formId}-icon-error`">
                                                    {{ form.errors.icon_type || form.errors.icon_emoji || form.errors.icon_file }}
                                                </FieldError>
                                                <!--                                            <FieldDescription v-if="imageNeedsSelection && form.icon_type === 'image' && !form.icon_file">-->
                                                <!--                                                Choose your image again to restore this draft.-->
                                                <!--                                            </FieldDescription>-->
                                            </Field>
                                            <Field :data-invalid="!!form.errors.status">
                                                <FieldLabel
                                                    class="sr-only"
                                                    :for="`${formId}-status`">
                                                    Status
                                                </FieldLabel>
                                                <ChoiceSelect
                                                    :id="`${formId}-status`"
                                                    v-model="form.status"
                                                    variant="filled"
                                                    name="status"
                                                    :options="statusOptions"
                                                    :aria-invalid="!!form.errors.status"
                                                    :aria-describedby="form.errors.status ? `${formId}-status-error` : undefined" />
                                                <FieldError
                                                    :id="`${formId}-status-error`"
                                                    :errors="[form.errors.status]" />
                                            </Field>
                                        </div>
                                        <Field :data-invalid="!!form.errors.description">
                                            <FieldLabel :for="`${formId}-description`">
                                                Description
                                            </FieldLabel>
                                            <Textarea
                                                :id="`${formId}-description`"
                                                v-model="form.description"
                                                variant="filled"
                                                name="description"
                                                class="resize-none"
                                                maxlength="10000"
                                                :rows="4"
                                                :aria-invalid="!!form.errors.description"
                                                :aria-describedby="form.errors.description ? `${formId}-description-error` : undefined" />
                                            <FieldError
                                                :id="`${formId}-description-error`"
                                                :errors="[form.errors.description]" />
                                        </Field>
                                        <Field :data-invalid="!!form.errors.tags">
                                            <FieldLabel :for="`${formId}-tags`">
                                                Tags
                                            </FieldLabel>
                                            <ProjectTagsInput
                                                :id="`${formId}-tags`"
                                                v-model="form.tags"
                                                :invalid="!!form.errors.tags" />
                                            <FieldError
                                                :id="`${formId}-tags-error`"
                                                :errors="[form.errors.tags]" />
                                        </Field>
                                    </FieldGroup>
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>
                    <TabsContent
                        value="links"
                        class="min-w-0">
                        <Card>
                            <CardHeader>
                                <h2 class="text-sm font-normal tracking-[-0.025em]">
                                    Useful links
                                </h2>
                            </CardHeader>
                            <CardContent>
                                <FieldGroup>
                                    <!--                                    <FieldDescription v-if="!form.links.length">-->
                                    <!--                                        No links yet. Add a site, document, or other useful destination.-->
                                    <!--                                    </FieldDescription>-->
                                    <p
                                        v-if="form.links.length"
                                        :id="`${formId}-link-order-help`"
                                        class="sr-only">
                                        Drag to reorder links, or focus a reorder handle and use the up and down arrow keys.
                                    </p>
                                    <VueDraggable
                                        v-if="form.links.length"
                                        v-model="form.links"
                                        tag="ol"
                                        handle=".project-link-handle"
                                        :disabled="form.processing"
                                        ghost-class="opacity-50"
                                        class="divide-y"
                                        aria-label="Useful links"
                                        @update="form.clearErrors(); linkAnnouncement = 'Link order updated.'">
                                        <Collapsible
                                            v-for="(link, index) in form.links"
                                            :key="link.id"
                                            as-child
                                            :open="linkDetailsOpen[link.id] || !!(form.errors[`links.${index}.category`] || form.errors[`links.${index}.description`])"
                                            @update:open="linkDetailsOpen[link.id] = $event">
                                            <li
                                                :aria-label="link.label || `Link ${index + 1}`"
                                                class="grid gap-3 py-4">
                                                <div class="flex min-w-0 items-start gap-3 @lg/project-form:items-center">
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        class="project-link-handle touch-none cursor-grab text-muted-foreground active:cursor-grabbing"
                                                        :aria-label="`Reorder ${link.label || 'link'}`"
                                                        :aria-describedby="`${formId}-link-order-help`"
                                                        :disabled="form.processing || form.links.length < 2"
                                                        @keydown.up.prevent="moveLink(index, -1, $event)"
                                                        @keydown.down.prevent="moveLink(index, 1, $event)">
                                                        <GripVerticalIcon aria-hidden="true" />
                                                    </Button>
                                                    <div class="grid min-w-0 flex-1 gap-3 @lg/project-form:grid-cols-[minmax(0,0.9fr)_minmax(0,1.6fr)]">
                                                        <Field
                                                            :data-invalid="!!form.errors[`links.${index}.label`]">
                                                            <FieldLabel
                                                                class="sr-only"
                                                                :for="`${link.id}-label`">
                                                                Label (optional)
                                                            </FieldLabel>
                                                            <Input
                                                                :id="`${link.id}-label`"
                                                                v-model="link.label"
                                                                variant="filled"
                                                                maxlength="2048"
                                                                :placeholder="link.url || 'Label (optional)'"
                                                                :aria-invalid="!!form.errors[`links.${index}.label`]"
                                                                :aria-describedby="form.errors[`links.${index}.label`] ? `${formId}-links.${index}.label-error` : undefined" />
                                                            <FieldError
                                                                :id="`${formId}-links.${index}.label-error`"
                                                                :errors="[form.errors[`links.${index}.label`]]" />
                                                        </Field>
                                                        <Field
                                                            :data-invalid="!!form.errors[`links.${index}.url`]">
                                                            <FieldLabel
                                                                class="sr-only"
                                                                :for="`${link.id}-url`">
                                                                URL
                                                            </FieldLabel>
                                                            <Input
                                                                :id="`${link.id}-url`"
                                                                v-model="link.url"
                                                                variant="filled"
                                                                maxlength="2048"
                                                                placeholder="https://example.com"
                                                                :aria-invalid="!!form.errors[`links.${index}.url`]"
                                                                :aria-describedby="form.errors[`links.${index}.url`] ? `${formId}-links.${index}.url-error` : undefined" />
                                                            <FieldError
                                                                :id="`${formId}-links.${index}.url-error`"
                                                                :errors="[form.errors[`links.${index}.url`]]" />
                                                        </Field>
                                                    </div>
                                                    <OpenTargetButton
                                                        v-if="project.links.some(saved => saved.id === link.id)"
                                                        :id="link.id"
                                                        :project-id="project.id"
                                                        kind="links"
                                                        :native="native"
                                                        :href="project.links.find(saved => saved.id === link.id)?.url"
                                                        label="Open link" />
                                                    <CollapsibleTrigger as-child>
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon-sm"
                                                            :aria-label="`Category and notes for ${link.label || 'link'}`"
                                                            title="Category & notes">
                                                            <SlidersHorizontalIcon aria-hidden="true" />
                                                        </Button>
                                                    </CollapsibleTrigger>
                                                    <Button
                                                        type="button"
                                                        variant="destructive"
                                                        size="icon-sm"
                                                        :aria-label="`Remove ${link.label || 'link'}`"
                                                        @click="form.links.splice(index, 1); form.clearErrors()">
                                                        <Trash2Icon aria-hidden="true" />
                                                    </Button>
                                                </div>
                                                <CollapsibleContent class="grid gap-4 pl-11">
                                                    <Field
                                                        class="gap-2"
                                                        :data-invalid="!!form.errors[`links.${index}.category`]">
                                                        <FieldLabel :for="`${link.id}-category`">
                                                            Category (optional)
                                                        </FieldLabel>
                                                        <AutocompleteRoot
                                                            :model-value="link.category ?? ''"
                                                            open-on-click
                                                            @update:model-value="link.category = $event">
                                                            <AutocompleteAnchor class="relative">
                                                                <AutocompleteInput as-child>
                                                                    <Input
                                                                        :id="`${link.id}-category`"
                                                                        :model-value="link.category ?? ''"
                                                                        variant="filled"
                                                                        maxlength="50"
                                                                        placeholder="Choose or type a category"
                                                                        :aria-invalid="!!form.errors[`links.${index}.category`]"
                                                                        :aria-describedby="form.errors[`links.${index}.category`] ? `${formId}-links.${index}.category-error` : undefined"
                                                                        class="pr-10"
                                                                        @update:model-value="link.category = String($event)" />
                                                                </AutocompleteInput>
                                                                <AutocompleteTrigger as-child>
                                                                    <Button
                                                                        type="button"
                                                                        variant="ghost"
                                                                        size="icon"
                                                                        class="absolute inset-y-0 right-0 text-muted-foreground"
                                                                        aria-label="Show category suggestions">
                                                                        <ChevronsUpDownIcon aria-hidden="true" />
                                                                    </Button>
                                                                </AutocompleteTrigger>
                                                            </AutocompleteAnchor>
                                                            <AutocompletePortal>
                                                                <AutocompleteContent
                                                                    position="popper"
                                                                    align="start"
                                                                    hide-when-empty
                                                                    class="z-50 max-h-56 min-w-(--reka-combobox-trigger-width) overflow-y-auto rounded-xl border retina:border-[0.5px] border-border bg-popover p-1 text-popover-foreground shadow-lg">
                                                                    <AutocompleteItem
                                                                        v-for="category in ['Website', 'Social', 'Analytics', 'Inbox', 'Documentation', 'Hosting']"
                                                                        :key="category"
                                                                        :value="category"
                                                                        class="flex cursor-default items-center rounded-lg px-3 py-2 text-sm outline-none data-[highlighted]:bg-accent data-[highlighted]:text-accent-foreground">
                                                                        {{ category }}
                                                                    </AutocompleteItem>
                                                                </AutocompleteContent>
                                                            </AutocompletePortal>
                                                        </AutocompleteRoot>
                                                        <FieldError
                                                            :id="`${formId}-links.${index}.category-error`"
                                                            :errors="[form.errors[`links.${index}.category`]]" />
                                                    </Field>
                                                    <Field
                                                        class="gap-2"
                                                        :data-invalid="!!form.errors[`links.${index}.description`]">
                                                        <FieldLabel :for="`${link.id}-description`">
                                                            Notes
                                                        </FieldLabel>
                                                        <Textarea
                                                            :id="`${link.id}-description`"
                                                            :model-value="link.description ?? ''"
                                                            variant="filled"
                                                            class="resize-none"
                                                            maxlength="10000"
                                                            :rows="3"
                                                            :aria-invalid="!!form.errors[`links.${index}.description`]"
                                                            :aria-describedby="form.errors[`links.${index}.description`] ? `${formId}-links.${index}.description-error` : undefined"
                                                            placeholder="Access notes or setup steps"
                                                            @update:model-value="link.description = String($event)" />
                                                        <FieldError
                                                            :id="`${formId}-links.${index}.description-error`"
                                                            :errors="[form.errors[`links.${index}.description`]]" />
                                                    </Field>
                                                </CollapsibleContent>
                                            </li>
                                        </Collapsible>
                                    </VueDraggable>
                                    <p
                                        class="sr-only"
                                        role="status">
                                        {{ linkAnnouncement }}
                                    </p>
                                    <div>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            @click="addLink">
                                            <PlusIcon aria-hidden="true" />{{ form.links.length ? 'Add another link' : 'Add link' }}
                                        </Button>
                                    </div>
                                </FieldGroup>
                            </CardContent>
                        </Card>
                    </TabsContent>
                </div>
                <FieldDescription>
                    {{ form.isDirty ? 'Archive or restore saves all entered project changes. ' : '' }}
                </FieldDescription>
            </FieldGroup>
            <Field
                orientation="horizontal"
                class="mt-6">
                <Button
                    type="submit"
                    :disabled="form.processing || inspection.processing || !!form.errors.revision">
                    {{ form.processing ? 'Saving…' : 'Save changes' }}
                </Button>
                <Button
                    as-child
                    variant="outline">
                    <Link :href="`/projects/${project.id}`">
                        Cancel
                    </Link>
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="form.processing"
                    @click="archive">
                    {{ form.isDirty ? (project.status === 'Archived' ? 'Save changes and restore' : 'Save changes and mark archived') : (project.status === 'Archived' ? 'Restore project' : 'Mark archived') }}
                </Button>
                <Button
                    type="button"
                    variant="destructive"
                    :disabled="form.processing"
                    @click="removeOpen = true">
                    Remove project
                </Button>
            </Field>
        </form>
        <Dialog v-model:open="departureOpen">
            <DialogContent>
                <DialogHeader><DialogTitle>Discard project changes?</DialogTitle><DialogDescription>Your unsaved details, sources and links will be discarded.</DialogDescription></DialogHeader><DialogFooter>
                    <Button
                        variant="outline"
                        @click="departureOpen = false; destination = null">
                        Stay
                    </Button><Button
                        variant="destructive"
                        @click="discardDraft">
                        Discard changes
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
        <Dialog
            :open="!!relinking"
            @update:open="relinking = null">
            <DialogContent>
                <DialogHeader><DialogTitle>Relink local folder</DialogTitle><DialogDescription>This replaces the project's folder link. Files are not moved or deleted.</DialogDescription></DialogHeader><form
                    class="grid gap-4"
                    @submit.prevent="replaceFolder">
                    <Field>
                        <FieldLabel for="replacement-path">
                            Replacement folder path
                        </FieldLabel><Input
                            id="replacement-path"
                            v-model="replacement.path"
                            :disabled="replacement.processing"
                            :aria-invalid="!!replacement.errors.path"
                            aria-describedby="replacement-error" /><FieldError
                                v-if="replacement.errors.path"
                                id="replacement-error">
                                {{ replacement.errors.path }}
                            </FieldError>
                    </Field><DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="replacement.processing"
                            @click="relinking = null">
                            Cancel
                        </Button><Button :disabled="replacement.processing">
                            {{ replacement.processing ? 'Reading folder…' : 'Relink folder' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
        <Dialog v-model:open="removeOpen">
            <DialogContent>
                <DialogHeader><DialogTitle>Remove {{ project.name }}?</DialogTitle><DialogDescription>This removes the project and its saved metadata from Orbit. Source folders and repositories stay on disk. This cannot be undone.</DialogDescription></DialogHeader>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="form.processing"
                        @click="removeOpen = false">
                        Cancel
                    </Button><Button
                        type="button"
                        variant="destructive"
                        :disabled="form.processing"
                        @click="removeProject">
                        Remove project
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </Tabs>
</template>
