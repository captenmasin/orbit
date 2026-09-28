<script setup lang="ts">
import { cn } from '@/lib/utils'
import type { HTMLAttributes } from 'vue'

const props = defineProps<{
    class?: HTMLAttributes['class']
}>()
</script>

<template>
    <div
        data-slot="card-footer"
        :class="cn('mt-auto flex items-center pt-4', props.class)"
    >
        <slot />
    </div>
</template>
