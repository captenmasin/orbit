<script setup lang="ts">
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import type { Project } from '@/types';
const props = defineProps<{ project: Project }>();
const open = ref(false);
const pasted = ref('');
const items = ref<{ title: string; selected: boolean }[]>([]);
const form = useForm({ action: 'task.bulk', revision: props.project.revision, column_id: '', titles: [] as string[] });
const existing = computed(() => new Set(props.project.board_columns?.flatMap(column => column.tasks.map(task => task.title)) ?? []));
const selected = computed(() => items.value.filter(item => item.selected).map(item => item.title.trim()));
const invalid = computed(() => !selected.value.length || selected.value.length > 200 || selected.value.some(title => !title || title.length > 255 || existing.value.has(title)) || new Set(selected.value).size !== selected.value.length);
function begin() {
    pasted.value = '';
    items.value = [];
    form.reset();
    form.clearErrors();
    form.revision = props.project.revision;
    form.column_id = props.project.board_columns?.[0]?.id ?? '';
    open.value = true;
}
function preview() {
    items.value = pasted.value.split(/\r\n|\r|\n/).map(line => line.replace(/^\s*(?:[-*+]\s+|\d+[.)]\s+)?(?:\[[ xX]\]\s*)?/, '').trim()).filter(Boolean).map(title => ({ title, selected: true }));
}
function save() {
    form.titles = selected.value;
    form.put(`/projects/${props.project.id}/board`, { preserveScroll: true, errorBag: 'bulkIdeas', onSuccess: () => { open.value = false; pasted.value = ''; items.value = []; } });
}
</script>

<template>
    <Button variant="outline" :disabled="!project.board_columns?.length" @click="begin">Paste ideas</Button>
    <Dialog :open="open" @update:open="value => { if (!form.processing) open = value; }"><DialogContent class="max-h-[calc(100dvh-2rem)] overflow-y-auto">
        <DialogHeader><DialogTitle>Paste ideas</DialogTitle><DialogDescription>Review one title per line, choose a column, and create the selected cards in source order.</DialogDescription></DialogHeader>
        <form class="grid gap-4" @submit.prevent="save">
            <Alert v-if="form.hasErrors" variant="destructive" role="alert"><AlertDescription><p v-for="message in form.errors" :key="message">{{ message }}</p></AlertDescription></Alert>
            <template v-if="!items.length"><Field><FieldLabel for="bulk-ideas-source">Idea list</FieldLabel><Textarea id="bulk-ideas-source" v-model="pasted" :rows="8" maxlength="52000" placeholder="- First idea&#10;- Second idea" /></Field><Button type="button" :disabled="!pasted.trim()" @click="preview">Preview titles</Button></template>
            <template v-else>
                <Field><FieldLabel for="bulk-ideas-column">Column</FieldLabel><NativeSelect id="bulk-ideas-column" v-model="form.column_id" :disabled="form.processing"><NativeSelectOption v-for="column in project.board_columns" :key="column.id" :value="column.id">{{ column.name }}</NativeSelectOption></NativeSelect></Field>
                <p class="text-sm text-muted-foreground">{{ selected.length }} selected · maximum 200. Deselect or rename duplicates.</p>
                <ol class="grid gap-3"><li v-for="(item, index) in items" :key="index" class="grid gap-1"><div class="flex items-center gap-2"><input v-model="item.selected" type="checkbox" :aria-label="`Include idea ${index + 1}`" :disabled="form.processing"><Input v-model="item.title" :aria-label="`Idea ${index + 1} title`" maxlength="255" :disabled="form.processing" /></div><p v-if="item.selected && (existing.has(item.title.trim()) || items.slice(0, index).some(previous => previous.selected && previous.title.trim() === item.title.trim()))" class="text-sm text-destructive">This title already exists in the project or list.</p></li></ol>
                <DialogFooter><Button type="button" variant="outline" :disabled="form.processing" @click="items = []">Back to list</Button><Button type="submit" :disabled="invalid || form.processing || !form.column_id">{{ form.processing ? 'Saving…' : 'Create selected cards' }}</Button></DialogFooter>
            </template>
            <Button type="button" variant="ghost" :disabled="form.processing" @click="open = false">Cancel</Button>
        </form>
    </DialogContent></Dialog>
</template>
