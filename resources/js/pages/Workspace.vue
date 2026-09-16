<script setup lang="ts">
import { watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { PlusIcon, SearchIcon } from '@lucide/vue';
import ProjectForm from '@/components/ProjectForm.vue';
import ProjectIcon from '@/components/ProjectIcon.vue';
import WorkspaceSidebar from '@/components/WorkspaceSidebar.vue';
import { Badge } from '@/components/ui/badge';
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Field, FieldLabel } from '@/components/ui/field';
import { Empty, EmptyContent, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Separator } from '@/components/ui/separator';
import { SidebarInset, SidebarTrigger } from '@/components/ui/sidebar';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import type { CatalogFilters, Project, ProjectPage, ProjectFolder } from '@/types';

const props = defineProps<{ projects: ProjectPage; selectedProject: Project | null; inspection: ProjectFolder[]; statuses: string[]; message: string | null; creating: boolean; filters: CatalogFilters; tags: string[]; native: boolean }>();
const search = useForm({ ...props.filters });
watch(() => props.filters, filters => { search.defaults(filters); search.reset(); });
function filter() {
    search.get('/', { preserveState: true, preserveScroll: true });
}
const date = (value: string | null) => value ? new Date(value).toLocaleDateString() : '—';
</script>

<template>
    <Head :title="creating ? 'New project' : selectedProject?.name ?? 'Projects'" />
    <Button as-child class="sr-only focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:z-50">
        <a href="#main">Skip to content</a>
    </Button>
    <WorkspaceSidebar :projects="projects" :selected-project="selectedProject" :creating="creating" @create="router.get('/projects/create')" />
    <SidebarInset class="min-w-0">
        <header class="flex h-16 shrink-0 items-center gap-2 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12">
            <div class="flex min-w-0 items-center gap-2 px-4">
                <SidebarTrigger class="-ml-1" />
                <Separator orientation="vertical" class="mr-2 data-[orientation=vertical]:h-4" />
                <Breadcrumb>
                    <BreadcrumbList>
                        <template v-if="selectedProject || creating">
                            <BreadcrumbItem class="hidden md:block">
                                <BreadcrumbLink as-child><Link href="/">Projects</Link></BreadcrumbLink>
                            </BreadcrumbItem>
                            <BreadcrumbSeparator class="hidden md:block" />
                            <BreadcrumbItem><BreadcrumbPage class="line-clamp-1 break-all">{{ selectedProject?.name ?? 'New project' }}</BreadcrumbPage></BreadcrumbItem>
                        </template>
                        <BreadcrumbItem v-else><BreadcrumbPage>Projects</BreadcrumbPage></BreadcrumbItem>
                    </BreadcrumbList>
                </Breadcrumb>
            </div>
        </header>
        <div id="main" tabindex="-1" class="flex flex-1 flex-col gap-4 p-4 pt-0">
            <template v-if="selectedProject || creating">
                <div class="flex flex-wrap items-center gap-4">
                    <ProjectIcon v-if="selectedProject" :name="selectedProject.name" :type="selectedProject.icon_type" :emoji="selectedProject.icon_emoji" :image="selectedProject.icon_url" />
                    <h1 class="text-2xl font-semibold tracking-tight break-all">{{ selectedProject?.name ?? 'New project' }}</h1>
                    <Badge v-if="selectedProject" variant="secondary">{{ selectedProject.status }}</Badge>
                </div>
                <div class="w-full min-w-0">
                    <ProjectForm :key="selectedProject?.id ?? 'create'" :project="selectedProject ?? undefined" :scan-folders="inspection" :statuses="statuses" :message="message" :native="native" />
                </div>
            </template>
            <template v-else>
                <div class="flex items-center justify-between gap-4">
                    <h1 class="text-2xl font-semibold tracking-tight">Projects</h1>
                    <Button as-child><Link href="/projects/create"><PlusIcon aria-hidden="true" />New project</Link></Button>
                </div>
                <form class="grid gap-3 sm:grid-cols-2 xl:grid-cols-[minmax(200px,1fr)_160px_160px_160px_auto]" @submit.prevent="filter">
                    <Field><FieldLabel for="catalog-search">Search</FieldLabel><Input id="catalog-search" v-model="search.q" name="q" placeholder="Name, description or tag" maxlength="255" /></Field>
                    <Field><FieldLabel for="catalog-status">Status</FieldLabel><NativeSelect id="catalog-status" v-model="search.status"><NativeSelectOption value="">All statuses</NativeSelectOption><NativeSelectOption v-for="status in statuses" :key="status" :value="status">{{ status }}</NativeSelectOption></NativeSelect></Field>
                    <Field><FieldLabel for="catalog-tag">Tag</FieldLabel><NativeSelect id="catalog-tag" v-model="search.tag"><NativeSelectOption value="">All tags</NativeSelectOption><NativeSelectOption v-for="tag in tags" :key="tag" :value="tag">{{ tag }}</NativeSelectOption></NativeSelect></Field>
                    <Field><FieldLabel for="catalog-sort">Sort</FieldLabel><NativeSelect id="catalog-sort" v-model="search.sort"><NativeSelectOption value="name">Name A–Z</NativeSelectOption><NativeSelectOption value="name-desc">Name Z–A</NativeSelectOption><NativeSelectOption value="last-commit">Last known commit</NativeSelectOption></NativeSelect></Field>
                    <div class="flex items-end gap-2"><Button type="submit" variant="outline" :disabled="search.processing"><SearchIcon aria-hidden="true" />Search</Button><Button v-if="filters.q || filters.status || filters.tag || filters.sort !== 'name'" as-child variant="ghost"><Link href="/">Clear</Link></Button></div>
                </form>
                <Table v-if="projects.data.length">
                    <TableHeader><TableRow><TableHead>Name</TableHead><TableHead>Status</TableHead><TableHead>Tags</TableHead><TableHead>Repositories / folders</TableHead><TableHead>Last known commit</TableHead></TableRow></TableHeader>
                    <TableBody>
                        <TableRow v-for="project in projects.data" :key="project.id">
                            <TableCell>
                                <Link :href="`/projects/${project.id}`" class="flex items-center gap-3 whitespace-normal">
                                    <ProjectIcon :name="project.name" :type="project.icon_type" :emoji="project.icon_emoji" :image="project.icon_url" />
                                    <div class="grid min-w-32 gap-1"><span class="font-medium break-all">{{ project.name }}</span><span v-if="project.description" class="line-clamp-1 text-muted-foreground">{{ project.description }}</span></div>
                                </Link>
                            </TableCell>
                            <TableCell><Badge variant="secondary">{{ project.status }}</Badge></TableCell>
                            <TableCell><div class="flex flex-wrap gap-1"><Badge v-for="tag in project.tags" :key="tag.id" variant="outline">{{ tag.name }}</Badge></div></TableCell>
                            <TableCell>{{ project.repositories_count }} / {{ project.folders_count }}</TableCell>
                            <TableCell>{{ date(project.last_commit_at) }}</TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
                <Empty v-else>
                    <EmptyHeader><EmptyTitle><h2>{{ filters.q || filters.status || filters.tag ? 'No matching projects' : 'No projects yet' }}</h2></EmptyTitle></EmptyHeader>
                    <EmptyContent v-if="filters.q || filters.status || filters.tag"><Button as-child variant="outline"><Link href="/">Clear filters</Link></Button></EmptyContent>
                </Empty>
                <nav v-if="projects.prev_page_url || projects.next_page_url" class="flex gap-2" aria-label="Project pages">
                    <Button v-if="projects.prev_page_url" as-child variant="outline"><Link :href="projects.prev_page_url">Previous</Link></Button>
                    <Button v-if="projects.next_page_url" as-child variant="outline"><Link :href="projects.next_page_url">Next</Link></Button>
                </nav>
            </template>
        </div>
    </SidebarInset>
</template>
