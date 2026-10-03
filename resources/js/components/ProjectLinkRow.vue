<script setup lang="ts">
import LinkIcon from '@/components/LinkIcon.vue';
import MarkdownContent from '@/components/MarkdownContent.vue';
import OpenTargetButton from '@/components/OpenTargetButton.vue';
import ProjectImportanceButton from '@/components/ProjectImportanceButton.vue';
import { ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { router } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { ContextMenuSeparator } from 'reka-ui';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import type { Project, ProjectLink } from '@/types';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { CopyIcon, ExternalLinkIcon, InfoIcon, PencilIcon, StarIcon, Trash2Icon } from '@lucide/vue';
import { ContextMenu, ContextMenuContent, ContextMenuItem, ContextMenuTrigger } from '@/components/ui/context-menu';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';

const props = defineProps<{ project: Project; link: ProjectLink; native: boolean; disabled?: boolean; highlighted?: boolean }>();
const openTarget = ref<InstanceType<typeof OpenTargetButton> | null>(null);
const importanceButton = ref<InstanceType<typeof ProjectImportanceButton> | null>(null);
const detailsOpen = ref(false);
const removing = ref(false);
const removeSaving = ref(false);
const removeError = ref('');
const editing = ref(false);
const editSaving = ref(false);
const editRevision = ref(0);
const editDraft = ref({ label: '', url: '', category: '', description: '' });
const editErrors = ref<Record<string, string>>({});
watch(removing, () => { removeError.value = ''; });

function openLink(event: Event) {
    if (!props.native) return;
    event.preventDefault();
    openTarget.value?.open();
}

function beginEdit() {
    if (props.disabled || removeSaving.value || editSaving.value || importanceButton.value?.saving) return;
    const link = props.project.links.find(link => link.id === props.link.id);
    if (!link) return;
    editDraft.value = { label: link.label, url: link.url, category: link.category ?? '', description: link.description ?? '' };
    editRevision.value = props.project.revision;
    editErrors.value = {};
    editing.value = true;
}
function saveLink() {
    if (!editing.value || editSaving.value || removeSaving.value || props.disabled || importanceButton.value?.saving) return;
    const project = props.project;
    const index = project.links.findIndex(link => link.id === props.link.id);
    editErrors.value = {};
    if (index === -1) { editErrors.value.general = 'This link was removed. Close this dialog to refresh the list.'; return; }
    editSaving.value = true;
    router.put(`/projects/${project.id}`, {
        name: project.name, description: project.description, status: project.status, revision: editRevision.value,
        links: project.links.map(({ id, label, url, category, description }) => ({ id, label, url, category, description, ...(id === props.link.id ? editDraft.value : {}) })),
        return_back: true,
    }, {
        preserveScroll: true,
        errorBag: 'linkActions',
        onSuccess: () => { editing.value = false; },
        onError: errors => {
            for (const [key, message] of Object.entries(errors)) {
                const field = key.startsWith(`links.${index}.`) ? key.slice(`links.${index}.`.length) : 'general';
                editErrors.value[field in editDraft.value ? field : 'general'] = String(message);
            }
        },
        onNetworkError: () => { editErrors.value.general = 'Could not save link. Try again.'; },
        onFinish: () => { editSaving.value = false; },
    });
}
async function copyLink(url: string) {
    try { await navigator.clipboard.writeText(url); toast.success('URL copied.'); }
    catch { toast.error('Could not copy URL.'); }
}
function removeLink() {
    if (!removing.value || removeSaving.value || editSaving.value || props.disabled || importanceButton.value?.saving) return;
    const project = props.project;
    if (!project.links.some(link => link.id === props.link.id)) { removing.value = false; return; }
    removeSaving.value = true;
    removeError.value = '';
    router.put(`/projects/${project.id}`, {
        name: project.name, description: project.description, status: project.status, revision: project.revision,
        links: project.links.filter(link => link.id !== props.link.id).map(({ id, label, url, category, description }) => ({ id, label, url, category, description })),
        return_back: true,
    }, {
        preserveScroll: true,
        errorBag: 'linkActions',
        onSuccess: () => { removing.value = false; },
        onError: errors => { removeError.value = String(Object.values(errors)[0] ?? 'Could not delete link. Try again.'); },
        onNetworkError: () => { removeError.value = 'Could not delete link. Try again.'; },
        onFinish: () => { removeSaving.value = false; },
    });
}
</script>

<template>
    <ContextMenu>
        <ContextMenuTrigger as-child>
            <li
                :id="'link-' + link.id"
                class="group/link-row flex min-w-0 items-center gap-1 rounded-lg pr-2 transition-colors hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                :class="highlighted ? 'ring-2 ring-ring' : ''">
                <OpenTargetButton
                    :id="link.id"
                    ref="openTarget"
                    :project-id="project.id"
                    kind="links"
                    :native="native"
                    :href="link.url"
                    :label="`Open ${link.label}`"
                    text
                    class="min-w-0 flex-1">
                    <span
                        class="flex min-w-0 flex-1 text-2xl items-center gap-2.5 px-2 py-1.5"
                        :title="link.url">
                        <LinkIcon :url="link.url" />
                        <span class="min-w-0 flex-1 truncate text-[13px] font-normal">{{ link.label }}</span>
                    </span>
                </OpenTargetButton>
                <div class="flex shrink-0 items-center opacity-0 group-focus-within/link-row:opacity-100 group-hover/link-row:opacity-100 [@media(hover:none)]:opacity-100">
                    <ProjectImportanceButton
                        ref="importanceButton"
                        :project="project"
                        kind="links"
                        :item="link"
                        :label="link.label"
                        :disabled="disabled || removeSaving || editSaving" />
                    <Dialog
                        v-if="link.category || link.description_html"
                        v-model:open="detailsOpen">
                        <DialogTrigger as-child>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                :aria-label="`Details for ${link.label}`">
                                <InfoIcon aria-hidden="true" />
                            </Button>
                        </DialogTrigger>
                        <DialogContent class="max-h-[85vh] overflow-y-auto">
                            <DialogHeader>
                                <DialogTitle>{{ link.label }}</DialogTitle>
                                <DialogDescription class="break-all">
                                    <a
                                        :href="link.url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        @click="openLink">{{ link.url }}</a>
                                </DialogDescription>
                            </DialogHeader>
                            <p
                                v-if="link.category"
                                class="text-sm text-muted-foreground">
                                {{ link.category }}
                            </p>
                            <MarkdownContent
                                v-if="link.description_html"
                                :html="link.description_html" />
                        </DialogContent>
                    </Dialog>
                </div>
            </li>
        </ContextMenuTrigger>
        <ContextMenuContent>
            <ContextMenuItem
                v-if="native"
                @select="openTarget?.open()">
                <ExternalLinkIcon aria-hidden="true" />Open link
            </ContextMenuItem>
            <ContextMenuItem
                v-else
                as-child>
                <a
                    :href="link.url"
                    target="_blank"
                    rel="noopener noreferrer"><ExternalLinkIcon aria-hidden="true" />Open link</a>
            </ContextMenuItem>
            <ContextMenuItem @select="copyLink(link.url)">
                <CopyIcon aria-hidden="true" />Copy URL
            </ContextMenuItem>
            <ContextMenuItem
                :disabled="disabled || removeSaving || editSaving || importanceButton?.saving"
                @select="importanceButton?.toggle()">
                <StarIcon aria-hidden="true" />{{ link.important ? 'Unfavourite' : 'Favourite' }}
            </ContextMenuItem>
            <ContextMenuItem
                v-if="link.category || link.description_html"
                @select="detailsOpen = true">
                <InfoIcon aria-hidden="true" />Details
            </ContextMenuItem>
            <ContextMenuItem
                :disabled="disabled || removeSaving || editSaving || importanceButton?.saving"
                @select="beginEdit">
                <PencilIcon aria-hidden="true" />Edit link
            </ContextMenuItem>
            <ContextMenuSeparator class="mx-2.5 my-1.5 h-px bg-border/80" />
            <ContextMenuItem
                variant="destructive"
                :disabled="disabled || removeSaving || editSaving || importanceButton?.saving"
                @select="removing = true">
                <Trash2Icon aria-hidden="true" />Delete link
            </ContextMenuItem>
        </ContextMenuContent>
        <Dialog
            :open="editing"
            @update:open="open => { if (!editSaving) editing = open; }">
            <DialogContent
                :aria-describedby="undefined"
                class="max-h-[calc(100dvh-2rem)] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Edit link</DialogTitle>
                </DialogHeader>
                <form
                    class="grid gap-4"
                    @submit.prevent="saveLink">
                    <FieldError :errors="[editErrors.general]" />
                    <Field
                        class="gap-2"
                        :data-invalid="!!editErrors.label">
                        <FieldLabel :for="`edit-link-${link.id}-label`">
                            Name
                        </FieldLabel>
                        <Input
                            :id="`edit-link-${link.id}-label`"
                            v-model="editDraft.label"
                            variant="filled"
                            maxlength="2048"
                            :placeholder="editDraft.url || 'Name (optional)'"
                            :disabled="editSaving"
                            :aria-invalid="!!editErrors.label"
                            :aria-describedby="editErrors.label ? `edit-link-${link.id}-label-error` : undefined" />
                        <FieldError
                            :id="`edit-link-${link.id}-label-error`"
                            :errors="[editErrors.label]" />
                    </Field>
                    <Field
                        class="gap-2"
                        :data-invalid="!!editErrors.url">
                        <FieldLabel :for="`edit-link-${link.id}-url`">
                            URL
                        </FieldLabel>
                        <Input
                            :id="`edit-link-${link.id}-url`"
                            v-model="editDraft.url"
                            variant="filled"
                            type="url"
                            required
                            maxlength="2048"
                            placeholder="https://example.com"
                            :disabled="editSaving"
                            :aria-invalid="!!editErrors.url"
                            :aria-describedby="editErrors.url ? `edit-link-${link.id}-url-error` : undefined" />
                        <FieldError
                            :id="`edit-link-${link.id}-url-error`"
                            :errors="[editErrors.url]" />
                    </Field>
                    <Field
                        class="gap-2"
                        :data-invalid="!!editErrors.category">
                        <FieldLabel :for="`edit-link-${link.id}-category`">
                            Category (optional)
                        </FieldLabel>
                        <Input
                            :id="`edit-link-${link.id}-category`"
                            v-model="editDraft.category"
                            variant="filled"
                            maxlength="50"
                            placeholder="Category (optional)"
                            :disabled="editSaving"
                            :aria-invalid="!!editErrors.category"
                            :aria-describedby="editErrors.category ? `edit-link-${link.id}-category-error` : undefined" />
                        <FieldError
                            :id="`edit-link-${link.id}-category-error`"
                            :errors="[editErrors.category]" />
                    </Field>
                    <Field
                        class="gap-2"
                        :data-invalid="!!editErrors.description">
                        <FieldLabel :for="`edit-link-${link.id}-description`">
                            Notes
                        </FieldLabel>
                        <Textarea
                            :id="`edit-link-${link.id}-description`"
                            v-model="editDraft.description"
                            variant="filled"
                            class="resize-none"
                            :rows="3"
                            maxlength="10000"
                            placeholder="Access notes or setup steps"
                            :disabled="editSaving"
                            :aria-invalid="!!editErrors.description"
                            :aria-describedby="editErrors.description ? `edit-link-${link.id}-description-error` : undefined" />
                        <FieldError
                            :id="`edit-link-${link.id}-description-error`"
                            :errors="[editErrors.description]" />
                    </Field>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="editSaving"
                            @click="editing = false">
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            :disabled="disabled || removeSaving || editSaving || importanceButton?.saving">
                            {{ editSaving ? 'Saving…' : 'Save changes' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
        <Dialog
            :open="removing"
            @update:open="open => { if (!open && !removeSaving) removing = false; }">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete {{ link.label }}?</DialogTitle>
                    <DialogDescription>This removes the saved link from this project. This cannot be undone.</DialogDescription>
                </DialogHeader>
                <p
                    v-if="removeError"
                    role="alert"
                    class="text-sm text-destructive">
                    {{ removeError }}
                </p>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="removeSaving"
                        @click="removing = false">
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        :disabled="disabled || removeSaving || editSaving || importanceButton?.saving"
                        @click="removeLink">
                        {{ removeSaving ? 'Deleting…' : 'Delete link' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </ContextMenu>
</template>
