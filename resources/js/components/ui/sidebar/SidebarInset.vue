<script setup lang="ts">
import { cn } from '@/lib/utils'
import type { HTMLAttributes } from 'vue'

const props = defineProps<{
    class?: HTMLAttributes['class']
}>()
</script>

<template>
    <div
        data-slot="sidebar-inset"
        :class="cn(
            'bg-background relative flex w-full flex-1 flex-col',
            props.class,
        )"
    >
        <slot />
    </div>
</template>
