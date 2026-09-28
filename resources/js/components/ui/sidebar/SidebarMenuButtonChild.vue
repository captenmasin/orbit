<script setup lang="ts">
import { cn } from '@/lib/utils'
import { Primitive } from 'reka-ui'
import type { HTMLAttributes } from 'vue'
import type { PrimitiveProps } from 'reka-ui'
import { sidebarMenuButtonVariants } from '.'
import type { SidebarMenuButtonVariants } from '.'

export interface SidebarMenuButtonProps extends PrimitiveProps {
    size?: SidebarMenuButtonVariants['size']
    isActive?: boolean
    class?: HTMLAttributes['class']
}

const props = withDefaults(defineProps<SidebarMenuButtonProps>(), {
    as: 'button',
    size: 'default',
})
</script>

<template>
    <Primitive
        data-slot="sidebar-menu-button"
        data-sidebar="menu-button"
        :data-size="size"
        :data-active="isActive || undefined"
        :class="cn(sidebarMenuButtonVariants({ size }), props.class)"
        :as="as"
        :as-child="asChild"
        v-bind="$attrs"
    >
        <slot />
    </Primitive>
</template>
