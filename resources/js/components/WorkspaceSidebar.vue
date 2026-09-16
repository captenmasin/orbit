<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeftIcon, ChevronRightIcon, LayoutGridIcon, OrbitIcon, PlusIcon } from '@lucide/vue';
import ProjectIcon from '@/components/ProjectIcon.vue';
import {
    Sidebar, SidebarContent, SidebarGroup, SidebarGroupLabel, SidebarHeader,
    SidebarMenu, SidebarMenuButton, SidebarMenuItem, SidebarRail, useSidebar,
} from '@/components/ui/sidebar';
import type { Project, ProjectPage } from '@/types';

defineProps<{ projects: ProjectPage; selectedProject: Project | null; creating: boolean }>();
const emit = defineEmits<{ create: [] }>();
const { setOpenMobile } = useSidebar();

function createProject() {
    setOpenMobile(false);
    emit('create');
}
</script>

<template>
    <Sidebar collapsible="icon" aria-label="Workspace">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child tooltip="Orbit">
                        <Link href="/" aria-label="Orbit" @click="setOpenMobile(false)">
                            <div class="flex aspect-square size-8 items-center justify-center rounded-lg bg-sidebar-primary text-sidebar-primary-foreground">
                                <OrbitIcon class="size-4" aria-hidden="true" />
                            </div>
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
                        <SidebarMenuButton tooltip="New project" :is-active="creating" @click="createProject">
                            <PlusIcon aria-hidden="true" /><span>New project</span>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                    <SidebarMenuItem>
                        <SidebarMenuButton as-child tooltip="All projects" :is-active="!selectedProject && !creating">
                            <Link href="/" :aria-current="!selectedProject && !creating ? 'page' : undefined" @click="setOpenMobile(false)">
                                <LayoutGridIcon aria-hidden="true" /><span>All projects</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarGroup>
            <SidebarGroup v-if="projects.data.length" class="group-data-[collapsible=icon]:hidden">
                <SidebarGroupLabel>Projects</SidebarGroupLabel>
                <SidebarMenu>
                    <SidebarMenuItem v-for="project in projects.data" :key="project.id">
                        <SidebarMenuButton as-child :is-active="selectedProject?.id === project.id">
                            <Link :href="`/projects/${project.id}`" :aria-current="selectedProject?.id === project.id ? 'page' : undefined" :title="project.name" @click="setOpenMobile(false)">
                                <ProjectIcon :name="project.name" :type="project.icon_type" :emoji="project.icon_emoji" :image="project.icon_url" size="sm" /><span>{{ project.name }}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                    <SidebarMenuItem v-if="projects.prev_page_url">
                        <SidebarMenuButton as-child>
                            <Link :href="projects.prev_page_url" @click="setOpenMobile(false)"><ChevronLeftIcon aria-hidden="true" /><span>Previous</span></Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                    <SidebarMenuItem v-if="projects.next_page_url">
                        <SidebarMenuButton as-child>
                            <Link :href="projects.next_page_url" @click="setOpenMobile(false)"><ChevronRightIcon aria-hidden="true" /><span>Next</span></Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarGroup>
        </SidebarContent>
        <SidebarRail />
    </Sidebar>
</template>
