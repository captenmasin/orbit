<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ProviderConnections from '@/components/ProviderConnections.vue';
import type { ProviderConnection } from '@/types';
defineProps<{ connections: ProviderConnection[]; native: boolean }>();
</script>

<template>
    <Head title="Connections" />
    <ProviderConnections :native="native" :connections="connections" />
</template>
