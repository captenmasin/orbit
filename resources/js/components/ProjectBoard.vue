<script setup lang="ts">
import {computed, nextTick, ref, watch} from 'vue';
import {router, useForm, useHttp, usePage} from '@inertiajs/vue3';
import {toast} from 'vue-sonner';
import {VueDraggable, type DraggableEvent} from 'vue-draggable-plus';
import {AlignLeftIcon, ArrowRightIcon, ChevronRightIcon, EllipsisIcon, PaperclipIcon, PlusIcon, SearchIcon, Trash2Icon, XIcon} from '@lucide/vue';
import {ContextMenuPortal, ContextMenuSeparator, ContextMenuSub, ContextMenuSubContent, ContextMenuSubTrigger, DropdownMenuContent, DropdownMenuItem, DropdownMenuPortal, DropdownMenuRoot, DropdownMenuTrigger} from 'reka-ui';
import MarkdownContent from '@/components/MarkdownContent.vue';
import BulkIdeas from '@/components/BulkIdeas.vue';
import {Alert, AlertDescription} from '@/components/ui/alert';
import {Button} from '@/components/ui/button';
import {ContextMenu, ContextMenuContent, ContextMenuItem, ContextMenuTrigger, contextMenuContentClass, contextMenuItemClass} from '@/components/ui/context-menu';
import {Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle} from '@/components/ui/dialog';
import {Field, FieldGroup, FieldLabel} from '@/components/ui/field';
import {Input} from '@/components/ui/input';
import ChoiceSelect from '@/components/ChoiceSelect.vue';
import {Textarea} from '@/components/ui/textarea';
import {reducedMotion} from '@/lib/appearance';
import {boardColumnColors as columnColors, boardColumnColor as columnColor} from '@/lib/project';
import type {BoardColumn, BoardTask, Project} from '@/types';

const props = defineProps<{ project: Project; targetTaskId?: string | null }>();
const page = usePage();
const columns = ref<BoardColumn[]>([]);
const editor = ref<'task' | 'column' | 'delete-task' | 'delete-column' | null>(null);
const cardMenuTaskId = ref<string | null>(null);
const dragged = ref<{ kind: 'task' | 'column'; id: string; revision: number } | null>(null);
const form = useForm(() => ({action: '', revision: props.project.revision, id: null as string | null, title: '', description: '', name: '', color: 'gray', column_id: '', position: 0, destination_id: '', attachments: [] as File[], removed_attachment_ids: [] as string[]}));
const quickAdd = useForm({action: 'task.save', revision: props.project.revision, column_id: '', title: ''});
const quickAddColumnId = ref<string | null>(null);
const query = ref('');
const busy = computed(() => form.processing || quickAdd.processing);
const taskCount = computed(() => columns.value.reduce((count, column) => count + column.tasks.length, 0));
const matchingTaskIds = computed(() => new Set(columns.value.flatMap(column => column.tasks)
    .filter(task => [task.title, task.description, ...task.attachments.map(file => file.name)].join(' ').toLowerCase().includes(query.value.trim().toLowerCase()))
    .map(task => task.id)));
let lastDragAt = 0;
const preview = useHttp<{ description: string }, { html: string }>({description: ''});
const previewOpen = ref(false);
const previewHtml = ref('');
const attachmentPickerKey = ref(0);
const attachmentInput = ref<HTMLInputElement | null>(null);
const selectedTask = computed(() => columns.value.flatMap(column => column.tasks).find(task => task.id === form.id));
const retainedAttachments = computed(() => selectedTask.value?.attachments.filter(file => !form.removed_attachment_ids.includes(file.id)) ?? []);
const selectedColumn = computed(() => columns.value.find(column => column.id === form.id));
const destinations = computed(() => columns.value.filter(column => column.id !== form.id));
const dialogTitle = computed(() => ({
    task: form.id ? 'Card details' : 'New card', column: form.id ? 'Edit list' : 'New list',
    'delete-task': 'Delete card?', 'delete-column': 'Delete list?',
})[editor.value ?? 'task']);
const deleting = computed(() => editor.value?.startsWith('delete-'));
const cannotDeleteColumn = computed(() => editor.value === 'delete-column' && !!selectedColumn.value?.tasks.length && !form.destination_id);

function syncColumns() {
    columns.value = (props.project.board_columns ?? []).map(column => ({...column, tasks: [...column.tasks]}));
}

watch(() => props.project, syncColumns, {immediate: true});

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
        if (!preview.hasErrors) {
            previewOpen.value = false;
            toast.error('The preview could not be loaded. Try again.');
        }
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
    if (busy.value) return;
    reset('task.save');
    form.id = task?.id ?? null;
    form.column_id = column.id;
    form.title = task?.title ?? '';
    form.description = task?.description ?? '';
    editor.value = 'task';
}

async function focusQuickAdd() {
    await nextTick();
    document.getElementById(`quick-add-${quickAddColumnId.value}`)?.focus();
}

async function openQuickAdd(column: BoardColumn) {
    if (busy.value || editor.value) return;
    query.value = '';
    quickAddColumnId.value = column.id;
    quickAdd.column_id = column.id;
    quickAdd.clearErrors();
    await focusQuickAdd();
}

function cancelQuickAdd() {
    if (quickAdd.processing) return;
    quickAddColumnId.value = null;
    quickAdd.reset();
    quickAdd.clearErrors();
}

function saveQuickAdd() {
    if (busy.value || editor.value || !quickAddColumnId.value) return;
    if (!quickAdd.title.trim()) {
        quickAdd.setError('title', 'Enter a card title.');
        return;
    }
    quickAdd.transform(data => ({...data, title: data.title.trim(), revision: props.project.revision, _method: 'put'})).post(`/projects/${props.project.id}/board`, {
        preserveScroll: true, errorBag: 'board',
        onSuccess: () => {
            quickAdd.reset('title');
            quickAdd.clearErrors();
        },
        onFinish: () => {
            syncColumns();
            void focusQuickAdd();
        },
    });
}

function editColumn(column?: BoardColumn) {
    if (busy.value) return;
    reset('column.save');
    form.id = column?.id ?? null;
    form.name = column?.name ?? '';
    form.color = column ? columnColor(column) : 'gray';
    editor.value = 'column';
}

function deleteColumn(column: BoardColumn) {
    reset('column.delete');
    form.id = column.id;
    form.name = column.name;
    editor.value = 'delete-column';
}

function openTaskCard(event: MouseEvent, column: BoardColumn, task: BoardTask) {
    if (busy.value || editor.value || cardMenuTaskId.value || dragged.value || Date.now() - lastDragAt < 250 || (event.target as HTMLElement).closest('a, button')) return;
    editTask(column, task);
}

function openCardMenu(event: KeyboardEvent) {
    if (event.key !== 'ContextMenu' && !(event.shiftKey && event.key === 'F10')) return;
    event.preventDefault();
    if (busy.value || editor.value || dragged.value) return;
    const card = event.currentTarget as HTMLElement;
    const bounds = card.getBoundingClientRect();
    card.dispatchEvent(new MouseEvent('contextmenu', {bubbles: true, cancelable: true, clientX: bounds.left + bounds.width / 2, clientY: bounds.top + bounds.height / 2}));
}

function confirmDelete() {
    form.clearErrors();
    form.action = 'task.delete';
    editor.value = 'delete-task';
}

function deleteTask(column: BoardColumn, task: BoardTask) {
    if (busy.value || editor.value) return;
    editTask(column, task);
    confirmDelete();
}

function moveTask(column: BoardColumn, task: BoardTask, destination: BoardColumn) {
    if (busy.value || editor.value || column.id === destination.id) return;
    reset('task.move');
    form.id = task.id;
    form.column_id = destination.id;
    form.position = destination.tasks.length;
    submit();
}

function submit() {
    if (busy.value) return;
    form.transform(data => ({...data, _method: 'put'})).post(`/projects/${props.project.id}/board`, {
        preserveScroll: true, errorBag: 'board',
        onSuccess: () => {
            form.attachments = [];
            form.removed_attachment_ids = [];
            editor.value = null;
            cardMenuTaskId.value = null;
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
    dragged.value = {kind, id: event.data.id, revision: props.project.revision};
}

function finishDrag(event: DraggableEvent) {
    const item = dragged.value;
    dragged.value = null;
    if (item) lastDragAt = Date.now();
    if (!item || busy.value || event.newDraggableIndex === undefined || (event.from === event.to && event.oldDraggableIndex === event.newDraggableIndex)) {
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
    if (column && task) {
        editTask(column, task);
        const url = new URL(page.url, 'http://orbit.local');
        url.searchParams.delete('task');
        router.replace({url: `${url.pathname}${url.search}${url.hash}`, preserveState: true, preserveScroll: true});
    }
}, {immediate: true});

function reload() {
    router.reload({
        only: ['selectedProject'], onSuccess: () => {
            editor.value = null;
            form.clearErrors();
            quickAdd.clearErrors();
        }
    });
}
</script>

<template>
    <div class="grid min-w-0 gap-4 pb-8" :aria-busy="busy">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div><h2 id="board-title" class="text-xl font-semibold tracking-[-0.025em]">Board</h2></div>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <BulkIdeas :show-trigger="true" :project="project"/>
                <div class="relative min-w-[240px] max-w-[20rem]">
                    <label for="board-card-search" class="sr-only">Search cards</label>
                    <SearchIcon class="pointer-events-none absolute top-1/2 left-3.5 size-3.5 -translate-y-1/2 text-muted-foreground" aria-hidden="true"/>
                    <Input id="board-card-search" v-model="query" maxlength="255" placeholder="Search cards" class="h-9 rounded-full border-0 bg-muted pl-9 text-[13px] shadow-none focus-visible:ring-2 focus-visible:ring-ring/50 md:text-[13px]" :disabled="busy"/>
                </div>
            </div>
        </div>
        <Alert v-if="form.hasErrors && !editor" variant="destructive" role="alert">
            <AlertDescription><p v-for="(error, key) in form.errors" :key="key">{{ error }}</p>
                <Button v-if="form.errors.revision" type="button" variant="outline" @click="reload">Reload board</Button>
            </AlertDescription>
        </Alert>
        <div v-if="!columns.length" class="flex flex-col items-center rounded-[1.25rem] border border-black/8 bg-neutral-50 px-6 py-14 text-center dark:border-white/10 dark:bg-neutral-800">
            <span class="flex size-12 items-center justify-center rounded-2xl bg-background text-muted-foreground"><PlusIcon class="size-5" aria-hidden="true"/></span>
            <h3 class="mt-4 text-sm font-semibold">No lists yet</h3>
            <Button type="button" class="mt-5" :disabled="busy" @click="editColumn()">
                <PlusIcon aria-hidden="true"/>
                Add a list
            </Button>
        </div>
        <div v-else class="-mx-4 flex min-w-0 items-start gap-4 overflow-x-auto px-4 pb-4 lg:-mx-6 lg:px-6" role="region" aria-label="Task board" tabindex="0">
            <VueDraggable v-model="columns" handle="[data-column-handle]" filter="button, input" :prevent-on-filter="false" direction="horizontal" :animation="reducedMotion ? 0 : 150" :disabled="busy || !!editor || !!cardMenuTaskId || !!query.trim()" ghost-class="opacity-50" class="flex shrink-0 items-start gap-4" @start="startDrag($event, 'column')" @end="finishDrag">
                <section v-for="column in columns" :key="column.id" class="flex w-[min(15rem,82vw)] shrink-0 flex-col gap-1.5 rounded-[10px] bg-neutral-100 p-2 dark:bg-neutral-800" :aria-labelledby="`column-${column.id}`">
                    <div data-column-handle class="flex h-8 min-w-0 cursor-grab items-center gap-2 active:cursor-grabbing">
                        <span class="size-1.5 shrink-0 rounded-full" :class="columnColors[columnColor(column)]?.dotClass ?? columnColors.gray.dotClass" aria-hidden="true"/>
                        <h3 :id="`column-${column.id}`" class="min-w-0 flex-1 break-words text-[13px] font-medium">
                            <span class="block truncate" :title="column.name">{{ column.name }}</span>
                        </h3>
                        <span class="text-xs tabular-nums text-muted-foreground">{{ column.tasks.length }}</span>
                        <DropdownMenuRoot>
                            <DropdownMenuTrigger as-child>
                                <Button type="button" variant="ghost" size="icon-sm" class="text-muted-foreground" :aria-label="`Actions for ${column.name} list`" :disabled="busy || !!editor">
                                    <EllipsisIcon class="size-4" aria-hidden="true"/>
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuPortal>
                                <DropdownMenuContent align="end" :side-offset="4" :class="contextMenuContentClass" @close-auto-focus="event => { if (editor) event.preventDefault(); }">
                                    <DropdownMenuItem :class="contextMenuItemClass" @select="editColumn(column)">Edit list</DropdownMenuItem>
                                    <DropdownMenuItem :class="contextMenuItemClass" class="text-destructive focus:bg-destructive/10 focus:text-destructive data-highlighted:bg-destructive/10 data-highlighted:text-destructive [&_svg]:text-destructive" @select="deleteColumn(column)">
                                        <Trash2Icon class="size-4" aria-hidden="true"/>
                                        Delete list
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenuPortal>
                        </DropdownMenuRoot>
                    </div>
                    <VueDraggable v-model="column.tasks" tag="ol" :group="`project-${project.id}-tasks`" :data-column-id="column.id" handle="[data-task-handle]" :animation="reducedMotion ? 0 : 150" :disabled="busy || !!editor || !!cardMenuTaskId || !!query.trim()" ghost-class="opacity-50" class="grid min-h-4 content-start gap-1.5" :aria-label="`${column.name} cards`" @start="startDrag($event, 'task')" @end="finishDrag">
                        <li v-for="task in column.tasks" v-show="!query.trim() || matchingTaskIds.has(task.id)" :key="task.id">
                            <ContextMenu @update:open="value => { if (value) cardMenuTaskId = task.id; else if (cardMenuTaskId === task.id) cardMenuTaskId = null; }">
                                <ContextMenuTrigger as-child :disabled="busy || !!editor || !!dragged">
                                    <div data-task-handle role="button" tabindex="0" aria-haspopup="menu" :aria-expanded="cardMenuTaskId === task.id" :aria-label="`Open card: ${task.title}`" :aria-disabled="busy" class="cursor-grab rounded-lg bg-white p-3 transition-colors hover:bg-white/70 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring active:cursor-grabbing dark:bg-neutral-900 dark:hover:bg-neutral-900/70" @click="openTaskCard($event, column, task)" @keydown="openCardMenu" @keydown.enter.prevent="editTask(column, task)" @keydown.space.prevent="editTask(column, task)">
                                        <p class="break-words text-[13px] leading-[19px] font-medium">{{ task.title }}</p>
                                        <div v-if="task.description || task.attachments.length" class="mt-2 flex items-center gap-3 text-xs text-muted-foreground">
                                            <AlignLeftIcon v-if="task.description" class="size-3.5" role="img" aria-label="Has description"/>
                                            <span v-if="task.attachments.length" class="flex items-center gap-1" :aria-label="`${task.attachments.length} attachments`"><PaperclipIcon class="size-3.5" aria-hidden="true"/>{{ task.attachments.length }}</span>
                                        </div>
                                    </div>
                                </ContextMenuTrigger>
                                <ContextMenuContent class="min-w-44" @close-auto-focus="event => { if (editor) event.preventDefault(); }">
                                    <ContextMenuItem :disabled="busy" @select="editTask(column, task)">
                                        <AlignLeftIcon aria-hidden="true"/>
                                        Open details
                                    </ContextMenuItem>
                                    <ContextMenuSub>
                                        <ContextMenuSubTrigger :disabled="busy || columns.length < 2" :class="contextMenuItemClass">
                                            <ArrowRightIcon class="size-4" aria-hidden="true"/>
                                            Move to list
                                            <ChevronRightIcon class="ml-auto size-4" aria-hidden="true"/>
                                        </ContextMenuSubTrigger>
                                        <ContextMenuPortal>
                                            <ContextMenuSubContent :class="contextMenuContentClass" class="max-h-(--reka-context-menu-content-available-height)">
                                                <ContextMenuItem v-for="destination in columns" :key="destination.id" :disabled="busy || destination.id === column.id" @select="moveTask(column, task, destination)">{{ destination.name }}</ContextMenuItem>
                                            </ContextMenuSubContent>
                                        </ContextMenuPortal>
                                    </ContextMenuSub>
                                    <ContextMenuSeparator class="mx-2.5 my-1.5 h-px bg-border/80"/>
                                    <ContextMenuItem variant="destructive" :disabled="busy" @select="deleteTask(column, task)">
                                        <Trash2Icon aria-hidden="true"/>
                                        Delete card
                                    </ContextMenuItem>
                                </ContextMenuContent>
                            </ContextMenu>
                        </li>
                    </VueDraggable>
                    <form v-if="quickAddColumnId === column.id" class="grid gap-2" @submit.prevent="saveQuickAdd">
                        <Textarea :id="`quick-add-${column.id}`" v-model="quickAdd.title" maxlength="255" :rows="3" placeholder="Enter a title for this card…" :aria-label="`New card title in ${column.name}`" :aria-invalid="!!quickAdd.errors.title" :aria-describedby="quickAdd.hasErrors ? `quick-add-errors-${column.id}` : undefined" :disabled="busy || !!editor" class="min-h-21 resize-none bg-background" @keydown.enter.exact.prevent="saveQuickAdd" @keydown.esc.stop.prevent="cancelQuickAdd"/>
                        <div v-if="quickAdd.hasErrors" :id="`quick-add-errors-${column.id}`" role="alert" class="grid gap-1 text-xs text-destructive"><p v-for="(error, key) in quickAdd.errors" :key="key">{{ error }}</p>
                            <Button v-if="quickAdd.errors.revision" type="button" size="sm" variant="outline" :disabled="busy" @click="reload">Reload board</Button>
                        </div>
                        <div class="flex items-center gap-1">
                            <Button type="submit" size="sm" :disabled="busy || !!editor">{{ quickAdd.processing ? 'Adding…' : 'Add card' }}</Button>
                            <Button type="button" variant="ghost" size="icon-sm" aria-label="Cancel adding card" :disabled="busy" @click="cancelQuickAdd">
                                <XIcon aria-hidden="true"/>
                            </Button>
                        </div>
                    </form>
                    <Button v-else type="button" variant="ghost" size="sm" class="w-full justify-start" :aria-label="`Add a card to ${column.name}`" :disabled="busy || !!editor" @click="openQuickAdd(column)">
                        <PlusIcon aria-hidden="true"/>
                        Add a card
                    </Button>
                </section>
            </VueDraggable>
            <Button type="button" variant="ghost" size="sm" :disabled="busy" @click="editColumn()">
                <PlusIcon aria-hidden="true"/>
                Add a list
            </Button>
        </div>
        <p v-if="query.trim() && !matchingTaskIds.size" role="status" class="text-sm text-muted-foreground">No cards match “{{ query }}”.</p>
        <Dialog :open="!!editor" @update:open="value => { if (!value && !form.processing) editor = null; }">
        <DialogContent v-bind="deleting ? {} : { 'aria-describedby': undefined }" class="max-h-[calc(100dvh-2rem)] overflow-y-auto" :class="editor === 'task' ? 'sm:max-w-2xl' : undefined">
                <DialogHeader>
                    <DialogTitle>{{ dialogTitle }}</DialogTitle>
                <DialogDescription v-if="editor === 'delete-task'">“{{ form.title }}” will be permanently deleted.</DialogDescription>
                <DialogDescription v-else-if="editor === 'delete-column'">{{ selectedColumn?.tasks.length ? 'Move the remaining cards to another list before deleting this one.' : `Delete the empty “${form.name}” list.` }}</DialogDescription>
                </DialogHeader>
                <form class="grid gap-4" novalidate @submit.prevent="submit">
                    <Alert v-if="form.hasErrors" variant="destructive" role="alert">
                        <AlertDescription><p v-for="(error, key) in form.errors" :key="key">{{ error }}</p>
                            <Button v-if="form.errors.revision" type="button" variant="outline" @click="reload">Reload board</Button>
                        </AlertDescription>
                    </Alert>
                    <FieldGroup v-if="editor === 'task'" class="gap-4">
                        <Field>
                            <FieldLabel for="task-title">Card title</FieldLabel>
                            <Input id="task-title" v-model="form.title" maxlength="255" placeholder="Card title" :aria-invalid="!!form.errors.title" :disabled="form.processing"/></Field>
                        <Field>
                            <FieldLabel for="task-column">In list</FieldLabel>
                            <ChoiceSelect id="task-column" v-model="form.column_id" :aria-invalid="!!form.errors.column_id" :disabled="form.processing" :options="columns.map(column => ({ value: column.id, label: column.name }))"/>
                        </Field>
                        <Field>
                            <div class="flex items-center justify-between gap-2">
                                <FieldLabel for="task-description">Description</FieldLabel>
                                <Button type="button" variant="ghost" size="sm" :aria-pressed="previewOpen" :disabled="preview.processing" @click="togglePreview">{{ previewOpen ? 'Write' : 'Preview' }}</Button>
                            </div>
                            <template v-if="previewOpen">
                                <p v-if="preview.processing" role="status" class="text-sm text-muted-foreground">Loading preview…</p>
                                <p v-else-if="preview.errors.description" role="alert" class="text-sm text-destructive">{{ preview.errors.description }}</p>
                                <MarkdownContent v-else-if="previewHtml" :html="previewHtml"/>
                                <p v-else class="text-sm text-muted-foreground">Nothing to preview.</p>
                            </template>
                            <Textarea v-else id="task-description" v-model="form.description" maxlength="10000" :rows="5" placeholder="Add a description… Markdown is supported." :aria-invalid="!!form.errors.description" :disabled="form.processing" class="min-h-30 resize-y"/>
                        </Field>
                        <Field>
                            <div class="flex items-center justify-between gap-2">
                                <FieldLabel for="task-attachments">Attachments <span class="ml-1 text-xs font-normal text-muted-foreground">{{ retainedAttachments.length + form.attachments.length || '' }}</span></FieldLabel>
                                <Button type="button" variant="outline" size="sm" :disabled="form.processing" aria-describedby="attachment-limits" @click="attachmentInput?.click()">
                                    <PlusIcon aria-hidden="true"/>
                                    Add attachment
                                </Button>
                            </div>
                            <input id="task-attachments" ref="attachmentInput" :key="attachmentPickerKey" type="file" multiple class="hidden" :disabled="form.processing" @change="attachFiles"/>
                            <ul v-if="retainedAttachments.length || form.attachments.length" class="grid gap-1.5" aria-label="Task attachments">
                                <li v-for="file in retainedAttachments" :key="file.id" class="flex min-w-0 items-center gap-3 rounded-md border p-2">
                                    <PaperclipIcon class="size-4 shrink-0 text-muted-foreground" aria-hidden="true"/>
                                    <a :href="attachmentUrl(form.id!, file.id)" download class="min-w-0 flex-1 truncate text-sm underline underline-offset-4" :title="file.name">{{ file.name }}</a>
                                    <span class="shrink-0 text-xs text-muted-foreground">{{ fileSize(file.size) }}</span>
                                    <Button type="button" variant="ghost" size="icon-sm" :aria-label="`Remove attachment: ${file.name}`" :disabled="form.processing" @click="form.removed_attachment_ids.push(file.id)">
                                        <XIcon aria-hidden="true"/>
                                    </Button>
                                </li>
                                <li v-for="(file, index) in form.attachments" :key="index" class="flex min-w-0 items-center gap-3 rounded-md border p-2">
                                    <PaperclipIcon class="size-4 shrink-0 text-muted-foreground" aria-hidden="true"/>
                                    <span class="min-w-0 flex-1 truncate text-sm" :title="file.name">{{ file.name }}</span>
                                    <span class="shrink-0 text-xs text-muted-foreground">{{ fileSize(file.size) }} · Pending</span>
                                    <Button type="button" variant="ghost" size="icon-sm" :aria-label="`Remove pending attachment: ${file.name}`" :disabled="form.processing" @click="form.attachments.splice(index, 1)">
                                        <XIcon aria-hidden="true"/>
                                    </Button>
                                </li>
                            </ul>
                            <p id="attachment-limits" class="text-xs text-muted-foreground">Up to 10 files, 10 MB each.</p>
                            <div v-if="form.progress" class="grid gap-1.5">
                                <div class="flex items-center justify-between text-xs text-muted-foreground"><span>Uploading attachments…</span><span class="tabular-nums">{{ form.progress.percentage }}%</span></div>
                                <div role="progressbar" aria-label="Attachment upload progress" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="form.progress.percentage" class="h-1 overflow-hidden rounded-full bg-muted">
                                    <div class="h-full rounded-full bg-foreground transition-[width] duration-200" :style="{ width: `${form.progress.percentage}%` }"/>
                                </div>
                            </div>
                        </Field>
                    </FieldGroup>
                    <template v-if="editor === 'column'">
                        <Field>
                            <FieldLabel for="column-name">List name</FieldLabel>
                            <Input id="column-name" v-model="form.name" maxlength="100" :aria-invalid="!!form.errors.name" :disabled="form.processing"/></Field>
                        <Field>
                            <FieldLabel for="column-color">Dot colour</FieldLabel>
                            <ChoiceSelect id="column-color" v-model="form.color" :disabled="form.processing" :aria-invalid="!!form.errors.color" :options="Object.entries(columnColors).map(([value, color]) => ({ value, ...color }))" />
                        </Field>
                    </template>
                    <Field v-if="editor === 'delete-column' && selectedColumn?.tasks.length">
                        <FieldLabel for="column-destination">Move remaining tasks to</FieldLabel>
                        <ChoiceSelect id="column-destination" v-model="form.destination_id" :aria-invalid="!!form.errors.destination_id" :disabled="form.processing" :options="[{ value: '', label: 'Choose a column', disabled: true }, ...destinations.map(column => ({ value: column.id, label: column.name }))]"/>
                        <p v-if="!destinations.length" class="text-sm text-muted-foreground">Add another list before deleting this one.</p>
                    </Field>
                    <DialogFooter>
                        <Button v-if="form.id && editor === 'task'" type="button" variant="ghost" class="sm:mr-auto" :disabled="form.processing" @click="confirmDelete">
                            <Trash2Icon aria-hidden="true"/>
                            Delete card
                        </Button>
                        <Button type="button" variant="outline" :disabled="form.processing" @click="editor = null">Cancel</Button>
                    <Button type="submit" :variant="deleting ? 'destructive' : 'default'" :disabled="form.processing || cannotDeleteColumn">{{ form.processing ? 'Saving…' : deleting ? (editor === 'delete-task' ? 'Delete card' : 'Delete list') : editor === 'task' || form.id ? 'Save changes' : 'Add list' }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
