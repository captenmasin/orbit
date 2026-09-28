<script setup lang="ts">
import ChoiceSelect from '@/components/ChoiceSelect.vue';
import { toast } from 'vue-sonner';
import { useHttp } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Textarea } from '@/components/ui/textarea';
import type { Project, ScratchpadAction } from '@/types';
import { Field, FieldLabel } from '@/components/ui/field';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

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
        if (action.type === 'task') return { ...action, column_id: columns.value.find(column => column.value === action.column_id)?.value ?? '', selected: true };
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
    <Dialog
        :open="open"
        @update:open="value => { if (!form.processing) open = value; }">
        <DialogContent class="flex max-h-[calc(100dvh-2rem)] flex-col overflow-hidden sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Review suggested actions</DialogTitle>
                <DialogDescription>Edit the suggestions and choose what to save. Saving clears the scratchpad; cancel keeps your notes.</DialogDescription>
            </DialogHeader>
            <form
                class="flex min-h-0 flex-col gap-4"
                @submit.prevent="save">
                <div class="-m-1 grid min-h-0 gap-4 overflow-y-auto overscroll-contain p-1">
                    <Alert
                        v-if="form.hasErrors"
                        variant="destructive"
                        role="alert">
                        <AlertDescription>
                            <p
                                v-for="(message, key) in form.errors"
                                :key="key">
                                {{ message }}
                            </p>
                        </AlertDescription>
                    </Alert>
                    <ol class="grid gap-4">
                        <Card
                            v-for="(item, index) in items"
                            :key="index"
                            as="li"
                            size="sm">
                            <CardHeader class="pt-2">
                                <label class="flex min-w-0 items-center gap-2 text-sm font-normal">
                                    <Checkbox
                                        v-model="item.selected"
                                        :disabled="form.processing"
                                        :aria-label="`Include ${item.type === 'task' ? 'card' : item.type} ${index + 1}`" />
                                    <span class="capitalize">{{ item.type === 'project_detail' ? 'Project detail' : item.type === 'task' ? 'Card' : item.type }}</span>
                                    <span
                                        v-if="!item.selected"
                                        class="min-w-0 truncate text-muted-foreground">{{ item.type === 'link' ? item.label || item.url : item.type === 'project_detail' ? `${detailLabels[item.field]}: ${item.value}` : item.title }}</span>
                                </label>
                            </CardHeader>
                            <CardContent
                                v-if="item.selected"
                                class="grid gap-4"
                                :class="item.type === 'task' ? 'sm:grid-cols-[minmax(0,1fr)_10rem]' : 'sm:grid-cols-2'">
                                <template v-if="item.type === 'link'">
                                    <Field>
                                        <FieldLabel :for="`suggested-link-label-${index}`">
                                            Label
                                        </FieldLabel><Input
                                            :id="`suggested-link-label-${index}`"
                                            v-model="item.label"
                                            maxlength="255"
                                            :disabled="form.processing" />
                                    </Field>
                                    <Field>
                                        <FieldLabel :for="`suggested-link-url-${index}`">
                                            URL
                                        </FieldLabel><Input
                                            :id="`suggested-link-url-${index}`"
                                            v-model="item.url"
                                            maxlength="2048"
                                            :disabled="form.processing" />
                                    </Field>
                                    <Field class="sm:col-span-2">
                                        <FieldLabel :for="`suggested-link-notes-${index}`">
                                            Notes (optional)
                                        </FieldLabel><Textarea
                                            :id="`suggested-link-notes-${index}`"
                                            v-model="item.description"
                                            :rows="2"
                                            maxlength="10000"
                                            :disabled="form.processing" />
                                    </Field>
                                </template>
                                <template v-else-if="item.type === 'document'">
                                    <Field class="sm:col-span-2">
                                        <FieldLabel :for="`suggested-document-title-${index}`">
                                            Title
                                        </FieldLabel><Input
                                            :id="`suggested-document-title-${index}`"
                                            v-model="item.title"
                                            maxlength="255"
                                            :disabled="form.processing" />
                                    </Field>
                                    <Field class="sm:col-span-2">
                                        <FieldLabel :for="`suggested-document-body-${index}`">
                                            Markdown
                                        </FieldLabel><Textarea
                                            :id="`suggested-document-body-${index}`"
                                            v-model="item.body"
                                            :rows="4"
                                            maxlength="50000"
                                            :disabled="form.processing" />
                                    </Field>
                                </template>
                                <template v-else-if="item.type === 'task'">
                                    <Field>
                                        <FieldLabel :for="`suggested-task-title-${index}`">
                                            Title
                                        </FieldLabel><Input
                                            :id="`suggested-task-title-${index}`"
                                            v-model="item.title"
                                            maxlength="255"
                                            :disabled="form.processing" />
                                    </Field>
                                    <Field v-if="columns.length">
                                        <FieldLabel :for="`suggested-task-column-${index}`">
                                            Board list
                                        </FieldLabel><ChoiceSelect
                                            :id="`suggested-task-column-${index}`"
                                            v-model="item.column_id"
                                            :options="columns"
                                            placeholder="Choose a list"
                                            :disabled="form.processing" />
                                    </Field>
                                    <p
                                        v-else
                                        class="text-sm text-destructive">
                                        Add a board list or deselect this card.
                                    </p>
                                </template>
                                <template v-else>
                                    <Field class="sm:col-span-2">
                                        <FieldLabel :for="`suggested-detail-field-${index}`">
                                            Change
                                        </FieldLabel><ChoiceSelect
                                            :id="`suggested-detail-field-${index}`"
                                            :model-value="item.field"
                                            :options="detailFields"
                                            :disabled="form.processing"
                                            @update:model-value="item.field = $event as ProjectDetailField" />
                                    </Field>
                                    <Field
                                        v-if="item.field === 'status'"
                                        class="sm:col-span-2">
                                        <FieldLabel :for="`suggested-detail-value-${index}`">
                                            Status
                                        </FieldLabel><ChoiceSelect
                                            :id="`suggested-detail-value-${index}`"
                                            v-model="item.value"
                                            :options="statuses"
                                            :disabled="form.processing" />
                                    </Field>
                                    <Field
                                        v-else-if="item.field === 'description'"
                                        class="sm:col-span-2">
                                        <FieldLabel :for="`suggested-detail-value-${index}`">
                                            Description (replaces current text)
                                        </FieldLabel><Textarea
                                            :id="`suggested-detail-value-${index}`"
                                            v-model="item.value"
                                            :rows="3"
                                            maxlength="10000"
                                            :disabled="form.processing" />
                                    </Field>
                                    <Field
                                        v-else
                                        class="sm:col-span-2">
                                        <FieldLabel :for="`suggested-detail-value-${index}`">
                                            {{ item.field === 'tag' ? 'Tag to add' : 'Project name (replaces current name)' }}
                                        </FieldLabel><Input
                                            :id="`suggested-detail-value-${index}`"
                                            v-model="item.value"
                                            :maxlength="item.field === 'tag' ? 50 : 255"
                                            :disabled="form.processing" />
                                    </Field>
                                </template>
                            </CardContent>
                        </Card>
                    </ol>
                </div>
                <DialogFooter class="shrink-0">
                    <p class="self-center text-xs text-muted-foreground sm:mr-auto">
                        {{ selectedActions.length }} of {{ items.length }} selected
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="form.processing"
                        @click="open = false">
                        Cancel
                    </Button><Button
                        type="submit"
                        :disabled="!valid || form.processing">
                        {{ form.processing ? 'Saving…' : 'Save selected actions' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
