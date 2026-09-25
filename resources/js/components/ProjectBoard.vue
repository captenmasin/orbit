<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { router, useForm, useHttp } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { VueDraggable, type DraggableEvent } from 'vue-draggable-plus';
import { GripVerticalIcon, PaperclipIcon, PlusIcon, Trash2Icon, XIcon } from '@lucide/vue';
import MarkdownContent from '@/components/MarkdownContent.vue';
import BulkIdeas from '@/components/BulkIdeas.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import ChoiceSelect from '@/components/ChoiceSelect.vue';
import { Textarea } from '@/components/ui/textarea';
import type { BoardColumn, BoardTask, Project } from '@/types';

const props = defineProps<{ project: Project; targetTaskId?: string | null }>();
const columns = ref<BoardColumn[]>([]);
const editor = ref<'task' | 'column' | 'delete-task' | 'delete-column' | null>(null);
const renamingColumnId = ref<string | null>(null);
const dragged = ref<{ kind: 'task' | 'column'; id: string; revision: number } | null>(null);
const form = useForm({ action: '', revision: props.project.revision, id: null as string | null, title: '', description: '', name: '', column_id: '', position: 0, destination_id: '', attachments: [] as File[], removed_attachment_ids: [] as string[] });
const preview = useHttp<{ description: string }, { html: string }>({ description: '' });
const previewOpen = ref(false);
const previewHtml = ref('');
const attachmentPickerKey = ref(0);
const selectedTask = computed(() => columns.value.flatMap(column => column.tasks).find(task => task.id === form.id));
const retainedAttachments = computed(() => selectedTask.value?.attachments.filter(file => !form.removed_attachment_ids.includes(file.id)) ?? []);
const selectedColumn = computed(() => columns.value.find(column => column.id === form.id));
const destinations = computed(() => columns.value.filter(column => column.id !== form.id));
const dialogTitle = computed(() => ({
    task: form.id ? 'Edit task' : 'New task', column: 'New column',
    'delete-task': 'Delete task?', 'delete-column': 'Delete column?',
})[editor.value ?? 'task']);
const deleting = computed(() => editor.value?.startsWith('delete-'));
const cannotDeleteColumn = computed(() => editor.value === 'delete-column' && !!selectedColumn.value?.tasks.length && !form.destination_id);

function syncColumns() {
    columns.value = (props.project.board_columns ?? []).map(column => ({ ...column, tasks: [...column.tasks] }));
}
watch(() => props.project, syncColumns, { immediate: true });

function reset(action: string) {
    form.reset();
    form.clearErrors();
    form.action = action;
    form.revision = props.project.revision;
    previewOpen.value = false;
    previewHtml.value = '';
    preview.clearErrors();
}
async function togglePreview() {
    previewOpen.value = !previewOpen.value;
    if (!previewOpen.value) return;
    previewHtml.value = '';
    preview.description = form.description;
    try {
        previewHtml.value = (await preview.post(`/projects/${props.project.id}/board/preview`)).html;
    } catch {
        if (!preview.hasErrors) { previewOpen.value = false; toast.error('The preview could not be loaded. Try again.'); }
    }
}
function attachFiles(event: Event) {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    attachmentPickerKey.value++;
    form.clearErrors('attachments');
    if (retainedAttachments.value.length + form.attachments.length + files.length > 10) {
        form.setError('attachments', 'A task can have up to 10 attachments.');
    } else if (files.some(file => file.size > 10 * 1024 * 1024)) {
        form.setError('attachments', 'Each attachment must be 10 MB or smaller.');
    } else {
        form.attachments.push(...files);
    }
}
const attachmentUrl = (taskId: string, attachmentId: string) => `/projects/${props.project.id}/tasks/${taskId}/attachments/${attachmentId}`;
const fileSize = (size: number) => size >= 1024 * 1024 ? `${(size / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.ceil(size / 1024))} KB`;
function editTask(column: BoardColumn, task?: BoardTask) {
    reset('task.save');
    form.id = task?.id ?? null;
    form.column_id = column.id;
    form.title = task?.title ?? '';
    form.description = task?.description ?? '';
    editor.value = 'task';
}
function editColumn() {
    reset('column.save');
    editor.value = 'column';
}
async function renameColumn(column: BoardColumn) {
    reset('column.save');
    form.id = column.id;
    form.name = column.name;
    renamingColumnId.value = column.id;
    await nextTick();
    const input = document.getElementById(`column-name-${column.id}`) as HTMLInputElement | null;
    input?.focus();
    input?.select();
}
function cancelColumnRename() {
    if (form.processing) return;
    renamingColumnId.value = null;
    form.clearErrors();
}
function saveColumnName() {
    if (!renamingColumnId.value || form.processing) return;
    if (form.name.trim() === selectedColumn.value?.name) {
        cancelColumnRename();
        return;
    }
    submit();
}
function deleteColumn(column: BoardColumn) {
    reset('column.delete');
    form.id = column.id;
    form.name = column.name;
    renamingColumnId.value = null;
    editor.value = 'delete-column';
}
function openTaskCard(event: MouseEvent, column: BoardColumn, task: BoardTask) {
    if (form.processing || editor.value || (event.target as HTMLElement).closest('a, button')) return;
    editTask(column, task);
}
function confirmDelete() {
    form.clearErrors();
    form.action = 'task.delete';
    editor.value = 'delete-task';
}
function submit() {
    if (form.processing) return;
    // const message = form.action.endsWith('.delete') ? 'Deleted' : form.action.endsWith('.move') ? 'Moved' : 'Saved';
    form.transform(data => ({ ...data, _method: 'put' })).post(`/projects/${props.project.id}/board`, {
        preserveScroll: true, errorBag: 'board',
        onSuccess: () => {
            form.attachments = [];
            form.removed_attachment_ids = [];
            editor.value = null;
            renamingColumnId.value = null;
        },
        onError: errors => {
            if (!editor.value && !errors.revision) {
                toast.error(String(Object.values(errors)[0] ?? 'The board could not be updated. Try again.'));
                form.clearErrors();
            }
        },
        onFinish: syncColumns,
    });
}
function startDrag(event: DraggableEvent<BoardTask | BoardColumn>, kind: 'task' | 'column') {
    dragged.value = { kind, id: event.data.id, revision: props.project.revision };
}
function finishDrag(event: DraggableEvent) {
    const item = dragged.value;
    dragged.value = null;
    if (!item || form.processing || event.newDraggableIndex === undefined || (event.from === event.to && event.oldDraggableIndex === event.newDraggableIndex)) {
        syncColumns();
        return;
    }
    reset(`${item.kind}.move`);
    if (item.kind === 'task') form.column_id = event.to.dataset.columnId ?? '';
    form.position = event.newDraggableIndex;
    form.id = item.id;
    form.revision = item.revision;
    submit();
}
watch(() => [props.targetTaskId, props.project.id], () => {
    if (!props.targetTaskId) return;
    const column = props.project.board_columns?.find(column => column.tasks.some(task => task.id === props.targetTaskId));
    const task = column?.tasks.find(task => task.id === props.targetTaskId);
    if (column && task) editTask(column, task);
}, { immediate: true });
function reload() {
    router.reload({ only: ['selectedProject'], onSuccess: () => { editor.value = null; form.clearErrors(); } });
}
</script>

<template>
    <div class="grid min-w-0 gap-6 pb-8" :aria-busy="form.processing">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <h2 class="text-2xl font-semibold tracking-[-0.03em]">Board</h2>
            <div class="flex flex-wrap gap-2"><BulkIdeas :project="project" /><Button type="button" variant="outline" :disabled="form.processing" @click="editColumn()"><PlusIcon aria-hidden="true" />Add column</Button></div>
        </div>
        <Alert v-if="form.hasErrors && !editor" variant="destructive" role="alert">
            <AlertDescription><p v-for="(error, key) in form.errors" :key="key">{{ error }}</p><Button v-if="form.errors.revision" type="button" variant="outline" @click="reload">Reload board</Button></AlertDescription>
        </Alert>
        <div v-if="!columns.length" class="flex flex-col items-center rounded-[1.25rem] border border-black/8 bg-neutral-50 px-6 py-14 text-center dark:border-white/10 dark:bg-neutral-800">
            <span class="flex size-12 items-center justify-center rounded-2xl bg-background text-muted-foreground"><PlusIcon class="size-5" aria-hidden="true" /></span>
            <h3 class="mt-4 text-base font-semibold">No columns yet</h3>
            <p class="mt-1 max-w-sm text-sm text-muted-foreground">Add a column to start organizing tasks.</p>
            <Button type="button" class="mt-5" :disabled="form.processing" @click="editColumn()"><PlusIcon aria-hidden="true" />Add column</Button>
        </div>
        <VueDraggable v-else v-model="columns" handle="[data-column-handle]" direction="horizontal" :animation="150" :disabled="form.processing || !!editor || !!renamingColumnId" ghost-class="opacity-50" class="flex min-w-0 gap-4 overflow-x-auto pb-4" role="region" aria-label="Task board" tabindex="0" @start="startDrag($event, 'column')" @end="finishDrag">
            <section v-for="column in columns" :key="column.id" class="flex w-[min(18rem,82vw)] shrink-0 flex-col gap-3 rounded-[1.25rem] border border-black/8 bg-neutral-50 p-3 dark:border-white/10 dark:bg-neutral-800" :aria-labelledby="`column-${column.id}`">
                <div class="flex min-w-0 items-center gap-2 px-1 pt-1">
                    <span data-column-handle aria-hidden="true" class="cursor-grab text-muted-foreground active:cursor-grabbing"><GripVerticalIcon class="size-4" /></span>
                    <h3 :id="`column-${column.id}`" class="min-w-0 flex-1 break-words text-sm font-semibold tracking-[-0.01em]">
                        <Input v-if="renamingColumnId === column.id" :id="`column-name-${column.id}`" v-model="form.name" maxlength="100" :aria-label="`Rename ${column.name} column`" :aria-invalid="!!form.errors.name" :disabled="form.processing" class="h-8" @keydown.enter.prevent="saveColumnName" @keydown.esc.stop.prevent="cancelColumnRename" @blur="cancelColumnRename" />
                        <button v-else type="button" class="w-full cursor-text rounded text-left hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring" :aria-label="`Rename ${column.name} column`" :disabled="form.processing || !!editor" @click="renameColumn(column)">{{ column.name }}</button>
                    </h3>
                    <Badge variant="secondary" class="rounded-full">{{ column.tasks.length }}</Badge>
                    <Button type="button" variant="ghost" size="icon-sm" :aria-label="`Delete ${column.name} column`" :disabled="form.processing" @click="deleteColumn(column)"><Trash2Icon aria-hidden="true" /></Button>
                </div>
                <Button type="button" :variant="column.tasks.length ? 'outline' : 'ghost'" class="w-full justify-center rounded-xl" :aria-label="`Add task to ${column.name}`" :disabled="form.processing" @click="editTask(column)"><PlusIcon aria-hidden="true" />Add task</Button>
                <VueDraggable v-model="column.tasks" tag="ol" :group="`project-${project.id}-tasks`" :data-column-id="column.id" handle="[data-task-handle]" :animation="150" :disabled="form.processing || !!editor || !!renamingColumnId" filter="button, a, input, textarea, select" :prevent-on-filter="false" ghost-class="opacity-50" class="grid min-h-12 content-start gap-3" :aria-label="`${column.name} tasks`" @start="startDrag($event, 'task')" @end="finishDrag">
                    <li v-for="task in column.tasks" :key="task.id" class="cursor-pointer rounded-xl border border-black/8 bg-white p-3 shadow-xs dark:border-white/10 dark:bg-neutral-900" @click="openTaskCard($event, column, task)">
                        <div class="flex items-start gap-2">
                            <span data-task-handle aria-hidden="true" class="cursor-grab text-muted-foreground active:cursor-grabbing" @click.stop><GripVerticalIcon class="size-4" /></span>
                            <button type="button" class="min-w-0 flex-1 break-words text-left text-sm font-semibold focus-visible:rounded focus-visible:outline-2 focus-visible:outline-ring" :aria-label="`Edit task: ${task.title}`" :disabled="form.processing" @click="editTask(column, task)">{{ task.title }}</button>
                        </div>
                        <MarkdownContent v-if="task.description" :html="task.description_html" class="mt-2 max-h-40 overflow-hidden" />
                        <ul v-if="task.attachments.length" class="mt-3 grid gap-2" aria-label="Attachments">
                            <li v-for="file in task.attachments" :key="file.id">
                                <a :href="attachmentUrl(task.id, file.id)" download class="flex items-center gap-2 text-sm underline underline-offset-4" :title="file.name" :draggable="false"><PaperclipIcon class="size-4 shrink-0" aria-hidden="true" /><span class="truncate">{{ file.name }}</span></a>
                            </li>
                        </ul>
                    </li>
                </VueDraggable>
            </section>
        </VueDraggable>
    </div>
    <Dialog :open="!!editor" @update:open="value => { if (!value && !form.processing) editor = null; }">
        <DialogContent class="max-h-[calc(100dvh-2rem)] overflow-y-auto">
            <DialogHeader>
                <DialogTitle>{{ dialogTitle }}</DialogTitle>
                <DialogDescription v-if="editor === 'delete-task'">“{{ form.title }}” will be permanently deleted.</DialogDescription>
                <DialogDescription v-else-if="editor === 'delete-column'">{{ selectedColumn?.tasks.length ? 'Move the remaining tasks to another column before deleting this one.' : `Delete the empty “${form.name}” column.` }}</DialogDescription>
                <DialogDescription v-else class="sr-only">{{ editor === 'task' ? 'Task details' : 'Column name' }}</DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" novalidate @submit.prevent="submit">
                <Alert v-if="form.hasErrors" variant="destructive" role="alert">
                    <AlertDescription><p v-for="(error, key) in form.errors" :key="key">{{ error }}</p><Button v-if="form.errors.revision" type="button" variant="outline" @click="reload">Reload board</Button></AlertDescription>
                </Alert>
                <FieldGroup v-if="editor === 'task'">
                    <Field><FieldLabel for="task-title">Title</FieldLabel><Input id="task-title" v-model="form.title" maxlength="255" :aria-invalid="!!form.errors.title" :disabled="form.processing" /></Field>
                    <Field>
                        <div class="flex items-center justify-between gap-2">
                            <FieldLabel for="task-description">Description (Markdown)</FieldLabel>
                            <Button type="button" variant="ghost" size="sm" :aria-pressed="previewOpen" :disabled="preview.processing" @click="togglePreview">{{ previewOpen ? 'Write' : 'Preview' }}</Button>
                        </div>
                        <template v-if="previewOpen">
                            <p v-if="preview.processing" role="status" class="text-sm text-muted-foreground">Loading preview…</p>
                            <p v-else-if="preview.errors.description" role="alert" class="text-sm text-destructive">{{ preview.errors.description }}</p>
                            <MarkdownContent v-else-if="previewHtml" :html="previewHtml" />
                            <p v-else class="text-sm text-muted-foreground">Nothing to preview.</p>
                        </template>
                        <Textarea v-else id="task-description" v-model="form.description" maxlength="10000" :rows="6" :aria-invalid="!!form.errors.description" :disabled="form.processing" />
                    </Field>
                    <Field>
                        <FieldLabel for="task-attachments">Attachments</FieldLabel>
                        <Input id="task-attachments" :key="attachmentPickerKey" type="file" multiple :disabled="form.processing" :aria-invalid="!!form.errors.attachments" aria-describedby="attachment-limits" @change="attachFiles" />
                        <p id="attachment-limits" class="text-sm text-muted-foreground">Up to 10 files, 10 MB each.</p>
                        <ul v-if="retainedAttachments.length || form.attachments.length" class="grid gap-2" aria-label="Task attachments">
                            <li v-for="file in retainedAttachments" :key="file.id" class="flex min-w-0 items-center gap-2">
                                <a :href="attachmentUrl(form.id!, file.id)" download class="min-w-0 flex-1 truncate underline underline-offset-4" :title="file.name">{{ file.name }}</a>
                                <span class="shrink-0 text-xs text-muted-foreground">{{ fileSize(file.size) }}</span>
                                <Button type="button" variant="ghost" size="icon-sm" :aria-label="`Remove attachment: ${file.name}`" :disabled="form.processing" @click="form.removed_attachment_ids.push(file.id)"><XIcon aria-hidden="true" /></Button>
                            </li>
                            <li v-for="(file, index) in form.attachments" :key="index" class="flex min-w-0 items-center gap-2">
                                <span class="min-w-0 flex-1 truncate" :title="file.name">{{ file.name }}</span>
                                <span class="shrink-0 text-xs text-muted-foreground">{{ fileSize(file.size) }} · New</span>
                                <Button type="button" variant="ghost" size="icon-sm" :aria-label="`Remove pending attachment: ${file.name}`" :disabled="form.processing" @click="form.attachments.splice(index, 1)"><XIcon aria-hidden="true" /></Button>
                            </li>
                        </ul>
                        <progress v-if="form.progress" :value="form.progress.percentage" max="100" aria-label="Attachment upload progress" class="w-full">{{ form.progress.percentage }}%</progress>
                    </Field>
                </FieldGroup>
                <Field v-if="editor === 'task'">
                    <FieldLabel for="task-column">Column</FieldLabel>
                    <ChoiceSelect id="task-column" v-model="form.column_id" :aria-invalid="!!form.errors.column_id" :disabled="form.processing" :options="columns.map(column => ({ value: column.id, label: column.name }))" />
                </Field>
                <Field v-if="editor === 'column'"><FieldLabel for="column-name">Name</FieldLabel><Input id="column-name" v-model="form.name" maxlength="100" :aria-invalid="!!form.errors.name" :disabled="form.processing" /></Field>
                <Field v-if="editor === 'delete-column' && selectedColumn?.tasks.length">
                    <FieldLabel for="column-destination">Move remaining tasks to</FieldLabel>
                    <ChoiceSelect id="column-destination" v-model="form.destination_id" :aria-invalid="!!form.errors.destination_id" :disabled="form.processing" :options="[{ value: '', label: 'Choose a column', disabled: true }, ...destinations.map(column => ({ value: column.id, label: column.name }))]" />
                    <p v-if="!destinations.length" class="text-sm text-muted-foreground">Add another column before deleting this one.</p>
                </Field>
                <DialogFooter>
                    <Button v-if="form.id && editor === 'task'" type="button" variant="destructive" :disabled="form.processing" @click="confirmDelete">Delete task</Button>
                    <Button type="button" variant="outline" :disabled="form.processing" @click="editor = null">Cancel</Button>
                    <Button type="submit" :variant="deleting ? 'destructive' : 'default'" :disabled="form.processing || cannotDeleteColumn">{{ form.processing ? 'Saving…' : deleting ? (editor === 'delete-task' ? 'Delete task' : 'Delete column') : 'Save' }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
