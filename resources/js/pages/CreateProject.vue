<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ProjectForm from '@/components/ProjectForm.vue';
import type { ProviderConnection } from '@/types';
defineProps<{ statuses: string[]; native: boolean; connections: ProviderConnection[] }>();
</script>

<template>
    <div class="flex w-full max-w-[1200px] flex-col gap-8 pb-8">
        <Head title="New project" />
        <div class="flex flex-col gap-2">
            <h1 class="text-[2rem] leading-tight font-semibold tracking-[-0.035em]">New project</h1>
            <p class="max-w-2xl text-sm leading-6 text-muted-foreground">Bring your work together in one place. Start with the essentials, then connect folders, repositories, and links if you have them.</p>
        </div>
        <ProjectForm :statuses="statuses" :native="native" :connections="connections" />
    </div>
</template>
