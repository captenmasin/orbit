<script setup lang="ts">
import { onBeforeUnmount, reactive, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { FolderOpenIcon, PlusIcon, SearchIcon } from '@lucide/vue';
import FilterSelect from '@/components/FilterSelect.vue';
import MultiFilterSelect from '@/components/MultiFilterSelect.vue';
import ProjectCard from '@/components/ProjectCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { CatalogFilters, ProjectPage, SidebarProject } from '@/types';

const props = defineProps<{ projects: ProjectPage; statuses: string[]; filters: CatalogFilters; tags: string[] }>();
const page = usePage<{ sidebarProjects: SidebarProject[] }>();
const search = reactive({ q: props.filters.q, status: props.filters.status, tag: [...props.filters.tag] });
watch(() => props.filters, filters => Object.assign(search, { q: filters.q, status: filters.status, tag: [...filters.tag] }));
let searchTimer: ReturnType<typeof setTimeout> | undefined;
function applyFilters() {
    clearTimeout(searchTimer);
    router.get('/', Object.fromEntries(Object.entries(search).filter(([, value]) => Array.isArray(value) ? value.length : value)), { preserveState: true, preserveScroll: true, replace: true });
}
function queueSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 250);
}
function clearFilters() {
    Object.assign(search, { q: '', status: '', tag: [] });
    applyFilters();
}
function filterByTag(tag: string) {
    search.tag = search.tag.includes(tag) ? search.tag.filter(value => value !== tag) : [...search.tag, tag];
    applyFilters();
}
onBeforeUnmount(() => clearTimeout(searchTimer));
</script>

<template>
    <div class="flex w-full max-w-[1900px] flex-col gap-8 pb-8">
        <Head title="Dashboard" />
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="flex items-baseline gap-3 text-[2rem] leading-tight font-semibold tracking-[-0.035em]">Projects <span class="text-lg font-medium tracking-normal text-muted-foreground">{{ page.props.sidebarProjects.length }}</span></h1>
            <Button as-child><Link href="/projects/create"><PlusIcon aria-hidden="true" />New project</Link></Button>
        </div>

        <div role="search" class="flex flex-wrap items-center gap-2.5" aria-label="Filter projects">
            <div class="relative min-w-[240px] max-w-[28rem] flex-[1_1_20rem]">
                <label for="catalog-search" class="sr-only">Search projects</label>
                <SearchIcon class="pointer-events-none absolute top-1/2 left-3.5 size-3.5 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                <Input id="catalog-search" v-model="search.q" name="q" placeholder="Search projects" maxlength="255" class="h-9 rounded-full border-0 bg-muted pl-9 text-[13px] shadow-none focus-visible:ring-2 focus-visible:ring-ring/50 md:text-[13px]" @input="queueSearch" @keydown.enter.prevent="applyFilters" />
            </div>
            <div class="min-w-[155px] flex-1 sm:flex-none"><label for="catalog-status" class="sr-only">Status</label><FilterSelect id="catalog-status" label="Status" :model-value="search.status" :options="statuses" all-label="All statuses" @update:model-value="value => { search.status = value; applyFilters(); }" /></div>
            <div class="min-w-[135px] flex-1 sm:flex-none"><label for="catalog-tag" class="sr-only">Tag</label><MultiFilterSelect id="catalog-tag" label="Tag" :model-value="search.tag" :options="tags" @update:model-value="value => { search.tag = value; applyFilters(); }" /></div>
            <button v-if="search.q || search.status || search.tag.length" type="button" class="h-9 px-3 text-[13px] text-muted-foreground transition-colors hover:text-foreground focus-visible:rounded-full focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring" @click="clearFilters">Clear</button>
        </div>

        <section aria-label="Projects" class="flex flex-col gap-4">
            <p v-if="filters.q || filters.status || filters.tag.length" class="text-sm text-muted-foreground">{{ projects.data.length }} {{ projects.data.length === 1 ? 'match' : 'matches' }}</p>
            <div v-if="projects.data.length" class="grid grid-cols-[repeat(auto-fill,minmax(min(100%,21rem),1fr))] gap-4">
                <ProjectCard v-for="project in projects.data" :key="project.id" :project="project" :selected-tags="search.tag" @toggle-tag="filterByTag" />
            </div>
            <div v-else class="flex flex-col items-center px-6 py-16 text-center">
                <span class="flex size-12 items-center justify-center rounded-2xl bg-muted text-muted-foreground"><FolderOpenIcon class="size-5" aria-hidden="true" /></span>
                <h2 class="mt-4 text-base font-semibold">{{ filters.q || filters.status || filters.tag.length ? 'No matching projects' : 'No projects yet' }}</h2>
                <p class="mt-1 max-w-sm text-sm text-muted-foreground">{{ filters.q || filters.status || filters.tag.length ? 'Try another search or clear the filters.' : 'Create a project to start organizing your work.' }}</p>
                <Button v-if="filters.q || filters.status || filters.tag.length" variant="outline" class="mt-5" @click="clearFilters">Clear filters</Button>
                <Button v-else as-child class="mt-5"><Link href="/projects/create"><PlusIcon aria-hidden="true" />New project</Link></Button>
            </div>
        </section>

        <nav v-if="projects.prev_page_url || projects.next_page_url" class="flex justify-end gap-2" aria-label="Project pages">
            <Button v-if="projects.prev_page_url" as-child variant="outline"><Link :href="projects.prev_page_url">Previous</Link></Button>
            <Button v-if="projects.next_page_url" as-child variant="outline"><Link :href="projects.next_page_url">Next</Link></Button>
        </nav>
    </div>
</template>
