<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { ExternalLinkIcon } from '@lucide/vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
const props = defineProps<{ projectId: string; kind: 'folders' | 'repositories' | 'links' | 'secrets'; id: string; native: boolean; href?: string; label?: string; compact?: boolean }>();
const request = useHttp({ target: '' });
async function open() {
    try {
        const result = await request.post(`/projects/${props.projectId}/open/${props.kind}/${props.id}`);
        if (!result) toast.error(String(Object.values(request.errors)[0] ?? 'The item could not be opened. Try again.'));
    } catch {
        toast.error('The item could not be opened. Try again.');
    }
}
</script>

<template>
    <div>
        <Button v-if="!native && href" as-child variant="outline" :size="compact ? 'icon-sm' : 'sm'">
            <a :href="href" target="_blank" rel="noopener noreferrer" :aria-label="compact ? (label ?? 'Open') : undefined"><ExternalLinkIcon aria-hidden="true" /><span v-if="!compact">{{ label ?? 'Open' }}</span></a>
        </Button>
        <Button v-else type="button" variant="outline" :size="compact ? 'icon-sm' : 'sm'" :aria-label="compact ? (label ?? 'Open') : undefined" :disabled="request.processing || !native" @click="open">
            <ExternalLinkIcon aria-hidden="true" /><span v-if="!compact">{{ label ?? 'Open' }}</span>
        </Button>
    </div>
</template>
