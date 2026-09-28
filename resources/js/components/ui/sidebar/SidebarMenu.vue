<script setup lang="ts">
import { cn } from '@/lib/utils'
import type { HTMLAttributes } from 'vue'

const props = defineProps<{
    class?: HTMLAttributes['class']
}>()
</script>

<template>
    <ul
        data-slot="sidebar-menu"
        data-sidebar="menu"
        :class="cn('gap-1 flex w-full min-w-0 flex-col', props.class)"
    >
        <slot />
    </ul>
</template>
