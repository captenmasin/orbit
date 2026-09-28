<script setup lang="ts">
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { FieldLabel } from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import type { Project, ProjectSecret } from '@/types';
import { computed, onScopeDispose, ref, watch } from 'vue';
import { router, useHttp, usePage } from '@inertiajs/vue3';

const props = defineProps<{ project: Project; secret: ProjectSecret; native: boolean }>();
const page = usePage();
const draft = ref(props.secret.description ?? '');
const savedDescription = ref(draft.value);
const dirty = computed(() => draft.value !== savedDescription.value);
const saving = ref(false);
const error = ref('');
const needsReload = ref(false);
const reloading = ref(false);
const inputId = `secret-description-${props.secret.id}`;
const form = useHttp<{
    project_revision: number; revision: number; description: string;
}, { description: string | null; revision: number; project_revision: number; updated_at: string }>({
    project_revision: props.project.revision, revision: props.secret.revision, description: draft.value,
});
let saveTimer: ReturnType<typeof setTimeout> | undefined;
let pendingSave: Promise<boolean> | null = null;
let pendingNavigation: string | null = null;
let disposed = false;

defineExpose({ flush, dirty, saving });

watch(draft, () => {
    clearTimeout(saveTimer);
    if (needsReload.value) return;
    error.value = '';
    form.clearErrors();
    if (props.native && dirty.value) saveTimer = setTimeout(() => { void flush(); }, 800);
});
watch(() => props.native, native => {
    clearTimeout(saveTimer);
    if (!native || !dirty.value || needsReload.value) return;
    error.value = '';
    form.clearErrors();
    saveTimer = setTimeout(() => { void flush(); }, 800);
});
watch(() => props.secret.description, description => {
    if (dirty.value || saving.value) return;
    savedDescription.value = description ?? '';
    draft.value = savedDescription.value;
});

function flush(): Promise<boolean> {
    clearTimeout(saveTimer);
    if (pendingSave) return pendingSave;
    if (!dirty.value) return Promise.resolve(true);
    if (!props.native || needsReload.value || reloading.value || disposed) return Promise.resolve(false);
    saving.value = true;
    pendingSave = savePending().finally(() => {
        clearTimeout(saveTimer);
        pendingSave = null;
        saving.value = false;
    });
    return pendingSave;
}
async function savePending(): Promise<boolean> {
    while (dirty.value) {
        if (disposed || needsReload.value || !props.native) return false;
        error.value = '';
        form.clearErrors();
        const description = draft.value;
        Object.assign(form, { project_revision: props.project.revision, revision: props.secret.revision, description });
        try {
            const result = await form.put(`/projects/${props.project.id}/secrets/${props.secret.id}/description`, {
                onHttpException: response => { error.value = JSON.parse(response.data).message ?? 'Could not save the description. Try again.'; },
            });
            if (disposed) return false;
            if (!result) {
                error.value = Object.values(form.errors).flat().join(' ') || 'Could not save the description. Try again.';
                return false;
            }
            savedDescription.value = result.description ?? '';
            if (draft.value === description) draft.value = savedDescription.value;
            await new Promise<void>(resolve => {
                router.replaceProp('selectedProject', (current: Project) => current?.id === props.project.id ? {
                    ...current,
                    revision: Math.max(current.revision, result.project_revision),
                    secrets: current.secrets?.map(secret => secret.id === props.secret.id && secret.revision <= result.revision
                        ? { ...secret, description: result.description, revision: result.revision, updated_at: result.updated_at } : secret),
                } : current, { onFinish: () => resolve() });
            });
        } catch (cause) {
            if ((cause as { response?: { status?: number } })?.response?.status === 409) {
                needsReload.value = true;
                error.value = 'This secret changed. Reload the project before trying again. Your draft is kept.';
            } else if (!error.value) error.value = 'Could not save the description. Try again.';
            return false;
        }
    }
    return !disposed;
}
function reloadProject() {
    reloading.value = true;
    router.reload({
        only: ['selectedProject'],
        onSuccess: () => {
            needsReload.value = false;
            form.clearErrors();
            error.value = 'Reloaded the latest version. Your draft is still unsaved.';
        },
        onNetworkError: () => { error.value = 'Could not reload the project. Your draft is kept.'; },
        onFinish: () => { reloading.value = false; },
    });
}

const stopNavigationGuard = router.on('before', event => {
    if (event.detail.visit.method !== 'get' || (!dirty.value && !saving.value)) return;
    const destination = String(event.detail.visit.url);
    const currentUrl = new URL(page.url ?? '/', 'https://orbit.local');
    const targetUrl = new URL(destination, currentUrl);
    if (targetUrl.pathname === currentUrl.pathname && targetUrl.search === currentUrl.search) return;
    if (!props.native && dirty.value) {
        const message = 'Unlock secrets to save your description before leaving.';
        if (!needsReload.value) error.value = message;
        toast.error(message);
        pendingNavigation = null;
        return false;
    }
    pendingNavigation = destination;
    void flush().then(saved => {
        if (!saved || disposed) { pendingNavigation = null; return; }
        if (!pendingNavigation) return;
        const target = pendingNavigation;
        pendingNavigation = null;
        router.visit(target);
    });
    return false;
});
if (typeof window !== 'undefined') {
    const warnUnsaved = (event: BeforeUnloadEvent) => {
        if (dirty.value || saving.value) { event.preventDefault(); event.returnValue = ''; }
    };
    window.addEventListener('beforeunload', warnUnsaved);
    onScopeDispose(() => window.removeEventListener('beforeunload', warnUnsaved));
}
onScopeDispose(() => {
    disposed = true;
    clearTimeout(saveTimer);
    form.cancel();
    stopNavigationGuard();
});
</script>

<template>
    <div class="space-y-2">
        <FieldLabel :for="inputId">
            Description
        </FieldLabel>
        <Textarea
            :id="inputId"
            v-model="draft"
            maxlength="2000"
            :rows="4"
            :readonly="!native"
            placeholder="Add a description…"
            class="min-h-24 resize-none rounded-xl border-0 bg-muted px-3.5 py-3 text-[13px] shadow-none focus-visible:ring-2 focus-visible:ring-ring/50 md:text-[13px]"
            :aria-invalid="!!error"
            :aria-describedby="error ? `${inputId}-error` : undefined" />
        <div
            v-if="error"
            :id="`${inputId}-error`"
            role="alert"
            class="flex flex-wrap items-center gap-2 text-sm text-destructive">
            <span>{{ error }}</span>
            <Button
                v-if="needsReload"
                type="button"
                size="sm"
                variant="outline"
                :disabled="reloading"
                @click="reloadProject">
                {{ reloading ? 'Reloading…' : 'Reload project' }}
            </Button>
            <Button
                v-else
                type="button"
                size="sm"
                variant="outline"
                :disabled="saving || !native"
                @click="flush">
                Retry save
            </Button>
        </div>
    </div>
</template>
