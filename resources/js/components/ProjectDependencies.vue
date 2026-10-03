<script setup lang="ts">
import ChoiceSelect from '@/components/ChoiceSelect.vue';
import TextTransition from '@/components/TextTransition.vue';
import { toast } from 'vue-sonner';
import { computed, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Link, useHttp } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import type { FolderPreview, PackageRoot, Project, ProjectFolder } from '@/types';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { ArrowRightIcon, ArrowUpRightIcon, FolderSearchIcon, PlusIcon, RefreshCwIcon } from '@lucide/vue';
import { dependencyHealth, dependencyReleaseUrl, folderName, type DependencyIssue } from '@/lib/dependencies';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

const props = defineProps<{ project: Project; folders: ProjectFolder[]; native: boolean; busy: boolean }>();
const emit = defineEmits<{ refresh: [kind: 'all' | 'folder' | 'root', id?: string | null]; changed: [] }>();
const folderId = ref('');
const scopedFolders = computed(() => props.folders.filter(folder => !folderId.value || folder.id === folderId.value));
const health = computed(() => dependencyHealth(scopedFolders.value));
const allHealth = computed(() => dependencyHealth(props.folders));
const packageFolders = computed(() => props.folders.filter(folder => allHealth.value.locations.some(location => location.folder.id === folder.id)));
watch(packageFolders, folders => { if (!folders.some(folder => folder.id === folderId.value)) folderId.value = ''; });
const filter = ref('attention');
const showAll = ref(false);
watch([folderId, filter], () => { showAll.value = false; });
const issues = computed(() => health.value.issues.filter(issue => filter.value === 'security' ? !!issue.advisories.length : filter.value === 'outdated' ? !!issue.latest : true));
const visibleIssues = computed(() => showAll.value ? issues.value : issues.value.slice(0, 10));
const hasSecurityResults = computed(() => health.value.locations.some(location => location.securityCurrent));
const hasUpdateResults = computed(() => health.value.locations.some(location => location.outdatedCurrent));
const checkedAt = computed(() => health.value.locations.flatMap(location => [location.securityCurrent ? location.root.security?.checked_at : null, location.outdatedCurrent ? location.root.outdated?.checked_at : null]).filter((value): value is string => !!value).sort().at(-1));
const date = (value?: string | null) => value ? new Date(value).toLocaleString() : 'Not checked';
const folderLabel = (folder: ProjectFolder) => props.folders.filter(item => folderName(item) === folderName(folder)).length > 1 ? folder.path : folderName(folder);
const locationLabel = (root: PackageRoot, folder: ProjectFolder) => root.relative_path === '.' ? folderLabel(folder) : `${folderLabel(folder)} / ${root.relative_path}`;
const updateLabel = (issue: DependencyIssue) => {
    const current = issue.current.replace(/^v/, '').split('.');
    const latest = issue.latest?.replace(/^v/, '').split('.');
    return !latest ? 'Update available' : current[0] !== latest[0] ? 'Major update' : current[1] !== latest[1] ? 'Minor update' : 'Patch update';
};
const review = ref<DependencyIssue | null>(null);
const releaseOpen = useHttp({ url: '' });
async function openRelease(event: MouseEvent, issue: DependencyIssue) {
    if (!props.native) return;
    event.preventDefault();
    const url = dependencyReleaseUrl(issue);
    if (releaseOpen.processing || !url) return;
    releaseOpen.url = url;
    try {
        if (!await releaseOpen.post(`/projects/${props.project.id}/open-url`)) throw new Error('Package page could not be opened');
    } catch { toast.error('The package page could not be opened. Try again.'); }
}
const managementOpen = ref(false);
const checking = ref(false);
const checkingRoot = ref('');
const checkingLocation = computed(() => health.value.locations.find(location => location.root.id === checkingRoot.value));
const check = useHttp({});
async function checkLocations() {
    if (checking.value) return;
    checking.value = true;
    for (const { root, folder } of health.value.locations) {
        checkingRoot.value = root.id;
        check.clearErrors();
        try {
            const result = await check.post(`/projects/${props.project.id}/roots/${root.id}/check`);
            if (!result) throw new Error('Dependency check failed');
        } catch { toast.error(`${locationLabel(root, folder)}: ${String(Object.values(check.errors)[0] ?? 'This location could not be checked. Try again.')}`); }
        finally { emit('changed'); }
    }
    checkingRoot.value = '';
    checking.value = false;
}
const tools = ['php', 'node', 'composer', 'npm', 'pnpm', 'yarn'];
const editorOpen = ref(false);
const advancedOpen = ref(false);
const edit = useHttp({ action: 'save', id: null as string | null, folder_id: '', revision: null as number | null, path: '', executable_overrides: {} as Record<string, string> });
const picker = useHttp<{ path: string }, { folder: FolderPreview | null }>({ path: '' });
function editRoot(root?: PackageRoot, targetFolder?: ProjectFolder) {
    const folder = targetFolder ?? props.folders.find(folder => folder.id === root?.project_folder_id) ?? props.folders[0];
    Object.assign(edit, {
        action: 'save', id: root?.id ?? null, folder_id: folder?.id ?? '', revision: root?.revision ?? null,
        path: root && folder ? folder.path + (root.relative_path === '.' ? '' : '/' + root.relative_path) : '',
        executable_overrides: Object.fromEntries(tools.map(tool => [tool, root?.executable_overrides?.[tool] ?? ''])) });
    advancedOpen.value = !!root && Object.values(root.executable_overrides ?? {}).some(Boolean);
    edit.clearErrors();
    editorOpen.value = true;
}
const selectedLocation = computed(() => props.folders.flatMap(folder => folder.package_roots ?? []).find(root => root.id === edit.id));
watch(() => edit.errors, errors => { if (Object.keys(errors).some(key => key.startsWith('executable_overrides'))) advancedOpen.value = true; }, { deep: true });
async function pickRoot() {
    try {
        const result = await picker.post('/folders/inspect');
        if (result.folder) edit.path = result.folder.path;
    } catch { toast.error('The folder picker could not open. You can enter the path below.'); }
}
async function saveRoot(action = 'save') {
    edit.action = action;
    try {
        const result = await edit.post(`/projects/${props.project.id}/roots`);
        if (!result) return;
        editorOpen.value = false;
        emit('changed');
    } catch { if (!edit.hasErrors) toast.error('The package location could not be saved. Try again.'); }
}
</script>

<template>
    <div>
        <div class="space-y-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="space-y-1">
                    <h2 class="text-xl font-normal tracking-[-0.025em]">
                        Dependencies
                    </h2>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="allHealth.total"
                        variant="outline"
                        @click="managementOpen = true">
                        Manage locations
                    </Button>
                    <Button
                        :disabled="busy || checking || !health.locations.length"
                        @click="checkLocations()">
                        <RefreshCwIcon
                            :class="{ 'animate-spin motion-reduce:animate-none': checking }"
                            aria-hidden="true" />
                        <TextTransition :text="checking ? 'Checking…' : folderId ? 'Check this folder' : 'Check dependencies'" />
                    </Button>
                </div>
            </div>
            <Card
                v-if="!allHealth.total"
                as="section">
                <CardContent class="items-center px-6 py-12 text-center">
                    <FolderSearchIcon
                        class="size-5 text-muted-foreground"
                        aria-hidden="true" />
                    <h3 class="mt-4 text-sm font-medium">
                        No packages to check yet
                    </h3>
                    <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                        {{ folders.length ? 'Add a location containing package manifests or lockfiles inside a linked folder.' : 'Link a local folder to check its dependencies.' }}
                    </p>
                    <div class="mt-5 flex flex-wrap gap-2">
                        <Button
                            v-if="folders.length"
                            variant="outline"
                            @click="editRoot()">
                            Add package location
                        </Button>
                        <Button
                            v-else
                            as-child
                            variant="outline">
                            <Link :href="`/projects/${project.id}/edit?tab=repositories`">
                                Link a folder
                            </Link>
                        </Button>
                    </div>
                </CardContent>
            </Card>
            <template v-else>
                <ChoiceSelect
                    v-if="packageFolders.length > 1"
                    id="dependency-folder"
                    v-model="folderId"
                    variant="filled"
                    class="w-full sm:w-auto sm:min-w-40"
                    aria-label="Filter by linked folder"
                    :disabled="checking"
                    :options="[{ value: '', label: `All folders (${packageFolders.length})` }, ...packageFolders.map(folder => ({ value: folder.id, label: folderLabel(folder) }))]" />
                <Card
                    as="section"
                    aria-labelledby="dependency-findings-title">
                    <CardHeader class="flex flex-wrap items-center justify-between gap-3">
                        <div class="space-y-1">
                            <h3
                                id="dependency-findings-title"
                                class="text-sm font-normal">
                                Packages needing attention
                            </h3>
                            <p
                                role="status"
                                class="text-xs text-muted-foreground">
                                <TextTransition
                                    :text="checking ? `Checking ${checkingLocation ? locationLabel(checkingLocation.root, checkingLocation.folder) : 'package locations'}…` : checkedAt ? `Last checked ${date(checkedAt)}` : 'Not checked yet'"
                                    :shimmer="checking" />
                            </p>
                        </div>
                        <div
                            class="flex flex-wrap gap-1"
                            role="group"
                            aria-label="Filter dependency findings">
                            <Button
                                size="sm"
                                :variant="filter === 'attention' ? 'secondary' : 'ghost'"
                                :aria-pressed="filter === 'attention'"
                                @click="filter = 'attention'">
                                All findings <span class="tabular-nums text-muted-foreground">{{ health.issueCount }}</span>
                            </Button>
                            <Button
                                size="sm"
                                :variant="filter === 'security' ? 'secondary' : 'ghost'"
                                :aria-pressed="filter === 'security'"
                                @click="filter = 'security'">
                                Security <span
                                    class="tabular-nums"
                                    :class="health.security ? 'text-destructive' : 'text-muted-foreground'">{{ hasSecurityResults ? health.security : '—' }}</span>
                            </Button>
                            <Button
                                size="sm"
                                :variant="filter === 'outdated' ? 'secondary' : 'ghost'"
                                :aria-pressed="filter === 'outdated'"
                                @click="filter = 'outdated'">
                                Updates <span class="tabular-nums text-muted-foreground">{{ hasUpdateResults ? health.outdated : '—' }}</span>
                            </Button>
                        </div>
                    </CardHeader>
                    <CardContent class="p-0">
                        <ul
                            v-if="issues.length"
                            id="dependency-findings"
                            class="divide-y divide-border/70"
                            aria-label="Dependency findings">
                            <li
                                v-for="issue in visibleIssues"
                                :key="issue.key"
                                class="grid gap-3 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:px-7 lg:grid-cols-[minmax(0,1fr)_12rem_8rem] lg:items-center lg:gap-6">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="break-all text-sm font-medium">
                                            {{ issue.name }}
                                        </p>
                                        <Badge :variant="issue.advisories.length ? 'destructive' : 'outline'">
                                            {{ issue.severity ? `${issue.severity} severity` : updateLabel(issue) }}
                                        </Badge>
                                    </div>
                                    <p
                                        class="mt-1 break-words text-xs text-muted-foreground"
                                        :title="`${issue.folder.path} / ${issue.root.relative_path}`">
                                        {{ locationLabel(issue.root, issue.folder) }} · {{ issue.manager }} · {{ issue.identity }} · {{ issue.scope }}
                                    </p>
                                </div>
                                <dl class="flex items-center gap-3 text-sm sm:row-start-2 lg:row-start-auto">
                                    <div class="grid gap-1">
                                        <dt class="text-xs text-muted-foreground">
                                            Installed
                                        </dt>
                                        <dd class="font-mono text-xs">
                                            {{ issue.current }}
                                        </dd>
                                    </div>
                                    <ArrowRightIcon
                                        v-if="issue.latest"
                                        class="size-3.5 shrink-0 text-muted-foreground"
                                        aria-hidden="true" />
                                    <div
                                        v-if="issue.latest"
                                        class="grid gap-1">
                                        <dt class="text-xs text-muted-foreground">
                                            Latest
                                        </dt>
                                        <dd class="font-mono text-xs">
                                            {{ issue.latest }}
                                        </dd>
                                    </div>
                                </dl>
                                <div class="flex items-center sm:col-start-2 sm:row-span-2 sm:row-start-1 sm:justify-end lg:col-start-3 lg:row-span-1">
                                    <Button
                                        v-if="issue.advisories.length"
                                        size="sm"
                                        variant="outline"
                                        :aria-label="`Review security issues for ${issue.name}`"
                                        @click="review = issue">
                                        Review issue
                                    </Button>
                                    <Button
                                        v-else-if="dependencyReleaseUrl(issue)"
                                        as-child
                                        size="sm"
                                        variant="ghost">
                                        <a
                                            :href="dependencyReleaseUrl(issue)"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            :aria-label="issue.ecosystem === 'composer' ? `View ${issue.name} package` : `View ${issue.name} ${issue.latest} release`"
                                            @click="openRelease($event, issue)">
                                            {{ issue.ecosystem === 'composer' ? 'View package' : 'View release' }}<ArrowUpRightIcon aria-hidden="true" />
                                        </a>
                                    </Button>
                                </div>
                            </li>
                        </ul>
                        <div
                            v-else
                            class="space-y-1 px-6 py-12 text-center">
                            <h3 class="text-sm font-medium">
                                {{ health.complete ? (filter === 'attention' ? 'No packages need attention' : filter === 'security' ? 'No known security issues' : 'All checked packages are up to date') : 'No findings in current results' }}
                            </h3>
                            <p class="text-sm text-muted-foreground">
                                {{ health.complete ? 'Check again when your package files change.' : 'Check dependencies to refresh the results.' }}
                            </p>
                        </div>
                        <div
                            v-if="issues.length > 4"
                            class="flex flex-wrap items-center justify-between gap-2 border-t border-border/70 px-5 py-2 sm:px-7">
                            <p class="text-xs text-muted-foreground">
                                Showing {{ visibleIssues.length }} of {{ issues.length }} findings
                            </p>
                            <Button
                                size="sm"
                                variant="ghost"
                                :aria-expanded="showAll"
                                aria-controls="dependency-findings"
                                @click="showAll = !showAll">
                                <TextTransition :text="showAll ? 'Show fewer' : `View all ${issues.length}`" />
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </template>
        </div>
        <Dialog v-model:open="managementOpen">
            <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader><DialogTitle>Package locations</DialogTitle><DialogDescription>Each linked folder can contain several package locations. Check them together or configure each one.</DialogDescription></DialogHeader><ul class="divide-y">
                    <li
                        v-for="location in allHealth.locations"
                        :key="location.root.id"
                        class="space-y-2 py-3">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="min-w-0 break-all text-sm font-medium">
                                {{ locationLabel(location.root, location.folder) }}
                            </p><Button
                                size="sm"
                                variant="outline"
                                @click="editRoot(location.root)">
                                Location settings
                            </Button>
                        </div><p class="break-all text-xs text-muted-foreground">
                            {{ location.folder.path }}{{ location.root.relative_path === '.' ? '' : `/${location.root.relative_path}` }}
                        </p><p class="text-xs text-muted-foreground">
                            {{ location.state }}{{ location.reason ? ` · ${location.reason}` : '' }}
                        </p>
                    </li>
                </ul><DialogFooter>
                    <Button
                        as-child
                        variant="outline">
                        <Link :href="`/projects/${project.id}/edit?tab=repositories`">
                            Linked folder settings
                        </Link>
                    </Button><Button
                        :disabled="!folders.length"
                        @click="editRoot()">
                        <PlusIcon aria-hidden="true" />Add location
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
        <Dialog
            :open="!!review"
            @update:open="open => { if (!open) review = null; }">
            <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Review {{ review?.name }}</DialogTitle><DialogDescription v-if="review">
                        {{ locationLabel(review.root, review.folder) }} · {{ review.manager }} · Installed {{ review.current }}
                    </DialogDescription>
                </DialogHeader><ul class="space-y-5">
                    <li
                        v-for="advisory in review?.advisories"
                        :key="advisory.id"
                        class="space-y-2">
                        <div class="flex flex-wrap items-start gap-2">
                            <h3 class="min-w-0 flex-1 text-sm font-normal">
                                {{ advisory.title }}
                            </h3><Badge
                                variant="outline"
                                class="text-destructive">
                                {{ advisory.severity }}
                            </Badge>
                        </div><p class="text-sm text-muted-foreground">
                            {{ advisory.fixed_versions.length ? `Advisory fixed versions: ${advisory.fixed_versions.join(', ')}` : 'No fixed version is listed in this advisory.' }}
                        </p><a
                            v-if="/^https:\/\/osv\.dev\/vulnerability\/[A-Za-z0-9._-]+$/.test(advisory.url)"
                            :href="advisory.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="text-sm underline underline-offset-4">{{ advisory.id }} advisory</a><p
                                v-else
                                class="text-xs text-muted-foreground">
                                {{ advisory.id }}
                            </p>
                    </li>
                </ul><DialogFooter>
                    <Button
                        v-if="review?.latest && dependencyReleaseUrl(review)"
                        as-child
                        variant="outline">
                        <a
                            :href="dependencyReleaseUrl(review)"
                            target="_blank"
                            rel="noopener noreferrer"
                            :aria-label="review.ecosystem === 'composer' ? `View ${review.name} package` : `View ${review.name} ${review.latest} release`"
                            @click="openRelease($event, review)">{{ review.ecosystem === 'composer' ? 'View package' : `View ${review.latest} release` }}</a>
                    </Button><Button @click="review = null">
                        Done
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
        <Dialog v-model:open="editorOpen">
            <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader><DialogTitle>{{ edit.id ? 'Location settings' : 'Add package location' }}</DialogTitle><DialogDescription>Choose the linked folder itself or a directory inside it containing Composer or JavaScript package files, Python requirements or lockfiles, Cargo files, go.mod, Gemfile.lock, packages.lock.json, pubspec.lock, pom.xml, or Gradle lockfiles. Optional executable overrides apply to this location.</DialogDescription></DialogHeader>
                <form
                    class="space-y-4"
                    @submit.prevent="saveRoot()">
                    <Alert
                        v-if="edit.hasErrors"
                        variant="destructive">
                        <AlertDescription>
                            <ul>
                                <li
                                    v-for="(message, key) in edit.errors"
                                    :key="key">
                                    {{ message }}
                                </li>
                            </ul>
                        </AlertDescription>
                    </Alert>
                    <FieldGroup>
                        <Field>
                            <FieldLabel for="root-folder">
                                Linked folder
                            </FieldLabel><ChoiceSelect
                                id="root-folder"
                                v-model="edit.folder_id"
                                :disabled="!!edit.id"
                                :options="folders.map(folder => ({ value: folder.id, label: folder.path }))" />
                        </Field>
                        <Field>
                            <FieldLabel for="root-path">
                                Package location path
                            </FieldLabel><Input
                                id="root-path"
                                v-model="edit.path"
                                placeholder="/absolute/path"
                                :aria-invalid="!!edit.errors.path" /><div v-if="native">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        :disabled="picker.processing"
                                        @click="pickRoot">
                                        Choose folder
                                    </Button>
                                </div>
                        </Field>
                        <Collapsible v-model:open="advancedOpen">
                            <CollapsibleTrigger as-child>
                                <Button
                                    type="button"
                                    variant="outline">
                                    Advanced: executable overrides
                                </Button>
                            </CollapsibleTrigger>
                            <CollapsibleContent class="grid gap-4 pt-4">
                                <p class="text-sm text-muted-foreground">
                                    Blank fields inherit automatic detection and <Link
                                        href="/settings?section=tools"
                                        class="underline">
                                        workspace runtime settings
                                    </Link>. Entered values override them, even when Advanced is closed.
                                </p>
                                <div
                                    v-if="selectedLocation?.snapshot?.runtimes"
                                    class="grid gap-2 text-xs text-muted-foreground">
                                    <p>Last checked runtimes; changed override drafts have not been checked yet.</p>
                                    <p
                                        v-for="(runtime, tool) in selectedLocation.snapshot.runtimes"
                                        :key="tool"
                                        class="break-all">
                                        {{ tool }} · {{ runtime.state }} · {{ runtime.version ?? 'No version' }} · {{ runtime.path ?? 'No executable' }} · {{ ({ automatic: 'Automatic detection', global: 'Workspace default', root: 'Project override' })[runtime.source] }}<span v-if="edit.executable_overrides[tool]"> · Manual override: {{ edit.executable_overrides[tool] }}</span>
                                    </p>
                                </div><p
                                    v-else
                                    class="text-xs text-muted-foreground">
                                    Detected runtimes are not available yet. Save and check this location.
                                </p>
                                <Field
                                    v-for="tool in tools"
                                    :key="tool">
                                    <FieldLabel :for="`runtime-${tool}`">
                                        {{ tool }} executable
                                    </FieldLabel><Input
                                        :id="`runtime-${tool}`"
                                        v-model="edit.executable_overrides[tool]"
                                        placeholder="Automatic detection / workspace default"
                                        :aria-invalid="!!edit.errors[`executable_overrides.${tool}`]" />
                                </Field>
                            </CollapsibleContent>
                        </Collapsible>
                    </FieldGroup>
                    <DialogFooter>
                        <Button
                            v-if="edit.id"
                            type="button"
                            variant="destructive"
                            :disabled="edit.processing"
                            @click="saveRoot('delete')">
                            Remove location
                        </Button><Button
                            type="button"
                            variant="outline"
                            @click="editorOpen = false">
                            Cancel
                        </Button><Button
                            type="submit"
                            :disabled="edit.processing">
                            Save location
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
