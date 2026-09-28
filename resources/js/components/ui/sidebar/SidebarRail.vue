<script setup lang="ts">
import { cn } from '@/lib/utils'
import { useSidebar } from './utils'
import type { HTMLAttributes } from 'vue'

const props = defineProps<{
    class?: HTMLAttributes['class']
}>()

const { toggleSidebar } = useSidebar()
</script>

<template>
    <button
        data-sidebar="rail"
        data-slot="sidebar-rail"
        aria-label="Toggle Sidebar"
        :tabindex="-1"
        title="Toggle Sidebar"
        :class="cn(
            'hover:after:bg-sidebar-border absolute inset-y-0 -right-4 z-20 hidden w-4 -translate-x-1/2 cursor-w-resize after:transition-colors after:absolute after:inset-y-0 after:start-1/2 after:w-0.5 sm:flex group-data-[state=collapsed]:cursor-e-resize',
            props.class,
        )"
        @click="toggleSidebar"
    >
        <slot />
    </button>
</template>
