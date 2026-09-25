<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useHttp } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import ChoiceSelect from '@/components/ChoiceSelect.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import type { Project, ScratchpadAction } from '@/types';

type DraftAction =
    | { type: 'link'; label: string; url: string; description: string; selected: boolean }
    | { type: 'document'; title: string; body: string; selected: boolean }
    | { type: 'task'; title: string; column_id: string; selected: boolean }
    | { type: 'project_detail'; field: 'name' | 'description' | 'status' | 'tag'; value: string; selected: boolean };
type ProjectDetailField = Extract<DraftAction, { type: 'project_detail' }>['field'];

const props = defineProps<{ project: Project; statuses: string[] }>();
const emit = defineEmits<{ saved: [] }>();
const open = ref(false);
const items = ref<DraftAction[]>([]);
const form = useHttp<{ revision: number; actions: ScratchpadAction[] }, { revision: number }>({ revision: props.project.revision, actions: [] });
const columns = computed(() => (props.project.board_columns ?? []).map(column => ({ value: column.id, label: column.name })));
const detailLabels: Record<ProjectDetailField, string> = { name: 'Project name', description: 'Project description', status: 'Project status', tag: 'Add a tag' };
const detailFields = Object.entries(detailLabels).map(([value, label]) => ({ value, label }));
const selectedActions = computed<ScratchpadAction[]>(() => items.value.filter(item => item.selected).map(({ selected, ...action }) => action as ScratchpadAction));
const valid = computed(() => selectedActions.value.length > 0 && selectedActions.value.every(action => {
    if (action.type === 'link') return !!action.label.trim() && !!action.url.trim();
    if (action.type === 'document') return !!action.title.trim();
    if (action.type === 'task') return !!action.title.trim() && !!action.column_id;
    if (action.field === 'description') return true;
    return action.field === 'status' ? props.statuses.includes(action.value) : !!action.value.trim();
}));

function begin(actions: ScratchpadAction[]) {
    items.value = actions.map(action => {
        if (action.type === 'task') return { ...action, column_id: action.column_id ?? columns.value[0]?.value ?? '', selected: true };
        if (action.type === 'link') return { ...action, description: action.description ?? '', selected: true };
        return { ...action, selected: true };
    });
    form.revision = props.project.revision;
    form.actions = [];
    form.clearErrors();
    open.value = true;
}
defineExpose({ begin });
watch(() => props.project.id, () => { open.value = false; items.value = []; });

async function save() {
    if (!valid.value || form.processing) return;
    form.actions = selectedActions.value;
    form.clearErrors();
    try {
        const result = await form.post(`/projects/${props.project.id}/scratchpad/actions`);
        if (!result) return;
        open.value = false;
        emit('saved');
    } catch {
        if (!form.hasErrors) toast.error('Could not save these actions. Try again.');
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="value => { if (!form.processing) open = value; }">
        <DialogContent class="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Review suggested actions</DialogTitle>
                <DialogDescription>Edit or deselect suggestions. Saving creates the selected items and clears the scratchpad. Cancel keeps your notes.</DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="save">
                <Alert v-if="form.hasErrors" variant="destructive" role="alert"><AlertDescription><p v-for="(message, key) in form.errors" :key="key">{{ message }}</p></AlertDescription></Alert>
                <ol class="grid gap-3">
                    <li v-for="(item, index) in items" :key="index" class="grid gap-3 rounded-xl border border-border p-4">
                        <label class="flex min-w-0 items-center gap-2 text-sm font-medium">
                            <Checkbox v-model="item.selected" :disabled="form.processing" :aria-label="`Include ${item.type} ${index + 1}`" />
                            <span class="capitalize">{{ item.type === 'project_detail' ? 'Project detail' : item.type }}</span>
                            <span class="min-w-0 truncate font-normal text-muted-foreground">{{ item.type === 'link' ? item.label || item.url : item.type === 'project_detail' ? `${detailLabels[item.field]}: ${item.value}` : item.title }}</span>
                        </label>
                        <template v-if="item.selected">
                            <template v-if="item.type === 'link'">
                                <Field><FieldLabel :for="`suggested-link-label-${index}`">Label</FieldLabel><Input :id="`suggested-link-label-${index}`" v-model="item.label" maxlength="255" :disabled="form.processing" /></Field>
                                <Field><FieldLabel :for="`suggested-link-url-${index}`">URL</FieldLabel><Input :id="`suggested-link-url-${index}`" v-model="item.url" maxlength="2048" :disabled="form.processing" /></Field>
                                <Field><FieldLabel :for="`suggested-link-notes-${index}`">Notes (optional)</FieldLabel><Textarea :id="`suggested-link-notes-${index}`" v-model="item.description" :rows="2" maxlength="10000" :disabled="form.processing" /></Field>
                            </template>
                            <template v-else-if="item.type === 'document'">
                                <Field><FieldLabel :for="`suggested-document-title-${index}`">Title</FieldLabel><Input :id="`suggested-document-title-${index}`" v-model="item.title" maxlength="255" :disabled="form.processing" /></Field>
                                <Field><FieldLabel :for="`suggested-document-body-${index}`">Markdown</FieldLabel><Textarea :id="`suggested-document-body-${index}`" v-model="item.body" :rows="4" maxlength="50000" :disabled="form.processing" /></Field>
                            </template>
                            <template v-else-if="item.type === 'task'">
                                <Field><FieldLabel :for="`suggested-task-title-${index}`">Task</FieldLabel><Input :id="`suggested-task-title-${index}`" v-model="item.title" maxlength="255" :disabled="form.processing" /></Field>
                                <Field v-if="columns.length"><FieldLabel :for="`suggested-task-column-${index}`">Board column</FieldLabel><ChoiceSelect :id="`suggested-task-column-${index}`" v-model="item.column_id" :options="columns" :disabled="form.processing" /></Field>
                                <p v-else class="text-sm text-destructive">Add a board column or deselect this task.</p>
                            </template>
                            <template v-else>
                                <Field><FieldLabel :for="`suggested-detail-field-${index}`">Change</FieldLabel><ChoiceSelect :id="`suggested-detail-field-${index}`" :model-value="item.field" :options="detailFields" :disabled="form.processing" @update:model-value="item.field = $event as ProjectDetailField" /></Field>
                                <Field v-if="item.field === 'status'"><FieldLabel :for="`suggested-detail-value-${index}`">Status</FieldLabel><ChoiceSelect :id="`suggested-detail-value-${index}`" v-model="item.value" :options="statuses" :disabled="form.processing" /></Field>
                                <Field v-else-if="item.field === 'description'"><FieldLabel :for="`suggested-detail-value-${index}`">Description (replaces current text)</FieldLabel><Textarea :id="`suggested-detail-value-${index}`" v-model="item.value" :rows="3" maxlength="10000" :disabled="form.processing" /></Field>
                                <Field v-else><FieldLabel :for="`suggested-detail-value-${index}`">{{ item.field === 'tag' ? 'Tag to add' : 'Project name (replaces current name)' }}</FieldLabel><Input :id="`suggested-detail-value-${index}`" v-model="item.value" :maxlength="item.field === 'tag' ? 50 : 255" :disabled="form.processing" /></Field>
                            </template>
                        </template>
                    </li>
                </ol>
                <DialogFooter class="gap-2"><Button type="button" variant="outline" :disabled="form.processing" @click="open = false">Cancel</Button><Button type="submit" :disabled="!valid || form.processing">{{ form.processing ? 'Saving…' : 'Save selected actions' }}</Button></DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
