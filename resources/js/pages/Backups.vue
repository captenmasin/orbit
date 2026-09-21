<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import WorkspaceBackups from '@/components/WorkspaceBackups.vue';
defineProps<{ native: boolean }>();
</script>

<template>
    <Head title="Backups" />
    <WorkspaceBackups :native="native" />
</template>
