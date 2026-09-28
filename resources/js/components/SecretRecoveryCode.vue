<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';

defineProps<{ code: string }>();
defineEmits<{ saved: [] }>();
</script>

<template>
    <div
        class="grid gap-4 text-left"
        role="status">
        <p class="text-sm font-medium">
            Save your new recovery code
        </p>
        <p class="text-sm leading-6 text-muted-foreground">
            Keep this code in your password manager or another safe place outside Orbit. It can reset your PIN without deleting secrets. It is shown only now and replaces any previous recovery code.
        </p>
        <Textarea
            :model-value="code"
            aria-label="Recovery code"
            readonly
            :rows="3"
            class="font-mono text-sm" />
        <Button
            type="button"
            class="justify-self-start"
            @click="$emit('saved')">
            I've saved my recovery code
        </Button>
    </div>
</template>
