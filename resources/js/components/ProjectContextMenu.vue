<script setup lang="ts">
import ProjectStatusDot from '@/components/ProjectStatusDot.vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import type { SidebarProject } from '@/types';
import { Button } from '@/components/ui/button';
import { createReusableTemplate } from '@vueuse/core';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ArchiveIcon, ArrowUpRightIcon, CheckIcon, ChevronRightIcon, CopyIcon, CircleDotIcon, PencilIcon, Trash2Icon } from '@lucide/vue';
import { ContextMenu, ContextMenuContent, ContextMenuItem, ContextMenuTrigger, contextMenuContentClass, contextMenuItemClass } from '@/components/ui/context-menu';
import { DropdownMenuContent, DropdownMenuPortal, DropdownMenuRoot, ContextMenuPortal, ContextMenuSeparator, ContextMenuSub, ContextMenuSubContent, ContextMenuSubTrigger } from 'reka-ui';

const props = defineProps<{ project: SidebarProject; disabled?: boolean; visible?: boolean }>();
const { define: DefineProjectActions, reuse: ProjectActions } = createReusableTemplate();
const page = usePage<{ statuses: string[] }>();
const projectAction = useForm({ revision: 0 });
const duplicating = ref(false);
const duplicateBusy = ref(false);
function duplicateProject() {
    if (!duplicating.value || duplicateBusy.value || props.disabled || projectAction.processing) return;
    duplicateBusy.value = true;
    router.post(`/projects/${props.project.id}/duplicate`, {}, { onSuccess: () => { duplicating.value = false; }, onError: errors => { toast.error(Object.values(errors)[0] ?? 'Could not duplicate project. Try again.'); }, onFinish: () => { duplicateBusy.value = false; } });
}
const removing = ref<SidebarProject | null>(null);

function changeStatus(status: string) {
    const project = props.project;
    if (projectAction.processing || props.disabled || project.status === status) return;
    projectAction.revision = project.revision;
    projectAction.transform(data => ({ ...data, name: project.name, description: project.description, status, return_back: true })).put(`/projects/${project.id}`, {
        preserveScroll: true,
        errorBag: 'projectContextMenu',
        onError: errors => { toast.error(Object.values(errors)[0] ?? 'Could not change project status.'); },
    });
}
function removeProject() {
    if (!removing.value || projectAction.processing || props.disabled) return;
    projectAction.revision = removing.value.revision;
    projectAction.transform(data => ({ revision: data.revision })).delete(`/projects/${removing.value.id}`, {
        errorBag: 'projectContextMenu',
        onError: errors => { toast.error(Object.values(errors)[0] ?? 'Could not delete project.'); },
        onSuccess: () => { removing.value = null; },
    });
}
</script>

<template>
    <div>
        <DefineProjectActions>
            <ContextMenuItem as-child>
                <Link :href="`/projects/${project.id}`">
                    <ArrowUpRightIcon aria-hidden="true" />Open project
                </Link>
            </ContextMenuItem>
            <ContextMenuItem as-child>
                <Link :href="`/projects/${project.id}/edit`">
                    <PencilIcon aria-hidden="true" />Edit project
                </Link>
            </ContextMenuItem>
            <ContextMenuItem
                :disabled="projectAction.processing || disabled || duplicateBusy"
                @select="duplicating = true">
                <CopyIcon aria-hidden="true" />Duplicate project
            </ContextMenuItem>
            <ContextMenuSeparator class="mx-2.5 my-1.5 h-px bg-border/80" />
            <ContextMenuSub>
                <ContextMenuSubTrigger
                    :disabled="projectAction.processing || disabled"
                    :class="contextMenuItemClass">
                    <CircleDotIcon aria-hidden="true" />Change status<ChevronRightIcon
                        class="ml-auto size-3.5"
                        aria-hidden="true" />
                </ContextMenuSubTrigger>
                <ContextMenuPortal>
                    <ContextMenuSubContent
                        :class="contextMenuContentClass"
                        class="max-h-(--reka-context-menu-content-available-height)">
                        <ContextMenuItem
                            v-for="status in page.props.statuses"
                            :key="status"
                            :disabled="projectAction.processing || disabled || status === project.status"
                            @select="changeStatus(status)">
                            <ProjectStatusDot
                                :status="status"
                                class="size-2" />{{ status }}<CheckIcon
                                    v-if="status === project.status"
                                    class="ml-auto size-4"
                                    aria-hidden="true" />
                        </ContextMenuItem>
                    </ContextMenuSubContent>
                </ContextMenuPortal>
            </ContextMenuSub>
            <ContextMenuItem
                v-if="project.status !== 'Archived'"
                :disabled="projectAction.processing || disabled"
                @select="changeStatus('Archived')">
                <ArchiveIcon aria-hidden="true" />Mark archived
            </ContextMenuItem>
            <ContextMenuSeparator class="mx-2.5 my-1.5 h-px bg-border/80" />
            <ContextMenuItem
                variant="destructive"
                :disabled="projectAction.processing || disabled"
                @select="removing = project">
                <Trash2Icon aria-hidden="true" />Delete project
            </ContextMenuItem>
        </DefineProjectActions>
        <ContextMenu>
            <ContextMenuTrigger as-child>
                <div>
                    <DropdownMenuRoot>
                        <slot />
                        <DropdownMenuPortal v-if="visible">
                            <DropdownMenuContent
                                align="end"
                                :side-offset="4"
                                :class="contextMenuContentClass"
                                class="w-56">
                                <ProjectActions />
                            </DropdownMenuContent>
                        </DropdownMenuPortal>
                    </DropdownMenuRoot>
                </div>
            </ContextMenuTrigger>
            <ContextMenuContent class="w-56">
                <ProjectActions />
            </ContextMenuContent>
            <Dialog v-model:open="duplicating">
                <DialogContent>
                    <DialogHeader><DialogTitle>Duplicate {{ project.name }}?</DialogTitle><DialogDescription>Copies documents, tasks, assets and secrets. Repository links and local source folder paths are retained; source files are not cloned.</DialogDescription></DialogHeader><DialogFooter>
                        <Button
                            variant="outline"
                            :disabled="duplicateBusy"
                            @click="duplicating = false">
                            Cancel
                        </Button><Button
                            :disabled="duplicateBusy || disabled"
                            @click="duplicateProject">
                            {{ duplicateBusy ? 'Duplicating…' : 'Duplicate project' }}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <Dialog
                :open="!!removing"
                @update:open="open => { if (!open && !projectAction.processing) removing = null; }">
                <DialogContent>
                    <DialogHeader><DialogTitle>Delete {{ removing?.name }}?</DialogTitle><DialogDescription>This removes the project and its saved metadata from Orbit. Source folders and repositories stay on disk. This cannot be undone.</DialogDescription></DialogHeader>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="projectAction.processing"
                            @click="removing = null">
                            Cancel
                        </Button><Button
                            type="button"
                            variant="destructive"
                            :disabled="projectAction.processing || disabled"
                            @click="removeProject">
                            {{ projectAction.processing ? 'Deleting…' : 'Delete project' }}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </ContextMenu>
    </div>
</template>
