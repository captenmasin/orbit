<script setup lang="ts">
import LinkIcon from '@/components/LinkIcon.vue';
import ProjectIcon from '@/components/ProjectIcon.vue';
import ChoiceSelect from '@/components/ChoiceSelect.vue';
import OpenTargetButton from '@/components/OpenTargetButton.vue';
import ProjectTagsInput from '@/components/ProjectTagsInput.vue';
import ConnectedRepositoryPicker from '@/components/ConnectedRepositoryPicker.vue';
import { toast } from 'vue-sonner';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { repositoryName } from '@/lib/project';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { useObjectUrl, useSessionStorage } from '@vueuse/core';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Link, router, useForm, useHttp, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onScopeDispose, reactive, ref, watch } from 'vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import type { FolderPreview, Project, ProjectFolder, ProjectLink, ProviderConnection, Repository } from '@/types';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import { AutocompleteAnchor, AutocompleteContent, AutocompleteInput, AutocompleteItem, AutocompletePortal, AutocompleteRoot, AutocompleteTrigger } from 'reka-ui';
import { Combobox, ComboboxAnchor, ComboboxEmpty, ComboboxGroup, ComboboxInput, ComboboxItem, ComboboxItemIndicator, ComboboxList, ComboboxTrigger } from '@/components/ui/combobox';
import { ArrowDownIcon, ArrowUpIcon, CheckIcon, ChevronDownIcon, ChevronsUpDownIcon, FolderOpenIcon, FolderPlusIcon, GitBranchIcon, PlusIcon, SlidersHorizontalIcon, Trash2Icon } from '@lucide/vue';

type FormRepository = Repository & { provider_full_name?: string };
type SelectedRepository = { connection_id: string; full_name: string; name: string; remote_url: string; description: string | null };
const props = defineProps<{ project?: Project; statuses: string[]; native: boolean; connections?: ProviderConnection[] }>();
const formId = props.project?.id ?? 'create';
const noRepositoryOption = { id: '', name: 'No repository' };
const tab = props.project ? useSessionStorage(`project:${formId}:edit-tab`, 'overview', { flush: 'sync' }) : ref('overview');
const requestedTab = new URLSearchParams(usePage().url.split('?')[1]).get('tab');
if (props.project && requestedTab && ['overview', 'repositories', 'links'].includes(requestedTab)) tab.value = requestedTab;
function values() {
    return {
        name: props.project?.name ?? '', description: props.project?.description ?? '',
        status: props.project?.status ?? props.statuses[0]!,
        icon_type: props.project?.icon_type ?? 'initials', icon_emoji: props.project?.icon_emoji ?? '',
        icon_file: null as File | null, tags: props.project?.tags.map(tag => tag.name) ?? [],
        repositories: (props.project?.repositories ?? []).map(repo => ({ ...repo })) as FormRepository[],
        folders: (props.project?.folders ?? []).map(folder => ({ ...folder })) as ProjectFolder[],
        links: (props.project?.links ?? []).map(link => ({ ...link })) as ProjectLink[],
        ...(props.project ? { revision: props.project.revision } : {}),
    };
}
const form = useForm(values());
const draftKey = `project-form:${formId}`;
const recovered = router.restore(draftKey) as { data: ReturnType<typeof values>; imageSelected: boolean } | undefined;
const imageNeedsSelection = ref(!!recovered?.imageSelected);
if (recovered) {
    Object.assign(form, recovered.data, { icon_file: null });
    if (props.project && recovered.data.revision !== props.project.revision) form.setError('revision', 'This project changed. Reload saved details and review your recovered draft.');
}
watch(() => form.data(), () => {
    const { icon_file, ...data } = form.data();
    router.remember(form.isDirty ? { data, imageSelected: !!icon_file || imageNeedsSelection.value } : null, draftKey);
}, { deep: true, flush: 'post' });
const departureOpen = ref(false);
let destination: string | null = null;
const stopDeparture = router.on('before', event => {
    const visit = event.detail.visit;
    if (!form.isDirty || visit.method.toLowerCase() !== 'get') return;
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
        form.defaults(values()); form.revision = props.project?.revision;
        form.clearErrors('revision');
    } });
}
function warnBeforeUnload(event: BeforeUnloadEvent) {
    if (form.isDirty) { event.preventDefault(); event.returnValue = ''; }
}
if (typeof window !== 'undefined') window.addEventListener('beforeunload', warnBeforeUnload);
onMounted(() => { if (recovered && props.project) router.reload({ only: ['selectedProject'], onSuccess: () => {
    if (form.revision !== props.project?.revision) form.setError('revision', 'This project changed. Reload saved details and review your recovered draft.');
} }); });
onScopeDispose(() => { stopDeparture(); if (typeof window !== 'undefined') window.removeEventListener('beforeunload', warnBeforeUnload); });
const linkDetailsOpen = reactive<Record<string, boolean>>(Object.fromEntries((props.project?.links ?? []).map(link => [link.id, !!(link.category || link.description)])));
watch(() => props.project?.revision, () => {
    if (!form.isDirty) {
        form.defaults(values());
        form.reset();
    }
});
const imagePreview = useObjectUrl(computed(() => form.icon_file));
const removeOpen = ref(false);
const folderWarnings = reactive<Record<string, string[]>>({});
const inspection = useHttp<{ path: string }, { folder: FolderPreview | null }>({ path: '' });
const cloning = useHttp<{ connection_id: string; full_name: string }, { folder: FolderPreview | null }>({ connection_id: '', full_name: '' });
const canStartFromRepo = computed(() => props.native && !!props.connections?.length);
const cloneError = computed(() => Object.values(cloning.errors)[0] ?? '');
const clonedSource = ref<{ repositoryId: string; path: string } | null>(null);
const startedSource = computed(() => {
    const repository = form.repositories.find(repo => repo.id === clonedSource.value?.repositoryId);
    const folder = form.folders.find(item => item.path === clonedSource.value?.path && item.repository_id === repository?.id);

    return folder && repository ? { folder, repository } : null;
});
const date = (value?: string | null) => value ? new Date(value).toLocaleString() : 'No known commit';

function submit() {
    if (form.processing || inspection.processing || cloning.processing || form.errors.revision) return;
    form.transform(data => ({ ...data, _method: props.project ? 'put' : 'post' })).post(props.project ? `/projects/${props.project.id}` : '/projects', {
        preserveScroll: true,
        errorBag: props.project ? 'editProject' : 'createProject',
        onSuccess: () => {
            router.remember(null, draftKey);
            if (props.project) {
                form.defaults(values());
                form.reset();
            }
        },
        onError: errors => {
            const key = Object.keys(errors)[0] ?? '';
            tab.value = key.startsWith('links') ? 'links' : key.startsWith('repositories') || key.startsWith('folders') ? 'repositories' : 'overview';
        },
    });
}
function archive() {
    if (form.processing || inspection.processing || cloning.processing || form.errors.revision) return;
    form.status = props.project?.status === 'Archived' ? (props.project.previous_status ?? props.statuses[0]!) : 'Archived';
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
    } catch { if (!replacement.hasErrors) replacement.setError('path', 'The folder could not be inspected. Check the path and try again.'); }
}
onScopeDispose(() => { inspection.cancel(); cloning.cancel(); replacement.cancel(); });
async function inspectFolder(id: string | null = null, picker = false) {
    inspection.clearErrors();
    if (picker) inspection.path = '';
    try {
        const result = await inspection.post('/folders/inspect');
        if (result.folder) useFolder(result.folder, id);
    } catch {
        if (!inspection.hasErrors) toast.error('The folder could not be inspected. Try again.');
    }
}
function useFolder(folder: FolderPreview, relinkingId: string | null = null, selectedRepositoryId: string | null = null) {
    if (form.folders.some(item => item.path === folder.path && item.id !== relinkingId)) {
        inspection.setError('path', 'This folder is already linked.');
        return;
    }
    const current = form.folders.find(item => item.id === relinkingId);
    let repositoryId = selectedRepositoryId ?? current?.repository_id ?? null;
    if (!current && folder.remote_url && !selectedRepositoryId) {
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
    if (!props.project) {
        if (!form.name) form.name = folder.name;
        if (!form.description) form.description = folder.description ?? '';
    }
    if (!relinkingId) { inspection.path = ''; inspection.clearErrors(); }
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
    if (!form.name) form.name = repository.name;
    if (!form.description && repository.description) form.description = repository.description;

    return id;
}
async function cloneRepository(repository: SelectedRepository) {
    if (cloning.processing) return;
    cloning.connection_id = repository.connection_id;
    cloning.full_name = repository.full_name;
    cloning.clearErrors();
    try {
        const result = await cloning.post('/repositories/clone');
        if (result?.folder) {
            const repositoryId = addConnectedRepository(repository);
            useFolder(result.folder, null, repositoryId);
            clonedSource.value = { repositoryId, path: result.folder.path };
        }
    } catch {
        if (!cloning.hasErrors) toast.error('The repository could not be cloned. Try again.');
    }
}
function addLink() {
    form.links.push({ id: crypto.randomUUID(), label: '', url: '', category: '', description: '' });
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
            class="w-full"
            novalidate
            @submit.prevent="submit">
            <FieldGroup class="gap-6">
                <Alert
                    v-if="form.hasErrors"
                    variant="destructive"
                    role="alert">
                    <AlertDescription>
                        <ul class="list-disc pl-4">
                            <li
                                v-for="(error, key) in form.errors"
                                :id="`${formId}-${key}-error`"
                                :key="key">
                                {{ error }}
                            </li>
                        </ul>
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
                <TabsContent value="overview">
                    <div :class="!project ? 'grid gap-5 lg:grid-cols-[minmax(0,1fr)_19rem] lg:items-start' : undefined">
                        <component :is="Card">
                            <CardHeader>
                                <h2 class="text-sm font-normal tracking-[-0.025em]">
                                    Project details
                                </h2>
                                <p class="text-sm text-muted-foreground">
                                    The basics that make this project easy to find.
                                </p>
                            </CardHeader>
                            <component
                                :is="CardContent"
                                class="p-5 sm:p-7">
                                <FieldGroup class="gap-6">
                                    <Field :data-invalid="!!form.errors.name">
                                        <FieldLabel :for="`${formId}-name`">
                                            Name
                                        </FieldLabel>
                                        <Input
                                            :id="`${formId}-name`"
                                            v-model="form.name"
                                            name="name"
                                            maxlength="255"
                                            :autofocus="!project"
                                            :aria-invalid="!!form.errors.name"
                                            :aria-describedby="form.errors.name ? `${formId}-name-error` : undefined"
                                            :placeholder="!project ? 'What are you working on?' : undefined" />
                                    </Field>
                                    <Field :data-invalid="!!form.errors.description">
                                        <FieldLabel :for="`${formId}-description`">
                                            Description (optional)
                                        </FieldLabel>
                                        <Textarea
                                            :id="`${formId}-description`"
                                            v-model="form.description"
                                            name="description"
                                            maxlength="10000"
                                            :rows="4"
                                            :aria-invalid="!!form.errors.description"
                                            :aria-describedby="form.errors.description ? `${formId}-description-error` : undefined"
                                            :placeholder="!project ? 'A short note about this project' : undefined" />
                                    </Field>
                                    <div class="grid gap-6 sm:grid-cols-2">
                                        <Field :data-invalid="!!form.errors.status">
                                            <FieldLabel :for="`${formId}-status`">
                                                Status
                                            </FieldLabel>
                                            <ChoiceSelect
                                                :id="`${formId}-status`"
                                                v-model="form.status"
                                                name="status"
                                                :options="statuses"
                                                :aria-invalid="!!form.errors.status" />
                                        </Field>
                                        <FieldSet>
                                            <FieldLegend
                                                :variant="!project ? 'label' : 'legend'"
                                                :class="!project ? 'leading-snug' : undefined">
                                                Icon
                                            </FieldLegend>
                                            <div class="flex items-center gap-4">
                                                <ProjectIcon
                                                    :name="form.name"
                                                    :type="form.icon_type"
                                                    :emoji="form.icon_emoji"
                                                    :image="imagePreview ?? project?.icon_url"
                                                    size="lg" />
                                                <ChoiceSelect
                                                    v-model="form.icon_type"
                                                    aria-label="Icon type"
                                                    :options="[{ value: 'initials', label: 'Initials' }, { value: 'emoji', label: 'Emoji' }, { value: 'image', label: 'Image' }]" />
                                            </div>
                                            <Field
                                                v-if="form.icon_type === 'emoji'"
                                                :data-invalid="!!form.errors.icon_emoji">
                                                <FieldLabel :for="`${formId}-emoji`">
                                                    Emoji
                                                </FieldLabel>
                                                <Input
                                                    :id="`${formId}-emoji`"
                                                    v-model="form.icon_emoji"
                                                    maxlength="32"
                                                    :aria-invalid="!!form.errors.icon_emoji"
                                                    placeholder="🪐" />
                                            </Field>
                                            <Field
                                                v-if="form.icon_type === 'image'"
                                                :data-invalid="!!form.errors.icon_file">
                                                <FieldLabel :for="`${formId}-image`">
                                                    Image
                                                </FieldLabel>
                                                <Input
                                                    :id="`${formId}-image`"
                                                    type="file"
                                                    accept="image/png,image/jpeg,image/gif,image/webp"
                                                    :aria-invalid="!!form.errors.icon_file"
                                                    @change="form.icon_file = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                                                <FieldDescription v-if="imageNeedsSelection">
                                                    Reselect your image; selected files cannot be recovered from history.
                                                </FieldDescription>
                                                <FieldDescription>PNG, JPEG, GIF or WebP. Up to 2 MB and 2048 × 2048 pixels.</FieldDescription>
                                            </Field>
                                        </FieldSet>
                                    </div>
                                    <Field :data-invalid="!!form.errors.tags">
                                        <FieldLabel :for="`${formId}-tags`">
                                            Tags
                                        </FieldLabel>
                                        <ProjectTagsInput
                                            :id="`${formId}-tags`"
                                            v-model="form.tags"
                                            :invalid="!!form.errors.tags" />
                                    </Field>
                                </FieldGroup>
                            </component>
                        </component>
                        <div
                            v-if="!project"
                            class="grid gap-4">
                            <aside class="rounded-[1.25rem] border border-black/8 bg-neutral-50 p-5 dark:border-white/10 dark:bg-neutral-800">
                                <div class="flex size-10 items-center justify-center rounded-xl bg-background text-muted-foreground dark:bg-[#303030]">
                                    <FolderPlusIcon
                                        class="size-5"
                                        aria-hidden="true" />
                                </div>
                                <h2 class="mt-5 text-sm font-normal tracking-[-0.02em]">
                                    Start from a folder
                                </h2>
                                <p class="mt-1 text-sm leading-6 text-muted-foreground">
                                    Choose a local folder to fill in its name and connect its repository.
                                </p>
                                <Field class="mt-6">
                                    <FieldLabel
                                        v-if="!native"
                                        :for="`${formId}-folder-path`">
                                        Folder path
                                    </FieldLabel>
                                    <Input
                                        v-if="!native"
                                        :id="`${formId}-folder-path`"
                                        v-model="inspection.path"
                                        placeholder="/absolute/path/to/project"
                                        class="h-10 rounded-xl bg-background px-3"
                                        :aria-invalid="!!inspection.errors.path"
                                        :aria-describedby="inspection.errors.path ? `${formId}-folder-path-error` : undefined" />
                                    <Button
                                        type="button"
                                        variant="outline"
                                        :disabled="inspection.processing"
                                        @click="inspectFolder(null, native)">
                                        <FolderPlusIcon aria-hidden="true" />{{ inspection.processing ? 'Reading folder…' : (native ? 'Choose folder' : 'Add folder') }}
                                    </Button>
                                    <FieldError
                                        v-if="inspection.errors.path"
                                        :id="`${formId}-folder-path-error`"
                                        role="alert">
                                        {{ inspection.errors.path }}
                                    </FieldError>
                                </Field>
                            </aside>
                            <aside
                                v-if="canStartFromRepo"
                                class="rounded-[1.25rem] border border-black/8 bg-neutral-50 p-5 dark:border-white/10 dark:bg-neutral-800">
                                <div class="flex size-10 items-center justify-center rounded-xl bg-background text-muted-foreground dark:bg-[#303030]">
                                    <GitBranchIcon
                                        class="size-5"
                                        aria-hidden="true" />
                                </div>
                                <h2 class="mt-5 text-sm font-normal tracking-[-0.02em]">
                                    Start from a repo
                                </h2>
                                <p class="mt-1 text-sm leading-6 text-muted-foreground">
                                    {{ startedSource ? 'Review the details, then create the project.' : 'Choose a connected repository and a folder to clone it into.' }}
                                </p>
                                <div
                                    v-if="startedSource"
                                    class="mt-6 grid gap-3">
                                    <div
                                        class="rounded-xl border bg-background p-3"
                                        role="status">
                                        <p class="flex items-center gap-2 break-all text-sm font-medium">
                                            <CheckIcon
                                                class="size-4 shrink-0 text-green-600 dark:text-green-400"
                                                aria-hidden="true" />{{ startedSource.repository.provider_full_name }}
                                        </p>
                                        <p class="mt-1 break-all text-xs text-muted-foreground">
                                            Cloned to {{ startedSource.folder.path }}
                                        </p>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        @click="tab = 'repositories'">
                                        View in Sources
                                    </Button>
                                </div>
                                <div
                                    v-else
                                    class="mt-6 grid gap-2">
                                    <ConnectedRepositoryPicker
                                        :connections="connections ?? []"
                                        :existing-urls="form.repositories.map(existing => existing.remote_url)"
                                        :native="native"
                                        clone
                                        :busy="cloning.processing"
                                        @select="cloneRepository" />
                                    <p
                                        v-if="cloning.processing"
                                        class="text-sm text-muted-foreground"
                                        role="status">
                                        Cloning {{ cloning.full_name }}…
                                    </p>
                                    <FieldError
                                        v-if="cloneError"
                                        role="alert">
                                        {{ cloneError }}
                                    </FieldError>
                                </div>
                            </aside>
                        </div>
                    </div>
                </TabsContent>
                <TabsContent value="repositories">
                    <component :is="Card">
                        <CardHeader
                            class="px-5 pb-3 pt-3 sm:px-7">
                            <h2 class="text-sm font-normal tracking-[-0.025em]">
                                Connect your work
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                Add a repository or a local folder now. You can add more later.
                            </p>
                        </CardHeader>
                        <div>
                            <FieldGroup class="grid gap-4 lg:grid-cols-2">
                                <component
                                    :is="CardContent"
                                    class="p-5 sm:p-6">
                                    <FieldSet>
                                        <FieldLegend :class="!project ? 'mb-0 flex items-center gap-3 font-normal' : undefined">
                                            <span
                                                v-if="!project"
                                                class="flex size-9 items-center justify-center rounded-xl bg-muted text-muted-foreground dark:bg-[#303030]"><GitBranchIcon
                                                    class="size-4"
                                                    aria-hidden="true" /></span>Repositories
                                        </FieldLegend>
                                        <p
                                            v-if="!project"
                                            class="text-sm leading-6 text-muted-foreground">
                                            Connect a Git remote to keep its code close to this project.
                                        </p>
                                        <FieldDescription v-else-if="!form.repositories.length">
                                            No repositories linked.
                                        </FieldDescription>
                                        <FieldGroup
                                            v-for="(repo, index) in form.repositories"
                                            :key="repo.id"
                                            :class="!project ? 'gap-4 border-t border-black/8 pt-5 dark:border-white/10' : undefined">
                                            <div
                                                v-if="!project"
                                                class="flex min-w-0 items-center justify-between gap-3">
                                                <span class="flex min-w-0 items-center gap-2"><span class="truncate text-sm font-normal">{{ repo.name || `Repository ${index + 1}` }}</span><Badge
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
                                            <FieldDescription v-if="project && repo.provider_connection_id">
                                                Connected provider. Changing this URL disconnects its provider and clears cached activity. You can reconnect it from Sources.
                                            </FieldDescription>
                                            <Field>
                                                <FieldLabel :for="`${repo.id}-url`">
                                                    Remote URL
                                                </FieldLabel>
                                                <Input
                                                    :id="`${repo.id}-url`"
                                                    :model-value="repo.remote_url"
                                                    placeholder="https://github.com/owner/repository.git"
                                                    maxlength="2048"
                                                    :readonly="!project && !!repo.provider_connection_id"
                                                    :aria-invalid="!!form.errors[`repositories.${index}.remote_url`]"
                                                    @update:model-value="updateRemote(repo, String($event))" />
                                            </Field>
                                            <FieldError
                                                v-if="form.errors[`repositories.${index}.provider_connection_id`] || form.errors[`repositories.${index}.provider_full_name`]"
                                                role="alert">
                                                {{ form.errors[`repositories.${index}.provider_connection_id`] || form.errors[`repositories.${index}.provider_full_name`] }}
                                            </FieldError>
                                            <div
                                                v-if="project"
                                                class="flex flex-wrap gap-2">
                                                <OpenTargetButton
                                                    v-if="project.repositories.some(saved => saved.id === repo.id)"
                                                    :id="repo.id"
                                                    :project-id="project.id"
                                                    kind="repositories"
                                                    :native="native"
                                                    :href="repo.web_url"
                                                    label="Open repository" />
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    @click="removeRepository(repo.id)">
                                                    <Trash2Icon aria-hidden="true" />Remove repository
                                                </Button>
                                            </div>
                                        </FieldGroup>
                                        <div :class="!project ? 'mt-auto flex flex-wrap gap-2 pt-1' : undefined">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                @click="addRepository">
                                                <PlusIcon aria-hidden="true" />Add repository
                                            </Button><ConnectedRepositoryPicker
                                                v-if="!project"
                                                :connections="connections ?? []"
                                                :existing-urls="form.repositories.map(existing => existing.remote_url)"
                                                :native="native"
                                                @select="addConnectedRepository" />
                                        </div>
                                    </FieldSet>
                                </component>
                                <component
                                    :is="CardContent"
                                    class="p-5 sm:p-6">
                                    <FieldSet>
                                        <FieldLegend :class="!project ? 'mb-0 flex items-center gap-3 font-normal' : undefined">
                                            <span
                                                v-if="!project"
                                                class="flex size-9 items-center justify-center rounded-xl bg-muted text-muted-foreground dark:bg-[#303030]"><FolderOpenIcon
                                                    class="size-4"
                                                    aria-hidden="true" /></span>Local folders
                                        </FieldLegend>
                                        <p
                                            v-if="!project"
                                            class="text-sm leading-6 text-muted-foreground">
                                            Choose a folder to bring in its name and Git details.
                                        </p>
                                        <FieldDescription v-else-if="!form.folders.length">
                                            No folders linked.
                                        </FieldDescription>
                                        <Field :class="!project ? 'mt-auto gap-3 pt-0 dark:border-white/10' : undefined">
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
                                                    placeholder="/absolute/path/to/project"
                                                    :aria-invalid="!!inspection.errors.path"
                                                    :aria-describedby="inspection.errors.path ? `${formId}-sources-folder-path-error` : undefined" />
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    :size="project && !native ? 'input' : 'default'"
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
                                            <FieldDescription v-if="project">
                                                Removing a folder or repository only unlinks it from this project.
                                            </FieldDescription>
                                        </Field>
                                        <FieldGroup
                                            v-for="(folder, index) in form.folders"
                                            :key="folder.id"
                                            :class="!project ? 'gap-3 border-t border-black/8 pt-5 dark:border-white/10' : 'gap-3'">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="break-all font-medium">{{ folder.path }}</span>
                                                <Badge :variant="folder.availability === 'Available' ? 'secondary' : 'destructive'">
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
                                                class="grid gap-4 border-t border-border pt-4"
                                                :class="project ? '@2xl/field-group:grid-cols-[minmax(0,1fr)_auto] @2xl/field-group:items-end' : '@sm/field-group:grid-cols-[minmax(0,1fr)_auto] @sm/field-group:items-end'">
                                                <Field class="min-w-0 gap-2">
                                                    <FieldLabel :for="`${folder.id}-repository`">
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
                                                                    variant="outline"
                                                                    class="h-9 w-[200px] justify-between rounded-md px-2.5"
                                                                    :aria-invalid="!!form.errors[`folders.${index}.repository_id`]">
                                                                    <span class="min-w-0 truncate">{{ form.repositories.find(repo => repo.id === folder.repository_id)?.name || 'No repository' }}</span>
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
                                                        v-if="project?.folders.some(saved => saved.id === folder.id)"
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
                                    </FieldSet>
                                </component>
                            </FieldGroup>
                        </div>
                    </component>
                </TabsContent>
                <TabsContent value="links">
                    <component :is="Card">
                        <CardHeader
                            class="px-5 pb-3 pt-3 sm:px-7">
                            <h2 class="text-sm font-normal tracking-[-0.025em]">
                                Useful links
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                Keep the places you visit for this project close at hand.
                            </p>
                        </CardHeader>
                        <component
                            :is="CardContent"
                            class="p-5 sm:p-7">
                            <FieldGroup :class="!project ? 'gap-4' : undefined">
                                <FieldDescription v-if="!form.links.length">
                                    No links yet. Add a site, document, or other useful destination.
                                </FieldDescription>
                                <div
                                    v-for="(link, index) in form.links"
                                    :key="link.id"
                                    role="group"
                                    :aria-labelledby="`${link.id}-heading`"
                                    :class="!project ? 'rounded-xl border border-black/8 bg-neutral-50 p-4 dark:border-white/10 dark:bg-neutral-800' : 'rounded-lg border p-4'">
                                    <div class="flex min-w-0 flex-wrap items-center justify-between gap-3">
                                        <div class="flex min-w-0 flex-1 items-center gap-2.5">
                                            <LinkIcon :url="link.url" />
                                            <h3
                                                :id="`${link.id}-heading`"
                                                class="truncate text-sm font-normal">
                                                {{ link.label || `Link ${index + 1}` }}
                                            </h3>
                                        </div>
                                        <div class="flex shrink-0 items-center gap-1">
                                            <OpenTargetButton
                                                v-if="project?.links.some(saved => saved.id === link.id)"
                                                :id="link.id"
                                                :project-id="project.id"
                                                kind="links"
                                                :native="native"
                                                :href="project.links.find(saved => saved.id === link.id)?.url"
                                                label="Open link" />
                                            <template v-if="form.links.length > 1">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    :aria-label="`Move ${link.label || 'link'} up`"
                                                    :disabled="index === 0"
                                                    @click="moveLink(index, -1)">
                                                    <ArrowUpIcon aria-hidden="true" />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    :aria-label="`Move ${link.label || 'link'} down`"
                                                    :disabled="index === form.links.length - 1"
                                                    @click="moveLink(index, 1)">
                                                    <ArrowDownIcon aria-hidden="true" />
                                                </Button>
                                            </template>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon-sm"
                                                :aria-label="`Remove ${link.label || 'link'}`"
                                                @click="form.links.splice(index, 1); form.clearErrors()">
                                                <Trash2Icon aria-hidden="true" />
                                            </Button>
                                        </div>
                                    </div>
                                    <div class="mt-4 grid gap-4 md:grid-cols-[minmax(0,0.9fr)_minmax(0,1.6fr)]">
                                        <Field
                                            class="gap-2"
                                            :data-invalid="!!form.errors[`links.${index}.label`]">
                                            <FieldLabel :for="`${link.id}-label`">
                                                Label
                                            </FieldLabel>
                                            <Input
                                                :id="`${link.id}-label`"
                                                v-model="link.label"
                                                maxlength="255"
                                                placeholder="Project website"
                                                :aria-invalid="!!form.errors[`links.${index}.label`]"
                                                :aria-describedby="form.errors[`links.${index}.label`] ? `${formId}-links.${index}.label-error` : undefined" />
                                        </Field>
                                        <Field
                                            class="gap-2"
                                            :data-invalid="!!form.errors[`links.${index}.url`]">
                                            <FieldLabel :for="`${link.id}-url`">
                                                URL
                                            </FieldLabel>
                                            <Input
                                                :id="`${link.id}-url`"
                                                v-model="link.url"
                                                maxlength="2048"
                                                placeholder="https://example.com"
                                                :aria-invalid="!!form.errors[`links.${index}.url`]"
                                                :aria-describedby="form.errors[`links.${index}.url`] ? `${formId}-links.${index}.url-error` : undefined" />
                                        </Field>
                                    </div>
                                    <Collapsible
                                        class="group/collapsible mt-4 border-t border-black/8 pt-2 dark:border-white/10"
                                        :open="linkDetailsOpen[link.id] || !!(form.errors[`links.${index}.category`] || form.errors[`links.${index}.description`])"
                                        @update:open="linkDetailsOpen[link.id] = $event">
                                        <CollapsibleTrigger as-child>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                class="-ml-2 w-full justify-start rounded-lg px-2 text-muted-foreground hover:bg-transparent hover:text-foreground aria-expanded:bg-transparent dark:hover:bg-transparent">
                                                <SlidersHorizontalIcon aria-hidden="true" />Category &amp; notes<ChevronDownIcon
                                                    class="ml-auto transition-transform group-data-[state=open]/collapsible:rotate-180"
                                                    aria-hidden="true" />
                                            </Button>
                                        </CollapsibleTrigger>
                                        <CollapsibleContent class="grid gap-4 pt-4">
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
                                                        <AutocompleteInput
                                                            :id="`${link.id}-category`"
                                                            maxlength="50"
                                                            placeholder="Choose or type a category"
                                                            :aria-invalid="!!form.errors[`links.${index}.category`]"
                                                            :aria-describedby="form.errors[`links.${index}.category`] ? `${formId}-links.${index}.category-error` : undefined"
                                                            class="h-10 w-full rounded-xl border border-input bg-background px-3 pr-10 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-3 aria-invalid:ring-destructive/20 md:text-sm" />
                                                        <AutocompleteTrigger
                                                            class="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-muted-foreground hover:text-foreground focus-visible:rounded-r-xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                                            aria-label="Show category suggestions">
                                                            <ChevronsUpDownIcon
                                                                class="size-4"
                                                                aria-hidden="true" />
                                                        </AutocompleteTrigger>
                                                    </AutocompleteAnchor>
                                                    <AutocompletePortal>
                                                        <AutocompleteContent
                                                            position="popper"
                                                            align="start"
                                                            hide-when-empty
                                                            class="z-50 max-h-56 min-w-(--reka-combobox-trigger-width) overflow-y-auto rounded-xl border border-border bg-popover p-1 text-popover-foreground shadow-lg">
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
                                            </Field>
                                            <Field
                                                class="gap-2"
                                                :data-invalid="!!form.errors[`links.${index}.description`]">
                                                <FieldLabel :for="`${link.id}-description`">
                                                    Notes (Markdown, optional)
                                                </FieldLabel>
                                                <Textarea
                                                    :id="`${link.id}-description`"
                                                    :model-value="link.description ?? ''"
                                                    maxlength="10000"
                                                    :rows="3"
                                                    :aria-invalid="!!form.errors[`links.${index}.description`]"
                                                    :aria-describedby="form.errors[`links.${index}.description`] ? `${formId}-links.${index}.description-error` : undefined"
                                                    placeholder="Access notes or setup steps"
                                                    @update:model-value="link.description = String($event)" />
                                            </Field>
                                        </CollapsibleContent>
                                    </Collapsible>
                                </div>
                                <div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        @click="addLink">
                                        <PlusIcon aria-hidden="true" />{{ form.links.length ? 'Add another link' : 'Add link' }}
                                    </Button>
                                </div>
                            </FieldGroup>
                        </component>
                    </component>
                </TabsContent>
                <FieldDescription v-if="project">
                    {{ form.isDirty ? 'Archive or restore saves all entered project changes. ' : '' }}Archived projects remain visible in Dashboard and the Archived sidebar group. Use the Status filter to narrow the list.
                </FieldDescription>
                <Field
                    orientation="horizontal"
                    :class="!project ? 'flex-wrap justify-end gap-3 pt-1' : undefined">
                    <Button
                        v-if="!project"
                        as-child
                        variant="ghost">
                        <Link href="/">
                            Cancel
                        </Link>
                    </Button>
                    <Button
                        type="submit"
                        :disabled="form.processing || inspection.processing || cloning.processing || !!form.errors.revision">
                        {{ form.processing ? 'Saving…' : (project ? 'Save changes' : 'Create project') }}
                    </Button>
                    <Button
                        v-if="project"
                        as-child
                        variant="outline">
                        <Link :href="`/projects/${project.id}`">
                            Cancel
                        </Link>
                    </Button>
                    <Button
                        v-if="project"
                        type="button"
                        variant="outline"
                        :disabled="form.processing"
                        @click="archive">
                        {{ form.isDirty ? (project.status === 'Archived' ? 'Save changes and restore' : 'Save changes and mark archived') : (project.status === 'Archived' ? 'Restore project' : 'Mark archived') }}
                    </Button>
                    <Button
                        v-if="project"
                        type="button"
                        variant="destructive"
                        :disabled="form.processing"
                        @click="removeOpen = true">
                        Remove project
                    </Button>
                </Field>
            </FieldGroup>
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
                <DialogHeader><DialogTitle>Remove {{ project?.name }}?</DialogTitle><DialogDescription>This removes the project and its saved metadata from Orbit. Source folders and repositories stay on disk. This cannot be undone.</DialogDescription></DialogHeader>
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
