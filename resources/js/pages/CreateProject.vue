<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ProjectForm from '@/components/ProjectForm.vue';
defineProps<{ statuses: string[]; native: boolean }>();
</script>

<template>
    <Head title="New project" />
    <h1 class="text-2xl font-semibold tracking-tight">New project</h1>
    <ProjectForm :statuses="statuses" :native="native" />
</template>
