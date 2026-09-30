<script setup lang="ts">
import { cn } from '@/lib/utils'
import { contextMenuItemClass } from '.'
import type { HTMLAttributes } from 'vue'
import { reactiveOmit } from '@vueuse/core'
import { blinkMenuSelection } from '@/lib/menu-selection'
import {
    ContextMenuItem,
    useForwardPropsEmits,
} from 'reka-ui'
import type { ContextMenuItemEmits, ContextMenuItemProps } from 'reka-ui'

const props = withDefaults(defineProps<ContextMenuItemProps & {
    class?: HTMLAttributes['class']
    inset?: boolean
    variant?: 'default' | 'destructive'
}>(), {
    variant: 'default',
})
const emits = defineEmits<ContextMenuItemEmits>()

const delegatedProps = reactiveOmit(props, 'class')

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
    <ContextMenuItem
        data-slot="context-menu-item"
        :data-inset="inset ? '' : undefined"
        :data-variant="variant"
        v-bind="forwarded"
        :class="cn(
            contextMenuItemClass,
            'group/context-menu-item data-inset:pl-9 focus:[&_svg]:text-accent-foreground data-[variant=destructive]:text-destructive data-[variant=destructive]:focus:bg-destructive/10 data-[variant=destructive]:data-highlighted:bg-destructive/10 dark:data-[variant=destructive]:focus:bg-destructive/20 dark:data-[variant=destructive]:data-highlighted:bg-destructive/20 data-[variant=destructive]:focus:text-destructive data-[variant=destructive]:data-highlighted:text-destructive data-[variant=destructive]:[&_svg]:text-destructive',
            props.class,
        )"
        @click.capture="blinkMenuSelection"
    >
        <slot />
    </ContextMenuItem>
</template>

<style scoped>
[data-variant='destructive'] {
    --menu-selection-highlight: color-mix(in oklab, var(--destructive) 10%, transparent);
}

:global(.dark [data-slot='context-menu-item'][data-variant='destructive']) {
    --menu-selection-highlight: color-mix(in oklab, var(--destructive) 20%, transparent);
}
</style>
