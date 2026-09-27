<script setup lang="ts">
import { computed, nextTick, onScopeDispose, ref, watch } from 'vue';
import { router, useForm, useHttp, usePage } from '@inertiajs/vue3';
import { SparklesIcon } from '@lucide/vue';
import { toast } from 'vue-sonner';
import ScratchpadActionsReview from '@/components/ScratchpadActionsReview.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import type { Project, ScratchpadAction } from '@/types';

const props = defineProps<{ project: Project }>();
const project = computed(() => props.project);
const page = usePage();
const scratchpadValues = () => ({ revision: project.value.revision, scratchpad: project.value.scratchpad ?? '' });
const scratchpadForm = useForm(scratchpadValues());
const scratchpadSave = useHttp<{ revision: number; scratchpad: string }, { revision: number; updated_at: string }>(scratchpadValues());
const actionPreview = useHttp<{ revision: number }, { actions: ScratchpadAction[]; statuses: string[] }>({ revision: project.value.revision });
const actionStatuses = ref<string[]>([]);
const actionNotice = ref('');
const actionNeedsReload = ref(false);
const actionReview = ref<{ begin: (actions: ScratchpadAction[]) => void } | null>(null);
const suggestedActions = ref<ScratchpadAction[]>([]);
const actionButtonLabel = computed(() => actionPreview.processing ? 'Generating actions' : suggestedActions.value.length ? `Review ${suggestedActions.value.length} suggested ${suggestedActions.value.length === 1 ? 'action' : 'actions'}` : 'Generate actions');
defineExpose({ saving: computed(() => scratchpadSave.processing) });

let saveTimer: ReturnType<typeof setTimeout> | undefined;
let previewTimer: ReturnType<typeof setTimeout> | undefined;
let previewAfterSave = false;
let pendingNavigation: string | null = null;
let lastPreviewedNote = '';
let lastPreviewAt = 0;
function queueScratchpadSave() {
    clearTimeout(saveTimer);
    if (scratchpadForm.isDirty && !scratchpadForm.hasErrors) saveTimer = setTimeout(saveScratchpad, 800);
}
function queueActionPreview() {
    clearTimeout(previewTimer);
    if (!scratchpadForm.scratchpad.trim() || scratchpadForm.scratchpad === lastPreviewedNote || scratchpadForm.hasErrors || actionPreview.processing) return;
    previewTimer = setTimeout(() => {
        if (!scratchpadSave.processing && !scratchpadForm.isDirty) void generateActions(false);
    }, Math.max(4000, lastPreviewAt ? lastPreviewAt + 10000 - Date.now() : 0));
}
const stopNavigationGuard = router.on('before', event => {
    if (event.detail.visit.method !== 'get' || !scratchpadForm.isDirty) return;
    const destination = String(event.detail.visit.url);
    const currentUrl = new URL(page.url ?? '/', 'http://orbit.local');
    const targetUrl = new URL(destination, currentUrl);
    if (targetUrl.pathname === currentUrl.pathname && targetUrl.search === currentUrl.search) return;
    pendingNavigation = destination;
    if (!scratchpadSave.processing) void saveScratchpad();
    return false;
});
if (typeof window !== 'undefined') {
    const warnUnsaved = (event: BeforeUnloadEvent) => {
        if (scratchpadForm.isDirty || scratchpadSave.processing) { event.preventDefault(); event.returnValue = ''; }
    };
    window.addEventListener('beforeunload', warnUnsaved);
    onScopeDispose(() => window.removeEventListener('beforeunload', warnUnsaved));
}
onScopeDispose(() => { clearTimeout(saveTimer); clearTimeout(previewTimer); stopNavigationGuard(); });
watch(() => scratchpadForm.scratchpad, () => {
    suggestedActions.value = [];
    actionNotice.value = '';
    scratchpadForm.clearErrors('scratchpad');
    if (scratchpadForm.isDirty) { queueScratchpadSave(); queueActionPreview(); }
});
watch(() => project.value.revision, () => {
    if (scratchpadForm.isDirty || scratchpadSave.processing) {
        scratchpadForm.revision = project.value.revision;
        scratchpadForm.defaults('revision', project.value.revision);
    } else {
        scratchpadForm.defaults(scratchpadValues());
        scratchpadForm.reset();
    }
});
watch(() => project.value.id, () => {
    clearTimeout(saveTimer);
    clearTimeout(previewTimer);
    previewAfterSave = false;
    pendingNavigation = null;
    lastPreviewedNote = '';
    scratchpadForm.defaults(scratchpadValues());
    scratchpadForm.reset();
    scratchpadForm.clearErrors();
    actionPreview.clearErrors();
    actionStatuses.value = [];
    actionNotice.value = '';
    actionNeedsReload.value = false;
    suggestedActions.value = [];
});
async function saveScratchpad() {
    clearTimeout(saveTimer);
    if (!scratchpadForm.isDirty || scratchpadSave.processing || scratchpadForm.errors.revision) return;
    scratchpadForm.clearErrors('scratchpad');
    const note = scratchpadForm.scratchpad;
    const projectId = project.value.id;
    Object.assign(scratchpadSave, { revision: scratchpadForm.revision, scratchpad: note });
    try {
        const result = await scratchpadSave.put(`/projects/${projectId}/scratchpad`);
        if (project.value.id !== projectId) return;
        if (!result) {
            scratchpadForm.setError(scratchpadSave.errors);
            if (!scratchpadForm.hasErrors) toast.error('Could not save notes. Try again.');
            pendingNavigation = null;
            previewAfterSave = false;
            return;
        }
        scratchpadForm.revision = result.revision;
        scratchpadForm.defaults({ revision: result.revision, scratchpad: note });
        if (scratchpadForm.scratchpad === note) scratchpadForm.reset();
        router.replaceProp('selectedProject', (current: Project) => current.id === projectId ? { ...current, revision: result.revision, scratchpad: note, updated_at: result.updated_at } : current);
        await nextTick();
        if (scratchpadForm.isDirty) {
            if (pendingNavigation) void saveScratchpad();
            else queueScratchpadSave();
        } else if (pendingNavigation) {
            const destination = pendingNavigation;
            pendingNavigation = null;
            router.visit(destination);
        } else if (previewAfterSave) { previewAfterSave = false; void generateActions(); }
        else queueActionPreview();
    } catch (error) {
        if (project.value.id !== projectId) return;
        if ((error as { response?: { status?: number } })?.response?.status === 409) scratchpadForm.setError('revision', 'This project changed. Reload the scratchpad before trying again.');
        else if (!scratchpadForm.hasErrors) toast.error('Could not save notes. Try again.');
        pendingNavigation = null;
        previewAfterSave = false;
    }
}
function reloadProject() {
    router.reload({ only: ['selectedProject'], onSuccess: () => {
        scratchpadForm.clearErrors('revision');
        actionNotice.value = '';
        actionNeedsReload.value = false;
    } });
}
async function generateActions(openReview = true) {
    clearTimeout(previewTimer);
    actionNotice.value = '';
    if (openReview && suggestedActions.value.length && !scratchpadForm.isDirty) {
        actionReview.value?.begin(suggestedActions.value);
        return;
    }
    if (scratchpadForm.isDirty) {
        previewAfterSave = openReview;
        saveScratchpad();
        return;
    }
    if (scratchpadSave.processing || actionPreview.processing || !scratchpadForm.scratchpad.trim()) return;
    const projectId = project.value.id;
    const note = scratchpadForm.scratchpad;
    const revision = project.value.revision;
    lastPreviewedNote = note;
    lastPreviewAt = Date.now();
    actionPreview.revision = project.value.revision;
    actionPreview.clearErrors();
    actionNeedsReload.value = false;
    try {
        const result = await actionPreview.post(`/projects/${project.value.id}/scratchpad/actions/preview`);
        if (project.value.id !== projectId || scratchpadForm.scratchpad !== note || project.value.revision !== revision) return;
        if (result?.actions.length) {
            actionStatuses.value = result.statuses;
            suggestedActions.value = result.actions;
            if (openReview) actionReview.value?.begin(result.actions);
        }
        else toast.error(Object.values(actionPreview.errors)[0] ?? 'No project actions found in these notes.');
    } catch (error) {
        if (project.value.id !== projectId || scratchpadForm.scratchpad !== note) return;
        actionNeedsReload.value = (error as { response?: { status?: number } })?.response?.status === 409;
        if (actionNeedsReload.value) actionNotice.value = 'This project changed. Reload it before generating actions.';
        else toast.error('Could not suggest actions. Try again.');
    } finally {
        if ((project.value.id !== projectId || scratchpadForm.scratchpad !== note) && !scratchpadForm.isDirty) queueActionPreview();
    }
}
function actionsSaved() {
    router.reload({ only: ['selectedProject'], onSuccess: () => { actionNotice.value = ''; } });
}
</script>

<template>
    <Card as="section" aria-labelledby="scratchpad-title">
        <CardHeader><h2 id="scratchpad-title" class="text-sm font-semibold">Scratchpad</h2></CardHeader>
        <CardContent class="focus-within:border-ring focus-within:ring-3 focus-within:ring-ring/50" :class="scratchpadForm.errors.scratchpad ? 'border-destructive focus-within:border-destructive focus-within:ring-destructive/20' : undefined">
            <form class="space-y-3" @submit.prevent="saveScratchpad()">
                <label for="project-scratchpad" class="sr-only">Scratchpad notes</label>
                <Textarea id="project-scratchpad" v-model="scratchpadForm.scratchpad" maxlength="50000" :rows="4" placeholder="Jot down ideas, reminders, and rough notes…" class="min-h-24 resize-none rounded-none border-0 bg-transparent p-0 font-mono shadow-none focus-visible:ring-0 aria-invalid:border-0 aria-invalid:ring-0 dark:bg-transparent" :aria-invalid="!!scratchpadForm.errors.scratchpad" :aria-describedby="scratchpadForm.errors.scratchpad ? 'scratchpad-error' : undefined" />
                <div class="flex items-center gap-2">
<!--                    <Button type="submit" size="sm" :disabled="scratchpadSave.processing || !scratchpadForm.isDirty || !!scratchpadForm.errors.revision">Save notes</Button>-->
                    <span role="status" class="text-xs text-muted-foreground">{{ scratchpadSave.processing ? 'Saving…' : '' }}</span>
                    <Button type="button" size="icon-sm" variant="ghost" class="ml-auto" :aria-label="actionButtonLabel" :title="actionButtonLabel" :disabled="scratchpadSave.processing || actionPreview.processing || !!scratchpadForm.errors.revision || !scratchpadForm.scratchpad.trim()" @click="generateActions()"><SparklesIcon aria-hidden="true" :class="actionPreview.processing ? 'animate-pulse' : undefined" /></Button>
                </div>
                <p v-if="scratchpadForm.errors.scratchpad" id="scratchpad-error" role="alert" class="text-sm text-destructive">{{ scratchpadForm.errors.scratchpad }}</p>
                <div v-if="scratchpadForm.errors.revision" role="alert" class="flex flex-wrap items-center gap-2 text-sm text-destructive"><span>{{ scratchpadForm.errors.revision }}</span><Button type="button" size="sm" variant="outline" @click="reloadProject">Reload project</Button></div>
                <div v-if="actionNotice" role="alert" class="flex flex-wrap items-center gap-2 text-sm text-destructive"><span>{{ actionNotice }}</span><Button v-if="actionNeedsReload" type="button" size="sm" variant="outline" @click="reloadProject">Reload project</Button></div>
            </form>
            <ScratchpadActionsReview ref="actionReview" :key="project.id" :project="project" :statuses="actionStatuses" @saved="actionsSaved" />
        </CardContent>
    </Card>
</template>
