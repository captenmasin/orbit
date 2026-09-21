<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import WorkspaceSidebar from '@/components/WorkspaceSidebar.vue';
import { Button } from '@/components/ui/button';
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb';
import { Separator } from '@/components/ui/separator';
import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar';
import type { Project, SidebarProject } from '@/types';

const page = usePage<{ sidebarProjects: SidebarProject[]; selectedProject?: Project; message?: string | null }>();
const project = computed(() => page.props.selectedProject);
const titles: Record<string, string> = { Dashboard: 'Dashboard', CreateProject: 'New project', EditProject: 'Edit project', Connections: 'Connections', Backups: 'Backups' };
const title = computed(() => titles[page.component] ?? project.value?.name ?? 'Orbit');
</script>

<template>
    <SidebarProvider>
        <Button as-child class="sr-only focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:z-50"><a href="#main">Skip to content</a></Button>
        <WorkspaceSidebar :projects="page.props.sidebarProjects" :selected-project="project ?? null" :page="page.component" />
        <SidebarInset class="min-w-0">
            <header class="flex h-16 shrink-0 items-center gap-2 px-4">
                <SidebarTrigger class="-ml-1" />
                <Separator orientation="vertical" class="mr-2 data-[orientation=vertical]:h-4" />
                <Breadcrumb>
                    <BreadcrumbList>
                        <template v-if="page.component !== 'Dashboard'">
                            <BreadcrumbItem class="hidden md:block"><BreadcrumbLink as-child><Link href="/">Dashboard</Link></BreadcrumbLink></BreadcrumbItem>
                            <BreadcrumbSeparator class="hidden md:block" />
                        </template>
                        <template v-if="project && page.component === 'EditProject'">
                            <BreadcrumbItem><BreadcrumbLink as-child><Link :href="`/projects/${project.id}`">{{ project.name }}</Link></BreadcrumbLink></BreadcrumbItem>
                            <BreadcrumbSeparator />
                        </template>
                        <BreadcrumbItem><BreadcrumbPage class="line-clamp-1 break-all">{{ title }}</BreadcrumbPage></BreadcrumbItem>
                    </BreadcrumbList>
                </Breadcrumb>
            </header>
            <main id="main" tabindex="-1" class="flex min-w-0 flex-1 flex-col gap-6 p-4 pt-0 lg:p-6 lg:pt-0">
                <p v-if="page.props.message" class="text-sm text-muted-foreground" role="status">{{ page.props.message }}</p>
                <slot />
            </main>
        </SidebarInset>
    </SidebarProvider>
</template>
