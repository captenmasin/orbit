<script setup lang="ts">
import ProjectBoard from '@/components/ProjectBoard.vue';
import ProjectHeader from '@/components/ProjectHeader.vue';
import ContentSearch from '@/components/ContentSearch.vue';
import ProjectAssets from '@/components/ProjectAssets.vue';
import ProjectLinkRow from '@/components/ProjectLinkRow.vue';
import ProjectSecrets from '@/components/ProjectSecrets.vue';
import OpenTargetButton from '@/components/OpenTargetButton.vue';
import ProjectDocuments from '@/components/ProjectDocuments.vue';
import CreateProjectLink from '@/components/CreateProjectLink.vue';
import ProjectScratchpad from '@/components/ProjectScratchpad.vue';
import ProjectDependencies from '@/components/ProjectDependencies.vue';
import ProjectImportanceButton from '@/components/ProjectImportanceButton.vue';
import ProjectProviderActivity from '@/components/ProjectProviderActivity.vue';
import { toast } from 'vue-sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { computed, nextTick, ref, watch } from 'vue';
import { linkCategoryIcon } from '@/lib/link-categories';
import { dependencyHealth, folderName } from '@/lib/dependencies';
import { boardColumnColor, boardColumnColors } from '@/lib/project';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Head, Link, router, useHttp, usePage } from '@inertiajs/vue3';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { Project, ProjectFolder, ProviderConnection, Repository } from '@/types';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useDocumentVisibility, useIntervalFn, useLocalStorage, useSessionStorage, useTimeAgo, useWindowFocus } from '@vueuse/core';
import { ArrowRightIcon, ChevronDownIcon, CircleAlertIcon, CircleCheckIcon, FileTextIcon, FolderOpenIcon, GitBranchIcon, InfoIcon, LineSquiggleIcon, PackageIcon, ShieldAlertIcon, Trash2Icon } from '@lucide/vue';

const props = defineProps<{ selectedProject: Project; statuses: string[]; inspection: ProjectFolder[]; activity: Repository[]; connections: ProviderConnection[]; native: boolean }>();
const project = computed(() => props.selectedProject);
const page = usePage();
const scratchpad = ref<{ saving: boolean } | null>(null);
const documentsPage = ref<InstanceType<typeof ProjectDocuments> | null>(null);
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
const taskColumns = computed(() => (project.value.board_columns ?? []).filter(column => column.tasks.length));
const importantDocuments = computed(() => (project.value.documents ?? []).filter(document => document.important));
function groupLinks(links: Project['links']) {
    const groups = new Map<string, Project['links']>();
    for (const link of links) {
        const category = link.category?.trim() ?? '';
        if (!groups.has(category)) groups.set(category, []);
        groups.get(category)!.push(link);
    }
    return [...groups].sort(([a], [b]) => Number(!a) - Number(!b));
}
const importantLinks = computed(() => (project.value.links ?? []).filter(link => link.important));
const overviewLinkGroups = computed(() => groupLinks((project.value.links ?? []).filter(link => !link.important)));
const overviewLinkColumns = computed(() => {
    const groups = overviewLinkGroups.value.map(([category, links], index) => ({ category, links, index }));
    const middle = Math.ceil(groups.length / 2);
    return [groups.slice(0, middle), groups.slice(middle)];
});
const linkGroupOpen = useLocalStorage<Record<string, boolean>>(() => `project:${project.value.id}:link-groups`, {}, { flush: 'sync' });
function isLinkGroupOpen(category: string, index: number): boolean {
    return Object.hasOwn(linkGroupOpen.value, category) ? linkGroupOpen.value[category] : index === 0;
}
function setLinkGroupOpen(category: string, event: Event) {
    linkGroupOpen.value = { ...linkGroupOpen.value, [category]: (event.currentTarget as HTMLDetailsElement).open };
}
const previewDocuments = computed(() => importantDocuments.value.length ? importantDocuments.value : [...(project.value.documents ?? [])].sort((a, b) => b.updated_at.localeCompare(a.updated_at)).slice(0, 3));
const hasSources = computed(() => !!(project.value.repositories?.length || props.inspection.length));
const hasProjectDetails = computed(() => !!(taskColumns.value.length || hasSources.value || previewDocuments.value.length || latestCommit.value));
const showGettingStarted = computed(() => !hasSources.value && !project.value.links.length && !previewDocuments.value.length && !taskColumns.value.length && !project.value.scratchpad?.trim() && !project.value.secrets?.length && !project.value.assets?.length);
async function createDocument() {
    await changeTab('documents');
    if (!documentsPage.value?.dirty) documentsPage.value?.edit();
}
function focusScratchpad() {
    document.getElementById('project-scratchpad')?.focus();
}
function reloadProject() {
    router.reload({ only: ['selectedProject', 'inspection', 'activity'], onSuccess: () => {
        statusError.value = '';
        statusNeedsReload.value = false;
        sourceError.value = '';
        sourceNeedsReload.value = false;
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
const sourcesOpen = ref(false);
const removingSource = ref<{ kind: 'folders' | 'repositories'; id: string; label: string } | null>(null);
const sourceRemoving = ref(false);
const sourceError = ref('');
const sourceNeedsReload = ref(false);
watch(removingSource, () => { sourceError.value = ''; sourceNeedsReload.value = false; });
function removeSource() {
    if (!removingSource.value || sourceRemoving.value || sourceNeedsReload.value || statusSaving.value || scratchpad.value?.saving) return;
    const { kind, id } = removingSource.value;
    if (!project.value[kind].some(source => source.id === id)) { removingSource.value = null; return; }
    sourceRemoving.value = true;
    sourceError.value = '';
    router.put(`/projects/${project.value.id}`, {
        name: project.value.name,
        description: project.value.description,
        status: project.value.status,
        revision: project.value.revision,
        [kind]: kind === 'folders'
            ? project.value.folders.filter(folder => folder.id !== id).map(({ id, path, repository_id }) => ({ id, path, repository_id }))
            : project.value.repositories.filter(repo => repo.id !== id).map(({ id, name, remote_url }) => ({ id, name, remote_url })),
    }, {
        preserveScroll: true,
        errorBag: 'sources',
        onSuccess: () => { removingSource.value = null; },
        onError: errors => {
            sourceError.value = String(Object.values(errors)[0] ?? 'Could not remove source.');
            sourceNeedsReload.value = !!errors.revision;
        },
        onNetworkError: () => { sourceError.value = 'Could not remove source. Try again.'; },
        onFinish: () => { sourceRemoving.value = false; },
    });
}
function closeProjectSearch() {
    document.querySelector<HTMLElement>('[aria-label="Project sections"] [role="tab"][aria-selected="true"]')?.focus();
}
const tab = useSessionStorage(() => `project:${project.value.id}:tab`, 'overview', { flush: 'sync' });
async function changeTab(value: string | number) {
    if (tab.value === 'secrets' && secretsPage.value && !await secretsPage.value.flush()) return;
    tab.value = String(value);
}
watch(tab, value => { if (!['overview', 'sources', 'documents', 'board', 'assets', 'dependencies', 'secrets'].includes(value)) tab.value = 'overview'; }, { immediate: true });
const query = computed(() => new URL(page.url ?? '/', 'https://orbit.local').searchParams);
const targetDocumentId = computed(() => query.value.get('document'));
const targetTaskId = computed(() => query.value.get('task'));
const targetSecretId = computed(() => query.value.get('secret'));
const targetLinkId = computed(() => query.value.get('link'));
const missingLink = computed(() => !!targetLinkId.value && !(project.value.links ?? []).some(link => link.id === targetLinkId.value));
const visibleRepositories = computed(() => sourcesOpen.value ? project.value.repositories : project.value.repositories.slice(0, 2));
const visibleFolders = computed(() => sourcesOpen.value ? props.inspection : props.inspection.slice(0, 2));
watch(() => project.value.id, () => {
    sourcesOpen.value = false;
    removingSource.value = null;
    statusError.value = '';
    statusNeedsReload.value = false;
});
watch(query, value => {
    const requested = value.get('tab');
    if (requested && ['overview', 'sources', 'documents', 'board', 'assets', 'dependencies', 'secrets'].includes(requested)) tab.value = requested;
}, { immediate: true });
function focusTargetLink() {
    const target = typeof document === 'undefined' ? null : document.getElementById(`link-${targetLinkId.value}`);
    if (!target) return;
    target.querySelector<HTMLElement>('a, button')?.focus({ preventScroll: true });
    target.scrollIntoView({ block: 'center' });
}
watch([targetLinkId, tab, () => project.value.links], async () => {
    const link = (project.value.links ?? []).find(link => link.id === targetLinkId.value);
    if (!link || tab.value !== 'overview') return;
    if (!link.important) linkGroupOpen.value = { ...linkGroupOpen.value, [link.category?.trim() ?? '']: true };
    await nextTick();
    focusTargetLink();
}, { immediate: true });
const scan = useHttp({ kind: 'all' as 'all' | 'folder' | 'root', id: null as string | null, only_stale: false });
let automaticScanErrorProject: string | null = null;
const visible = useDocumentVisibility();
const focused = useWindowFocus();
const active = computed(() => visible.value === 'visible' && focused.value);
const pending = computed(() => props.inspection.some(folder => [folder, ...(folder.package_roots ?? [])].some(target => ['Queued', 'Scanning'].includes(target.scan_state ?? ''))));
function localCommitUrl(folder: ProjectFolder): string | undefined {
    if (!folder.git_remote || !folder.last_commit_hash) return;
    try {
        const url = new URL(folder.git_remote.replace(/^[\w.-]+@([^:]+):/, 'https://$1/').replace(/^ssh:\/\/(?:[^@/]+@)?/, 'https://'));
        if (!['github.com', 'gitlab.com'].includes(url.host)) return;
        return `https://${url.host}${url.pathname.replace(/\/+$/, '').replace(/\.git$/, '')}/${url.host === 'gitlab.com' ? '-/commit' : 'commit'}/${folder.last_commit_hash}`;
    } catch { return; }
}
const latestCommit = computed(() => [
    ...props.inspection.filter(folder => folder.last_commit_at).map(folder => ({ ...folder, source: folderName(folder), url: localCommitUrl(folder) })),
    ...props.activity.filter(repo => repo.remote_commit_at).map(repo => ({
        last_commit_at: repo.remote_commit_at,
        branch: repo.default_branch,
        git_state: 'Remote default branch',
        source: repo.name,
        path: `${repo.provider_name} · remote · ${repo.provider_snapshots?.find(snapshot => snapshot.resource === 'overview')?.state ?? 'Not scanned'}`,
        commit_subject: repo.provider_snapshots?.find(snapshot => snapshot.resource === 'overview')?.payload?.commit?.title,
        url: repo.provider_snapshots?.find(snapshot => snapshot.resource === 'overview')?.payload?.commit?.url,
    })),
].sort((a, b) => b.last_commit_at!.localeCompare(a.last_commit_at!))[0]);
const latestCommitAge = useTimeAgo(() => latestCommit.value?.last_commit_at ?? Date.now());
const commitOpen = useHttp({ url: '' });
async function openCommit(event: Event) {
    if (!props.native) return;
    event.preventDefault();
    if (commitOpen.processing || !latestCommit.value?.url) return;
    commitOpen.url = latestCommit.value.url;
    try {
        if (!await commitOpen.post(`/projects/${project.value.id}/open-url`)) throw new Error('Commit could not be opened');
    } catch { toast.error('The commit could not be opened. Try again.'); }
}
const dependencies = computed(() => dependencyHealth(props.inspection));
const folderIssueCount = computed(() => props.inspection.filter(folder => (folder.availability && folder.availability !== 'Available') || folder.scan_error).length);
const needsAttention = computed(() => !!(dependencies.value.issueCount || folderIssueCount.value));
// const checksMessage = computed(() => {
//     if (!props.inspection.length) return 'Connect a local folder to check dependencies.';
//     if (pending.value) return 'Checking local folders…';
//     if (!dependencies.value.total) return 'Dependency checks aren’t set up. Add a package root in Dependencies.';
//     if (!dependencies.value.complete) return 'Dependency checks are incomplete. Review the results for details.';
//     return 'No security issues or outdated packages found.';
// });
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
                        value="sources"
                        class="h-8 flex-none rounded-none px-3 text-[13px]">
                        Sources
                    </TabsTrigger>
                    <TabsTrigger
                        value="documents"
                        class="h-8 flex-none rounded-none px-3 text-[13px]">
                        Documents
                    </TabsTrigger>
                    <TabsTrigger
                        value="board"
                        class="h-8 flex-none rounded-none px-3 text-[13px]">
                        Tasks
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
                <div class="grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1.65fr)_minmax(19rem,1fr)]">
                    <div
                        class="min-w-0 space-y-5"
                        :class="{ 'xl:contents xl:space-y-0': !hasProjectDetails }">
                        <section
                            v-if="showGettingStarted"
                            aria-labelledby="getting-started-title"
                            class="space-y-3 xl:col-start-1">
                            <h2
                                id="getting-started-title"
                                class="text-base font-medium">
                                Start with a source, document, or note
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                Keep your code, reference material, and ideas together here.
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <Button
                                    as-child
                                    variant="outline"
                                    size="sm">
                                    <Link :href="`/projects/${project.id}/edit?tab=repositories`">
                                        <FolderOpenIcon aria-hidden="true" />Connect a source
                                    </Link>
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    @click="createDocument">
                                    <FileTextIcon aria-hidden="true" />Add a document
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    @click="focusScratchpad">
                                    <LineSquiggleIcon aria-hidden="true" />Write a note
                                </Button>
                            </div>
                        </section>
                        <Card
                            v-if="needsAttention"
                            as="section"
                            size="sm"
                            aria-labelledby="attention-title">
                            <CardHeader>
                                <h2
                                    id="attention-title"
                                    class="flex items-center gap-2 text-sm font-normal">
                                    <CircleAlertIcon
                                        v-if="needsAttention"
                                        class="size-4"
                                        :class="dependencies.security ? 'text-destructive' : 'text-amber-600 dark:text-amber-400'"
                                        aria-hidden="true" />
                                    <CircleCheckIcon
                                        v-else-if="dependencies.complete && !pending"
                                        class="size-4 text-emerald-600 dark:text-emerald-400"
                                        aria-hidden="true" />
                                    <InfoIcon
                                        v-else
                                        class="size-4 text-muted-foreground"
                                        aria-hidden="true" />
                                    {{ needsAttention ? 'Needs attention' : 'Project checks' }}
                                </h2>
                            </CardHeader>
                            <CardContent class="gap-3">
                                <div class="divide-y divide-border/70">
                                    <div
                                        v-if="dependencies.security"
                                        class="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0">
                                        <ShieldAlertIcon
                                            class="size-4 shrink-0 text-destructive"
                                            aria-hidden="true" />
                                        <h3 class="min-w-0 flex-1 text-sm font-medium text-destructive">
                                            {{ dependencies.security }} {{ dependencies.security === 1 ? 'package' : 'packages' }} with security issues
                                        </h3>
                                        <Button
                                            type="button"
                                            variant="link"
                                            size="xs"
                                            aria-label="Review security issues"
                                            @click="changeTab('dependencies')">
                                            Review security <ArrowRightIcon aria-hidden="true" />
                                        </Button>
                                    </div>
                                    <div
                                        v-if="dependencies.outdated"
                                        class="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0">
                                        <PackageIcon
                                            class="size-4 shrink-0 text-amber-600 dark:text-amber-400"
                                            aria-hidden="true" />
                                        <h3 class="min-w-0 flex-1 text-sm font-medium">
                                            {{ dependencies.outdated }} outdated {{ dependencies.outdated === 1 ? 'package' : 'packages' }}
                                        </h3>
                                        <Button
                                            type="button"
                                            variant="link"
                                            size="xs"
                                            aria-label="Review outdated packages"
                                            @click="changeTab('dependencies')">
                                            Review updates <ArrowRightIcon aria-hidden="true" />
                                        </Button>
                                    </div>
                                    <div
                                        v-if="folderIssueCount"
                                        class="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0">
                                        <FolderOpenIcon
                                            class="size-4 shrink-0 text-amber-600 dark:text-amber-400"
                                            aria-hidden="true" />
                                        <div class="min-w-0 flex-1">
                                            <h3 class="text-sm font-medium">
                                                {{ folderIssueCount }} local {{ folderIssueCount === 1 ? 'folder needs' : 'folders need' }} review
                                            </h3>
                                            <p class="mt-0.5 text-xs text-muted-foreground">
                                                Check folder access and scan errors.
                                            </p>
                                        </div>
                                        <Button
                                            type="button"
                                            variant="link"
                                            size="xs"
                                            aria-label="Review local folders"
                                            @click="sourcesOpen = true; changeTab('sources')">
                                            Review folders <ArrowRightIcon aria-hidden="true" />
                                        </Button>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <Card
                            as="section"
                            size="sm"
                            class="xl:col-start-1"
                            aria-labelledby="links-title">
                            <CardHeader class="flex flex-row items-center justify-between gap-2">
                                <h2
                                    id="links-title"
                                    class="text-sm font-normal">
                                    Links <span class="ml-1 text-xs text-muted-foreground">{{ project.links.length }}</span>
                                </h2>
                                <CreateProjectLink
                                    :key="project.id"
                                    :project="project"
                                    :disabled="!!scratchpad?.saving" />
                            </CardHeader>
                            <CardContent class="@container">
                                <p
                                    v-if="!project.links.length"
                                    class="text-sm text-muted-foreground">
                                    Add a link to keep a useful URL with this project.
                                </p>
                                <section
                                    v-if="importantLinks.length"
                                    aria-labelledby="favourite-links-title"
                                    class="mb-3">
                                    <h3
                                        id="favourite-links-title"
                                        class="mb-1 px-2 text-sm text-muted-foreground">
                                        Favourites
                                    </h3>
                                    <ul class="grid grid-cols-1 gap-x-6 gap-y-0.5 @sm:grid-cols-2">
                                        <ProjectLinkRow
                                            v-for="link in importantLinks"
                                            :key="link.id"
                                            :project="project"
                                            :link="link"
                                            :native="native"
                                            :highlighted="link.id === targetLinkId"
                                            :disabled="!!scratchpad?.saving" />
                                    </ul>
                                </section>
                                <div
                                    v-if="overviewLinkGroups.length"
                                    class="grid grid-cols-1 items-start gap-x-6 @sm:grid-cols-2">
                                    <div
                                        v-for="(groups, columnIndex) in overviewLinkColumns"
                                        :key="columnIndex"
                                        class="min-w-0">
                                        <details
                                            v-for="{ category, links, index } in groups"
                                            :key="`${project.id}:${category}`"
                                            :open="isLinkGroupOpen(category, index)"
                                            :aria-labelledby="`overview-link-group-${index}`"
                                            class="group/link mb-1"
                                            @toggle="setLinkGroupOpen(category, $event)">
                                            <summary
                                                :id="`overview-link-group-${index}`"
                                                class="flex h-9 cursor-pointer list-none items-center gap-2 rounded-lg px-2 text-sm text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring [&::-webkit-details-marker]:hidden">
                                                <ChevronDownIcon
                                                    class="size-3.5 shrink-0 -rotate-90 group-open/link:rotate-0"
                                                    aria-hidden="true" />
                                                <component
                                                    :is="linkCategoryIcon(category)"
                                                    v-if="linkCategoryIcon(category)"
                                                    class="size-4 shrink-0"
                                                    aria-hidden="true" />
                                                <span class="min-w-0 flex-1 truncate">{{ category || 'Other links' }}</span>
                                                <span class="text-xs tabular-nums">{{ links.length }}</span>
                                            </summary>
                                            <ul class="grid gap-0.5 pb-2">
                                                <ProjectLinkRow
                                                    v-for="link in links"
                                                    :key="link.id"
                                                    :project="project"
                                                    :link="link"
                                                    :native="native"
                                                    :highlighted="link.id === targetLinkId"
                                                    :disabled="!!scratchpad?.saving" />
                                            </ul>
                                        </details>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <ProjectScratchpad
                            ref="scratchpad"
                            :key="project.id"
                            :class="{ 'xl:col-start-2 xl:row-start-1': !hasProjectDetails }"
                            :project="project" />

                        <p
                            v-if="missingLink"
                            role="status"
                            class="text-sm text-muted-foreground xl:col-start-1">
                            This link was removed.
                        </p>
                    </div>

                    <aside
                        v-if="hasProjectDetails"
                        class="grid min-w-0 content-start gap-4"
                        aria-label="Project details">
                        <Card
                            v-if="previewDocuments.length"
                            as="section"
                            size="sm"
                            aria-labelledby="documents-preview-title">
                            <CardHeader class="flex flex-row items-center justify-between gap-2">
                                <h2
                                    id="documents-preview-title"
                                    class="text-sm font-normal">
                                    Documents
                                </h2>
                                <Button
                                    type="button"
                                    variant="link"
                                    size="xs"
                                    @click="changeTab('documents')">
                                    View documents <ArrowRightIcon aria-hidden="true" />
                                </Button>
                            </CardHeader>
                            <CardContent>
                                <ul class="divide-y divide-border/70">
                                    <li
                                        v-for="document in previewDocuments"
                                        :key="document.id"
                                        class="flex min-w-0 items-center gap-3 py-2 first:pt-0 last:pb-0">
                                        <FileTextIcon
                                            class="size-4 shrink-0 text-muted-foreground"
                                            aria-hidden="true" />
                                        <Link
                                            :href="`/projects/${project.id}?tab=documents&document=${document.id}`"
                                            class="group min-w-0 flex-1 rounded-sm py-1 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
                                            <span class="block truncate underline-offset-4 group-hover:underline">{{ document.title }}</span>
                                            <span class="block truncate text-xs text-muted-foreground">Updated {{ date(document.updated_at) }}</span>
                                        </Link>
                                        <ProjectImportanceButton
                                            :project="project"
                                            kind="documents"
                                            :item="document"
                                            :label="document.title"
                                            :disabled="!!scratchpad?.saving" />
                                    </li>
                                </ul>
                            </CardContent>
                        </Card>

                        <Card
                            v-if="taskColumns.length"
                            as="section"
                            size="sm"
                            aria-labelledby="tasks-summary-title">
                            <CardHeader class="flex flex-row flex-wrap items-center justify-between gap-3">
                                <h2
                                    id="tasks-summary-title"
                                    class="text-sm font-medium">
                                    Tasks
                                </h2>
                                <Button
                                    type="button"
                                    variant="link"
                                    size="xs"
                                    @click="changeTab('board')">
                                    View tasks <ArrowRightIcon aria-hidden="true" />
                                </Button>
                            </CardHeader>
                            <CardContent>
                                <ul class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
                                    <li
                                        v-for="column in taskColumns"
                                        :key="column.id"
                                        class="flex items-center gap-2">
                                        <span
                                            class="size-2 shrink-0 rounded-full"
                                            :class="boardColumnColors[boardColumnColor(column)]?.dotClass ?? boardColumnColors.gray.dotClass"
                                            aria-hidden="true" />
                                        {{ column.tasks.length }} {{ column.name }}
                                    </li>
                                </ul>
                            </CardContent>
                        </Card>

                        <Card
                            v-if="project.repositories.length || inspection.length"
                            as="section"
                            size="sm"
                            aria-labelledby="sources-preview-title">
                            <CardHeader class="flex flex-row items-center justify-between gap-2">
                                <h2
                                    id="sources-preview-title"
                                    class="text-sm font-normal">
                                    Sources
                                </h2>
                                <Button
                                    type="button"
                                    variant="link"
                                    size="xs"
                                    @click="sourcesOpen = true; changeTab('sources')">
                                    View sources <ArrowRightIcon aria-hidden="true" />
                                </Button>
                            </CardHeader>
                            <CardContent>
                                <ul class="divide-y divide-border/70">
                                    <li
                                        v-for="repo in project.repositories.slice(0, 2)"
                                        :key="repo.id"
                                        class="flex min-w-0 items-center gap-3 py-2 first:pt-0 last:pb-0">
                                        <GitBranchIcon
                                            class="size-4 shrink-0 text-muted-foreground"
                                            aria-hidden="true" />
                                        <div class="min-w-0 flex-1">
                                            <p
                                                class="truncate text-sm font-medium"
                                                :title="repo.name">
                                                {{ repo.name }}
                                            </p>
                                            <p
                                                class="truncate text-xs text-muted-foreground"
                                                :title="repo.remote_url">
                                                {{ repo.remote_url }}
                                            </p>
                                        </div>
                                        <OpenTargetButton
                                            :id="repo.id"
                                            :project-id="project.id"
                                            kind="repositories"
                                            :native="native"
                                            :href="repo.web_url"
                                            :label="`Open ${repo.name}`"
                                            compact />
                                    </li>
                                    <li
                                        v-for="folder in inspection.slice(0, 2)"
                                        :key="folder.id"
                                        class="flex min-w-0 items-center gap-3 py-2 first:pt-0 last:pb-0">
                                        <FolderOpenIcon
                                            class="size-4 shrink-0 text-muted-foreground"
                                            aria-hidden="true" />
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-medium">
                                                {{ folderName(folder) }}
                                            </p>
                                            <p
                                                class="truncate text-xs text-muted-foreground"
                                                :title="folder.path">
                                                {{ folder.path }}
                                            </p>
                                        </div>
                                        <OpenTargetButton
                                            v-if="native"
                                            :id="folder.id"
                                            :project-id="project.id"
                                            kind="folders"
                                            :native="native"
                                            :label="`Open ${folderName(folder)} folder`"
                                            compact />
                                    </li>
                                </ul>
                            </CardContent>
                        </Card>

                        <Card
                            v-if="latestCommit"
                            as="section"
                            size="sm"
                            aria-label="Latest commit">
                            <CardHeader>
                                Latest update
                            </CardHeader>
                            <CardContent class="gap-1">
                                <p class="break-words text-[13px] leading-5 text-muted-foreground">
                                    <a
                                        v-if="latestCommit.url"
                                        :href="latestCommit.url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="underline underline-offset-4 hover:text-foreground"
                                        @click="openCommit">{{ latestCommit.commit_subject ?? 'Commit found' }}</a>
                                    <template v-else>
                                        {{ latestCommit.commit_subject ?? 'Commit found' }}
                                    </template>
                                </p>
                                <p class="text-xs leading-5 text-muted-foreground">
                                    {{ latestCommit.branch ?? latestCommit.git_state }} · <time :datetime="latestCommit.last_commit_at ?? undefined">{{ latestCommitAge }}</time>
                                </p>
                                <p
                                    class="truncate text-sm font-medium"
                                    :title="latestCommit.source">
                                    {{ latestCommit.source }}
                                </p>
                                <!--                                <p-->
                                <!--                                    class="truncate text-xs text-muted-foreground"-->
                                <!--                                    :title="latestCommit.path">-->
                                <!--                                    {{ latestCommit.path }}-->
                                <!--                                </p>-->
                            </CardContent>
                        </Card>
                    </aside>
                </div>
            </TabsContent>

            <TabsContent
                value="sources"
                class="space-y-5 pt-5">
                <Card
                    as="section"
                    aria-labelledby="sources-title">
                    <CardHeader class="flex flex-wrap items-center justify-between gap-3">
                        <h2
                            id="sources-title"
                            class="text-sm font-normal">
                            Sources
                        </h2>
                        <!--                                <Button-->
                        <!--                                    v-if="inspection.length"-->
                        <!--                                    variant="outline"-->
                        <!--                                    size="sm"-->
                        <!--                                    :disabled="scan.processing || pending"-->
                        <!--                                    @click="refresh()">-->
                        <!--                                    Refresh local folders-->
                        <!--                                </Button>-->
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
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        :aria-label="`Remove repository ${repo.name}`"
                                        :disabled="sourceRemoving || statusSaving || !!scratchpad?.saving"
                                        @click="removingSource = { kind: 'repositories', id: repo.id, label: repo.name }">
                                        <Trash2Icon aria-hidden="true" />Remove
                                    </Button>
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
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <OpenTargetButton
                                                        :id="folder.id"
                                                        :project-id="project.id"
                                                        kind="folders"
                                                        :native="native"
                                                        label="Open folder" />
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        :aria-label="`Remove folder ${folder.path}`"
                                                        :disabled="sourceRemoving || statusSaving || !!scratchpad?.saving"
                                                        @click="removingSource = { kind: 'folders', id: folder.id, label: folder.path }">
                                                        <Trash2Icon aria-hidden="true" />Remove
                                                    </Button>
                                                </div>
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

                <Dialog
                    :open="!!removingSource"
                    @update:open="value => { if (!value && !sourceRemoving) removingSource = null; }">
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Remove {{ removingSource?.kind === 'folders' ? 'folder' : 'repository' }}?</DialogTitle>
                            <DialogDescription>
                                This removes the source from this project. {{ removingSource?.kind === 'folders' ? 'Files on disk are kept.' : 'Local folders and the remote repository are kept.' }}
                            </DialogDescription>
                        </DialogHeader>
                        <p class="break-all text-sm font-medium">
                            {{ removingSource?.label }}
                        </p>
                        <p
                            v-if="sourceError"
                            role="alert"
                            class="text-sm text-destructive">
                            {{ sourceError }}
                        </p>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="sourceRemoving"
                                @click="removingSource = null">
                                Cancel
                            </Button>
                            <Button
                                v-if="sourceNeedsReload"
                                type="button"
                                variant="outline"
                                @click="reloadProject">
                                Reload sources
                            </Button>
                            <Button
                                type="button"
                                variant="destructive"
                                :disabled="sourceRemoving || sourceNeedsReload || statusSaving || !!scratchpad?.saving"
                                @click="removeSource">
                                {{ sourceRemoving ? 'Removing…' : 'Remove source' }}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

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
                            :active="active && tab === 'sources'" />
                    </Transition>
                </div>
            </TabsContent>

            <TabsContent
                v-show="tab === 'documents'"
                value="documents"
                force-mount
                class="pt-4">
                <ProjectDocuments
                    ref="documentsPage"
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
