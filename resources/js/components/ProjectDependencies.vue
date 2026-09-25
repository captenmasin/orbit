<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useHttp } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Input } from '@/components/ui/input';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { FolderSearchIcon, PlusIcon, RefreshCwIcon } from '@lucide/vue';
import ChoiceSelect from '@/components/ChoiceSelect.vue';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { dependencyRows } from '@/lib/dependencies';
import type { FolderPreview, PackageRoot, Project, ProjectFolder } from '@/types';

const props = defineProps<{ project: Project; folders: ProjectFolder[]; native: boolean; busy: boolean }>();
const emit = defineEmits<{ refresh: [kind: 'all' | 'folder' | 'root', id?: string | null]; changed: [] }>();
const roots = computed(() => props.folders.flatMap(folder => (folder.package_roots ?? []).map(root => ({ ...root, folder }))));
const selectedId = ref('');
watch(roots, value => { if (!value.some(root => root.id === selectedId.value)) selectedId.value = value[0]?.id ?? ''; }, { immediate: true });
const selected = computed(() => roots.value.find(root => root.id === selectedId.value));
const snapshot = computed(() => selected.value?.snapshot);
const updates = computed(() => selected.value?.outdated);
const updatesStale = computed(() => !!updates.value && (updates.value.fingerprint !== snapshot.value?.fingerprint || !['Current', 'Partial'].includes(selected.value?.scan_state ?? '')));
const updateCheck = useHttp({});
async function checkUpdates() {
    if (!selected.value) return;
    try {
        const result = await updateCheck.post(`/projects/${props.project.id}/roots/${selected.value.id}/outdated`);
        if (!result) { toast.error(String(Object.values(updateCheck.errors)[0] ?? 'Could not check for updates. Try again.')); return; }
        emit('changed');
    } catch { toast.error('Could not check for updates. Try again.'); }
}
const directDependencies = computed(() => dependencyRows(snapshot.value ?? null).filter(row => row.identity === 'Direct'));
const frameworks = computed(() => directDependencies.value.filter(row => ['laravel/framework', 'vue', 'react', 'next', 'nuxt', 'svelte', '@angular/core'].includes(row.name)));
const date = (value?: string | null) => value ? new Date(value).toLocaleString() : 'Not scanned';
const tools = ['php', 'node', 'composer', 'npm', 'pnpm', 'yarn'];
const editorOpen = ref(false);
const edit = useHttp({ action: 'save', id: null as string | null, folder_id: '', revision: null as number | null, path: '', executable_overrides: {} as Record<string, string> });
const picker = useHttp<{ path: string }, { folder: FolderPreview | null }>({ path: '' });

function editRoot(root?: PackageRoot) {
    const folder = props.folders.find(folder => folder.id === root?.project_folder_id) ?? props.folders[0];
    Object.assign(edit, { action: 'save', id: root?.id ?? null, folder_id: folder?.id ?? '', revision: root?.revision ?? null,
        path: root && folder ? `${folder.path}${root.relative_path === '.' ? '' : `/${root.relative_path}`}` : '',
        executable_overrides: Object.fromEntries(tools.map(tool => [tool, root?.executable_overrides?.[tool] ?? ''])) });
    edit.clearErrors();
    editorOpen.value = true;
}
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
    } catch { if (!edit.hasErrors) toast.error('The package root could not be saved. Try again.'); }
}
</script>

<template>
    <div>
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-xl font-semibold tracking-[-0.025em]">Dependencies</h2>
                <p class="mt-1 text-sm leading-6 text-muted-foreground">See the packages and runtimes found in linked folders.</p>
            </div>
            <Button :disabled="!folders.length" @click="editRoot()"><PlusIcon aria-hidden="true" />Add package root</Button>
        </div>
        <div v-if="!roots.length" class="flex flex-col items-center rounded-[1.25rem] border border-dashed border-black/10 px-6 py-16 text-center dark:border-white/10">
            <span class="flex size-12 items-center justify-center rounded-2xl bg-muted text-muted-foreground"><FolderSearchIcon class="size-5" aria-hidden="true" /></span>
            <h3 class="mt-4 text-base font-semibold">No package roots yet</h3>
            <p class="mt-1 max-w-sm text-sm text-muted-foreground">{{ folders.length ? 'Choose a folder to inspect its packages and runtimes.' : 'Link a local folder in project settings to inspect its dependencies.' }}</p>
            <Button v-if="folders.length" variant="outline" class="mt-5" @click="editRoot()">Add package root</Button>
        </div>
        <template v-if="selected">
            <div class="flex flex-wrap items-end gap-3 border-b pb-4">
                <Field class="min-w-0 flex-[1_1_18rem]"><FieldLabel for="package-root">Package root</FieldLabel><ChoiceSelect id="package-root" v-model="selectedId" :options="roots.map(root => ({ value: root.id, label: `${root.folder.path}${root.relative_path === '.' ? '' : `/${root.relative_path}`}` }))" /></Field>
                <Button variant="outline" :disabled="busy || !folders.length" @click="emit('refresh', 'all')"><RefreshCwIcon aria-hidden="true" />Refresh all</Button>
            </div>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                <Badge variant="secondary">{{ selected.scan_state }}</Badge>
                <span class="text-sm text-muted-foreground">Last attempt {{ date(selected.scan_attempted_at) }}</span>
                <Button size="sm" variant="outline" :disabled="busy || ['Queued', 'Scanning'].includes(selected.scan_state)" @click="emit('refresh', 'root', selected.id)">Refresh root</Button>
                <Button size="sm" variant="ghost" @click="editRoot(selected)">Root settings</Button>
            </div>
            <Alert v-if="selected.scan_error" variant="destructive"><AlertDescription>{{ selected.scan_error }}. Previous results are retained.</AlertDescription></Alert>
            <Alert v-if="snapshot && selected.scan_state !== 'Current' && selected.scan_state !== 'Partial'"><AlertDescription>Showing the previous inspection while this root is {{ selected.scan_state.toLowerCase() }}.</AlertDescription></Alert>
            <div v-if="snapshot" class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_21rem] xl:items-start">
                <section class="min-w-0 overflow-hidden rounded-[1.25rem] border border-black/8 bg-background dark:border-white/10" aria-labelledby="direct-dependencies-title">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-black/8 px-5 py-4 dark:border-white/10"><h3 id="direct-dependencies-title" class="text-base font-semibold tracking-[-0.02em]">Direct dependencies</h3><span class="text-sm text-muted-foreground">{{ directDependencies.length }}</span></div>
                    <ul v-if="directDependencies.length" class="divide-y divide-black/8 dark:divide-white/10">
                        <li v-for="dependency in directDependencies" :key="`${dependency.ecosystem}:${dependency.name}`" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-5 py-3 text-sm">
                            <div class="min-w-0"><p class="break-all font-medium">{{ dependency.name }}</p><p class="text-xs text-muted-foreground">{{ dependency.ecosystem === 'composer' ? 'Composer' : 'npm' }} · {{ dependency.scope }}{{ dependency.stale ? ' · previous data' : '' }}</p></div>
                            <div class="text-right"><p class="font-medium">{{ dependency.version ?? dependency.required ?? '—' }}</p><p v-if="dependency.version && dependency.required" class="text-xs text-muted-foreground">Requires {{ dependency.required }}</p></div>
                        </li>
                    </ul>
                    <p v-else class="px-5 py-8 text-sm text-muted-foreground">No direct packages found in this root.</p>
                </section>
                <div class="space-y-5">
                    <section class="space-y-4 rounded-[1.25rem] border border-black/8 bg-neutral-50 p-5 dark:border-white/10 dark:bg-neutral-800" aria-labelledby="dependency-updates-title">
                        <div class="flex flex-wrap items-center justify-between gap-3"><h3 id="dependency-updates-title" class="text-base font-semibold tracking-[-0.02em]">Updates</h3><Button variant="outline" size="sm" :disabled="updateCheck.processing || !['Current', 'Partial'].includes(selected.scan_state)" @click="checkUpdates">{{ updateCheck.processing ? 'Checking…' : 'Check for updates' }}</Button></div>
                        <p class="text-sm leading-6 text-muted-foreground">Compare locked direct packages with their latest stable releases.</p>
                        <template v-if="updates">
                            <p class="text-xs text-muted-foreground">Checked {{ date(updates.checked_at) }} · {{ updates.checked }} packages</p>
                            <Alert v-if="updatesStale"><AlertDescription>These results are from a previous inspection. Refresh this root and check again.</AlertDescription></Alert>
                            <Alert v-if="updates.unavailable || updates.skipped"><AlertDescription>{{ updates.unavailable }} packages could not be checked. {{ updates.skipped }} skipped (missing lock data, local dependencies, or the 100-package limit).</AlertDescription></Alert>
                            <p v-if="!updates.packages.length && !updatesStale" class="text-sm">{{ updates.unavailable || updates.skipped ? 'No updates found among the packages checked.' : updates.checked ? 'All checked dependencies are up to date.' : 'No locked direct dependencies to check.' }}</p>
                            <template v-if="updates.packages.length && !updatesStale"><p class="text-sm font-medium" role="status">{{ updates.packages.length }} {{ updates.packages.length === 1 ? 'package has' : 'packages have' }} updates</p><ul class="divide-y divide-black/8 dark:divide-white/10"><li v-for="item in updates.packages" :key="`${item.ecosystem}:${item.name}`" class="flex items-center justify-between gap-3 py-2.5 text-sm"><span class="min-w-0 break-all font-medium">{{ item.name }}</span><span class="shrink-0 text-muted-foreground">{{ item.current }} → {{ item.latest }}</span></li></ul></template>
                        </template>
                        <p v-else class="text-sm text-muted-foreground">Updates have not been checked yet.</p>
                    </section>
                    <section v-if="frameworks.length || snapshot.runtimes.php?.version || snapshot.runtimes.node?.version" class="space-y-3 rounded-[1.25rem] border border-black/8 bg-neutral-50 p-5 dark:border-white/10 dark:bg-neutral-800" aria-label="Detected stack">
                        <h3 class="text-base font-semibold tracking-[-0.02em]">Detected stack</h3>
                        <div class="flex flex-wrap gap-2"><template v-for="tool in ['php', 'node']" :key="tool"><Badge v-if="snapshot.runtimes[tool]?.version" variant="secondary">{{ tool === 'php' ? 'PHP' : 'Node' }} {{ snapshot.runtimes[tool]?.version }}</Badge></template><Badge v-for="framework in frameworks" :key="framework.name" variant="secondary">{{ framework.name }} {{ framework.version ?? framework.required }}{{ framework.stale ? ' · previous data' : '' }}</Badge></div>
                    </section>
                </div>
            </div>
            <div v-else class="rounded-[1.25rem] border border-dashed border-black/10 px-6 py-12 text-center dark:border-white/10"><h3 class="text-base font-semibold">No inspection yet</h3><p class="mt-1 text-sm text-muted-foreground">Refresh this root to read its package files.</p></div>
        </template>
    </div>
    <Dialog v-model:open="editorOpen">
        <DialogContent class="max-h-[85vh] overflow-y-auto">
            <DialogHeader><DialogTitle>{{ edit.id ? 'Root settings' : 'Add package root' }}</DialogTitle><DialogDescription>Choose a folder inside a linked folder. Executable paths are optional and apply to this root.</DialogDescription></DialogHeader>
            <form class="space-y-4" @submit.prevent="saveRoot()">
                <Alert v-if="edit.hasErrors" variant="destructive"><AlertDescription><ul><li v-for="(message, key) in edit.errors" :key="key">{{ message }}</li></ul></AlertDescription></Alert>
                <FieldGroup>
                    <Field><FieldLabel for="root-folder">Linked folder</FieldLabel><ChoiceSelect id="root-folder" v-model="edit.folder_id" :disabled="!!edit.id" :options="folders.map(folder => ({ value: folder.id, label: folder.path }))" /></Field>
                    <Field><FieldLabel for="root-path">Package root path</FieldLabel><Input id="root-path" v-model="edit.path" placeholder="/absolute/path" :aria-invalid="!!edit.errors.path" /><div v-if="native"><Button type="button" variant="outline" :disabled="picker.processing" @click="pickRoot">Choose folder</Button></div></Field>
                    <Field v-for="tool in tools" :key="tool"><FieldLabel :for="`runtime-${tool}`">{{ tool }} executable</FieldLabel><Input :id="`runtime-${tool}`" v-model="edit.executable_overrides[tool]" placeholder="Use app environment" /></Field>
                </FieldGroup>
                <DialogFooter><Button v-if="edit.id" type="button" variant="destructive" :disabled="edit.processing" @click="saveRoot('delete')">Remove root</Button><Button type="button" variant="outline" @click="editorOpen = false">Cancel</Button><Button type="submit" :disabled="edit.processing">Save root</Button></DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
    </div>
</template>
