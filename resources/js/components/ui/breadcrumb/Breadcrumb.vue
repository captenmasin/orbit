<script lang="ts" setup>
import { cn } from '@/lib/utils'
import type { HTMLAttributes } from 'vue'

const props = defineProps<{
    class?: HTMLAttributes['class']
}>()
</script>

<template>
    <nav
        aria-label="breadcrumb"
        data-slot="breadcrumb"
        :class="cn('select-none', props.class)"
    >
        <slot />
    </nav>
</template>
