<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import ProviderConnections from '@/components/ProviderConnections.vue';
import { Button } from '@/components/ui/button';
import type { ProviderConnection } from '@/types';
const props = defineProps<{ connections: ProviderConnection[]; native: boolean; mcp: { command: string; args: string[]; env: Record<string, string> } | null }>();
const configuration = computed(() => JSON.stringify({ mcpServers: { orbit: props.mcp } }, null, 2));
const copied = ref(false);
async function copyConfiguration() {
    try { await navigator.clipboard.writeText(configuration.value); copied.value = true; }
    catch { copied.value = false; toast.error('Copy failed. Select the configuration below instead.'); }
}
</script>

<template>
    <Head title="Connections" />
    <ProviderConnections :native="native" :connections="connections" />
    <section v-if="mcp" class="mt-8 space-y-3 rounded-lg border p-5" aria-labelledby="mcp-title">
        <div class="flex items-center justify-between gap-3"><h2 id="mcp-title" class="text-lg font-semibold">Connect an AI client</h2><Button variant="outline" size="sm" @click="copyConfiguration">{{ copied ? 'Copied' : 'Copy configuration' }}</Button></div>
        <p class="text-sm text-muted-foreground">Add this local MCP server to your AI client to manage projects, links, documents, boards, and assets. It can add encrypted secrets but cannot reveal their values.</p>
        <pre class="overflow-x-auto rounded-md bg-muted p-3 text-xs"><code>{{ configuration }}</code></pre>
    </section>
</template>
