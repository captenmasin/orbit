<script setup lang="ts">
import CreateProjectDialog from '@/components/CreateProjectDialog.vue';
import { Head, router } from '@inertiajs/vue3';
defineProps<{ statuses: string[] }>();
</script>

<template>
    <div>
        <Head title="New project" />
        <CreateProjectDialog
            open
            :statuses="statuses"
            @close="router.visit('/')" />
    </div>
</template>
