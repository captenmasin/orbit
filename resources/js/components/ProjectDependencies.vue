<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useHttp } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Input } from '@/components/ui/input';
import { Field, FieldDescription, FieldGroup, FieldLabel } from '@/components/ui/field';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
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
const frameworks = computed(() => dependencyRows(snapshot.value ?? null).filter(row => ['laravel/framework', 'vue', 'react', 'next', 'nuxt', 'svelte', '@angular/core'].includes(row.name) && row.identity === 'Direct'));
const query = ref('');
const page = ref(0);
const rows = computed(() => dependencyRows(snapshot.value ?? null).filter(row => `${row.name} ${row.scope} ${row.ecosystem} ${row.location ?? ''}`.toLowerCase().includes(query.value.toLowerCase())));
const pages = computed(() => Math.max(1, Math.ceil(rows.value.length / 50)));
const shown = computed(() => rows.value.slice(page.value * 50, (page.value + 1) * 50));
watch([selectedId, query], () => { page.value = 0; });
watch(pages, value => { page.value = Math.min(page.value, value - 1); });
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
                <div class="flex flex-wrap gap-2">
                    <template v-for="tool in ['php', 'node']" :key="tool"><Badge v-if="snapshot.runtimes[tool]?.version" variant="secondary">{{ tool === 'php' ? 'PHP' : 'Node' }} {{ snapshot.runtimes[tool]?.version }} · detected</Badge></template>
                    <Badge v-for="framework in frameworks" :key="framework.name" variant="secondary">{{ framework.name }} {{ framework.version ?? framework.required }} · {{ framework.version ? 'locked' : 'required' }}{{ framework.stale ? ' · previous data' : '' }}</Badge>
                </div>
                <section class="space-y-3" aria-label="Dependency sources">
                    <h3 class="font-medium">Sources</h3>
                    <p class="break-all text-sm text-muted-foreground">{{ snapshot.path }}</p>
                    <Table>
                        <TableHeader><TableRow><TableHead>File</TableHead><TableHead>State</TableHead><TableHead>Last read</TableHead></TableRow></TableHeader>
                        <TableBody>
                            <TableRow v-for="source in snapshot.files" :key="source.file">
                                <TableCell>{{ source.file }}<template v-if="source.lockfile_version"> · v{{ source.lockfile_version }}</template></TableCell>
                                <TableCell>{{ source.state }}<template v-if="source.state !== 'Current' && source.scanned_at"> · previous data</template></TableCell>
                                <TableCell>{{ date(source.scanned_at) }}</TableCell>
                            </TableRow>
                            <TableRow v-for="file in snapshot.unsupported_lockfiles" :key="file"><TableCell>{{ file }}</TableCell><TableCell>Locked versions unsupported</TableCell><TableCell>—</TableCell></TableRow>
                        </TableBody>
                    </Table>
                    <FieldDescription v-for="(constraint, engine) in snapshot.files['package.json']?.requirements" :key="engine">{{ engine }}: {{ constraint }} (package.json)</FieldDescription>
                </section>
                <section class="space-y-3" aria-label="Dependencies">
                    <div class="flex flex-wrap items-center justify-between gap-2"><h3 class="font-medium">Dependencies <span class="text-muted-foreground">({{ rows.length }})</span></h3><Input v-model="query" class="w-64" type="search" aria-label="Filter dependencies" placeholder="Filter dependencies…" /></div>
                    <FieldDescription>Required values come from manifests; locked values come from lockfiles. Installation and version compatibility are not checked.</FieldDescription>
                    <Table>
                        <TableHeader><TableRow><TableHead>Package</TableHead><TableHead>Required</TableHead><TableHead>Locked</TableHead><TableHead>Scope</TableHead><TableHead>Source / location</TableHead></TableRow></TableHeader>
                        <TableBody>
                            <TableRow v-for="(row, index) in shown" :key="index">
                                <TableCell class="font-medium">{{ row.name }}<span v-if="row.stale" class="ml-2 text-muted-foreground">Previous data</span></TableCell>
                                <TableCell>{{ row.required ?? '—' }}</TableCell>
                                <TableCell>{{ row.link ? `Link → ${row.link}` : row.version ?? 'Unknown' }}</TableCell>
                                <TableCell>{{ row.scope }}<br><span class="text-muted-foreground">{{ row.identity }}</span></TableCell>
                                <TableCell class="max-w-80 whitespace-normal break-all">{{ row.ecosystem }}<template v-if="row.location"><br>{{ row.location }}</template></TableCell>
                            </TableRow>
                            <TableRow v-if="!shown.length"><TableCell :colspan="5" class="text-muted-foreground">No matching dependencies.</TableCell></TableRow>
                        </TableBody>
                    </Table>
                    <div v-if="pages > 1" class="flex items-center gap-3"><Button size="sm" variant="outline" :disabled="page === 0" @click="page--">Previous</Button><span class="text-sm">{{ page + 1 }} / {{ pages }}</span><Button size="sm" variant="outline" :disabled="page + 1 >= pages" @click="page++">Next</Button></div>
                </section>
                <section class="space-y-3" aria-label="Detected runtimes">
                    <h3 class="font-medium">Runtimes</h3>
                    <FieldDescription>Host executables are probed from / to avoid loading project configuration. Set executable paths in Root settings.</FieldDescription>
                    <Table>
                        <TableHeader><TableRow><TableHead>Tool</TableHead><TableHead>Version</TableHead><TableHead>Executable / state</TableHead><TableHead>Last detected</TableHead></TableRow></TableHeader>
                        <TableBody>
                            <TableRow v-for="runtime in snapshot.runtimes" :key="runtime.tool">
                                <TableCell>{{ runtime.tool }}</TableCell><TableCell>{{ runtime.version ?? 'Unknown' }}</TableCell>
                                <TableCell class="max-w-96 whitespace-normal break-all">{{ runtime.path ?? 'No executable resolved' }}<br><span class="text-muted-foreground">{{ runtime.state }}</span><template v-if="runtime.last_success"><br>Previous: {{ runtime.last_success.version }} · {{ runtime.last_success.path }} · {{ date(runtime.last_success.scanned_at) }}</template></TableCell>
                                <TableCell>{{ date(runtime.scanned_at) }}</TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </section>
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
