<script setup lang="ts">
import { toast } from 'vue-sonner';
import { useHttp } from '@inertiajs/vue3';
import { ExternalLinkIcon } from '@lucide/vue';
import { Button } from '@/components/ui/button';
const props = defineProps<{ projectId: string; kind: 'folders' | 'repositories' | 'links' | 'secrets'; id: string; native: boolean; href?: string; label?: string; compact?: boolean; size?: 'sm' | 'input'; text?: boolean }>();
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
        <Button
            v-if="!native && href"
            as-child
            :variant="text ? 'ghost' : 'outline'"
            :class="text ? 'h-auto w-full min-w-0 justify-start whitespace-normal rounded-sm px-0 text-left hover:bg-transparent dark:hover:bg-transparent' : undefined"
            :size="compact ? (size === 'input' ? 'icon' : 'icon-sm') : (size ?? 'sm')">
            <a
                :href="href"
                target="_blank"
                rel="noopener noreferrer"
                :aria-label="compact || text ? (label ?? 'Open') : undefined"><slot v-if="text" /><template v-else><ExternalLinkIcon aria-hidden="true" /><span v-if="!compact">{{ label ?? 'Open' }}</span></template></a>
        </Button>
        <Button
            v-else
            type="button"
            :variant="text ? 'ghost' : 'outline'"
            :class="text ? 'h-auto w-full min-w-0 justify-start whitespace-normal rounded-sm px-0 text-left hover:bg-transparent dark:hover:bg-transparent' : undefined"
            :size="compact ? (size === 'input' ? 'icon' : 'icon-sm') : (size ?? 'sm')"
            :aria-label="compact || text ? (label ?? 'Open') : undefined"
            :disabled="request.processing || !native"
            :aria-describedby="!native && kind === 'folders' ? `folder-open-${id}` : undefined"
            @click="open">
            <slot v-if="text" /><template v-else>
                <ExternalLinkIcon aria-hidden="true" /><span v-if="!compact">{{ label ?? 'Open' }}</span>
            </template>
        </Button>
        <p
            v-if="!native && kind === 'folders'"
            :id="`folder-open-${id}`"
            class="mt-1 text-xs text-muted-foreground">
            Open folders in the Orbit desktop app.
        </p>
    </div>
</template>
