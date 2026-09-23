<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, useForm, useHttp } from '@inertiajs/vue3';
import { VueDraggable, type DraggableEvent } from 'vue-draggable-plus';
import { ArrowLeftIcon, ArrowRightIcon, GripVerticalIcon, PaperclipIcon, PencilIcon, PlusIcon, XIcon } from '@lucide/vue';
import MarkdownContent from '@/components/MarkdownContent.vue';
import BulkIdeas from '@/components/BulkIdeas.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import type { BoardColumn, BoardTask, Project } from '@/types';

const props = defineProps<{ project: Project; targetTaskId?: string | null }>();
const columns = ref<BoardColumn[]>([]);
const editor = ref<'task' | 'column' | 'move-task' | 'delete-task' | 'delete-column' | null>(null);
const announcement = ref('');
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
const destinationTasks = computed(() => columns.value.find(column => column.id === form.column_id)?.tasks.filter(task => task.id !== form.id) ?? []);
const dialogTitle = computed(() => ({
    task: form.id ? 'Edit task' : 'New task', column: form.id ? 'Edit column' : 'New column',
    'move-task': 'Move task', 'delete-task': 'Delete task?', 'delete-column': 'Delete column?',
})[editor.value ?? 'task']);
const deleting = computed(() => editor.value?.startsWith('delete-'));
const cannotDeleteColumn = computed(() => editor.value === 'delete-column' && !!selectedColumn.value?.tasks.length && !form.destination_id);

function syncColumns() {
    columns.value = (props.project.board_columns ?? []).map(column => ({ ...column, tasks: [...column.tasks] }));
}
watch(() => props.project, syncColumns, { immediate: true });

watch(() => form.column_id, () => {
    if (editor.value === 'move-task') form.position = destinationTasks.value.length;
}, { flush: 'sync' });

function reset(action: string) {
    form.reset();
    form.clearErrors();
    form.action = action;
    form.revision = props.project.revision;
    announcement.value = '';
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
        if (!preview.hasErrors) preview.setError('description', 'The preview could not be loaded. Try again.');
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
function editColumn(column?: BoardColumn) {
    reset('column.save');
    form.id = column?.id ?? null;
    form.name = column?.name ?? '';
    editor.value = 'column';
}
function moveTask(task: BoardTask) {
    reset('task.move');
    form.id = task.id;
    form.title = task.title;
    form.column_id = task.board_column_id;
    form.position = task.position;
    editor.value = 'move-task';
}
function confirmDelete() {
    form.clearErrors();
    form.action = editor.value === 'task' ? 'task.delete' : 'column.delete';
    editor.value = editor.value === 'task' ? 'delete-task' : 'delete-column';
}
function submit() {
    if (form.processing) return;
    const message = form.action.endsWith('.delete') ? 'Deleted' : form.action.endsWith('.move') ? 'Moved' : 'Saved';
    form.transform(data => ({ ...data, _method: 'put' })).post(`/projects/${props.project.id}/board`, {
        preserveScroll: true, errorBag: 'board',
        onSuccess: () => {
            form.attachments = [];
            form.removed_attachment_ids = [];
            editor.value = null;
            announcement.value = message;
        },
        onFinish: syncColumns,
    });
}
function moveColumn(column: BoardColumn, position: number) {
    reset('column.move');
    form.id = column.id;
    form.position = position;
    submit();
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
    else announcement.value = 'This task is no longer available.';
}, { immediate: true });
function reload() {
    router.reload({ only: ['selectedProject'], onSuccess: () => { editor.value = null; form.clearErrors(); announcement.value = 'Board reloaded'; } });
}
</script>

<template>
    <div class="grid min-w-0 gap-4" :aria-busy="form.processing">
        <div class="flex items-center justify-between gap-2">
            <h2 class="text-lg font-semibold">Board</h2>
            <div class="flex flex-wrap gap-2"><BulkIdeas :project="project" /><Button type="button" variant="outline" :disabled="form.processing" @click="editColumn()"><PlusIcon aria-hidden="true" />Add column</Button></div>
        </div>
        <Alert v-if="form.hasErrors && !editor" variant="destructive" role="alert">
            <AlertDescription><p v-for="(error, key) in form.errors" :key="key">{{ error }}</p><Button v-if="form.errors.revision" type="button" variant="outline" @click="reload">Reload board</Button></AlertDescription>
        </Alert>
        <p v-if="!columns.length" class="text-sm text-muted-foreground">Add a column to start creating tasks.</p>
        <VueDraggable v-model="columns" handle="[data-column-handle]" direction="horizontal" :animation="150" :disabled="form.processing || !!editor" ghost-class="opacity-50" class="-mx-4 flex min-w-0 gap-4 overflow-x-auto px-5 pt-1 pb-4" role="region" aria-label="Task board" tabindex="0" @start="startDrag($event, 'column')" @end="finishDrag">
            <section v-for="(column, index) in columns" :key="column.id" class="flex w-72 shrink-0 flex-col gap-3 rounded-xl bg-muted p-3" :aria-labelledby="`column-${column.id}`">
                <div class="flex items-center gap-1">
                    <span data-column-handle aria-hidden="true" class="cursor-grab active:cursor-grabbing"><GripVerticalIcon class="size-4" /></span>
                    <h3 :id="`column-${column.id}`" class="min-w-0 flex-1 break-words font-medium">{{ column.name }}</h3>
                    <Badge variant="secondary">{{ column.tasks.length }}</Badge>
                    <Button type="button" variant="ghost" size="icon-sm" :aria-label="`Move ${column.name} left`" :disabled="form.processing || index === 0" @click="moveColumn(column, index - 1)"><ArrowLeftIcon aria-hidden="true" /></Button>
                    <Button type="button" variant="ghost" size="icon-sm" :aria-label="`Move ${column.name} right`" :disabled="form.processing || index === columns.length - 1" @click="moveColumn(column, index + 1)"><ArrowRightIcon aria-hidden="true" /></Button>
                    <Button type="button" variant="ghost" size="icon-sm" :aria-label="`Edit ${column.name} column`" :disabled="form.processing" @click="editColumn(column)"><PencilIcon aria-hidden="true" /></Button>
                </div>
                <VueDraggable v-model="column.tasks" tag="ol" :group="`project-${project.id}-tasks`" :data-column-id="column.id" :animation="150" :disabled="form.processing || !!editor" filter="button, a, input, textarea, select" :prevent-on-filter="false" ghost-class="opacity-50" class="grid min-h-12 content-start gap-3" :aria-label="`${column.name} tasks`" @start="startDrag($event, 'task')" @end="finishDrag">
                    <li v-for="task in column.tasks" :key="task.id">
                        <Card size="sm">
                            <CardHeader><CardTitle class="break-words">{{ task.title }}</CardTitle></CardHeader>
                            <CardContent v-if="task.description || task.attachments.length" class="grid gap-3">
                                <MarkdownContent v-if="task.description" :html="task.description_html" class="max-h-40 overflow-hidden" />
                                <ul v-if="task.attachments.length" class="grid gap-2" aria-label="Attachments">
                                    <li v-for="file in task.attachments" :key="file.id">
                                        <a :href="attachmentUrl(task.id, file.id)" download class="flex items-center gap-2 text-sm underline underline-offset-4" :title="file.name" :draggable="false"><PaperclipIcon class="size-4 shrink-0" aria-hidden="true" /><span class="truncate">{{ file.name }}</span></a>
                                    </li>
                                </ul>
                            </CardContent>
                            <CardFooter class="gap-2">
                                <Button type="button" variant="ghost" size="sm" :aria-label="`Edit task: ${task.title}`" :disabled="form.processing" @click="editTask(column, task)">Edit</Button>
                                <Button type="button" variant="outline" size="sm" :aria-label="`Move task: ${task.title}`" :disabled="form.processing" @click="moveTask(task)">Move</Button>
                            </CardFooter>
                        </Card>
                    </li>
                </VueDraggable>
                <Button type="button" variant="outline" :aria-label="`Add task to ${column.name}`" :disabled="form.processing" @click="editTask(column)"><PlusIcon aria-hidden="true" />Add task</Button>
            </section>
        </VueDraggable>
        <p role="status" aria-live="polite" class="text-sm text-muted-foreground">{{ form.processing ? 'Saving…' : announcement }}</p>
    </div>
    <Dialog :open="!!editor" @update:open="value => { if (!value && !form.processing) editor = null; }">
        <DialogContent class="max-h-[calc(100dvh-2rem)] overflow-y-auto">
            <DialogHeader>
                <DialogTitle>{{ dialogTitle }}</DialogTitle>
                <DialogDescription v-if="editor === 'delete-task'">“{{ form.title }}” will be permanently deleted.</DialogDescription>
                <DialogDescription v-else-if="editor === 'delete-column'">{{ selectedColumn?.tasks.length ? 'Move the remaining tasks to another column before deleting this one.' : `Delete the empty “${form.name}” column.` }}</DialogDescription>
                <DialogDescription v-else-if="editor === 'move-task'">{{ form.title }}</DialogDescription>
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
                <Field v-if="editor === 'task' || editor === 'move-task'">
                    <FieldLabel for="task-column">Column</FieldLabel>
                    <NativeSelect id="task-column" v-model="form.column_id" :aria-invalid="!!form.errors.column_id" :disabled="form.processing"><NativeSelectOption v-for="column in columns" :key="column.id" :value="column.id">{{ column.name }}</NativeSelectOption></NativeSelect>
                </Field>
                <Field v-if="editor === 'move-task'">
                    <FieldLabel for="task-position">Position</FieldLabel>
                    <NativeSelect id="task-position" :model-value="String(form.position)" :aria-invalid="!!form.errors.position" :disabled="form.processing" @update:model-value="form.position = Number($event)"><NativeSelectOption v-for="position in destinationTasks.length + 1" :key="position" :value="String(position - 1)">{{ position }} · {{ destinationTasks[position - 1] ? `Before ${destinationTasks[position - 1]!.title}` : 'Last' }}</NativeSelectOption></NativeSelect>
                </Field>
                <Field v-if="editor === 'column'"><FieldLabel for="column-name">Name</FieldLabel><Input id="column-name" v-model="form.name" maxlength="100" :aria-invalid="!!form.errors.name" :disabled="form.processing" /></Field>
                <Field v-if="editor === 'delete-column' && selectedColumn?.tasks.length">
                    <FieldLabel for="column-destination">Move remaining tasks to</FieldLabel>
                    <NativeSelect id="column-destination" v-model="form.destination_id" :aria-invalid="!!form.errors.destination_id" :disabled="form.processing"><NativeSelectOption value="" disabled>Choose a column</NativeSelectOption><NativeSelectOption v-for="column in destinations" :key="column.id" :value="column.id">{{ column.name }}</NativeSelectOption></NativeSelect>
                    <p v-if="!destinations.length" class="text-sm text-muted-foreground">Add another column before deleting this one.</p>
                </Field>
                <DialogFooter>
                    <Button v-if="form.id && (editor === 'task' || editor === 'column')" type="button" variant="destructive" :disabled="form.processing" @click="confirmDelete">{{ editor === 'task' ? 'Delete task' : 'Delete column' }}</Button>
                    <Button type="button" variant="outline" :disabled="form.processing" @click="editor = null">Cancel</Button>
                    <Button type="submit" :variant="deleting ? 'destructive' : 'default'" :disabled="form.processing || cannotDeleteColumn">{{ form.processing ? 'Saving…' : deleting ? (editor === 'delete-task' ? 'Delete task' : 'Delete column') : editor === 'move-task' ? 'Move task' : 'Save' }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
