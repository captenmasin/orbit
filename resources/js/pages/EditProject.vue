<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import ProjectForm from '@/components/ProjectForm.vue';
import { Button } from '@/components/ui/button';
import type { Project } from '@/types';
defineProps<{ selectedProject: Project; statuses: string[]; native: boolean; message: string | null }>();
</script>

<template>
    <Head :title="`Edit ${selectedProject.name}`" />
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-semibold tracking-tight">Edit {{ selectedProject.name }}</h1>
        <Button as-child variant="outline"><Link :href="`/projects/${selectedProject.id}`">View project</Link></Button>
    </div>
    <ProjectForm :key="selectedProject.id" :project="selectedProject" :statuses="statuses" :native="native" :message="message" />
</template>
