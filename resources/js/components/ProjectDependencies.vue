<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useHttp } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Input } from '@/components/ui/input';
import { Field, FieldDescription, FieldGroup, FieldLabel } from '@/components/ui/field';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
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
const updateError = ref('');
async function checkUpdates() {
    if (!selected.value) return;
    updateError.value = '';
    try {
        await updateCheck.post(`/projects/${props.project.id}/roots/${selected.value.id}/outdated`);
        emit('changed');
    } catch { updateError.value = String(Object.values(updateCheck.errors)[0] ?? 'Could not check for updates. Try again.'); }
}
const frameworks = computed(() => dependencyRows(snapshot.value ?? null).filter(row => ['laravel/framework', 'vue', 'react', 'next', 'nuxt', 'svelte', '@angular/core'].includes(row.name) && row.identity === 'Direct'));
const date = (value?: string | null) => value ? new Date(value).toLocaleString() : 'Not scanned';
const tools = ['php', 'node', 'composer', 'npm', 'pnpm', 'yarn'];
const editorOpen = ref(false);
const error = ref('');
const edit = useHttp({ action: 'save', id: null as string | null, folder_id: '', revision: null as number | null, path: '', executable_overrides: {} as Record<string, string> });
const picker = useHttp<{ path: string }, { folder: FolderPreview | null }>({ path: '' });

function editRoot(root?: PackageRoot) {
    const folder = props.folders.find(folder => folder.id === root?.project_folder_id) ?? props.folders[0];
    Object.assign(edit, { action: 'save', id: root?.id ?? null, folder_id: folder?.id ?? '', revision: root?.revision ?? null,
        path: root && folder ? `${folder.path}${root.relative_path === '.' ? '' : `/${root.relative_path}`}` : '',
        executable_overrides: Object.fromEntries(tools.map(tool => [tool, root?.executable_overrides?.[tool] ?? ''])) });
    edit.clearErrors();
    error.value = '';
    editorOpen.value = true;
}
async function pickRoot() {
    try {
        const result = await picker.post('/folders/inspect');
        if (result.folder) edit.path = result.folder.path;
    } catch { error.value = 'The folder picker could not open. You can enter the path below.'; }
}
async function saveRoot(action = 'save') {
    edit.action = action;
    error.value = '';
    try {
        await edit.post(`/projects/${props.project.id}/roots`);
        editorOpen.value = false;
        emit('changed');
    } catch { if (!edit.hasErrors) error.value = 'The package root could not be saved. Try again.'; }
}
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-end gap-2">
            <Field v-if="roots.length" class="min-w-0 flex-1">
                <FieldLabel for="package-root">Package root</FieldLabel>
                <NativeSelect id="package-root" v-model="selectedId">
                    <NativeSelectOption v-for="root in roots" :key="root.id" :value="root.id">{{ root.folder.path }}{{ root.relative_path === '.' ? '' : `/${root.relative_path}` }}</NativeSelectOption>
                </NativeSelect>
            </Field>
            <Button variant="outline" :disabled="!folders.length" @click="editRoot()">Add package root</Button>
            <Button variant="outline" :disabled="busy || !folders.length" @click="emit('refresh', 'all')">Refresh all</Button>
        </div>
        <FieldDescription v-if="!roots.length">{{ folders.length ? 'Add a package root to inspect dependencies.' : 'Link a folder in Repositories to inspect dependencies.' }}</FieldDescription>
        <template v-if="selected">
            <div class="flex flex-wrap items-center gap-2">
                <Badge variant="secondary">{{ selected.scan_state }}</Badge>
                <span class="text-sm text-muted-foreground">Last attempt: {{ date(selected.scan_attempted_at) }}</span>
                <Button size="sm" variant="outline" :disabled="busy || ['Queued', 'Scanning'].includes(selected.scan_state)" @click="emit('refresh', 'root', selected.id)">Refresh root</Button>
                <Button size="sm" variant="outline" @click="editRoot(selected)">Root settings</Button>
            </div>
            <Alert v-if="selected.scan_error" variant="destructive"><AlertDescription>{{ selected.scan_error }}. Previous results are retained.</AlertDescription></Alert>
            <Alert v-if="snapshot && selected.scan_state !== 'Current' && selected.scan_state !== 'Partial'"><AlertDescription>Showing the previous inspection while this root is {{ selected.scan_state.toLowerCase() }}.</AlertDescription></Alert>
            <template v-if="snapshot">
                <section class="space-y-3 rounded-lg border p-4" aria-label="Dependency updates">
                    <div class="flex flex-wrap items-center justify-between gap-3"><h3 class="font-medium">Dependency updates</h3><Button variant="outline" size="sm" :disabled="updateCheck.processing || !['Current', 'Partial'].includes(selected.scan_state)" @click="checkUpdates">{{ updateCheck.processing ? 'Checking…' : 'Check for updates' }}</Button></div>
                    <FieldDescription>Checks direct dependencies against their latest stable releases.</FieldDescription>
                    <p v-if="updateError" class="text-sm text-destructive" role="alert">{{ updateError }}</p>
                    <template v-if="updates">
                        <p class="text-xs text-muted-foreground">Checked {{ date(updates.checked_at) }} · {{ updates.checked }} packages checked</p>
                        <Alert v-if="updatesStale"><AlertDescription>These results are from a previous inspection. Refresh this root and check again.</AlertDescription></Alert>
                        <Alert v-if="updates.unavailable || updates.skipped"><AlertDescription>{{ updates.unavailable }} packages could not be checked. {{ updates.skipped }} skipped (missing lock data, local dependencies, or the 100-package limit).</AlertDescription></Alert>
                        <p v-if="!updates.packages.length && !updatesStale" class="text-sm">{{ updates.unavailable || updates.skipped ? 'No updates found among the packages checked.' : updates.checked ? 'All checked dependencies are up to date.' : 'No locked direct dependencies to check.' }}</p>
                        <Badge v-if="updates.packages.length && !updatesStale" variant="secondary" role="status">{{ updates.packages.length }} {{ updates.packages.length === 1 ? 'dependency is' : 'dependencies are' }} out of date</Badge>
                    </template>
                    <p v-else class="text-sm text-muted-foreground">Updates have not been checked yet.</p>
                </section>
                <div class="flex flex-wrap gap-2">
                    <template v-for="tool in ['php', 'node']" :key="tool"><Badge v-if="snapshot.runtimes[tool]?.version" variant="secondary">{{ tool === 'php' ? 'PHP' : 'Node' }} {{ snapshot.runtimes[tool]?.version }} · detected</Badge></template>
                    <Badge v-for="framework in frameworks" :key="framework.name" variant="secondary">{{ framework.name }} {{ framework.version ?? framework.required }} · {{ framework.version ? 'locked' : 'required' }}{{ framework.stale ? ' · previous data' : '' }}</Badge>
                </div>
            </template>
        </template>
    </div>
    <Dialog v-model:open="editorOpen">
        <DialogContent class="max-h-[85vh] overflow-y-auto">
            <DialogHeader><DialogTitle>{{ edit.id ? 'Root settings' : 'Add package root' }}</DialogTitle><DialogDescription>Choose a folder inside a linked folder. Executable paths are optional and apply to this root.</DialogDescription></DialogHeader>
            <form class="space-y-4" @submit.prevent="saveRoot()">
                <Alert v-if="error || edit.hasErrors" variant="destructive"><AlertDescription>{{ error }}<ul><li v-for="(message, key) in edit.errors" :key="key">{{ message }}</li></ul></AlertDescription></Alert>
                <FieldGroup>
                    <Field><FieldLabel for="root-folder">Linked folder</FieldLabel><NativeSelect id="root-folder" v-model="edit.folder_id" :disabled="!!edit.id"><NativeSelectOption v-for="folder in folders" :key="folder.id" :value="folder.id">{{ folder.path }}</NativeSelectOption></NativeSelect></Field>
                    <Field><FieldLabel for="root-path">Package root path</FieldLabel><Input id="root-path" v-model="edit.path" placeholder="/absolute/path" :aria-invalid="!!edit.errors.path" /><div v-if="native"><Button type="button" variant="outline" :disabled="picker.processing" @click="pickRoot">Choose folder</Button></div></Field>
                    <Field v-for="tool in tools" :key="tool"><FieldLabel :for="`runtime-${tool}`">{{ tool }} executable</FieldLabel><Input :id="`runtime-${tool}`" v-model="edit.executable_overrides[tool]" placeholder="Use app environment" /></Field>
                </FieldGroup>
                <DialogFooter><Button v-if="edit.id" type="button" variant="destructive" :disabled="edit.processing" @click="saveRoot('delete')">Remove root</Button><Button type="button" variant="outline" @click="editorOpen = false">Cancel</Button><Button type="submit" :disabled="edit.processing">Save root</Button></DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
