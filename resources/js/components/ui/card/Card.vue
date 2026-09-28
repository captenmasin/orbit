<script setup lang="ts">
import { cn } from '@/lib/utils'
import {Comment, computed, useSlots, type HTMLAttributes} from 'vue'

const props = withDefaults(defineProps<{
    class?: HTMLAttributes['class']
    as?: string
    size?: 'default' | 'sm'
}>(), {
    as: 'div',
    size: 'default',
})

const slots = useSlots()
const hasDefaultSlot = computed(() => slots.default?.().some(({ type }) => type !== Comment) ?? false)
</script>

<template>
    <component
        :is="as"
        data-slot="card"
        :data-size="size"
        :class="cn(
            'group/card flex min-w-0 flex-col rounded-[1.25rem] border border-black/8 bg-neutral-50 p-1 text-card-foreground dark:border-white/10 dark:bg-neutral-800',
            props.class
        )"
    >
        <slot />
    </component>
</template>
