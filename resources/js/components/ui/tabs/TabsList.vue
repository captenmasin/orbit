<script setup lang="ts">
import { cn } from '@/lib/utils'
import { TabsList } from 'reka-ui'
import { tabsListVariants } from '.'
import type { HTMLAttributes } from 'vue'
import type { TabsListVariants } from '.'
import { reactiveOmit } from '@vueuse/core'
import type { TabsListProps } from 'reka-ui'

const props = withDefaults(defineProps<TabsListProps & {
    class?: HTMLAttributes['class']
    variant?: TabsListVariants['variant']
}>(), {
    variant: 'default',
})

const delegatedProps = reactiveOmit(props, 'class', 'variant')
</script>

<template>
    <TabsList
        data-slot="tabs-list"
        :data-variant="variant"
        v-bind="delegatedProps"
        :class="cn(tabsListVariants({ variant }), props.class)"
    >
        <slot />
    </TabsList>
</template>
