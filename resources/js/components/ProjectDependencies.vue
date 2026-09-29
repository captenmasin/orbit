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
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { FolderSearchIcon, PlusIcon, RefreshCwIcon } from '@lucide/vue';
import type { FolderPreview, PackageRoot, Project, ProjectFolder } from '@/types';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { dependencyHealth, dependencyReleaseUrl, dependencySeverities, folderName, type DependencyIssue } from '@/lib/dependencies';

const props = defineProps<{ project: Project; folders: ProjectFolder[]; native: boolean; busy: boolean }>();
const emit = defineEmits<{ refresh: [kind: 'all' | 'folder' | 'root', id?: string | null]; changed: [] }>();
const folderId = ref('');
watch(() => props.folders, folders => { if (!folders.some(folder => folder.id === folderId.value)) folderId.value = ''; });
const scopedFolders = computed(() => props.folders.filter(folder => !folderId.value || folder.id === folderId.value));
const health = computed(() => dependencyHealth(scopedFolders.value));
const allHealth = computed(() => dependencyHealth(props.folders));
const filter = ref('attention');
const showAll = ref(false);
watch([folderId, filter], () => { showAll.value = false; });
const issues = computed(() => health.value.issues.filter(issue => filter.value === 'security' ? !!issue.advisories.length : filter.value === 'outdated' ? !!issue.latest : true));
const visibleIssues = computed(() => showAll.value ? issues.value : issues.value.slice(0, 4));
const securitySummary = computed(() => dependencySeverities.map(severity => ({ severity, count: health.value.issues.filter(issue => issue.severity === severity).length })).filter(item => item.count).map(item => `${item.count} ${item.severity.toLowerCase()}`).join(' · '));
const unchecked = computed(() => health.value.locations.filter(location => !location.complete));
const hasSecurityResults = computed(() => health.value.locations.some(location => location.securityCurrent));
const hasUpdateResults = computed(() => health.value.locations.some(location => location.outdatedCurrent));
const checkedAt = computed(() => health.value.locations.flatMap(location => [location.securityCurrent ? location.root.security?.checked_at : null, location.outdatedCurrent ? location.root.outdated?.checked_at : null]).filter((value): value is string => !!value).sort().at(-1));
const date = (value?: string | null) => value ? new Date(value).toLocaleString() : 'Not checked';
const folderLabel = (folder: ProjectFolder) => props.folders.filter(item => folderName(item) === folderName(folder)).length > 1 ? folder.path : folderName(folder);
const locationLabel = (root: PackageRoot, folder: ProjectFolder) => `${folderLabel(folder)} / ${root.relative_path === '.' ? 'root' : root.relative_path}`;
const updateLabel = (issue: DependencyIssue) => {
    const current = issue.current.replace(/^v/, '').split('.');
    const latest = issue.latest?.replace(/^v/, '').split('.');
    return !latest ? 'Update available' : current[0] !== latest[0] ? 'Major update' : current[1] !== latest[1] ? 'Minor update' : 'Patch update';
};
const review = ref<DependencyIssue | null>(null);
const managementOpen = ref(false);
const checking = ref(false);
const checkingRoot = ref('');
const checkingLocation = computed(() => health.value.locations.find(location => location.root.id === checkingRoot.value));
const checkErrors = ref<Record<string, string>>({});
const check = useHttp({});
async function checkLocations(rootId?: string) {
    if (checking.value) return;
    const locations = health.value.locations.filter(location => !rootId || location.root.id === rootId);
    checking.value = true;
    for (const { root } of locations) {
        checkingRoot.value = root.id;
        delete checkErrors.value[root.id];
        check.clearErrors();
        try {
            const result = await check.post(`/projects/${props.project.id}/roots/${root.id}/check`);
            if (!result) checkErrors.value[root.id] = String(Object.values(check.errors)[0] ?? 'This location could not be checked. Try again.');
        } catch { checkErrors.value[root.id] = String(Object.values(check.errors)[0] ?? 'This location could not be checked. Try again.'); }
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
                    </h2><p class="text-sm text-muted-foreground">
                        Security issues and outdated packages across every linked folder.
                    </p>
                </div>
                <Button
                    :disabled="busy || checking || !health.locations.length"
                    @click="checkLocations()">
                    <RefreshCwIcon
                        v-if="checking"
                        class="animate-spin motion-reduce:animate-none"
                        aria-hidden="true" /><TextTransition :text="checking ? 'Checking folders…' : folderId ? 'Check this folder' : 'Check all folders'" />
                </Button>
            </div>
            <div
                v-if="!allHealth.total"
                class="flex flex-col items-center rounded-[1.25rem] border border-dashed border-black/10 px-6 py-16 text-center dark:border-white/10">
                <span class="flex size-12 items-center justify-center rounded-2xl bg-muted text-muted-foreground"><FolderSearchIcon
                    class="size-5"
                    aria-hidden="true" /></span>
                <h3 class="mt-4 text-sm font-normal">
                    No package locations yet
                </h3>
                <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                    {{ folders.length ? 'Add the package locations inside your linked folders to check their dependencies.' : 'Link a local folder to check its dependencies.' }}
                </p>
                <div class="mt-5 flex flex-wrap gap-2">
                    <Button
                        v-if="folders.length"
                        variant="outline"
                        @click="editRoot()">
                        Add package location
                    </Button><Button
                        v-else
                        as-child
                        variant="outline">
                        <Link :href="`/projects/${project.id}/edit?tab=repositories`">
                            Link a folder
                        </Link>
                    </Button>
                </div>
            </div>
            <template v-else>
                <div
                    class="grid gap-4 sm:grid-cols-2"
                    aria-label="Dependency summary">
                    <section class="space-y-1.5 rounded-[1.25rem] border border-black/8 bg-background p-5 dark:border-white/10">
                        <h3
                            class="text-2xl font-normal tracking-[-0.025em]"
                            :class="health.security ? 'text-destructive' : ''">
                            {{ hasSecurityResults ? `${health.security} security ${health.security === 1 ? 'issue' : 'issues'}` : 'Security not checked' }}
                        </h3><p class="text-[13px] text-muted-foreground">
                            {{ securitySummary || (health.complete ? 'No known issues in checked packages' : 'Check coverage is incomplete') }}
                        </p>
                    </section>
                    <section class="space-y-1.5 rounded-[1.25rem] border border-black/8 bg-background p-5 dark:border-white/10">
                        <h3 class="text-2xl font-normal tracking-[-0.025em]">
                            {{ hasUpdateResults ? `${health.outdated} outdated ${health.outdated === 1 ? 'package' : 'packages'}` : 'Updates not checked' }}
                        </h3><p class="text-[13px] text-muted-foreground">
                            Counts each package location separately{{ health.complete ? '' : ' · coverage incomplete' }}
                        </p>
                    </section>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div
                        class="flex flex-wrap gap-2"
                        role="group"
                        aria-label="Filter dependency findings">
                        <Button
                            size="input"
                            :variant="filter === 'attention' ? 'default' : 'outline'"
                            :aria-pressed="filter === 'attention'"
                            @click="filter = 'attention'">
                            Needs attention
                        </Button><Button
                            size="input"
                            :variant="filter === 'security' ? 'default' : 'outline'"
                            :aria-pressed="filter === 'security'"
                            @click="filter = 'security'">
                            Security {{ health.security }}
                        </Button><Button
                            size="input"
                            :variant="filter === 'outdated' ? 'default' : 'outline'"
                            :aria-pressed="filter === 'outdated'"
                            @click="filter = 'outdated'">
                            Outdated {{ health.outdated }}
                        </Button>
                    </div>
                    <ChoiceSelect
                        id="dependency-folder"
                        v-model="folderId"
                        class="w-auto min-w-40"
                        aria-label="Filter by linked folder"
                        :disabled="checking"
                        :options="[{ value: '', label: `All folders (${folders.length})` }, ...folders.map(folder => ({ value: folder.id, label: folderLabel(folder) }))]" />
                </div>
                <section
                    class="overflow-hidden rounded-[1.25rem] border border-black/8 bg-background dark:border-white/10"
                    aria-label="Dependency findings">
                    <div
                        v-if="issues.length"
                        class="overflow-x-auto">
                        <table class="w-full min-w-[40rem] text-left text-sm">
                            <thead class="bg-muted/70 text-xs font-normal text-muted-foreground">
                                <tr>
                                    <th
                                        scope="col"
                                        class="px-5 py-3 font-normal">
                                        Package
                                    </th><th
                                        scope="col"
                                        class="px-4 py-3 font-normal">
                                        Issue
                                    </th><th
                                        scope="col"
                                        class="px-4 py-3 font-normal">
                                        Versions
                                    </th><th
                                        scope="col"
                                        class="px-5 py-3">
                                        <span class="sr-only">Action</span>
                                    </th>
                                </tr>
                            </thead><tbody class="divide-y divide-black/8 dark:divide-white/10">
                                <tr
                                    v-for="issue in visibleIssues"
                                    :key="issue.key">
                                    <td class="px-5 py-3">
                                        <p class="break-all font-medium">
                                            {{ issue.name }}
                                        </p><p
                                            class="mt-1 text-xs text-muted-foreground"
                                            :title="`${issue.folder.path} / ${issue.root.relative_path}`">
                                            {{ locationLabel(issue.root, issue.folder) }} · {{ issue.manager }} · {{ issue.identity }} · {{ issue.scope }}
                                        </p>
                                    </td><td class="px-4 py-3">
                                        <Badge
                                            variant="outline"
                                            :class="issue.advisories.length ? 'text-destructive' : 'text-muted-foreground'">
                                            {{ issue.severity ? `${issue.severity} severity` : updateLabel(issue) }}
                                        </Badge>
                                    </td><td class="whitespace-nowrap px-4 py-3">
                                        <span class="block">Installed: {{ issue.current }}</span><span class="block text-muted-foreground">Latest available: {{ issue.latest ?? 'Not reported' }}</span>
                                    </td><td class="px-5 py-3 text-right">
                                        <Button
                                            v-if="issue.advisories.length"
                                            size="sm"
                                            variant="outline"
                                            @click="review = issue">
                                            Review issue
                                        </Button><Button
                                            v-else-if="dependencyReleaseUrl(issue)"
                                            as-child
                                            size="sm"
                                            variant="outline">
                                            <a
                                                :href="dependencyReleaseUrl(issue)"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                :aria-label="issue.manager === 'composer' ? `View ${issue.name} package` : `View ${issue.name} ${issue.latest} release`">{{ issue.manager === 'composer' ? 'View package' : 'View release' }}</a>
                                        </Button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div
                        v-else
                        class="space-y-1 px-6 py-12 text-center">
                        <h3 class="text-sm font-normal">
                            {{ health.complete ? (filter === 'attention' ? 'All checked dependencies are healthy' : filter === 'security' ? 'No known security issues' : 'All checked direct packages are up to date') : 'No confirmed findings in current results' }}
                        </h3><p class="text-sm text-muted-foreground">
                            {{ health.complete ? 'Check again when your package files change.' : 'Check the locations below before treating this project as healthy.' }}
                        </p>
                    </div>
                    <div
                        v-if="issues.length > 4"
                        class="border-t border-black/8 p-1 text-center dark:border-white/10">
                        <Button
                            size="sm"
                            variant="ghost"
                            :aria-expanded="showAll"
                            @click="showAll = !showAll">
                            <TextTransition :text="showAll ? 'Show fewer findings' : `View all ${issues.length} ${filter === 'outdated' ? 'outdated packages' : 'findings'}`" />
                        </Button>
                    </div>
                </section>
                <div
                    v-if="checking"
                    class="rounded-xl bg-muted px-4 py-3 text-sm text-muted-foreground"
                    role="status">
                    <TextTransition
                        :text="`Checking ${checkingLocation ? locationLabel(checkingLocation.root, checkingLocation.folder) : 'package locations'}…`"
                        shimmer />
                </div>
                <ul
                    v-if="unchecked.length || health.unconfigured.length || Object.keys(checkErrors).length"
                    class="space-y-2"
                    aria-label="Incomplete dependency checks">
                    <li
                        v-for="location in health.locations.filter(item => !item.complete || checkErrors[item.root.id])"
                        :key="location.root.id"
                        class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-muted px-4 py-3">
                        <div class="min-w-0 flex-1 text-[13px]">
                            <p class="font-medium">
                                {{ locationLabel(location.root, location.folder) }} · {{ checkErrors[location.root.id] ? 'Check failed' : location.state }}
                            </p><p class="mt-1 text-muted-foreground">
                                {{ checkErrors[location.root.id] || location.reason }} Totals include current results only.
                            </p>
                        </div><Button
                            size="sm"
                            variant="ghost"
                            :disabled="checking || busy"
                            @click="checkLocations(location.root.id)">
                            Check again
                        </Button><Button
                            size="sm"
                            variant="ghost"
                            @click="editRoot(location.root)">
                            Settings
                        </Button>
                    </li><li
                        v-for="folder in health.unconfigured"
                        :key="folder.id"
                        class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-muted px-4 py-3">
                        <p class="text-[13px] text-muted-foreground">
                            {{ folderLabel(folder) }} has no configured package locations and has not been checked.
                        </p><Button
                            size="sm"
                            variant="ghost"
                            @click="editRoot(undefined, folder)">
                            Add location
                        </Button>
                    </li>
                </ul>
                <div class="flex flex-wrap items-center justify-between gap-3 text-xs text-muted-foreground">
                    <p>{{ health.checked }} of {{ health.total }} package locations fully checked<span v-if="checkedAt"> · Last check {{ date(checkedAt) }}</span></p><Button
                        size="sm"
                        variant="ghost"
                        @click="managementOpen = true">
                        Manage package locations
                    </Button>
                </div>
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
                            :aria-label="review.manager === 'composer' ? `View ${review.name} package` : `View ${review.name} ${review.latest} release`">{{ review.manager === 'composer' ? 'View package' : `View ${review.latest} release` }}</a>
                    </Button><Button @click="review = null">
                        Done
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
        <Dialog v-model:open="editorOpen">
            <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader><DialogTitle>{{ edit.id ? 'Location settings' : 'Add package location' }}</DialogTitle><DialogDescription>Choose the linked folder itself or a directory inside it containing composer.json or package.json. Optional executable overrides apply to this location.</DialogDescription></DialogHeader>
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
