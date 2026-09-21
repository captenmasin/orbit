<script setup lang="ts">
import { ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { VueDraggable } from 'vue-draggable-plus';
import { GripVerticalIcon, LayoutGridIcon, SettingsIcon, OrbitIcon, PlusIcon, ArchiveIcon } from '@lucide/vue';
import ProjectIcon from '@/components/ProjectIcon.vue';
import { Sidebar, SidebarContent, SidebarGroup, SidebarGroupLabel, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem, SidebarRail, useSidebar } from '@/components/ui/sidebar';
import type { Project, SidebarProject } from '@/types';

const props = defineProps<{ projects: SidebarProject[]; selectedProject: Project | null; page: string }>();
const { setOpenMobile } = useSidebar();
const ordered = ref<SidebarProject[]>([]);
const order = useForm({ ids: [] as string[] });
const announcement = ref('');
watch(() => props.projects, projects => { ordered.value = [...projects]; }, { immediate: true });
function saveOrder() {
    if (order.processing) return;
    order.ids = ordered.value.map(project => project.id);
    if (order.ids.every((id, index) => id === props.projects[index]?.id)) return;
    order.put('/projects/order', {
        preserveScroll: true,
        onSuccess: () => { announcement.value = 'Project order saved.'; },
        onError: () => { ordered.value = [...props.projects]; announcement.value = 'Could not save the order. Reload and try again.'; },
        onFinish: () => { ordered.value = [...props.projects]; },
    });
}
function move(index: number, direction: number) {
    if (order.processing || index + direction < 0 || index + direction >= ordered.value.length) return;
    const [project] = ordered.value.splice(index, 1);
    ordered.value.splice(index + direction, 0, project!);
    saveOrder();
}
</script>

<template>
    <Sidebar collapsible="icon" aria-label="Workspace">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child tooltip="Orbit">
                        <Link href="/" aria-label="Orbit" @click="setOpenMobile(false)">
                            <div class="flex aspect-square size-8 items-center justify-center rounded-lg bg-sidebar-primary text-sidebar-primary-foreground"><OrbitIcon class="size-4" aria-hidden="true" /></div>
                            <span class="truncate font-medium">Orbit</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>
        <SidebarContent>
            <SidebarGroup>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton as-child tooltip="Dashboard" :is-active="page === 'Dashboard'">
                            <Link href="/" :aria-current="page === 'Dashboard' ? 'page' : undefined" @click="setOpenMobile(false)"><LayoutGridIcon aria-hidden="true" /><span>Dashboard</span></Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                    <SidebarMenuItem>
                        <SidebarMenuButton as-child tooltip="New project" :is-active="page === 'CreateProject'">
                            <Link href="/projects/create" @click="setOpenMobile(false)"><PlusIcon aria-hidden="true" /><span>New project</span></Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                    <SidebarMenuItem>
                        <SidebarMenuButton as-child tooltip="Connections" :is-active="page === 'Connections'">
                            <Link href="/settings/connections" @click="setOpenMobile(false)"><SettingsIcon aria-hidden="true" /><span>Connections</span></Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                    <SidebarMenuItem>
                        <SidebarMenuButton as-child tooltip="Backups" :is-active="page === 'Backups'">
                            <Link href="/settings/backups" @click="setOpenMobile(false)"><ArchiveIcon aria-hidden="true" /><span>Backups</span></Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarGroup>
            <SidebarGroup v-if="ordered.length" class="group-data-[collapsible=icon]:hidden">
                <SidebarGroupLabel>Projects</SidebarGroupLabel>
                <p id="project-order-help" class="sr-only">Drag to reorder, or focus a project handle and use the up and down arrow keys.</p>
                <VueDraggable v-model="ordered" tag="ul" handle=".project-handle" :animation="150" :disabled="order.processing" class="flex w-full min-w-0 flex-col gap-1" @end="saveOrder">
                    <SidebarMenuItem v-for="(project, index) in ordered" :key="project.id" class="flex items-center gap-1">
                        <button type="button" class="project-handle cursor-grab rounded p-1 text-muted-foreground hover:text-foreground focus-visible:outline-2 disabled:opacity-50" :aria-label="`Reorder ${project.name}`" aria-describedby="project-order-help" :disabled="order.processing" @keydown.up.prevent="move(index, -1)" @keydown.down.prevent="move(index, 1)"><GripVerticalIcon class="size-3.5" aria-hidden="true" /></button>
                        <SidebarMenuButton as-child :is-active="selectedProject?.id === project.id">
                            <Link :href="`/projects/${project.id}`" :aria-current="selectedProject?.id === project.id ? 'page' : undefined" :title="project.name" @click="setOpenMobile(false)">
                                <ProjectIcon :name="project.name" :type="project.icon_type" :emoji="project.icon_emoji" :image="project.icon_url" size="sm" /><span>{{ project.name }}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </VueDraggable>
                <p class="sr-only" role="status">{{ announcement }}</p>
                <p v-if="order.hasErrors" class="mt-2 text-xs text-destructive" role="alert">{{ Object.values(order.errors)[0] }} <button type="button" class="underline" @click="router.reload()">Reload</button></p>
            </SidebarGroup>
        </SidebarContent>
        <SidebarRail />
    </Sidebar>
</template>
