<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, useForm, useHttp } from '@inertiajs/vue3';
import { ArrowDownIcon, ArrowUpIcon, PencilIcon, PlusIcon, Trash2Icon } from '@lucide/vue';
import MarkdownContent from '@/components/MarkdownContent.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import type { Project, ProjectDocument } from '@/types';

const props = defineProps<{ project: Project; targetDocumentId?: string | null }>();
const documents = computed(() => props.project.documents ?? []);
const selectedId = ref<string | null>(props.targetDocumentId ?? documents.value[0]?.id ?? null);
const selected = computed(() => documents.value.find(document => document.id === selectedId.value));
const missing = computed(() => !!selectedId.value && !selected.value);
const editing = ref(false);
const deleting = ref(false);
const previewOpen = ref(false);
const previewHtml = ref('');
const preview = useHttp<{ body: string }, { html: string }>({ body: '' });
const form = useForm({ action: 'save', revision: props.project.revision, id: null as string | null, document_revision: null as number | null, title: '', body: '' });
const change = useForm({ action: '', revision: props.project.revision, id: '', document_revision: 1, position: 0 });
watch(() => props.targetDocumentId, id => { if (!editing.value) selectedId.value = id ?? documents.value[0]?.id ?? null; });
watch(documents, list => { if (!selectedId.value && !editing.value) selectedId.value = list[0]?.id ?? null; });

function edit(document?: ProjectDocument) {
    form.defaults({ action: 'save', revision: props.project.revision, id: document?.id ?? null, document_revision: document?.revision ?? null, title: document?.title ?? '', body: document?.body ?? '' });
    form.reset();
    form.clearErrors();
    change.clearErrors();
    previewOpen.value = false;
    previewHtml.value = '';
    editing.value = true;
}
function save() {
    form.put(`/projects/${props.project.id}/documents`, { preserveScroll: true, errorBag: 'document', onSuccess: () => {
        editing.value = false;
        selectedId.value = form.id ?? documents.value.at(-1)?.id ?? null;
    } });
}
function mutate(action: 'move' | 'delete', document: ProjectDocument, position = 0) {
    Object.assign(change, { action, revision: props.project.revision, id: document.id, document_revision: document.revision, position });
    change.put(`/projects/${props.project.id}/documents`, { preserveScroll: true, errorBag: 'documentChange', onSuccess: () => {
        if (action === 'delete') selectedId.value = documents.value[0]?.id ?? null;
        deleting.value = false;
    } });
}
function reload() {
    router.reload({ only: ['selectedProject'], onSuccess: () => {
        form.revision = props.project.revision;
        form.document_revision = documents.value.find(document => document.id === form.id)?.revision ?? form.document_revision;
        form.clearErrors('revision');
        change.clearErrors();
    } });
}
async function togglePreview() {
    previewOpen.value = !previewOpen.value;
    if (!previewOpen.value) return;
    preview.body = form.body;
    preview.clearErrors();
    try { previewHtml.value = (await preview.post(`/projects/${props.project.id}/documents/preview`)).html; }
    catch { if (!preview.hasErrors) preview.setError('body', 'The preview could not be loaded. Try again.'); }
}
</script>

<template>
    <div class="grid min-w-0 items-start gap-5 lg:grid-cols-[14rem_minmax(0,1fr)]">
        <nav aria-label="Project documents" class="space-y-3">
            <Button variant="outline" :disabled="editing || change.processing" @click="edit()"><PlusIcon aria-hidden="true" />New document</Button>
            <ul class="space-y-1">
                <li v-for="(document, index) in documents" :key="document.id" class="flex items-center gap-1">
                    <Button :variant="selectedId === document.id ? 'secondary' : 'ghost'" class="min-w-0 flex-1 justify-start whitespace-normal text-left" :aria-current="selectedId === document.id ? 'page' : undefined" :disabled="editing" @click="selectedId = document.id"><span class="break-words">{{ document.title }}</span></Button>
                    <Button variant="ghost" size="icon-sm" :aria-label="`Move ${document.title} up`" :disabled="editing || change.processing || index === 0" @click="mutate('move', document, index - 1)"><ArrowUpIcon aria-hidden="true" /></Button>
                    <Button variant="ghost" size="icon-sm" :aria-label="`Move ${document.title} down`" :disabled="editing || change.processing || index === documents.length - 1" @click="mutate('move', document, index + 1)"><ArrowDownIcon aria-hidden="true" /></Button>
                </li>
            </ul>
        </nav>
        <section class="min-w-0 space-y-4 rounded-lg border p-5" aria-label="Document">
            <Alert v-if="change.hasErrors || form.hasErrors" variant="destructive"><AlertDescription>
                <p v-for="(error, key) in { ...change.errors, ...form.errors }" :key="key">{{ error }}</p>
                <Button v-if="change.errors.revision || form.errors.revision" variant="outline" size="sm" class="mt-2" @click="reload">Reload saved documents and keep draft</Button>
            </AlertDescription></Alert>
            <form v-if="editing" class="space-y-4" @submit.prevent="save">
                <FieldGroup>
                    <Field><FieldLabel for="document-title">Document title</FieldLabel><Input id="document-title" v-model="form.title" maxlength="255" autofocus :aria-invalid="!!form.errors.title" /></Field>
                    <Field>
                        <div class="flex flex-wrap items-center justify-between gap-2"><FieldLabel for="document-body">Markdown</FieldLabel><Button type="button" variant="ghost" size="sm" :aria-pressed="previewOpen" :disabled="preview.processing" @click="togglePreview">{{ previewOpen ? 'Write' : 'Preview' }}</Button></div>
                        <template v-if="previewOpen"><p v-if="preview.processing" role="status">Loading preview…</p><p v-else-if="preview.errors.body" role="alert">{{ preview.errors.body }}</p><MarkdownContent v-else :html="previewHtml" navigation /></template>
                        <Textarea v-else id="document-body" v-model="form.body" :rows="18" maxlength="50000" class="font-mono" :aria-invalid="!!form.errors.body" />
                    </Field>
                </FieldGroup>
                <div class="flex flex-wrap gap-2"><Button :disabled="form.processing">Save document</Button><Button type="button" variant="outline" :disabled="form.processing" @click="editing = false">Cancel</Button></div>
            </form>
            <template v-else-if="selected">
                <div class="flex flex-wrap items-start justify-between gap-3"><h2 class="min-w-0 break-words text-lg font-semibold">{{ selected.title }}</h2><div class="flex gap-2"><Button variant="outline" size="sm" :disabled="change.processing" @click="edit(selected)"><PencilIcon aria-hidden="true" />Edit</Button><Button variant="ghost" size="sm" :disabled="change.processing" @click="deleting = true"><Trash2Icon aria-hidden="true" />Delete</Button></div></div>
                <MarkdownContent v-if="selected.body" :key="selected.id" :html="selected.body_html" navigation />
                <p v-else class="text-sm text-muted-foreground">This document is empty. Edit it to add Markdown.</p>
            </template>
            <p v-else-if="missing" role="status" class="text-sm text-muted-foreground">This document was removed. Choose another document.</p>
            <p v-else class="text-sm text-muted-foreground">Add documents for setup instructions, deployment commands, decisions, and ideas.</p>
        </section>
    </div>
    <Dialog v-model:open="deleting"><DialogContent><DialogHeader><DialogTitle>Delete document?</DialogTitle><DialogDescription>“{{ selected?.title }}” will be permanently removed.</DialogDescription></DialogHeader><DialogFooter><Button variant="outline" @click="deleting = false">Cancel</Button><Button variant="destructive" :disabled="change.processing || !selected" @click="selected && mutate('delete', selected)">Delete document</Button></DialogFooter></DialogContent></Dialog>
</template>
