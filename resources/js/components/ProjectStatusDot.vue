<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { projectStatusColors } from '@/lib/project';

defineProps<{ status: string }>();
const page = usePage<{ statusColors: Record<string, string> }>();
</script>

<template>
    <span
        class="shrink-0 rounded-full"
        :class="projectStatusColors[page.props.statusColors?.[status] ?? 'gray']?.dotClass ?? projectStatusColors.gray!.dotClass"
        aria-hidden="true" />
</template>
