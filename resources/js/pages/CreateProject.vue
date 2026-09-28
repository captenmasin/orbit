<script setup lang="ts">
import ProjectForm from '@/components/ProjectForm.vue';
import { Head } from '@inertiajs/vue3';
import type { ProviderConnection } from '@/types';
defineProps<{ statuses: string[]; native: boolean; connections: ProviderConnection[] }>();
</script>

<template>
    <div class="flex w-full max-w-[1200px] flex-col gap-8 pb-8">
        <Head title="New project" />
        <div class="flex flex-col gap-2">
            <h1 class="text-[2rem] leading-tight font-semibold tracking-[-0.035em]">
                New project
            </h1>
        </div>
        <ProjectForm
            :statuses="statuses"
            :native="native"
            :connections="connections" />
    </div>
</template>
