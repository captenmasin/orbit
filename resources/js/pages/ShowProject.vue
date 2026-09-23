<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { Head, Link, router, useHttp, usePage } from '@inertiajs/vue3';
import { useDocumentVisibility, useIntervalFn, useSessionStorage, useWindowFocus } from '@vueuse/core';
import { PencilIcon, RefreshCwIcon } from '@lucide/vue';
import ProjectIcon from '@/components/ProjectIcon.vue';
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
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { Project, ProjectFolder, ProviderConnection, Repository } from '@/types';
import { ContextMenuContent, ContextMenuItem, ContextMenuPortal, ContextMenuRoot, ContextMenuTrigger } from 'reka-ui';

const props = defineProps<{ selectedProject: Project; inspection: ProjectFolder[]; activity: Repository[]; connections: ProviderConnection[]; native: boolean }>();
const project = computed(() => props.selectedProject);
const tab = useSessionStorage(() => `project:${project.value.id}:tab`, 'overview', { flush: 'sync' });
watch(tab, value => { if (!['overview', 'documents', 'board', 'assets', 'dependencies', 'secrets'].includes(value)) tab.value = 'overview'; }, { immediate: true });
const page = usePage();
const query = computed(() => new URL(page.url ?? '/', 'http://orbit.local').searchParams);
const targetDocumentId = computed(() => query.value.get('document'));
const targetTaskId = computed(() => query.value.get('task'));
const targetSecretId = computed(() => query.value.get('secret'));
const targetLinkId = computed(() => query.value.get('link'));
const missingLink = computed(() => !!targetLinkId.value && !(project.value.links ?? []).some(link => link.id === targetLinkId.value));
const linkNotice = ref('');
async function copyLink(url: string) {
    try { await navigator.clipboard.writeText(url); linkNotice.value = 'URL copied.'; }
    catch { linkNotice.value = 'Could not copy URL.'; }
}
const linkGroups = computed(() => {
    const groups = new Map<string, Project['links']>();
    for (const link of project.value.links ?? []) {
        const category = link.category || 'Uncategorized';
        if (!groups.has(category)) groups.set(category, []);
        groups.get(category)!.push(link);
    }
    return Array.from(groups, ([category, links]) => ({ category, links }));
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
const scanError = ref('');
const visible = useDocumentVisibility();
const focused = useWindowFocus();
const active = computed(() => visible.value === 'visible' && focused.value);
const pending = computed(() => props.inspection.some(folder => [folder, ...(folder.package_roots ?? [])].some(target => ['Queued', 'Scanning'].includes(target.scan_state ?? ''))));
const latestCommit = computed(() => [...props.inspection, ...props.activity.filter(repo => repo.remote_commit_at).map(repo => ({ last_commit_at: repo.remote_commit_at, branch: repo.default_branch, git_state: 'Remote default branch', path: `${repo.provider_name} · remote · ${repo.provider_snapshots?.find(snapshot => snapshot.resource === 'overview')?.state ?? 'Not scanned'}`, commit_subject: repo.provider_snapshots?.find(snapshot => snapshot.resource === 'overview')?.payload?.commit?.title }))].filter(folder => folder.last_commit_at).sort((a, b) => b.last_commit_at!.localeCompare(a.last_commit_at!))[0]);
const updateCount = computed(() => props.inspection.flatMap(folder => folder.package_roots ?? []).filter(root => ['Current', 'Partial'].includes(root.scan_state) && root.outdated?.fingerprint === root.snapshot?.fingerprint).reduce((count, root) => count + (root.outdated?.packages.length ?? 0), 0));
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
    Object.assign(scan, { kind, id, only_stale: onlyStale });
    scanError.value = '';
    try {
        await scan.post(`/projects/${project.value.id}/inspection`);
        reloadInspection();
    } catch { scanError.value = 'Inspection could not be queued. Try refreshing again.'; }
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
    <Head :title="project.name" />
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex min-w-0 items-start gap-4">
            <ProjectIcon :name="project.name" :type="project.icon_type" :emoji="project.icon_emoji" :image="project.icon_url" size="lg" />
            <div class="min-w-0 space-y-2">
                <div class="flex flex-wrap items-center gap-3"><h1 class="break-all text-2xl font-semibold tracking-tight">{{ project.name }}</h1><Badge variant="secondary">{{ project.status }}</Badge></div>
                <p v-if="project.description" class="max-w-3xl whitespace-pre-line break-words text-sm text-muted-foreground">{{ project.description }}</p>
                <div v-if="project.tags.length" class="flex flex-wrap gap-1"><Badge v-for="tag in project.tags" :key="tag.id" variant="outline">{{ tag.name }}</Badge></div>
                <p v-if="project.archived_at" class="text-xs text-muted-foreground">Archived {{ date(project.archived_at) }}</p>
            </div>
        </div>
        <div class="flex flex-wrap gap-2"><Button as-child variant="outline"><Link :href="`/projects/${project.id}/edit`"><PencilIcon aria-hidden="true" />Edit project</Link></Button></div>
    </div>
    <ContentSearch :project-id="project.id" />
    <Alert v-if="updateCount"><AlertDescription class="flex flex-wrap items-center justify-between gap-2"><span>{{ updateCount }} {{ updateCount === 1 ? 'dependency is' : 'dependencies are' }} out of date.</span><Button size="sm" variant="outline" @click="tab = 'dependencies'">Dependencies</Button></AlertDescription></Alert>
    <Tabs v-model="tab">
        <TabsList aria-label="Project sections" class="max-w-full overflow-x-auto">
            <TabsTrigger value="overview">Overview</TabsTrigger>
            <TabsTrigger value="documents">Documents</TabsTrigger>
            <TabsTrigger value="board">Board</TabsTrigger>
            <TabsTrigger value="assets">Assets</TabsTrigger>
            <TabsTrigger value="dependencies">Dependencies</TabsTrigger>
            <TabsTrigger value="secrets">Secrets</TabsTrigger>
        </TabsList>
        <Alert v-if="scanError" variant="destructive"><AlertDescription>{{ scanError }}</AlertDescription></Alert>
        <TabsContent value="overview" class="space-y-6 pt-4">
            <section v-if="latestCommit" class="space-y-1 rounded-lg border bg-muted/30 p-4" aria-label="Latest known commit">
                <h2 class="text-sm font-medium">Latest known commit</h2>
                <p class="break-words text-sm">{{ latestCommit.commit_subject }}</p>
                <p class="break-all text-xs text-muted-foreground">{{ date(latestCommit.last_commit_at) }} · {{ latestCommit.branch ?? latestCommit.git_state }} · {{ latestCommit.path }}</p>
            </section>
            <section class="space-y-3 rounded-lg border p-5" aria-labelledby="links-title">
                <h2 id="links-title" class="text-lg font-semibold">Links</h2>
                <p v-if="linkNotice" role="status" class="text-sm text-muted-foreground">{{ linkNotice }}</p>
                <p v-if="missingLink" role="status" class="text-sm text-muted-foreground">This link was removed. Choose another link.</p>
                <section v-for="group in linkGroups" :key="group.category" class="space-y-1" :aria-label="group.category">
                    <h3 class="text-sm font-medium">{{ group.category }}</h3>
                    <ul class="divide-y">
                        <ContextMenuRoot v-for="link in group.links" :key="link.id">
                        <ContextMenuTrigger as-child><li :id="`link-${link.id}`" class="flex flex-wrap items-center gap-3 rounded-md py-3" :class="{ 'bg-muted px-3': link.id === targetLinkId }">
                            <LinkIcon :url="link.url" />
                            <div class="min-w-0 flex-1"><p class="break-words text-sm font-medium">{{ link.label }}</p><p class="break-all text-xs text-muted-foreground">{{ link.url }}</p><MarkdownContent v-if="link.description_html" class="mt-2" :html="link.description_html" /></div>
                            <OpenTargetButton :project-id="project.id" kind="links" :id="link.id" :native="native" :href="link.url" :label="`Open ${link.label}`" />
                        </li></ContextMenuTrigger>
                        <ContextMenuPortal><ContextMenuContent class="z-50 min-w-40 rounded-md border bg-popover p-1 text-sm text-popover-foreground shadow-md"><ContextMenuItem as-child><a :href="link.url" target="_blank" rel="noopener noreferrer" class="block cursor-default rounded px-2 py-1.5 outline-none focus:bg-accent">Open link</a></ContextMenuItem><ContextMenuItem class="cursor-default rounded px-2 py-1.5 outline-none focus:bg-accent" @select="copyLink(link.url)">Copy URL</ContextMenuItem></ContextMenuContent></ContextMenuPortal>
                        </ContextMenuRoot>
                    </ul>
                </section>
                <p v-if="!project.links.length" class="text-sm text-muted-foreground">No links added.</p>
            </section>
            <section class="space-y-4 rounded-lg border p-5" aria-labelledby="repositories-title">
                <h2 id="repositories-title" class="text-lg font-semibold">Repositories</h2>
                <div v-for="repo in project.repositories" :key="repo.id" class="flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0"><h3 class="break-words text-sm font-medium">{{ repo.name }}</h3><p class="break-all text-xs text-muted-foreground">{{ repo.remote_url }}</p></div>
                    <OpenTargetButton :project-id="project.id" kind="repositories" :id="repo.id" :native="native" :href="repo.web_url" label="Open repository" />
                </div>
                <p v-if="!project.repositories.length" class="text-sm text-muted-foreground">No repositories linked.</p>
                <ProjectProviderActivity v-if="project.repositories.length" :key="project.id" :project-id="project.id" :repositories="activity" :connections="connections" :active="active && tab === 'overview'" />
            </section>
            <section class="space-y-4 rounded-lg border p-5" aria-labelledby="folders-title">
                <div class="flex flex-wrap items-center justify-between gap-3"><h2 id="folders-title" class="text-lg font-semibold">Folders</h2><Button variant="outline" size="sm" :disabled="scan.processing || !inspection.length" @click="refresh()"><RefreshCwIcon aria-hidden="true" />Refresh</Button></div>
                <div v-for="folder in inspection" :key="folder.id" class="space-y-2 border-b pb-4 last:border-0 last:pb-0">
                    <div class="flex flex-wrap items-center justify-between gap-3"><h3 class="break-all text-sm font-medium">{{ folder.path }}</h3><OpenTargetButton :project-id="project.id" kind="folders" :id="folder.id" :native="native" label="Open folder" /></div>
                    <div class="flex flex-wrap gap-2"><Badge :variant="folder.availability === 'Available' ? 'secondary' : 'destructive'">{{ folder.availability }}</Badge><Badge variant="outline">{{ folder.git_state }}</Badge><Badge v-if="folder.branch" variant="outline">{{ folder.branch }}</Badge><Badge v-if="folder.scan_state" variant="outline">{{ folder.scan_state }}</Badge></div>
                    <p v-if="folder.repository_id" class="text-xs text-muted-foreground">Repository: {{ project.repositories.find(repo => repo.id === folder.repository_id)?.name }}</p>
                    <p v-if="folder.commit_subject" class="break-words text-sm">{{ folder.commit_subject }}</p>
                    <p v-if="folder.last_commit_at" class="break-all text-xs text-muted-foreground">{{ date(folder.last_commit_at) }} · {{ folder.last_commit_hash }}</p>
                    <p class="text-xs text-muted-foreground">Last scanned: {{ date(folder.scanned_at) }}</p>
                    <p v-if="folder.scan_error" class="text-sm text-destructive">{{ folder.scan_error }}. Previous results retained.</p>
                </div>
                <p v-if="!inspection.length" class="text-sm text-muted-foreground">No folders linked.</p>
            </section>
        </TabsContent>
        <TabsContent value="documents" class="pt-4"><ProjectDocuments :key="project.id" :project="project" :target-document-id="targetDocumentId" /></TabsContent>
        <TabsContent value="board" class="pt-4"><ProjectBoard :key="project.id" :project="project" :target-task-id="targetTaskId" /></TabsContent>
        <TabsContent value="assets" class="pt-4"><ProjectAssets :key="project.id" :project="project" /></TabsContent>
        <TabsContent value="dependencies" class="pt-4"><ProjectDependencies :key="project.id" :project="project" :folders="inspection" :native="native" :busy="scan.processing" @refresh="refresh" @changed="reloadInspection" /></TabsContent>
        <TabsContent value="secrets" class="pt-4"><ProjectSecrets :key="project.id" :project="project" :native="native" :target-secret-id="targetSecretId" /></TabsContent>
    </Tabs>
</template>
