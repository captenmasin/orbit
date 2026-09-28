<script setup lang="ts">
import ProjectForm from '@/components/ProjectForm.vue';
import type { Project, ProviderConnection } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
defineProps<{ selectedProject: Project; statuses: string[]; native: boolean; connections: ProviderConnection[] }>();
</script>

<template>
    <div class="flex w-full max-w-[1200px] min-w-0 flex-col gap-8 pb-8">
        <Head :title="`Edit ${selectedProject.name}`" />
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-[2rem] leading-tight font-semibold tracking-[-0.035em]">
                Edit {{ selectedProject.name }}
            </h1>
            <Button
                as-child
                variant="outline">
                <Link :href="`/projects/${selectedProject.id}`">
                    View project
                </Link>
            </Button>
        </div>
        <ProjectForm
            :key="selectedProject.id"
            :project="selectedProject"
            :statuses="statuses"
            :native="native"
            :connections="connections" />
    </div>
</template>
