<script setup lang="ts">
import { watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { PlusIcon, SearchIcon } from '@lucide/vue';
import ProjectIcon from '@/components/ProjectIcon.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Field, FieldLabel } from '@/components/ui/field';
import { Empty, EmptyContent, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import type { CatalogFilters, ProjectPage } from '@/types';

const props = defineProps<{ projects: ProjectPage; statuses: string[]; filters: CatalogFilters; tags: string[] }>();
const search = useForm({ ...props.filters });
watch(() => props.filters, filters => { search.defaults(filters); search.reset(); });
function filter() {
    search.get('/', { preserveState: true, preserveScroll: true });
}
const date = (value: string | null) => value ? new Date(value).toLocaleDateString() : '—';
</script>

<template>
    <Head title="Dashboard" />
    <div class="flex items-center justify-between gap-4">
        <h1 class="text-2xl font-semibold tracking-tight">Dashboard</h1>
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
