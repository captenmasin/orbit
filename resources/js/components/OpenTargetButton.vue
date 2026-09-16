<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { ExternalLinkIcon } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { FieldError } from '@/components/ui/field';
const props = defineProps<{ projectId: string; kind: 'folders' | 'repositories' | 'links'; id: string; native: boolean; href?: string; label?: string }>();
const request = useHttp({ target: '' });
async function open() {
    try {
        await request.post(`/projects/${props.projectId}/open/${props.kind}/${props.id}`);
    } catch {
        if (!request.hasErrors) request.setError('target', 'The item could not be opened. Try again.');
    }
}
</script>

<template>
    <div>
        <Button v-if="!native && href" as-child variant="outline" size="sm">
            <a :href="href" target="_blank" rel="noopener noreferrer"><ExternalLinkIcon aria-hidden="true" />{{ label ?? 'Open' }}</a>
        </Button>
        <Button v-else type="button" variant="outline" size="sm" :disabled="request.processing || !native" @click="open">
            <ExternalLinkIcon aria-hidden="true" />{{ label ?? 'Open' }}
        </Button>
        <FieldError v-if="request.errors.target" role="alert">{{ request.errors.target }}</FieldError>
    </div>
</template>
