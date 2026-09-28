<script setup lang="ts">
import ProjectIcon from '@/components/ProjectIcon.vue';
import ProjectStatusDot from '@/components/ProjectStatusDot.vue';
import ProjectContextMenu from '@/components/ProjectContextMenu.vue';
import { toast } from 'vue-sonner';
import { computed, ref, watch } from 'vue';
import { useLocalStorage } from '@vueuse/core';
import { Link, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { reducedMotion } from '@/lib/appearance';
import { VueDraggable } from 'vue-draggable-plus';
import type { Project, SidebarProject } from '@/types';
import { GripVerticalIcon, LayoutGridIcon, SettingsIcon, OrbitIcon, PlusIcon, ChevronRightIcon, SearchIcon } from '@lucide/vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarGroup, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem, SidebarRail, useSidebar } from '@/components/ui/sidebar';

const props = defineProps<{ projects: SidebarProject[]; selectedProject: Project | null; page: string; statuses?: string[] }>();
const emit = defineEmits<{ searchWorkspace: [] }>();
const { setOpenMobile } = useSidebar();
const ordered = ref<SidebarProject[]>([]);
const projectQuery = ref('');
const groupOpen = useLocalStorage<Record<string, boolean>>('orbit:sidebar-groups', {}, { flush: 'sync' });
const order = useForm({ ids: [] as string[] });
const announcement = ref('');
watch(() => props.projects, projects => { ordered.value = [...projects]; }, { immediate: true });
const groups = computed(() => {
    const projectsByStatus = new Map<string, SidebarProject[]>((props.statuses ?? []).map(status => [status, []]));
    for (const project of ordered.value.filter(project => project.name.toLocaleLowerCase().includes(projectQuery.value.trim().toLocaleLowerCase()))) {
        if (!projectsByStatus.has(project.status)) projectsByStatus.set(project.status, []);
        projectsByStatus.get(project.status)!.push(project);
    }
    return Array.from(projectsByStatus, ([status, projects]) => ({ status, projects })).filter(group => group.projects.length);
});
function reorderGroup(status: string, projects: SidebarProject[]) {
    if (projectQuery.value.trim()) return;
    let index = 0;
    ordered.value = ordered.value.map(project => project.status === status ? projects[index++]! : project);
}
function saveOrder() {
    if (order.processing) return;
    order.ids = ordered.value.map(project => project.id);
    if (order.ids.every((id, index) => id === props.projects[index]?.id)) return;
    order.put('/projects/order', {
        preserveScroll: true,
        onSuccess: () => { announcement.value = 'Project order saved.'; },
        onError: () => { ordered.value = [...props.projects]; toast.error('Could not save project order. Reload and try again.'); },
        onFinish: () => { ordered.value = [...props.projects]; },
    });
}
function move(status: string, index: number, direction: number) {
    const projects = groups.value.find(group => group.status === status)?.projects;
    if (projectQuery.value.trim() || order.processing || !projects || index + direction < 0 || index + direction >= projects.length) return;
    const reordered = [...projects];
    const [project] = reordered.splice(index, 1);
    reordered.splice(index + direction, 0, project!);
    reorderGroup(status, reordered);
    saveOrder();
}
function setGroupOpen(status: string, event: Event) {
    groupOpen.value[status] = (event.currentTarget as HTMLDetailsElement).open;
}
</script>

<template>
    <Sidebar
        aria-label="Workspace"
        class="border-r-0">
        <SidebarHeader class="flex-row items-center px-4 pt-4 pb-3 group-data-[collapsible=icon]:flex-col group-data-[collapsible=icon]:p-2">
            <SidebarMenu class="min-w-0 flex-1">
                <SidebarMenuItem>
                    <SidebarMenuButton
                        size="lg"
                        as-child
                        tooltip="Orbit"
                        class="h-10! gap-2.5 px-1! text-lg font-normal hover:bg-transparent! group-data-[collapsible=icon]:size-8!">
                        <Link
                            href="/"
                            aria-label="Orbit"
                            @click="setOpenMobile(false)">
                            <OrbitIcon
                                class="size-5!"
                                aria-hidden="true" />
                            <span class="truncate tracking-[-0.01em] group-data-[collapsible=icon]:hidden">Orbit</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <Button
                type="button"
                variant="ghost"
                size="icon-sm"
                class="text-sidebar-foreground/60"
                aria-label="Search workspace"
                @click="setOpenMobile(false); emit('searchWorkspace')">
                <SearchIcon aria-hidden="true" />
            </Button>
        </SidebarHeader>
        <SidebarContent class="gap-0">
            <SidebarGroup class="gap-1 px-3 pt-1 pb-6 group-data-[collapsible=icon]:px-2">
                <SidebarMenu class="gap-1">
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            as-child
                            tooltip="Dashboard"
                            :is-active="page === 'Dashboard'"
                            class="h-10 rounded-xl px-3 text-sm">
                            <Link
                                href="/"
                                :aria-current="page === 'Dashboard' ? 'page' : undefined"
                                @click="setOpenMobile(false)">
                                <LayoutGridIcon aria-hidden="true" /><span>Dashboard</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            as-child
                            tooltip="New project"
                            :is-active="page === 'CreateProject'"
                            class="h-10 rounded-xl px-3 text-sm">
                            <Link
                                href="/projects/create"
                                @click="setOpenMobile(false)">
                                <PlusIcon aria-hidden="true" /><span>New project</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarGroup>
            <SidebarGroup class="min-h-0 gap-2 px-3 pt-0 group-data-[collapsible=icon]:hidden">
                <div
                    v-if="ordered.length"
                    class="relative">
                    <SearchIcon
                        class="pointer-events-none absolute top-1/2 left-3 size-3.5 -translate-y-1/2 text-sidebar-foreground/60"
                        aria-hidden="true" />
                    <input
                        v-model="projectQuery"
                        type="search"
                        aria-label="Find a project"
                        placeholder="Find a project…"
                        class="h-9 w-full rounded-lg bg-transparent pr-3 pl-9 text-[13px] text-sidebar-foreground outline-none placeholder:text-sidebar-foreground/65 hover:bg-sidebar-accent/50 focus:bg-sidebar-accent/50 focus-visible:ring-2 focus-visible:ring-sidebar-ring"
                        @keydown.esc="projectQuery = ''">
                </div>
                <p
                    v-if="!projectQuery.trim()"
                    id="project-order-help"
                    class="sr-only">
                    Drag to reorder within a status, or focus a project handle and use the up and down arrow keys.
                </p>
                <details
                    v-for="group in groups"
                    :key="group.status"
                    :open="groupOpen[group.status] ?? true"
                    class="t-disclosure group/status"
                    @toggle="setGroupOpen(group.status, $event)">
                    <summary class="flex h-9 cursor-default list-none items-center gap-2 rounded-lg px-3 text-sm font-medium text-sidebar-foreground/65 hover:text-sidebar-foreground focus-visible:outline-2 focus-visible:outline-sidebar-ring [&::-webkit-details-marker]:hidden">
                        <ProjectStatusDot
                            :status="group.status"
                            class="size-1.5" /><span class="truncate">{{ group.status }}</span><ChevronRightIcon
                                class="size-3.5 shrink-0 transition-transform group-open/status:rotate-90"
                                aria-hidden="true" />
                    </summary>
                    <VueDraggable
                        :model-value="group.projects"
                        tag="ul"
                        handle=".project-handle"
                        :animation="reducedMotion ? 0 : 150"
                        :disabled="order.processing || !!projectQuery.trim()"
                        class="flex w-full min-w-0 flex-col gap-0.5"
                        @update:model-value="reorderGroup(group.status, $event)"
                        @end="saveOrder">
                        <ProjectContextMenu
                            v-for="(project, index) in group.projects"
                            :key="project.id"
                            :project="project"
                            :disabled="order.processing">
                            <SidebarMenuItem class="group/project">
                                <SidebarMenuButton
                                    as-child
                                    :is-active="selectedProject?.id === project.id"
                                    class="h-9 gap-2.5 rounded-xl px-3 pr-8 text-sm">
                                    <Link
                                        :href="`/projects/${project.id}`"
                                        :aria-current="selectedProject?.id === project.id ? 'page' : undefined"
                                        :title="project.name"
                                        @click="setOpenMobile(false)">
                                        <ProjectIcon
                                            :name="project.name"
                                            :type="project.icon_type"
                                            :emoji="project.icon_emoji"
                                            :image="project.icon_url"
                                            size="sm"
                                            class="size-5!" /><span class="min-w-0 flex-1 truncate">{{ project.name }}</span>
                                    </Link>
                                </SidebarMenuButton>
                                <button
                                    v-if="!projectQuery.trim()"
                                    type="button"
                                    class="project-handle absolute top-1.5 right-1 cursor-grab rounded p-1 text-sidebar-foreground/50 opacity-0 hover:bg-sidebar-accent hover:text-sidebar-foreground focus:opacity-100 focus-visible:outline-2 focus-visible:outline-sidebar-ring group-hover/project:opacity-100 disabled:opacity-30"
                                    :aria-label="`Reorder ${project.name}`"
                                    aria-describedby="project-order-help"
                                    :disabled="order.processing"
                                    @keydown.up.prevent="move(group.status, index, -1)"
                                    @keydown.down.prevent="move(group.status, index, 1)">
                                    <GripVerticalIcon
                                        class="size-3.5"
                                        aria-hidden="true" />
                                </button>
                            </SidebarMenuItem>
                        </ProjectContextMenu>
                    </VueDraggable>
                </details>
                <p
                    v-if="projectQuery.trim() && !groups.length"
                    class="px-2 py-3 text-[12px] text-sidebar-foreground/60"
                    role="status">
                    No matching projects
                </p>
                <p
                    v-else-if="!ordered.length"
                    class="px-2 py-3 text-[12px] text-sidebar-foreground/60">
                    No projects yet
                </p>
                <p
                    class="sr-only"
                    role="status">
                    {{ announcement }}
                </p>
            </SidebarGroup>
        </SidebarContent>
        <SidebarFooter class="px-3 py-3 group-data-[collapsible=icon]:px-2">
            <SidebarMenu class="gap-0.5">
                <SidebarMenuItem>
                    <SidebarMenuButton
                        as-child
                        tooltip="Settings"
                        :is-active="page === 'Settings'"
                        class="h-10 rounded-xl px-3 text-sm">
                        <Link
                            href="/settings"
                            :aria-current="page === 'Settings' ? 'page' : undefined"
                            @click="setOpenMobile(false)">
                            <SettingsIcon aria-hidden="true" /><span>Settings</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarFooter>
        <SidebarRail />
    </Sidebar>
</template>
