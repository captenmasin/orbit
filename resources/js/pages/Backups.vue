<script setup lang="ts">
import WorkspaceBackups from '@/components/WorkspaceBackups.vue';
import { Head } from '@inertiajs/vue3';
defineProps<{ native: boolean }>();
</script>

<template>
    <div class="flex min-w-0 flex-col gap-6">
        <Head title="Backups" />
        <WorkspaceBackups :native="native" />
    </div>
</template>
