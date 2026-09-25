<script setup lang="ts">
import { computed, nextTick, onScopeDispose, ref, watch } from 'vue';
import { Head, Link, router, useForm, useHttp, usePage } from '@inertiajs/vue3';
import { useDocumentVisibility, useIntervalFn, useSessionStorage, useWindowFocus } from '@vueuse/core';
import { ArrowUpRightIcon, ChevronDownIcon, FolderOpenIcon, InfoIcon, Link2Icon, PencilIcon, RefreshCwIcon, SearchIcon, SparklesIcon } from '@lucide/vue';
import { toast } from 'vue-sonner';
import ProjectIcon from '@/components/ProjectIcon.vue';
import ScratchpadActionsReview from '@/components/ScratchpadActionsReview.vue';
import LinkIcon from '@/components/LinkIcon.vue';
import MarkdownContent from '@/components/MarkdownContent.vue';
import OpenTargetButton from '@/components/OpenTargetButton.vue';
import ProjectDocuments from '@/components/ProjectDocuments.vue';
import ContentSearch from '@/components/ContentSearch.vue';
import ProjectAssets from '@/components/ProjectAssets.vue';
import ProjectBoard from '@/components/ProjectBoard.vue';
import ProjectDependencies from '@/components/ProjectDependencies.vue';
import ProjectProviderActivity from '@/components/ProjectProviderActivity.vue';
import ProjectSecrets from '@/components/ProjectSecrets.vue';
import ChoiceSelect from '@/components/ChoiceSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import type { Project, ProjectFolder, ProviderConnection, Repository, ScratchpadAction } from '@/types';
import { ContextMenu, ContextMenuContent, ContextMenuItem, ContextMenuTrigger } from '@/components/ui/context-menu';
import { projectStatusDotClasses } from '@/lib/project';

const props = defineProps<{ selectedProject: Project; statuses: string[]; inspection: ProjectFolder[]; activity: Repository[]; connections: ProviderConnection[]; native: boolean }>();
const project = computed(() => props.selectedProject);
const page = usePage();
const statusSaving = ref(false);
const statusError = ref('');
const statusNeedsReload = ref(false);
function changeStatus(status: string) {
    if (status === project.value.status || statusSaving.value || scratchpadSave.processing) return;
    statusSaving.value = true;
    statusError.value = '';
    statusNeedsReload.value = false;
    router.put(`/projects/${project.value.id}`, {
        name: project.value.name,
        description: project.value.description,
        status,
        revision: project.value.revision,
    }, {
        preserveScroll: true,
        onError: errors => {
            statusError.value = errors.revision ?? errors.status ?? Object.values(errors)[0] ?? 'Could not change status.';
            statusNeedsReload.value = !!errors.revision;
        },
        onNetworkError: () => { statusError.value = 'Could not change status. Try again.'; },
        onFinish: () => { statusSaving.value = false; },
    });
}
const taskCount = computed(() => (project.value.board_columns ?? []).reduce((count, column) => count + column.tasks.length, 0));
const previewColumns = computed(() => (project.value.board_columns ?? []).filter(column => column.tasks.length).slice(0, 3));
const recentDocuments = computed(() => [...(project.value.documents ?? [])].sort((a, b) => b.updated_at.localeCompare(a.updated_at)).slice(0, 2));
const scratchpadValues = () => ({ revision: project.value.revision, scratchpad: project.value.scratchpad ?? '' });
const scratchpadForm = useForm(scratchpadValues());
const scratchpadSave = useHttp<{ revision: number; scratchpad: string }, { revision: number }>(scratchpadValues());
const actionPreview = useHttp<{ revision: number }, { actions: ScratchpadAction[]; statuses: string[] }>({ revision: project.value.revision });
const actionStatuses = ref<string[]>([]);
const actionNotice = ref('');
const actionNeedsReload = ref(false);
const actionReview = ref<{ begin: (actions: ScratchpadAction[]) => void } | null>(null);
const suggestedActions = ref<ScratchpadAction[]>([]);
const actionButtonLabel = computed(() => actionPreview.processing ? 'Generating actions' : suggestedActions.value.length ? `Review ${suggestedActions.value.length} suggested ${suggestedActions.value.length === 1 ? 'action' : 'actions'}` : 'Generate actions');
let saveTimer: ReturnType<typeof setTimeout> | undefined;
let previewTimer: ReturnType<typeof setTimeout> | undefined;
let previewAfterSave = false;
let pendingNavigation: string | null = null;
let lastPreviewedNote = '';
let lastPreviewAt = 0;
function queueScratchpadSave() {
    clearTimeout(saveTimer);
    if (scratchpadForm.isDirty && !scratchpadForm.hasErrors) saveTimer = setTimeout(saveScratchpad, 800);
}
function queueActionPreview() {
    clearTimeout(previewTimer);
    if (!scratchpadForm.scratchpad.trim() || scratchpadForm.scratchpad === lastPreviewedNote || scratchpadForm.hasErrors || actionPreview.processing) return;
    previewTimer = setTimeout(() => {
        if (!scratchpadSave.processing && !scratchpadForm.isDirty) void generateActions(false);
    }, Math.max(4000, lastPreviewAt ? lastPreviewAt + 10000 - Date.now() : 0));
}
const stopNavigationGuard = router.on('before', event => {
    if (event.detail.visit.method !== 'get' || !scratchpadForm.isDirty) return;
    const destination = String(event.detail.visit.url);
    const currentUrl = new URL(page.url ?? '/', 'http://orbit.local');
    const targetUrl = new URL(destination, currentUrl);
    if (targetUrl.pathname === currentUrl.pathname && targetUrl.search === currentUrl.search) return;
    pendingNavigation = destination;
    if (!scratchpadSave.processing) void saveScratchpad();
    return false;
});
if (typeof window !== 'undefined') {
    const warnUnsaved = (event: BeforeUnloadEvent) => {
        if (scratchpadForm.isDirty || scratchpadSave.processing) { event.preventDefault(); event.returnValue = ''; }
    };
    window.addEventListener('beforeunload', warnUnsaved);
    onScopeDispose(() => window.removeEventListener('beforeunload', warnUnsaved));
}
onScopeDispose(() => { clearTimeout(saveTimer); clearTimeout(previewTimer); stopNavigationGuard(); });
watch(() => scratchpadForm.scratchpad, () => {
    suggestedActions.value = [];
    actionNotice.value = '';
    scratchpadForm.clearErrors('scratchpad');
    if (scratchpadForm.isDirty) { queueScratchpadSave(); queueActionPreview(); }
});
watch(() => project.value.revision, () => {
    if (scratchpadForm.isDirty || scratchpadSave.processing) {
        scratchpadForm.revision = project.value.revision;
        scratchpadForm.defaults('revision', project.value.revision);
    } else {
        scratchpadForm.defaults(scratchpadValues());
        scratchpadForm.reset();
    }
});
watch(() => project.value.id, () => {
    clearTimeout(saveTimer);
    clearTimeout(previewTimer);
    previewAfterSave = false;
    pendingNavigation = null;
    lastPreviewedNote = '';
    scratchpadForm.defaults(scratchpadValues());
    scratchpadForm.reset();
    scratchpadForm.clearErrors();
    actionPreview.clearErrors();
    actionStatuses.value = [];
    actionNotice.value = '';
    actionNeedsReload.value = false;
    suggestedActions.value = [];
    statusError.value = '';
    statusNeedsReload.value = false;
});
async function saveScratchpad() {
    clearTimeout(saveTimer);
    if (!scratchpadForm.isDirty || scratchpadSave.processing || scratchpadForm.errors.revision) return;
    scratchpadForm.clearErrors('scratchpad');
    const note = scratchpadForm.scratchpad;
    const projectId = project.value.id;
    Object.assign(scratchpadSave, { revision: scratchpadForm.revision, scratchpad: note });
    try {
        const result = await scratchpadSave.put(`/projects/${projectId}/scratchpad`);
        if (project.value.id !== projectId) return;
        if (!result) {
            scratchpadForm.setError(scratchpadSave.errors);
            if (!scratchpadForm.hasErrors) toast.error('Could not save notes. Try again.');
            pendingNavigation = null;
            previewAfterSave = false;
            return;
        }
        scratchpadForm.revision = result.revision;
        scratchpadForm.defaults({ revision: result.revision, scratchpad: note });
        if (scratchpadForm.scratchpad === note) scratchpadForm.reset();
        router.replaceProp('selectedProject', (current: Project) => current.id === projectId ? { ...current, revision: result.revision, scratchpad: note } : current);
        await nextTick();
        if (scratchpadForm.isDirty) {
            if (pendingNavigation) void saveScratchpad();
            else queueScratchpadSave();
        } else if (pendingNavigation) {
            const destination = pendingNavigation;
            pendingNavigation = null;
            router.visit(destination);
        } else if (previewAfterSave) { previewAfterSave = false; void generateActions(); }
        else queueActionPreview();
    } catch (error) {
        if (project.value.id !== projectId) return;
        if ((error as { response?: { status?: number } })?.response?.status === 409) scratchpadForm.setError('revision', 'This project changed. Reload the scratchpad before trying again.');
        else if (!scratchpadForm.hasErrors) toast.error('Could not save notes. Try again.');
        pendingNavigation = null;
        previewAfterSave = false;
    }
}
function reloadProject() {
    router.reload({ only: ['selectedProject'], onSuccess: () => {
        scratchpadForm.clearErrors('revision');
        actionNotice.value = '';
        actionNeedsReload.value = false;
        statusError.value = '';
        statusNeedsReload.value = false;
    } });
}
async function generateActions(openReview = true) {
    clearTimeout(previewTimer);
    actionNotice.value = '';
    if (openReview && suggestedActions.value.length && !scratchpadForm.isDirty) {
        actionReview.value?.begin(suggestedActions.value);
        return;
    }
    if (scratchpadForm.isDirty) {
        previewAfterSave = openReview;
        saveScratchpad();
        return;
    }
    if (scratchpadSave.processing || actionPreview.processing || !scratchpadForm.scratchpad.trim()) return;
    const projectId = project.value.id;
    const note = scratchpadForm.scratchpad;
    const revision = project.value.revision;
    lastPreviewedNote = note;
    lastPreviewAt = Date.now();
    actionPreview.revision = project.value.revision;
    actionPreview.clearErrors();
    actionNeedsReload.value = false;
    try {
        const result = await actionPreview.post(`/projects/${project.value.id}/scratchpad/actions/preview`);
        if (project.value.id !== projectId || scratchpadForm.scratchpad !== note || project.value.revision !== revision) return;
        if (result?.actions.length) {
            actionStatuses.value = result.statuses;
            suggestedActions.value = result.actions;
            if (openReview) actionReview.value?.begin(result.actions);
        }
        else toast.error(Object.values(actionPreview.errors)[0] ?? 'No project actions found in these notes.');
    } catch (error) {
        if (project.value.id !== projectId || scratchpadForm.scratchpad !== note) return;
        actionNeedsReload.value = (error as { response?: { status?: number } })?.response?.status === 409;
        if (actionNeedsReload.value) actionNotice.value = 'This project changed. Reload it before generating actions.';
        else toast.error('Could not suggest actions. Try again.');
    } finally {
        if ((project.value.id !== projectId || scratchpadForm.scratchpad !== note) && !scratchpadForm.isDirty) queueActionPreview();
    }
}
function actionsSaved() {
    router.reload({ only: ['selectedProject'], onSuccess: () => { actionNotice.value = ''; } });
}
const providerActivityOpen = ref(false);
const searchOpen = ref(false);
const linksOpen = ref(false);
async function openProjectSearch() {
    searchOpen.value = true;
    await nextTick();
    document.getElementById('project-content-search')?.focus();
}
async function closeProjectSearch() {
    searchOpen.value = false;
    await nextTick();
    document.getElementById('project-search-trigger')?.focus();
}
const tab = useSessionStorage(() => `project:${project.value.id}:tab`, 'overview', { flush: 'sync' });
watch(tab, value => { if (!['overview', 'documents', 'board', 'assets', 'dependencies', 'secrets'].includes(value)) tab.value = 'overview'; }, { immediate: true });
const query = computed(() => new URL(page.url ?? '/', 'http://orbit.local').searchParams);
const targetDocumentId = computed(() => query.value.get('document'));
const targetTaskId = computed(() => query.value.get('task'));
const targetSecretId = computed(() => query.value.get('secret'));
const targetLinkId = computed(() => query.value.get('link'));
const missingLink = computed(() => !!targetLinkId.value && !(project.value.links ?? []).some(link => link.id === targetLinkId.value));
const sortedLinks = computed(() => [...(project.value.links ?? [])].sort((a, b) => {
    const firstCategory = a.category?.trim() || 'Uncategorized';
    const secondCategory = b.category?.trim() || 'Uncategorized';
    if (firstCategory === 'Uncategorized') return secondCategory === 'Uncategorized' ? 0 : 1;
    if (secondCategory === 'Uncategorized') return -1;
    return firstCategory.localeCompare(secondCategory, undefined, { sensitivity: 'base' });
}));
const visibleLinks = computed(() => linksOpen.value || targetLinkId.value ? sortedLinks.value : sortedLinks.value.slice(0, 3));
const linkGroups = computed(() => {
    const groups = new Map<string, Project['links']>();
    for (const link of visibleLinks.value) {
        const category = link.category?.trim() || 'Uncategorized';
        if (!groups.has(category)) groups.set(category, []);
        groups.get(category)!.push(link);
    }
    return Array.from(groups, ([category, links]) => ({ category, links }));
});
async function copyLink(url: string) {
    try { await navigator.clipboard.writeText(url); toast.success('URL copied.'); }
    catch { toast.error('Could not copy URL.'); }
}
watch(() => project.value.id, () => { linksOpen.value = false; searchOpen.value = false; });
watch(query, value => {
    const requested = value.get('tab');
    if (requested && ['overview', 'documents', 'board', 'assets', 'dependencies', 'secrets'].includes(requested)) tab.value = requested;
}, { immediate: true });
watch([targetLinkId, tab, () => project.value.links], async () => {
    await nextTick();
    if (targetLinkId.value && !missingLink.value && tab.value === 'overview' && typeof document !== 'undefined') document.getElementById(`link-${targetLinkId.value}`)?.scrollIntoView({ block: 'center' });
}, { immediate: true });
const scan = useHttp({ kind: 'all' as 'all' | 'folder' | 'root', id: null as string | null, only_stale: false });
let automaticScanErrorProject: string | null = null;
const visible = useDocumentVisibility();
const focused = useWindowFocus();
const active = computed(() => visible.value === 'visible' && focused.value);
const pending = computed(() => props.inspection.some(folder => [folder, ...(folder.package_roots ?? [])].some(target => ['Queued', 'Scanning'].includes(target.scan_state ?? ''))));
const latestCommit = computed(() => [...props.inspection, ...props.activity.filter(repo => repo.remote_commit_at).map(repo => ({ last_commit_at: repo.remote_commit_at, branch: repo.default_branch, git_state: 'Remote default branch', path: `${repo.provider_name} · remote · ${repo.provider_snapshots?.find(snapshot => snapshot.resource === 'overview')?.state ?? 'Not scanned'}`, commit_subject: repo.provider_snapshots?.find(snapshot => snapshot.resource === 'overview')?.payload?.commit?.title }))].filter(folder => folder.last_commit_at).sort((a, b) => b.last_commit_at!.localeCompare(a.last_commit_at!))[0]);
const updateCount = computed(() => props.inspection.flatMap(folder => folder.package_roots ?? []).filter(root => ['Current', 'Partial'].includes(root.scan_state) && root.outdated?.fingerprint === root.snapshot?.fingerprint).reduce((count, root) => count + (root.outdated?.packages.length ?? 0), 0));
const folderIssueCount = computed(() => props.inspection.filter(folder => (folder.availability && folder.availability !== 'Available') || folder.scan_error).length);
const date = (value?: string | null) => value ? new Date(value).toLocaleString() : 'Not scanned';
let lastAutomaticCheck = 0;
let reloadingInspection = false;
function reloadInspection() {
    if (reloadingInspection) return;
    reloadingInspection = true;
    router.reload({ only: ['inspection'], onFinish: () => { reloadingInspection = false; } });
}
async function refresh(kind: 'all' | 'folder' | 'root' = 'all', id: string | null = null, onlyStale = false) {
    if (scan.processing) return;
    const projectId = project.value.id;
    Object.assign(scan, { kind, id, only_stale: onlyStale });
    scan.clearErrors();
    try {
        const result = await scan.post(`/projects/${projectId}/inspection`);
        if (project.value.id !== projectId) return;
        if (!result) throw new Error('Inspection request failed');
        automaticScanErrorProject = null;
        reloadInspection();
    } catch {
        if (project.value.id !== projectId) return;
        if (!onlyStale || automaticScanErrorProject !== projectId) toast.error(String(Object.values(scan.errors)[0] ?? 'Inspection could not be queued. Try refreshing again.'));
        if (onlyStale) automaticScanErrorProject = projectId;
    }
}
function checkInspection() {
    if (!active.value) return;
    if (Date.now() - lastAutomaticCheck >= 60000) {
        lastAutomaticCheck = Date.now();
        void refresh('all', null, true);
    } else if (pending.value) reloadInspection();
}
useIntervalFn(checkInspection, 2000);
watch([active, () => project.value.id], () => { lastAutomaticCheck = 0; checkInspection(); }, { immediate: true });
</script>

<template>
    <div class="flex w-full max-w-[1900px] flex-col gap-8 pb-8">
        <Head :title="project.name" />

        <header class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
            <div class="flex min-w-0 flex-1 items-start gap-4">
                <ProjectIcon :name="project.name" :type="project.icon_type" :emoji="project.icon_emoji" :image="project.icon_url" size="lg" />
                <div class="min-w-0 space-y-2">
                    <div class="flex flex-wrap items-center gap-3">
                        <h1 class="min-w-0 break-words text-[2rem] leading-tight font-semibold tracking-[-0.035em]">{{ project.name }}</h1>
                        <span class="flex items-center gap-1.5"><span class="size-2 shrink-0 rounded-full" :class="projectStatusDotClasses[project.status] ?? projectStatusDotClasses.Archived" aria-hidden="true"></span><ChoiceSelect :model-value="project.status" :options="statuses" aria-label="Project status" :disabled="statusSaving || scratchpadSave.processing" class="data-[size=default]:h-6 gap-1 rounded-sm border-0 bg-transparent py-0 pr-0 pl-0 text-xs text-muted-foreground shadow-none hover:bg-transparent hover:text-foreground dark:bg-transparent dark:hover:bg-transparent focus-visible:ring-2" @update:model-value="changeStatus" /></span>
                    </div>
                    <p v-if="statusError" role="alert" class="flex flex-wrap items-center gap-2 text-xs text-destructive"><span>{{ statusError }}</span><Button v-if="statusNeedsReload" type="button" size="sm" variant="outline" @click="reloadProject">Reload project</Button></p>
                    <p v-if="project.description" class="max-w-2xl whitespace-pre-line break-words text-sm leading-6 text-muted-foreground">{{ project.description }}</p>
                    <div v-if="project.tags.length" class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                        <template v-for="(tag, index) in project.tags" :key="tag.id"><span v-if="index" aria-hidden="true">·</span><span>{{ tag.name }}</span></template>
                    </div>
                    <p v-if="project.archived_at" class="text-xs text-muted-foreground">Archived {{ date(project.archived_at) }}</p>
                </div>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2" :class="searchOpen ? 'w-full lg:w-auto' : undefined">
                <div class="relative shrink-0 transition-[width] duration-200" :class="searchOpen ? 'w-full sm:w-80' : 'w-28'">
                    <Button v-if="!searchOpen" id="project-search-trigger" type="button" variant="outline" class="w-full" @click="openProjectSearch"><SearchIcon aria-hidden="true" />Search</Button>
                    <ContentSearch v-else id="project-search" compact :project-id="project.id" @close="closeProjectSearch" @navigate="searchOpen = false" />
                </div>
                <Button as-child variant="outline" :class="searchOpen ? 'hidden sm:inline-flex' : undefined"><Link :href="'/projects/' + project.id + '/edit'"><PencilIcon aria-hidden="true" />Edit project</Link></Button>
            </div>
        </header>

        <Tabs v-model="tab" class="w-full">
        <TabsList aria-label="Project sections" class="group-data-horizontal/tabs:h-auto max-w-full flex-wrap justify-start rounded-full border border-black/8 bg-muted p-1 dark:border-white/10">
            <TabsTrigger value="overview" class="h-9 flex-none rounded-full px-4 text-[13px] dark:data-active:bg-background">Overview</TabsTrigger>
            <TabsTrigger value="documents" class="h-9 flex-none rounded-full px-4 text-[13px] dark:data-active:bg-background">Documents</TabsTrigger>
            <TabsTrigger value="board" class="h-9 flex-none rounded-full px-4 text-[13px] dark:data-active:bg-background">Board</TabsTrigger>
            <TabsTrigger value="assets" class="h-9 flex-none rounded-full px-4 text-[13px] dark:data-active:bg-background">Assets</TabsTrigger>
            <TabsTrigger value="dependencies" class="h-9 flex-none rounded-full px-4 text-[13px] dark:data-active:bg-background">Dependencies</TabsTrigger>
            <TabsTrigger value="secrets" class="h-9 flex-none rounded-full px-4 text-[13px] dark:data-active:bg-background">Secrets</TabsTrigger>
        </TabsList>

        <TabsContent value="overview" class="space-y-8 pt-5">
            <div class="grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
                <div class="min-w-0 space-y-8">
                    <Card as="section" aria-labelledby="scratchpad-title">
                        <CardHeader><h2 id="scratchpad-title" class="text-base font-semibold">Scratchpad</h2></CardHeader>
                        <CardContent class="focus-within:border-ring focus-within:ring-3 focus-within:ring-ring/50" :class="scratchpadForm.errors.scratchpad ? 'border-destructive focus-within:border-destructive focus-within:ring-destructive/20' : undefined">
                            <form class="space-y-3" @submit.prevent="saveScratchpad()">
                                <label for="project-scratchpad" class="sr-only">Scratchpad notes</label>
                                <Textarea id="project-scratchpad" v-model="scratchpadForm.scratchpad" maxlength="50000" :rows="7" placeholder="Jot down ideas, reminders, and rough notes…" class="min-h-44 resize-none rounded-none border-0 bg-transparent p-0 font-mono shadow-none focus-visible:ring-0 aria-invalid:border-0 aria-invalid:ring-0 dark:bg-transparent" :aria-invalid="!!scratchpadForm.errors.scratchpad" :aria-describedby="scratchpadForm.errors.scratchpad ? 'scratchpad-error' : undefined" />
                                <div class="flex items-center gap-2">
                                    <Button type="submit" size="sm" :disabled="scratchpadSave.processing || !scratchpadForm.isDirty || !!scratchpadForm.errors.revision">Save notes</Button>
                                    <span role="status" class="text-xs text-muted-foreground">{{ scratchpadSave.processing ? 'Saving…' : '' }}</span>
                                    <Button type="button" size="icon-sm" variant="ghost" class="ml-auto" :aria-label="actionButtonLabel" :title="actionButtonLabel" :disabled="scratchpadSave.processing || actionPreview.processing || !!scratchpadForm.errors.revision || !scratchpadForm.scratchpad.trim()" @click="generateActions()"><SparklesIcon aria-hidden="true" :class="actionPreview.processing ? 'animate-pulse' : undefined" /></Button>
                                </div>
                                <p v-if="scratchpadForm.errors.scratchpad" id="scratchpad-error" role="alert" class="text-sm text-destructive">{{ scratchpadForm.errors.scratchpad }}</p>
                            <div v-if="scratchpadForm.errors.revision" role="alert" class="flex flex-wrap items-center gap-2 text-sm text-destructive"><span>{{ scratchpadForm.errors.revision }}</span><Button type="button" size="sm" variant="outline" @click="reloadProject">Reload project</Button></div>
                            <div v-if="actionNotice" role="alert" class="flex flex-wrap items-center gap-2 text-sm text-destructive"><span>{{ actionNotice }}</span><Button v-if="actionNeedsReload" type="button" size="sm" variant="outline" @click="reloadProject">Reload project</Button></div>
                            </form>
                            <ScratchpadActionsReview ref="actionReview" :key="project.id" :project="project" :statuses="actionStatuses" @saved="actionsSaved" />
                        </CardContent>
                    </Card>

                    <section v-if="taskCount" class="min-w-0" aria-labelledby="board-preview-title">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h2 id="board-preview-title" class="text-xl font-semibold tracking-tight">Board <span class="ml-1 text-sm font-normal text-muted-foreground">{{ taskCount }} {{ taskCount === 1 ? 'task' : 'tasks' }}</span></h2>
                            <Button type="button" variant="ghost" size="sm" @click="tab = 'board'">Open board <ArrowUpRightIcon aria-hidden="true" /></Button>
                        </div>
                        <div class="mt-4 flex gap-3 overflow-x-auto pb-1" role="region" aria-label="Board preview" tabindex="0">
                            <section v-for="column in previewColumns" :key="column.id" class="min-w-44 flex-1 rounded-[1.25rem] border border-black/8 bg-neutral-50 p-3 dark:border-white/10 dark:bg-neutral-800" :aria-label="column.name">
                                <h3 class="break-words text-sm font-semibold">{{ column.name }}</h3>
                                <ul class="mt-3 grid content-start gap-2">
                                    <li v-for="task in column.tasks.slice(0, 2)" :key="task.id">
                                        <Link :href="`/projects/${project.id}?tab=board&task=${task.id}`" class="block rounded-lg border bg-background px-3 py-2.5 text-sm font-medium hover:border-foreground/20 hover:shadow-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">{{ task.title }}</Link>
                                    </li>
                                </ul>
                                <p v-if="column.tasks.length > 2" class="mt-2 text-xs text-muted-foreground">+{{ column.tasks.length - 2 }} more</p>
                            </section>
                        </div>
                    </section>

                    <Card as="section" aria-labelledby="sources-title">
                        <CardHeader class="flex flex-wrap items-center justify-between gap-3">
                            <h2 id="sources-title" class="text-base font-semibold">Sources</h2>
                            <Button v-if="inspection.length" variant="outline" size="sm" :disabled="scan.processing" @click="refresh()"><RefreshCwIcon aria-hidden="true" />Refresh</Button>
                        </CardHeader>

                        <CardContent class="divide-y divide-border/70 p-0">
                            <div v-if="project.repositories.length" class="px-5 py-4">
                                <h3 class="text-sm font-semibold">Repositories <span class="ml-1 font-normal text-muted-foreground">{{ project.repositories.length }}</span></h3>
                                <ul class="mt-2 divide-y divide-border/70">
                                    <li v-for="repo in project.repositories" :key="repo.id" class="flex flex-wrap items-center justify-between gap-3 py-3">
                                        <div class="min-w-0 flex-1">
                                            <p class="break-words text-sm font-medium">{{ repo.name }}</p>
                                            <p class="break-all text-xs text-muted-foreground">{{ repo.remote_url }}</p>
                                        </div>
                                        <OpenTargetButton :project-id="project.id" kind="repositories" :id="repo.id" :native="native" :href="repo.web_url" label="Open repository" />
                                    </li>
                                </ul>
                            </div>

                            <div v-if="inspection.length" class="px-5 py-4">
                                <h3 class="text-sm font-semibold">Local folders <span class="ml-1 font-normal text-muted-foreground">{{ inspection.length }}</span></h3>
                                <ul class="mt-2 divide-y divide-border/70">
                                    <li v-for="folder in inspection" :key="folder.id">
                                        <details class="group py-3" :open="!!folder.scan_error || !!(folder.availability && folder.availability !== 'Available')">
                                            <summary class="flex cursor-pointer list-none items-center gap-2 rounded-md text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring [&::-webkit-details-marker]:hidden">
                                                <span class="min-w-0 flex-1 break-all font-medium">{{ folder.path }}</span>
                                                <Badge v-if="folder.availability && folder.availability !== 'Available'" variant="destructive">{{ folder.availability }}</Badge>
                                                <ChevronDownIcon class="size-4 shrink-0 text-muted-foreground transition-transform group-open:rotate-180" aria-hidden="true" />
                                            </summary>
                                            <div class="mt-3 grid gap-2 pl-2">
                                                <div class="flex flex-wrap items-center justify-between gap-3">
                                                    <div class="flex flex-wrap gap-1.5">
                                                        <Badge v-if="folder.availability === 'Available'" variant="secondary">{{ folder.availability }}</Badge>
                                                        <Badge v-if="folder.git_state" variant="outline">{{ folder.git_state }}</Badge>
                                                        <Badge v-if="folder.branch" variant="outline">{{ folder.branch }}</Badge>
                                                        <Badge v-if="folder.scan_state" variant="outline">{{ folder.scan_state }}</Badge>
                                                    </div>
                                                    <OpenTargetButton :project-id="project.id" kind="folders" :id="folder.id" :native="native" label="Open folder" />
                                                </div>
                                                <p v-if="folder.repository_id" class="text-xs text-muted-foreground">Repository: {{ project.repositories.find(repo => repo.id === folder.repository_id)?.name }}</p>
                                                <p v-if="folder.commit_subject" class="break-words text-sm">{{ folder.commit_subject }}</p>
                                                <p v-if="folder.last_commit_at" class="break-all text-xs text-muted-foreground">{{ date(folder.last_commit_at) }} · {{ folder.last_commit_hash }}</p>
                                                <p class="text-xs text-muted-foreground">Last scanned: {{ date(folder.scanned_at) }}</p>
                                                <p v-if="folder.scan_error" class="text-sm text-destructive">{{ folder.scan_error }}. Previous results retained.</p>
                                            </div>
                                        </details>
                                    </li>
                                </ul>
                            </div>

                            <div v-if="!project.repositories.length && !inspection.length" class="px-5 py-6">
                                <FolderOpenIcon class="size-5 text-muted-foreground" aria-hidden="true" />
                                <p class="mt-3 text-sm font-medium">Connect the code behind this project.</p>
                                <p class="mt-1 text-sm text-muted-foreground">Add a repository or local folder to see commits and scan details here.</p>
                                <Button as-child variant="outline" size="sm" class="mt-4"><Link :href="'/projects/' + project.id + '/edit'">Edit project</Link></Button>
                            </div>

                        </CardContent>
                    </Card>

                </div>

                <aside class="grid min-w-0 gap-4" aria-label="Project details">
                    <Card v-if="recentDocuments.length" as="section" aria-labelledby="documents-preview-title">
                        <CardHeader class="flex items-center justify-between gap-2">
                            <h2 id="documents-preview-title" class="text-base font-semibold">Documents</h2>
                        </CardHeader>
                        <CardContent class="min-h-32">
                            <ul class="grid gap-2">
                                <li v-for="document in recentDocuments" :key="document.id">
                                    <Link :href="`/projects/${project.id}?tab=documents&document=${document.id}`" class="block rounded-lg px-2 py-2.5 hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
                                        <span class="block break-words text-sm font-medium">{{ document.title }}</span>
                                        <span class="mt-1 block text-xs text-muted-foreground">Updated {{ date(document.updated_at) }}</span>
                                    </Link>
                                </li>
                            </ul>
                        </CardContent>
                    </Card>

                    <Card v-if="latestCommit" as="section" aria-labelledby="latest-commit-title">
                        <CardHeader><h2 id="latest-commit-title" class="text-base font-semibold">Latest commit</h2></CardHeader>
                        <CardContent class="min-h-32">
                            <p class="break-words text-sm font-medium">{{ latestCommit.commit_subject ?? 'Commit found' }}</p>
                            <p class="mt-1 text-xs leading-5 text-muted-foreground">{{ latestCommit.branch ?? latestCommit.git_state }} · {{ date(latestCommit.last_commit_at) }}</p>
                            <p class="mt-1 truncate text-xs text-muted-foreground" :title="latestCommit.path">{{ latestCommit.path }}</p>
                        </CardContent>
                    </Card>

                    <Card v-if="updateCount || folderIssueCount || pending" as="section" aria-labelledby="attention-title">
                        <CardHeader><h2 id="attention-title" class="text-base font-semibold">Needs attention</h2></CardHeader>
                        <CardContent class="min-h-32 gap-2">
                            <button v-if="updateCount" type="button" class="rounded-lg px-2 py-2.5 text-left hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring" @click="tab = 'dependencies'">
                                <span class="block text-sm font-medium">{{ updateCount }} dependency {{ updateCount === 1 ? 'update' : 'updates' }}</span>
                                <span class="mt-0.5 block text-xs text-muted-foreground">Open dependencies →</span>
                            </button>
                            <a v-if="folderIssueCount" href="#sources-title" class="block rounded-lg px-2 py-2.5 hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
                                <span class="block text-sm font-medium">{{ folderIssueCount }} local {{ folderIssueCount === 1 ? 'folder needs' : 'folders need' }} review</span>
                                <span class="mt-0.5 block text-xs text-muted-foreground">Review sources →</span>
                            </a>
                            <p v-if="pending" class="px-2 py-2.5 text-sm text-muted-foreground">Source scan in progress.</p>
                        </CardContent>
                    </Card>

                    <Card as="section" aria-labelledby="links-title">
                        <CardHeader><h2 id="links-title" class="text-base font-semibold">Links <span class="ml-1 text-xs font-normal text-muted-foreground">{{ project.links.length }}</span></h2></CardHeader>
                        <CardContent class="min-h-32 gap-3">
                            <p v-if="missingLink" role="status" class="text-sm text-muted-foreground">This link was removed. Choose another link.</p>
                            <div v-if="project.links.length" id="project-links" class="grid gap-4">
                                <section v-for="group in linkGroups" :key="group.category" :aria-label="group.category">
                                    <h3 class="px-2 text-sm font-semibold">{{ group.category }}</h3>
                                    <ul class="mt-1 grid gap-2">
                                        <ContextMenu v-for="link in group.links" :key="link.id">
                                            <ContextMenuTrigger as-child>
                                                <li :id="'link-' + link.id" class="flex min-w-0 items-center gap-3 rounded-lg px-2 py-2.5 transition hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring" :class="link.id === targetLinkId ? 'ring-2 ring-ring' : ''">
                                                    <LinkIcon :url="link.url" />
                                                    <div class="min-w-0 flex-1">
                                                        <p class="truncate text-sm font-semibold" :title="link.label">{{ link.label }}</p>
                                                        <p class="truncate text-xs text-muted-foreground" :title="link.url">{{ link.url }}</p>
                                                    </div>
                                                    <Dialog v-if="link.category || link.description_html">
                                                        <DialogTrigger as-child><Button type="button" variant="ghost" size="icon-sm" :aria-label="`Details for ${link.label}`"><InfoIcon aria-hidden="true" /></Button></DialogTrigger>
                                                        <DialogContent class="max-h-[85vh] overflow-y-auto">
                                                            <DialogHeader><DialogTitle>{{ link.label }}</DialogTitle><DialogDescription class="break-all">{{ link.url }}</DialogDescription></DialogHeader>
                                                            <p v-if="link.category" class="text-sm text-muted-foreground">{{ link.category }}</p>
                                                            <MarkdownContent v-if="link.description_html" :html="link.description_html" />
                                                        </DialogContent>
                                                    </Dialog>
                                                    <OpenTargetButton :project-id="project.id" kind="links" :id="link.id" :native="native" :href="link.url" :label="`Open ${link.label}`" compact />
                                                </li>
                                            </ContextMenuTrigger>
                                            <ContextMenuContent>
                                                <ContextMenuItem as-child><a :href="link.url" target="_blank" rel="noopener noreferrer">Open link</a></ContextMenuItem>
                                                <ContextMenuItem @select="copyLink(link.url)">Copy URL</ContextMenuItem>
                                            </ContextMenuContent>
                                        </ContextMenu>
                                    </ul>
                                </section>
                            </div>
                            <Button v-if="project.links.length > 3 && !targetLinkId" type="button" variant="ghost" size="sm" class="self-start" :aria-expanded="linksOpen" aria-controls="project-links" @click="linksOpen = !linksOpen">{{ linksOpen ? 'Show fewer links' : `Show all ${project.links.length} links` }}</Button>
                            <div v-if="!project.links.length">
                                <Link2Icon class="size-5 text-muted-foreground" aria-hidden="true" />
                                <p class="mt-2 text-sm font-medium">Keep important places close.</p>
                                <p class="mt-1 text-sm text-muted-foreground">Add project URLs in Edit project.</p>
                            </div>
                        </CardContent>
                    </Card>
                </aside>
            </div>

            <div v-if="project.repositories.length" class="space-y-4">
                <Button type="button" variant="outline" :aria-expanded="providerActivityOpen" @click="providerActivityOpen = !providerActivityOpen">
                    {{ providerActivityOpen ? 'Hide provider activity' : 'Show provider activity' }}
                    <ChevronDownIcon class="size-4 transition-transform" :class="{ 'rotate-180': providerActivityOpen }" aria-hidden="true" />
                </Button>
                <ProjectProviderActivity v-show="providerActivityOpen" :key="project.id" :project-id="project.id" :repositories="activity" :connections="connections" :active="active && tab === 'overview'" />
            </div>
        </TabsContent>

        <TabsContent value="documents" class="pt-4"><ProjectDocuments :key="project.id" :project="project" :target-document-id="targetDocumentId" /></TabsContent>
        <TabsContent value="board" class="pt-4"><ProjectBoard :key="project.id" :project="project" :target-task-id="targetTaskId" /></TabsContent>
        <TabsContent value="assets" class="pt-4"><ProjectAssets :key="project.id" :project="project" /></TabsContent>
        <TabsContent value="dependencies" class="pt-4"><ProjectDependencies :key="project.id" :project="project" :folders="inspection" :native="native" :busy="scan.processing" @refresh="refresh" @changed="reloadInspection" /></TabsContent>
        <TabsContent value="secrets" class="pt-4"><ProjectSecrets :key="project.id" :project="project" :native="native" :target-secret-id="targetSecretId" /></TabsContent>
        </Tabs>
    </div>
</template>
