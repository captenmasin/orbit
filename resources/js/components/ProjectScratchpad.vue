<script setup lang="ts">
import TextTransition from '@/components/TextTransition.vue';
import ScratchpadActionsReview from '@/components/ScratchpadActionsReview.vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import type { Project, ScratchpadAction } from '@/types';
import { router, useForm, useHttp, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onScopeDispose, ref, watch } from 'vue';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { LineSquiggleIcon, LoaderCircleIcon, SparklesIcon } from '@lucide/vue';

const props = defineProps<{ project: Project }>();
const project = computed(() => props.project);
const page = usePage();
const scratchpadValues = () => ({ revision: project.value.revision, scratchpad: project.value.scratchpad ?? '' });
const scratchpadForm = useForm(scratchpadValues());
const scratchpadSave = useHttp<{ revision: number; scratchpad: string }, { revision: number; updated_at: string }>(scratchpadValues());
const actionPreview = useHttp<{ revision: number }, { actions: ScratchpadAction[]; statuses: string[] }>({ revision: project.value.revision });
const actionStatuses = ref<string[]>([]);
const actionNotice = ref('');
const saveError = ref('');
const actionNeedsReload = ref(false);
const actionReview = ref<{ begin: (actions: ScratchpadAction[]) => void } | null>(null);
const suggestedActions = ref<ScratchpadAction[]>([]);
const actionButtonLabel = computed(() => actionPreview.processing ? 'Generating actions' : suggestedActions.value.length ? `Review ${suggestedActions.value.length} suggested ${suggestedActions.value.length === 1 ? 'action' : 'actions'}` : 'Generate actions');
defineExpose({ saving: computed(() => scratchpadSave.processing) });

let saveTimer: ReturnType<typeof setTimeout> | undefined;
let previewAfterSave = false;
let pendingNavigation: string | null = null;
function queueScratchpadSave() {
    clearTimeout(saveTimer);
    if (scratchpadForm.isDirty && !scratchpadForm.hasErrors) saveTimer = setTimeout(saveScratchpad, 800);
}
const stopNavigationGuard = router.on('before', event => {
    if (event.detail.visit.method !== 'get' || !scratchpadForm.isDirty) return;
    const destination = String(event.detail.visit.url);
    const currentUrl = new URL(page.url ?? '/', 'https://orbit.local');
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
onScopeDispose(() => { clearTimeout(saveTimer); stopNavigationGuard(); });
watch(() => scratchpadForm.scratchpad, () => {
    suggestedActions.value = [];
    actionNotice.value = '';
    saveError.value = '';
    scratchpadForm.clearErrors('scratchpad');
    if (scratchpadForm.isDirty) { queueScratchpadSave(); }
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

    previewAfterSave = false;
    pendingNavigation = null;
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
    saveError.value = '';
    scratchpadForm.clearErrors('scratchpad');
    const note = scratchpadForm.scratchpad;
    const projectId = project.value.id;
    Object.assign(scratchpadSave, { revision: scratchpadForm.revision, scratchpad: note });
    try {
        const result = await scratchpadSave.put(`/projects/${projectId}/scratchpad`);
        if (project.value.id !== projectId) return;
        if (!result) {
            scratchpadForm.setError(scratchpadSave.errors);
            if (!scratchpadForm.hasErrors) saveError.value = 'Could not save notes. Try again.';
            pendingNavigation = null;
            previewAfterSave = false;
            return;
        }
        scratchpadForm.revision = result.revision;
        scratchpadForm.defaults({ revision: result.revision, scratchpad: note });
        if (scratchpadForm.scratchpad === note) scratchpadForm.reset();
        router.replaceProp('selectedProject', (current: Project) => current.id === projectId ? { ...current, revision: result.revision, scratchpad: note, updated_at: result.updated_at } : current);
        await nextTick();
        finishScratchpadSave();
    } catch (error) {
        if (project.value.id !== projectId) return;
        if ((error as { response?: { status?: number } })?.response?.status === 409) scratchpadForm.setError('revision', 'This project changed. Reload the scratchpad before trying again.');
        else if (!scratchpadForm.hasErrors) saveError.value = 'Could not save notes. Try again.';
        pendingNavigation = null;
        previewAfterSave = false;
    }
}
function finishScratchpadSave() {
    if (scratchpadForm.isDirty) {
        if (pendingNavigation) void saveScratchpad();
        else queueScratchpadSave();
    } else if (pendingNavigation) {
        const destination = pendingNavigation;
        pendingNavigation = null;
        router.visit(destination);
    } else if (previewAfterSave) { previewAfterSave = false; void generateActions(); }
}
function reloadProject() {
    router.reload({ only: ['selectedProject'], onSuccess: () => {
        scratchpadForm.clearErrors('revision');
        actionNotice.value = '';
        actionNeedsReload.value = false;
    } });
}
async function generateActions() {

    actionNotice.value = '';
    if (suggestedActions.value.length && !scratchpadForm.isDirty) {
        actionReview.value?.begin(suggestedActions.value);
        return;
    }
    if (scratchpadForm.isDirty) {
        previewAfterSave = true;
        saveScratchpad();
        return;
    }
    if (scratchpadSave.processing || actionPreview.processing || !scratchpadForm.scratchpad.trim()) return;
    const projectId = project.value.id;
    const note = scratchpadForm.scratchpad;
    const revision = project.value.revision;
    actionPreview.revision = project.value.revision;
    actionPreview.clearErrors();
    actionNeedsReload.value = false;
    try {
        const result = await actionPreview.post(`/projects/${project.value.id}/scratchpad/actions/preview`);
        if (project.value.id !== projectId || scratchpadForm.scratchpad !== note || project.value.revision !== revision) return;
        if (result?.actions.length) {
            actionStatuses.value = result.statuses;
            suggestedActions.value = result.actions;
            actionReview.value?.begin(result.actions);
        }
        else toast.error(Object.values(actionPreview.errors)[0] ?? 'No project actions found in these notes.');
    } catch (error) {
        if (project.value.id !== projectId || scratchpadForm.scratchpad !== note) return;
        actionNeedsReload.value = (error as { response?: { status?: number } })?.response?.status === 409;
        if (actionNeedsReload.value) actionNotice.value = 'This project changed. Reload it before generating actions.';
        else toast.error('Could not suggest actions. Try again.');
    }
}
function actionsSaved() {
    router.reload({ only: ['selectedProject'], onSuccess: () => { actionNotice.value = ''; } });
}
</script>

<template>
    <Card
        as="section"
        aria-labelledby="scratchpad-title">
        <CardHeader class="px-2 sm:px-3">
            <h2
                id="scratchpad-title"
                class="flex items-center gap-2 text-sm font-normal">
                <LineSquiggleIcon
                    class="size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true" />
                Scratchpad
            </h2>
        </CardHeader>
        <CardContent
            class="focus-within:border-ring focus-within:ring-3 focus-within:ring-ring/50"
            :class="scratchpadForm.errors.scratchpad ? 'border-destructive focus-within:border-destructive focus-within:ring-destructive/20' : undefined">
            <form
                class="space-y-3"
                @submit.prevent="saveScratchpad()">
                <label
                    for="project-scratchpad"
                    class="sr-only">Scratchpad notes</label>
                <Textarea
                    id="project-scratchpad"
                    v-model="scratchpadForm.scratchpad"
                    maxlength="50000"
                    :rows="4"
                    placeholder="Jot down ideas, reminders, and rough notes…"
                    class="min-h-24 resize-none rounded-none border-0 bg-transparent p-0 font-mono shadow-none focus-visible:ring-0 aria-invalid:border-0 aria-invalid:ring-0 dark:bg-transparent"
                    :aria-invalid="!!scratchpadForm.errors.scratchpad"
                    :aria-describedby="scratchpadForm.errors.scratchpad ? 'scratchpad-error' : undefined" />
                <div class="flex items-center gap-2">
<!--                    <span-->
<!--                        role="status"-->
<!--                        class="text-xs text-muted-foreground"><TextTransition-->
<!--                            :text="scratchpadSave.processing ? 'Saving…' : scratchpadForm.isDirty ? 'Unsaved' : 'Saved'"-->
<!--                            shimmer /></span>-->
                    <Button
                        type="button"
                        size="icon-sm"
                        variant="ghost"
                        class="ml-auto"
                        :aria-label="actionButtonLabel"
                        :title="actionButtonLabel"
                        :disabled="scratchpadSave.processing || actionPreview.processing || !!scratchpadForm.errors.revision || !scratchpadForm.scratchpad.trim()"
                        @click="generateActions()">
                        <span
                            class="t-icon-swap"
                            :data-state="actionPreview.processing ? 'b' : 'a'"
                            aria-hidden="true"><span
                                class="t-icon"
                                data-icon="a"><SparklesIcon /></span><span
                                    class="t-icon"
                                    data-icon="b"><LoaderCircleIcon :class="actionPreview.processing ? 'animate-spin motion-reduce:animate-none' : undefined" /></span></span>
                    </Button>
                </div>
                <div
                    v-if="saveError"
                    role="alert"
                    class="flex flex-wrap items-center gap-2 text-sm text-destructive">
                    <span>{{ saveError }}</span><Button
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="scratchpadSave.processing"
                        @click="saveScratchpad">
                        Retry save
                    </Button>
                </div>
                <p
                    v-if="scratchpadForm.errors.scratchpad"
                    id="scratchpad-error"
                    role="alert"
                    class="text-sm text-destructive">
                    {{ scratchpadForm.errors.scratchpad }}
                </p>
                <div
                    v-if="scratchpadForm.errors.revision"
                    role="alert"
                    class="flex flex-wrap items-center gap-2 text-sm text-destructive">
                    <span>{{ scratchpadForm.errors.revision }}</span><Button
                        type="button"
                        size="sm"
                        variant="outline"
                        @click="reloadProject">
                        Reload project
                    </Button>
                </div>
                <div
                    v-if="actionNotice"
                    role="alert"
                    class="flex flex-wrap items-center gap-2 text-sm text-destructive">
                    <span>{{ actionNotice }}</span><Button
                        v-if="actionNeedsReload"
                        type="button"
                        size="sm"
                        variant="outline"
                        @click="reloadProject">
                        Reload project
                    </Button>
                </div>
            </form>
            <ScratchpadActionsReview
                ref="actionReview"
                :key="project.id"
                :project="project"
                :statuses="actionStatuses"
                @saved="actionsSaved" />
        </CardContent>
    </Card>
</template>
