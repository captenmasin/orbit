<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, useForm, useHttp } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { PencilIcon, PlusIcon, Trash2Icon } from '@lucide/vue';
import MarkdownContent from '@/components/MarkdownContent.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
const change = useForm({ action: 'delete', revision: props.project.revision, id: '', document_revision: 1 });
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
function deleteDocument(document: ProjectDocument) {
    Object.assign(change, { revision: props.project.revision, id: document.id, document_revision: document.revision });
    change.put(`/projects/${props.project.id}/documents`, { preserveScroll: true, errorBag: 'documentChange', onSuccess: () => {
        selectedId.value = documents.value[0]?.id ?? null;
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
    catch { if (!preview.hasErrors) { previewOpen.value = false; toast.error('The preview could not be loaded. Try again.'); } }
}
</script>

<template>
    <div class="flex min-w-0 flex-col gap-6 pb-8">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-2xl font-semibold tracking-[-0.03em]">Documents</h2>
                <p v-if="!documents.length" class="mt-1 text-sm leading-6 text-muted-foreground">Keep notes, instructions, and decisions together.</p>
            </div>
            <Button :disabled="editing || change.processing" @click="edit()"><PlusIcon aria-hidden="true" />New document</Button>
        </div>
        <div class="grid min-w-0 items-start gap-5" :class="documents.length ? 'lg:grid-cols-[17rem_minmax(0,1fr)]' : ''">
            <nav v-if="documents.length" aria-label="Project documents" class="min-w-0 rounded-[1.25rem] border border-black/8 bg-neutral-50 p-2 dark:border-white/10 dark:bg-neutral-800">
                <ul class="grid gap-1">
                    <li v-for="document in documents" :key="document.id" class="flex min-w-0 items-center gap-1">
                        <Button :variant="selectedId === document.id ? 'secondary' : 'ghost'" class="h-auto min-h-11 min-w-0 flex-1 justify-start rounded-xl px-3 py-2 text-left whitespace-normal" :aria-current="selectedId === document.id ? 'page' : undefined" :disabled="editing" @click="selectedId = document.id"><span class="break-words">{{ document.title }}</span></Button>
                    </li>
                </ul>
            </nav>
            <Card as="section" aria-label="Document" class="min-w-0">
                <CardContent class="min-h-72 gap-5 p-5 sm:p-7">
                    <Alert v-if="change.hasErrors || form.hasErrors" variant="destructive"><AlertDescription>
                        <p v-for="(error, key) in { ...change.errors, ...form.errors }" :key="key">{{ error }}</p>
                        <Button v-if="change.errors.revision || form.errors.revision" variant="outline" size="sm" class="mt-2" @click="reload">Reload saved documents and keep draft</Button>
                    </AlertDescription></Alert>
                    <form v-if="editing" class="space-y-6" @submit.prevent="save">
                        <h3 class="text-xl font-semibold tracking-[-0.025em]">{{ form.id ? 'Edit document' : 'New document' }}</h3>
                        <FieldGroup class="gap-5">
                            <Field><FieldLabel for="document-title">Document title</FieldLabel><Input id="document-title" v-model="form.title" maxlength="255" autofocus class="h-11 rounded-xl" :aria-invalid="!!form.errors.title" /></Field>
                            <Field>
                                <div class="flex flex-wrap items-center justify-between gap-2"><FieldLabel for="document-body">Markdown</FieldLabel><Button type="button" variant="ghost" size="sm" :aria-pressed="previewOpen" :disabled="preview.processing" @click="togglePreview">{{ previewOpen ? 'Write' : 'Preview' }}</Button></div>
                                <template v-if="previewOpen"><p v-if="preview.processing" role="status">Loading preview…</p><p v-else-if="preview.errors.body" role="alert">{{ preview.errors.body }}</p><MarkdownContent v-else :html="previewHtml" navigation /></template>
                                <Textarea v-else id="document-body" v-model="form.body" :rows="18" maxlength="50000" class="rounded-xl font-mono" :aria-invalid="!!form.errors.body" />
                            </Field>
                        </FieldGroup>
                        <div class="flex flex-wrap gap-2"><Button :disabled="form.processing">Save document</Button><Button type="button" variant="outline" :disabled="form.processing" @click="editing = false">Cancel</Button></div>
                    </form>
                    <template v-else-if="selected">
                        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-black/8 pb-5 dark:border-white/10"><h3 class="min-w-0 break-words text-xl font-semibold tracking-[-0.025em]">{{ selected.title }}</h3><div class="flex gap-2"><Button variant="outline" size="sm" :disabled="change.processing" @click="edit(selected)"><PencilIcon aria-hidden="true" />Edit</Button><Button variant="ghost" size="sm" :disabled="change.processing" @click="deleting = true"><Trash2Icon aria-hidden="true" />Delete</Button></div></div>
                        <MarkdownContent v-if="selected.body" :key="selected.id" :html="selected.body_html" navigation />
                        <p v-else class="text-sm text-muted-foreground">This document is empty. Edit it to add Markdown.</p>
                    </template>
                    <p v-else-if="missing" role="status" class="text-sm text-muted-foreground">This document was removed. Choose another document.</p>
                    <div v-else class="flex flex-1 flex-col items-center justify-center px-4 py-12 text-center">
                        <span class="flex size-12 items-center justify-center rounded-2xl bg-muted text-muted-foreground"><PlusIcon class="size-5" aria-hidden="true" /></span>
                        <h3 class="mt-4 text-base font-semibold">No documents yet</h3>
                        <p class="mt-1 max-w-sm text-sm text-muted-foreground">Add setup notes, decisions, and project reference material here.</p>
                        <Button variant="outline" class="mt-5" @click="edit()"><PlusIcon aria-hidden="true" />New document</Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
    <Dialog v-model:open="deleting"><DialogContent><DialogHeader><DialogTitle>Delete document?</DialogTitle><DialogDescription>“{{ selected?.title }}” will be permanently removed.</DialogDescription></DialogHeader><DialogFooter><Button variant="outline" @click="deleting = false">Cancel</Button><Button variant="destructive" :disabled="change.processing || !selected" @click="selected && deleteDocument(selected)">Delete document</Button></DialogFooter></DialogContent></Dialog>
</template>
