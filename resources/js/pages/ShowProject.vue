<script setup lang="ts">
import LinkIcon from '@/components/LinkIcon.vue';
import ProjectBoard from '@/components/ProjectBoard.vue';
import ProjectHeader from '@/components/ProjectHeader.vue';
import ContentSearch from '@/components/ContentSearch.vue';
import ProjectAssets from '@/components/ProjectAssets.vue';
import ProjectSecrets from '@/components/ProjectSecrets.vue';
import MarkdownContent from '@/components/MarkdownContent.vue';
import NumberTransition from '@/components/NumberTransition.vue';
import OpenTargetButton from '@/components/OpenTargetButton.vue';
import ProjectDocuments from '@/components/ProjectDocuments.vue';
import ProjectScratchpad from '@/components/ProjectScratchpad.vue';
import ProjectDependencies from '@/components/ProjectDependencies.vue';
import ProjectProviderActivity from '@/components/ProjectProviderActivity.vue';
import { toast } from 'vue-sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { computed, nextTick, ref, watch } from 'vue';
import { dependencyHealth } from '@/lib/dependencies';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Head, Link, router, useHttp, usePage } from '@inertiajs/vue3';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { Project, ProjectFolder, ProviderConnection, Repository } from '@/types';
import { useDocumentVisibility, useIntervalFn, useSessionStorage, useTimeAgo, useWindowFocus } from '@vueuse/core';
import { ArrowRightIcon, ChevronDownIcon, Clock3Icon, FolderOpenIcon, GitBranchIcon, InfoIcon } from '@lucide/vue';
import { ContextMenu, ContextMenuContent, ContextMenuItem, ContextMenuTrigger } from '@/components/ui/context-menu';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';

const props = defineProps<{ selectedProject: Project; statuses: string[]; inspection: ProjectFolder[]; activity: Repository[]; connections: ProviderConnection[]; native: boolean }>();
const project = computed(() => props.selectedProject);
const page = usePage();
const scratchpad = ref<{ saving: boolean } | null>(null);
const secretsPage = ref<{ flush: () => Promise<boolean> } | null>(null);
const statusSaving = ref(false);
const statusError = ref('');
const statusNeedsReload = ref(false);
function changeStatus(status: string) {
    if (status === project.value.status || statusSaving.value || scratchpad.value?.saving) return;
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
const summaryPillClass = 'inline-flex h-8 shrink-0 select-none items-center gap-1.5 rounded-full bg-muted py-1 pr-3 pl-1 text-[13px] font-normal shadow-[0_0_0_1px_#0000000a] dark:shadow-[0_0_0_1px_#ffffff0d]';
const summaryValueClass = 'grid h-6 min-w-6 shrink-0 place-items-center rounded-full bg-background px-1.5 font-medium tabular-nums';
const previewTasks = computed(() => (project.value.board_columns ?? [])
    .flatMap(column => column.tasks.map(task => ({ ...task, column: column.name })))
    .slice(0, 3));
const recentDocuments = computed(() => [...(project.value.documents ?? [])].sort((a, b) => b.updated_at.localeCompare(a.updated_at)).slice(0, 2));
function reloadProject() {
    router.reload({ only: ['selectedProject'], onSuccess: () => {
        statusError.value = '';
        statusNeedsReload.value = false;
    } });
}
const providerActivityOpen = ref(false);
const providerActivity = ref<InstanceType<typeof ProjectProviderActivity> | null>(null);
function repositoryActivity(repository: Repository): Repository {
    return props.activity.find(item => item.id === repository.id) ?? repository;
}
async function connectProvider(repository: Repository) {
    providerActivityOpen.value = true; await nextTick(); providerActivity.value?.edit(repositoryActivity(repository));
}
async function refreshActivity(repository: Repository) {
    providerActivityOpen.value = true; await nextTick(); await providerActivity.value?.refresh(repositoryActivity(repository));
}
const linksOpen = ref(false);
const sourcesOpen = ref(false);
function closeProjectSearch() {
    document.querySelector<HTMLElement>('[aria-label="Project sections"] [role="tab"][aria-selected="true"]')?.focus();
}
const tab = useSessionStorage(() => `project:${project.value.id}:tab`, 'overview', { flush: 'sync' });
async function changeTab(value: string | number) {
    if (tab.value === 'secrets' && secretsPage.value && !await secretsPage.value.flush()) return;
    tab.value = String(value);
}
watch(tab, value => { if (!['overview', 'documents', 'board', 'assets', 'dependencies', 'secrets'].includes(value)) tab.value = 'overview'; }, { immediate: true });
const query = computed(() => new URL(page.url ?? '/', 'https://orbit.local').searchParams);
const targetDocumentId = computed(() => query.value.get('document'));
const targetTaskId = computed(() => query.value.get('task'));
const targetSecretId = computed(() => query.value.get('secret'));
const targetLinkId = computed(() => query.value.get('link'));
const missingLink = computed(() => !!targetLinkId.value && !(project.value.links ?? []).some(link => link.id === targetLinkId.value));
const visibleLinks = computed(() => linksOpen.value || targetLinkId.value ? project.value.links : project.value.links.slice(0, 3));
const visibleRepositories = computed(() => sourcesOpen.value ? project.value.repositories : project.value.repositories.slice(0, 2));
const visibleFolders = computed(() => sourcesOpen.value ? props.inspection : props.inspection.slice(0, 2));
async function copyLink(url: string) {
    try { await navigator.clipboard.writeText(url); toast.success('URL copied.'); }
    catch { toast.error('Could not copy URL.'); }
}
watch(() => project.value.id, () => {
    linksOpen.value = false;
    sourcesOpen.value = false;
    statusError.value = '';
    statusNeedsReload.value = false;
});
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
const latestCommitAge = useTimeAgo(() => latestCommit.value?.last_commit_at ?? Date.now());
const lastUpdateFromGit = computed(() => !!project.value.repositories?.length || props.activity.length > 0 || props.inspection.some(folder => !!folder.git_root || !!folder.last_commit_at));
const lastUpdateAt = computed(() => lastUpdateFromGit.value ? latestCommit.value?.last_commit_at ?? null : project.value.updated_at);
const lastUpdateAge = useTimeAgo(() => lastUpdateAt.value ?? Date.now());
const dependencies = computed(() => dependencyHealth(props.inspection));
const folderIssueCount = computed(() => props.inspection.filter(folder => (folder.availability && folder.availability !== 'Available') || folder.scan_error).length);
const needsAttention = computed(() => !!(dependencies.value.issueCount || folderIssueCount.value));
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
    <div
        class="flex w-full flex-col gap-6 pb-8"
        :class="{ 'max-w-[1900px]': tab !== 'board' }">
        <Head :title="project.name" />

        <ProjectHeader
            :project="project"
            :statuses="statuses"
            :status-disabled="statusSaving || !!scratchpad?.saving"
            :status-error="statusError"
            :status-needs-reload="statusNeedsReload"
            @change-status="changeStatus"
            @reload="reloadProject" />

        <Tabs
            :model-value="tab"
            class="w-full"
            @update:model-value="changeTab">
            <div class="flex flex-wrap items-start gap-2">
                <TabsList
                    variant="line"
                    aria-label="Project sections"
                    class="group-data-horizontal/tabs:h-auto max-w-full flex-[1_1_36rem] flex-wrap justify-start p-0">
                    <TabsTrigger
                        value="overview"
                        class="h-8 flex-none rounded-none px-3 text-[13px]">
                        Overview
                    </TabsTrigger>
                    <TabsTrigger
                        value="documents"
                        class="h-8 flex-none rounded-none px-3 text-[13px]">
                        Documents
                    </TabsTrigger>
                    <TabsTrigger
                        value="board"
                        class="h-8 flex-none rounded-none px-3 text-[13px]">
                        Board
                    </TabsTrigger>
                    <TabsTrigger
                        value="assets"
                        class="h-8 flex-none rounded-none px-3 text-[13px]">
                        Assets
                    </TabsTrigger>
                    <TabsTrigger
                        value="secrets"
                        class="h-8 flex-none rounded-none px-3 text-[13px]">
                        Secrets
                    </TabsTrigger>
                    <TabsTrigger
                        value="dependencies"
                        class="h-8 flex-none rounded-none px-3 text-[13px]">
                        Dependencies
                    </TabsTrigger>
                </TabsList>
                <div class="ml-auto h-8 w-full shrink-0 sm:w-80">
                    <div class="ml-auto h-8 w-24 t-resize focus-within:w-full motion-reduce:transition-none">
                        <ContentSearch
                            id="project-search"
                            compact
                            :project-id="project.id"
                            @close="closeProjectSearch"
                            @navigate="closeProjectSearch" />
                    </div>
                </div>
            </div>

            <TabsContent
                value="overview"
                force-mount
                class="space-y-5 pt-5 data-[state=inactive]:hidden">
                <div
                    role="group"
                    aria-label="Project summary"
                    class="flex flex-wrap items-center gap-2">
                    <component
                        :is="lastUpdateFromGit && lastUpdateAt ? 'a' : 'span'"
                        v-if="lastUpdateAt || lastUpdateFromGit"
                        :href="lastUpdateFromGit && lastUpdateAt ? '#recent-activity-title' : undefined"
                        :class="[summaryPillClass, lastUpdateFromGit && lastUpdateAt && 'transition-colors hover:bg-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring dark:hover:bg-neutral-700']">
                        <span :class="summaryValueClass"><GitBranchIcon
                            v-if="lastUpdateFromGit"
                            class="size-3.5"
                            aria-hidden="true" /><Clock3Icon
                                v-else
                                class="size-3.5"
                                aria-hidden="true" /></span><time
                                    v-if="lastUpdateAt"
                                    :datetime="lastUpdateAt"
                                    :title="date(lastUpdateAt)"><span class="sr-only">Last update </span>{{ lastUpdateAge }}</time><span v-else>No commit data</span><span
                                        v-if="lastUpdateFromGit"
                                        class="sr-only">from Git</span>
                    </component>
                    <!--                <button type="button" :class="summaryPillClass" class="cursor-pointer transition-colors hover:bg-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring dark:hover:bg-neutral-700" @click="tab = 'assets'"><span :class="summaryValueClass"><NumberTransition :value="project.assets?.length ?? 0" /></span>Assets</button>-->
                    <!--                <a href="#links-title" :class="summaryPillClass" class="transition-colors hover:bg-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring dark:hover:bg-neutral-700" @click="linksOpen = true"><span :class="summaryValueClass"><NumberTransition :value="project.links.length" /></span>Links</a>-->
                    <!--                <button type="button" :class="summaryPillClass" class="cursor-pointer transition-colors hover:bg-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring dark:hover:bg-neutral-700" @click="tab = 'documents'"><span :class="summaryValueClass"><NumberTransition :value="project.documents?.length ?? 0" /></span>Documents</button>-->
                    <button
                        type="button"
                        :class="summaryPillClass"
                        class="cursor-pointer transition-colors hover:bg-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring dark:hover:bg-neutral-700"
                        @click="tab = 'board'">
                        <span :class="summaryValueClass"><NumberTransition :value="taskCount" /></span>Cards
                    </button>
                    <a
                        v-if="needsAttention"
                        href="#attention-title"
                        :class="summaryPillClass"
                        class="transition-colors hover:bg-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring dark:hover:bg-neutral-700"><span
                            :class="summaryValueClass"
                            class="text-destructive"><NumberTransition :value="dependencies.issueCount + folderIssueCount" /></span>Needs attention</a>
                </div>
                <div class="grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1.65fr)_minmax(19rem,1fr)]">
                    <div class="min-w-0 space-y-5">
                        <ProjectScratchpad
                            ref="scratchpad"
                            :key="project.id"
                            :project="project" />

                        <Card
                            as="section"
                            aria-labelledby="board-preview-title">
                            <CardHeader class="flex flex-wrap items-center justify-between gap-2">
                                <h2
                                    id="board-preview-title"
                                    class="text-sm font-normal">
                                    Board <span class="ml-1 text-xs font-normal text-muted-foreground">{{ taskCount }} {{ taskCount === 1 ? 'card' : 'cards' }}</span>
                                </h2>
                                <!--                            <Button type="button" variant="ghost" size="sm" @click="tab = 'board'">View board <ArrowUpRightIcon aria-hidden="true" /></Button>-->
                            </CardHeader>
                            <CardContent>
                                <ul
                                    v-if="previewTasks.length"
                                    class="grid gap-1">
                                    <li
                                        v-for="task in previewTasks"
                                        :key="task.id">
                                        <Link
                                            :href="`/projects/${project.id}?tab=board&task=${task.id}`"
                                            class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 rounded-lg px-2 py-2.5 hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
                                            <span class="min-w-0 break-words text-sm font-medium">{{ task.title }}</span>
                                            <span class="shrink-0 text-xs text-muted-foreground">{{ task.column }}</span>
                                        </Link>
                                    </li>
                                </ul>
                                <p
                                    v-else
                                    class="text-sm text-muted-foreground">
                                    No cards yet.
                                </p>
                            </CardContent>
                        </Card>

                        <Card
                            as="section"
                            aria-labelledby="sources-title">
                            <CardHeader class="flex flex-wrap items-center justify-between gap-3">
                                <h2
                                    id="sources-title"
                                    class="text-sm font-normal">
                                    Sources
                                </h2>
                                <Button
                                    v-if="inspection.length"
                                    variant="outline"
                                    size="sm"
                                    :disabled="scan.processing || pending"
                                    @click="refresh()">
                                    Refresh local folders
                                </Button>
                            </CardHeader>

                            <CardContent
                                id="project-sources"
                                class="divide-y divide-border/70 p-0">
                                <div
                                    v-if="project.repositories.length"
                                    class="px-5 py-4">
                                    <h3 class="text-sm font-normal">
                                        Repositories <span class="ml-1 font-normal text-muted-foreground">{{ project.repositories.length }}</span>
                                    </h3>
                                    <ul class="mt-2 divide-y divide-border/70">
                                        <li
                                            v-for="repo in visibleRepositories"
                                            :key="repo.id"
                                            class="flex flex-wrap items-center justify-between gap-3 py-2.5">
                                            <div class="min-w-0 flex-1">
                                                <p class="break-words text-sm font-medium">
                                                    {{ repo.name }}
                                                </p>
                                                <p class="break-all text-xs text-muted-foreground">
                                                    {{ repo.remote_url }}
                                                </p>
                                            </div>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                @click="connectProvider(repo)">
                                                {{ repositoryActivity(repo).provider_connection_id ? 'Connection settings' : 'Connect provider' }}
                                            </Button>
                                            <Button
                                                v-if="repositoryActivity(repo).provider_connection_id"
                                                variant="outline"
                                                size="sm"
                                                :disabled="!providerActivity || providerActivity.disabled(repositoryActivity(repo))"
                                                @click="refreshActivity(repo)">
                                                Refresh activity
                                            </Button>
                                            <OpenTargetButton
                                                :id="repo.id"
                                                :project-id="project.id"
                                                kind="repositories"
                                                :native="native"
                                                :href="repo.web_url"
                                                label="Open repository"
                                                compact />
                                        </li>
                                    </ul>
                                </div>

                                <div
                                    v-if="inspection.length"
                                    class="px-5 py-4">
                                    <h3 class="text-sm font-normal">
                                        Local folders <span class="ml-1 font-normal text-muted-foreground">{{ inspection.length }}</span>
                                    </h3>
                                    <ul class="mt-2 divide-y divide-border/70">
                                        <li
                                            v-for="folder in visibleFolders"
                                            :key="folder.id">
                                            <details
                                                class="t-disclosure group py-3"
                                                :open="!!folder.scan_error || !!(folder.availability && folder.availability !== 'Available')">
                                                <summary class="flex cursor-pointer list-none items-center gap-2 rounded-md text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring [&::-webkit-details-marker]:hidden">
                                                    <span class="min-w-0 flex-1 select-text break-all font-medium">{{ folder.path }}</span>
                                                    <Badge
                                                        v-if="folder.availability && folder.availability !== 'Available'"
                                                        variant="destructive">
                                                        {{ folder.availability }}
                                                    </Badge>
                                                    <ChevronDownIcon
                                                        class="size-4 shrink-0 text-muted-foreground transition-transform group-open:rotate-180"
                                                        aria-hidden="true" />
                                                </summary>
                                                <div class="mt-3 grid gap-2 pl-2">
                                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                                        <div class="flex flex-wrap gap-1.5">
                                                            <Badge
                                                                v-if="folder.availability === 'Available'"
                                                                variant="secondary">
                                                                {{ folder.availability }}
                                                            </Badge>
                                                            <Badge
                                                                v-if="folder.git_state"
                                                                variant="outline">
                                                                {{ folder.git_state }}
                                                            </Badge>
                                                            <Badge
                                                                v-if="folder.branch"
                                                                variant="outline">
                                                                {{ folder.branch }}
                                                            </Badge>
                                                            <Badge
                                                                v-if="folder.scan_state"
                                                                variant="outline">
                                                                {{ folder.scan_state }}
                                                            </Badge>
                                                        </div>
                                                        <OpenTargetButton
                                                            :id="folder.id"
                                                            :project-id="project.id"
                                                            kind="folders"
                                                            :native="native"
                                                            label="Open folder" />
                                                    </div>
                                                    <p
                                                        v-if="folder.repository_id"
                                                        class="text-xs text-muted-foreground">
                                                        Repository: {{ project.repositories.find(repo => repo.id === folder.repository_id)?.name }}
                                                    </p>
                                                    <p
                                                        v-if="folder.commit_subject"
                                                        class="break-words text-sm">
                                                        {{ folder.commit_subject }}
                                                    </p>
                                                    <p
                                                        v-if="folder.last_commit_at"
                                                        class="break-all text-xs text-muted-foreground">
                                                        {{ date(folder.last_commit_at) }} · {{ folder.last_commit_hash }}
                                                    </p>
                                                    <p class="text-xs text-muted-foreground">
                                                        Last scanned: {{ date(folder.scanned_at) }}
                                                    </p>
                                                    <p
                                                        v-if="folder.scan_error"
                                                        class="text-sm text-destructive">
                                                        {{ folder.scan_error }}. Previous results retained.
                                                    </p>
                                                </div>
                                            </details>
                                        </li>
                                    </ul>
                                </div>

                                <div
                                    v-if="project.repositories.length > 2 || inspection.length > 2"
                                    class="px-5 py-3">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        :aria-expanded="sourcesOpen"
                                        aria-controls="project-sources"
                                        @click="sourcesOpen = !sourcesOpen">
                                        {{ sourcesOpen ? 'Show fewer sources' : 'View all sources' }}
                                    </Button>
                                </div>

                                <div
                                    v-if="!project.repositories.length && !inspection.length"
                                    class="px-5 py-6">
                                    <FolderOpenIcon
                                        class="size-5 text-muted-foreground"
                                        aria-hidden="true" />
                                    <p class="mt-3 text-sm font-medium">
                                        Connect the code behind this project.
                                    </p>
                                    <p class="mt-1 text-sm text-muted-foreground">
                                        Add a repository or local folder to see commits and scan details here.
                                    </p>
                                    <Button
                                        as-child
                                        variant="outline"
                                        size="sm"
                                        class="mt-4">
                                        <Link :href="'/projects/' + project.id + '/edit'">
                                            Edit project
                                        </Link>
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    <aside
                        class="grid min-w-0 gap-4"
                        aria-label="Project details">
                        <Card
                            v-if="needsAttention"
                            as="section"
                            aria-labelledby="attention-title">
                            <p
                                v-if="pending"
                                role="status"
                                class="px-5 pt-4 text-sm text-muted-foreground">
                                Updating sources…
                            </p>
                            <CardHeader>
                                <h2
                                    id="attention-title"
                                    class="text-sm font-normal">
                                    Needs attention
                                </h2>
                            </CardHeader>
                            <CardContent class="min-h-32 gap-2">
                                <button
                                    v-if="dependencies.issueCount"
                                    type="button"
                                    class="rounded-lg px-2 py-2.5 text-left hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                    @click="tab = 'dependencies'">
                                    <span class="block text-sm font-medium">{{ dependencies.issueCount }} dependency {{ dependencies.issueCount === 1 ? 'issue' : 'issues' }}</span>
                                    <span class="mt-0.5 block text-xs text-muted-foreground">{{ dependencies.security }} with security issues · {{ dependencies.outdated }} outdated</span>
                                    <span class="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground">Open dependencies <ArrowRightIcon
                                        class="size-3"
                                        aria-hidden="true" /></span>
                                </button>
                                <a
                                    v-if="folderIssueCount"
                                    href="#sources-title"
                                    class="block select-none rounded-lg px-2 py-2.5 hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                    @click="sourcesOpen = true">
                                    <span class="block text-sm font-medium">{{ folderIssueCount }} local {{ folderIssueCount === 1 ? 'folder needs' : 'folders need' }} review</span>
                                    <span class="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground">Review sources <ArrowRightIcon
                                        class="size-3"
                                        aria-hidden="true" /></span>
                                </a>
                            </CardContent>
                        </Card>

                        <Card
                            v-if="latestCommit || recentDocuments.length"
                            as="section"
                            aria-labelledby="recent-activity-title">
                            <CardHeader>
                                <h2
                                    id="recent-activity-title"
                                    class="text-sm font-normal">
                                    Recent activity
                                </h2>
                            </CardHeader>
                            <CardContent class="gap-4">
                                <div v-if="latestCommit">
                                    <h3 class="text-xs font-normal text-muted-foreground">
                                        Latest commit
                                    </h3>
                                    <p class="mt-1 break-words text-sm font-medium">
                                        {{ latestCommit.commit_subject ?? 'Commit found' }}
                                    </p>
                                    <p class="mt-1 text-xs leading-5 text-muted-foreground">
                                        {{ latestCommit.branch ?? latestCommit.git_state }} · <time :datetime="latestCommit.last_commit_at ?? undefined">{{ latestCommitAge }}</time>
                                    </p>
                                    <p
                                        class="mt-1 truncate text-xs text-muted-foreground"
                                        :title="latestCommit.path">
                                        {{ latestCommit.path }}
                                    </p>
                                </div>
                                <div
                                    v-if="recentDocuments.length"
                                    class="border-t border-border/70 pt-3 first:border-0 first:pt-0">
                                    <h3 class="text-xs font-normal text-muted-foreground">
                                        Documents
                                    </h3>
                                    <ul class="mt-1 divide-y divide-border/70">
                                        <li
                                            v-for="document in recentDocuments"
                                            :key="document.id">
                                            <Link
                                                :href="`/projects/${project.id}?tab=documents&document=${document.id}`"
                                                class="block rounded-lg px-1 py-2 hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
                                                <span class="block break-words text-sm font-medium">{{ document.title }}</span>
                                                <span class="mt-0.5 block text-xs text-muted-foreground">Updated {{ date(document.updated_at) }}</span>
                                            </Link>
                                        </li>
                                    </ul>
                                </div>
                            </CardContent>
                        </Card>

                        <Card
                            as="section"
                            aria-labelledby="links-title">
                            <CardHeader>
                                <h2
                                    id="links-title"
                                    class="text-sm font-normal">
                                    Links <span class="ml-1 text-xs font-normal text-muted-foreground">{{ project.links.length }} {{ project.links.length === 1 ? 'link' : 'links' }}</span>
                                </h2>
                            </CardHeader>
                            <CardContent class="gap-2">
                                <p
                                    v-if="missingLink"
                                    role="status"
                                    class="text-sm text-muted-foreground">
                                    This link was removed. Choose another link.
                                </p>
                                <div
                                    v-if="project.links.length"
                                    id="project-links"
                                    class="grid gap-2">
                                    <ul class="grid gap-1">
                                        <ContextMenu
                                            v-for="link in visibleLinks"
                                            :key="link.id">
                                            <ContextMenuTrigger as-child>
                                                <li
                                                    :id="'link-' + link.id"
                                                    class="flex min-w-0 items-center gap-3 rounded-lg px-2 py-2.5 transition-colors hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                                    :class="link.id === targetLinkId ? 'ring-2 ring-ring' : ''">
                                                    <LinkIcon :url="link.url" />
                                                    <OpenTargetButton
                                                        :id="link.id"
                                                        :project-id="project.id"
                                                        kind="links"
                                                        :native="native"
                                                        :href="link.url"
                                                        :label="`Open ${link.label}`"
                                                        text
                                                        class="min-w-0 flex-1">
                                                        <span
                                                            class="block truncate text-sm"
                                                            :title="link.label">{{ link.label }}</span>
                                                        <span
                                                            class="block truncate text-xs text-muted-foreground"
                                                            :title="link.url">{{ link.url }}</span>
                                                        <span
                                                            v-if="(linksOpen || targetLinkId) && link.category"
                                                            class="block truncate text-xs text-muted-foreground">{{ link.category }}</span>
                                                    </OpenTargetButton>
                                                    <Dialog v-if="link.category || link.description_html">
                                                        <DialogTrigger as-child>
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="icon-sm"
                                                                :aria-label="`Details for ${link.label}`">
                                                                <InfoIcon aria-hidden="true" />
                                                            </Button>
                                                        </DialogTrigger>
                                                        <DialogContent class="max-h-[85vh] overflow-y-auto">
                                                            <DialogHeader>
                                                                <DialogTitle>{{ link.label }}</DialogTitle><DialogDescription class="break-all">
                                                                    {{ link.url }}
                                                                </DialogDescription>
                                                            </DialogHeader>
                                                            <p
                                                                v-if="link.category"
                                                                class="text-sm text-muted-foreground">
                                                                {{ link.category }}
                                                            </p>
                                                            <MarkdownContent
                                                                v-if="link.description_html"
                                                                :html="link.description_html" />
                                                        </DialogContent>
                                                    </Dialog>
                                                </li>
                                            </ContextMenuTrigger>
                                            <ContextMenuContent>
                                                <ContextMenuItem as-child>
                                                    <a
                                                        :href="link.url"
                                                        target="_blank"
                                                        rel="noopener noreferrer">Open link</a>
                                                </ContextMenuItem>
                                                <ContextMenuItem @select="copyLink(link.url)">
                                                    Copy URL
                                                </ContextMenuItem>
                                            </ContextMenuContent>
                                        </ContextMenu>
                                    </ul>
                                </div>
                                <Button
                                    v-if="project.links.length > 3 && !targetLinkId"
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    class="self-start"
                                    :aria-expanded="linksOpen"
                                    aria-controls="project-links"
                                    @click="linksOpen = !linksOpen">
                                    {{ linksOpen ? 'Show fewer links' : `View all ${project.links.length} links` }}
                                </Button>
                                <Button
                                    v-if="!project.links.length"
                                    as-child
                                    variant="outline"
                                    size="sm"
                                    class="self-start">
                                    <Link :href="`/projects/${project.id}/edit?tab=links`">
                                        Add links
                                    </Link>
                                </Button>
                            </CardContent>
                        </Card>
                    </aside>
                </div>

                <div
                    v-if="project.repositories.length"
                    class="space-y-4">
                    <Button
                        type="button"
                        variant="outline"
                        :aria-expanded="providerActivityOpen"
                        @click="providerActivityOpen = !providerActivityOpen">
                        {{ providerActivityOpen ? 'Hide provider activity' : 'Show provider activity' }}
                        <ChevronDownIcon
                            class="size-4 transition-transform"
                            :class="{ 'rotate-180': providerActivityOpen }"
                            aria-hidden="true" />
                    </Button>
                    <Transition name="t-panel-reveal">
                        <ProjectProviderActivity
                            v-show="providerActivityOpen"
                            ref="providerActivity"
                            :key="project.id"
                            :project-id="project.id"
                            :repositories="activity"
                            :connections="connections"
                            :active="active && tab === 'overview'" />
                    </Transition>
                </div>
            </TabsContent>

            <TabsContent
                v-show="tab === 'documents'"
                value="documents"
                force-mount
                class="pt-4">
                <ProjectDocuments
                    :key="project.id"
                    :project="project"
                    :target-document-id="targetDocumentId" />
            </TabsContent>
            <TabsContent
                value="board"
                class="pt-4">
                <ProjectBoard
                    :key="project.id"
                    :project="project"
                    :target-task-id="targetTaskId" />
            </TabsContent>
            <TabsContent
                value="assets"
                class="pt-4">
                <ProjectAssets
                    :key="project.id"
                    :project="project" />
            </TabsContent>
            <TabsContent
                value="dependencies"
                class="pt-4">
                <ProjectDependencies
                    :key="project.id"
                    :project="project"
                    :folders="inspection"
                    :native="native"
                    :busy="scan.processing"
                    @refresh="refresh"
                    @changed="reloadInspection" />
            </TabsContent>
            <TabsContent
                value="secrets"
                class="pt-4">
                <ProjectSecrets
                    ref="secretsPage"
                    :key="project.id"
                    :project="project"
                    :native="native"
                    :target-secret-id="targetSecretId" />
            </TabsContent>
        </Tabs>
    </div>
</template>
