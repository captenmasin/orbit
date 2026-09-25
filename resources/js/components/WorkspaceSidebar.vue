<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { VueDraggable } from 'vue-draggable-plus';
import { toast } from 'vue-sonner';
import { GripVerticalIcon, LayoutGridIcon, SettingsIcon, OrbitIcon, PlusIcon, ArchiveIcon, ChevronRightIcon, SearchIcon } from '@lucide/vue';
import ProjectIcon from '@/components/ProjectIcon.vue';
import { ContextMenu, ContextMenuContent, ContextMenuItem, ContextMenuTrigger } from '@/components/ui/context-menu';
import { Sidebar, SidebarContent, SidebarFooter, SidebarGroup, SidebarGroupLabel, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem, SidebarRail, useSidebar } from '@/components/ui/sidebar';
import type { Project, SidebarProject } from '@/types';

const props = defineProps<{ projects: SidebarProject[]; selectedProject: Project | null; page: string }>();
const emit = defineEmits<{ searchWorkspace: [] }>();
const { setOpenMobile } = useSidebar();
const ordered = ref<SidebarProject[]>([]);
const projectQuery = ref('');
const order = useForm({ ids: [] as string[] });
const announcement = ref('');
watch(() => props.projects, projects => { ordered.value = [...projects]; }, { immediate: true });
const groups = computed(() => {
    const projectsByStatus = new Map<string, SidebarProject[]>();
    for (const project of ordered.value.filter(project => project.name.toLocaleLowerCase().includes(projectQuery.value.trim().toLocaleLowerCase()))) {
        if (!projectsByStatus.has(project.status)) projectsByStatus.set(project.status, []);
        projectsByStatus.get(project.status)!.push(project);
    }
    return Array.from(projectsByStatus, ([status, projects]) => ({ status, projects }));
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
</script>

<template>
    <Sidebar aria-label="Workspace">
        <SidebarHeader class="px-3 pt-3 pb-2 group-data-[collapsible=icon]:p-2">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child tooltip="Orbit" class="h-10! px-1! text-[14px] font-semibold hover:bg-transparent! group-data-[collapsible=icon]:size-8!">
                        <Link href="/" aria-label="Orbit" @click="setOpenMobile(false)">
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-[9px] bg-sidebar-primary text-sidebar-primary-foreground shadow-sm"><OrbitIcon class="size-4" aria-hidden="true" /></span>
                            <span class="truncate tracking-[-0.01em]">Orbit</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>
        <SidebarContent class="gap-0">
            <SidebarGroup class="gap-1 px-3 pt-3 pb-4">
                <SidebarGroupLabel class="h-6 px-2 text-[10px] font-semibold tracking-[0.09em] uppercase">Workspace</SidebarGroupLabel>
                <SidebarMenu class="gap-0.5">
                    <SidebarMenuItem>
                        <SidebarMenuButton as-child tooltip="Dashboard" :is-active="page === 'Dashboard'" class="h-9 rounded-lg px-2.5 text-[13px]">
                            <Link href="/" :aria-current="page === 'Dashboard' ? 'page' : undefined" @click="setOpenMobile(false)"><LayoutGridIcon aria-hidden="true" /><span>Dashboard</span></Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                    <SidebarMenuItem>
                        <SidebarMenuButton as-child tooltip="New project" :is-active="page === 'CreateProject'" class="h-9 rounded-lg px-2.5 text-[13px]">
                            <Link href="/projects/create" @click="setOpenMobile(false)"><PlusIcon aria-hidden="true" /><span>New project</span></Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                    <SidebarMenuItem>
                        <SidebarMenuButton as-child tooltip="Search workspace" class="h-9 rounded-lg px-2.5 text-[13px]">
                            <button type="button" @click="emit('searchWorkspace'); setOpenMobile(false)"><SearchIcon aria-hidden="true" /><span>Search workspace</span></button>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarGroup>
            <SidebarGroup class="min-h-0 gap-1 border-t border-sidebar-border/70 px-3 pt-3 group-data-[collapsible=icon]:hidden">
                <SidebarGroupLabel class="h-7 justify-between px-2 text-[11px] font-semibold tracking-[-0.01em] text-sidebar-foreground/80">
                    <span>Projects</span><span class="tabular-nums text-sidebar-foreground/45">{{ ordered.length }}</span>
                </SidebarGroupLabel>
                <div v-if="ordered.length" class="relative mb-1.5">
                    <SearchIcon class="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-sidebar-foreground/45" aria-hidden="true" />
                    <input v-model="projectQuery" type="search" aria-label="Find a project" placeholder="Find a project…" class="h-8 w-full rounded-md border border-sidebar-border bg-background/80 pr-2 pl-8 text-[12px] text-sidebar-foreground outline-none placeholder:text-sidebar-foreground/45 focus-visible:border-sidebar-ring focus-visible:ring-2 focus-visible:ring-sidebar-ring/35" @keydown.esc="projectQuery = ''" />
                </div>
                <p v-if="!projectQuery.trim()" id="project-order-help" class="sr-only">Drag to reorder within a status, or focus a project handle and use the up and down arrow keys.</p>
                <details v-for="group in groups" :key="group.status" open class="group/status">
                    <summary class="flex h-7 cursor-pointer list-none items-center gap-1.5 rounded-md px-1.5 text-[10px] font-semibold tracking-[0.07em] text-sidebar-foreground/55 uppercase hover:text-sidebar-foreground focus-visible:outline-2 focus-visible:outline-sidebar-ring [&::-webkit-details-marker]:hidden">
                        <ChevronRightIcon class="size-3 shrink-0 transition-transform group-open/status:rotate-90" aria-hidden="true" /><span class="truncate">{{ group.status }}</span><span class="ml-auto tabular-nums">{{ group.projects.length }}</span>
                    </summary>
                    <VueDraggable :model-value="group.projects" tag="ul" handle=".project-handle" :animation="150" :disabled="order.processing || !!projectQuery.trim()" class="flex w-full min-w-0 flex-col gap-0.5" @update:model-value="reorderGroup(group.status, $event)" @end="saveOrder">
                        <ContextMenu v-for="(project, index) in group.projects" :key="project.id">
                            <ContextMenuTrigger as-child><SidebarMenuItem class="group/project">
                                <SidebarMenuButton as-child :is-active="selectedProject?.id === project.id" class="h-8 rounded-lg px-2 text-[12px]">
                                    <Link :href="`/projects/${project.id}`" :aria-current="selectedProject?.id === project.id ? 'page' : undefined" :title="project.name" @click="setOpenMobile(false)">
                                        <ProjectIcon :name="project.name" :type="project.icon_type" :emoji="project.icon_emoji" :image="project.icon_url" size="sm" class="size-5!" /><span class="min-w-0 flex-1 truncate">{{ project.name }}</span>
                                    </Link>
                                </SidebarMenuButton>
                                <button v-if="!projectQuery.trim()" type="button" class="project-handle absolute top-1 right-1 cursor-grab rounded p-1 text-sidebar-foreground/50 opacity-0 hover:bg-sidebar-accent hover:text-sidebar-foreground focus:opacity-100 focus-visible:outline-2 focus-visible:outline-sidebar-ring group-hover/project:opacity-100 disabled:opacity-30" :aria-label="`Reorder ${project.name}`" aria-describedby="project-order-help" :disabled="order.processing" @keydown.up.prevent="move(group.status, index, -1)" @keydown.down.prevent="move(group.status, index, 1)"><GripVerticalIcon class="size-3.5" aria-hidden="true" /></button>
                            </SidebarMenuItem></ContextMenuTrigger>
                            <ContextMenuContent><ContextMenuItem as-child><Link :href="`/projects/${project.id}`">Open project</Link></ContextMenuItem><ContextMenuItem as-child><Link :href="`/projects/${project.id}/edit`">Edit project</Link></ContextMenuItem><ContextMenuItem @select="router.post(`/projects/${project.id}/duplicate`)">Duplicate project</ContextMenuItem></ContextMenuContent>
                        </ContextMenu>
                    </VueDraggable>
                </details>
                <p v-if="projectQuery.trim() && !groups.length" class="px-2 py-3 text-[12px] text-sidebar-foreground/60" role="status">No matching projects</p>
                <p v-else-if="!ordered.length" class="px-2 py-3 text-[12px] text-sidebar-foreground/60">No projects yet</p>
                <p class="sr-only" role="status">{{ announcement }}</p>
            </SidebarGroup>
        </SidebarContent>
        <SidebarFooter class="border-t border-sidebar-border/70 px-3 py-2.5">
            <SidebarMenu class="gap-0.5">
                <SidebarMenuItem>
                    <SidebarMenuButton as-child tooltip="Connections" :is-active="page === 'Connections'" class="h-8 rounded-lg px-2.5 text-[12px]">
                        <Link href="/settings/connections" @click="setOpenMobile(false)"><SettingsIcon aria-hidden="true" /><span>Connections</span></Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
                <SidebarMenuItem>
                    <SidebarMenuButton as-child tooltip="Backups" :is-active="page === 'Backups'" class="h-8 rounded-lg px-2.5 text-[12px]">
                        <Link href="/settings/backups" @click="setOpenMobile(false)"><ArchiveIcon aria-hidden="true" /><span>Backups</span></Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarFooter>
        <SidebarRail />
    </Sidebar>
</template>
