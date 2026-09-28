<script setup lang="ts">
import { cn } from '@/lib/utils'
import { TabsList } from 'reka-ui'
import { tabsListVariants } from '.'
import type { HTMLAttributes } from 'vue'
import type { TabsListVariants } from '.'
import type { TabsListProps } from 'reka-ui'
import { useTemplateRef, watchPostEffect } from 'vue'
import { reactiveOmit, unrefElement, useMutationObserver, useResizeObserver } from '@vueuse/core'

const props = withDefaults(defineProps<TabsListProps & {
    class?: HTMLAttributes['class']
    variant?: TabsListVariants['variant']
}>(), {
    variant: 'default',
})

const delegatedProps = reactiveOmit(props, 'class', 'variant')
const tabList = useTemplateRef('tabList')
const indicator = useTemplateRef('indicator')

function updateIndicator(animate = true) {
    const activeTab = unrefElement(tabList)?.querySelector<HTMLElement>('[role="tab"][data-state="active"]')
    const underline = indicator.value
    if (!activeTab || !underline) return

    const vertical = activeTab.dataset.orientation === 'vertical'
    if (!animate) underline.style.transition = 'none'
    underline.style.transform = `translate(${activeTab.offsetLeft + (vertical ? activeTab.offsetWidth + 3 : 0)}px, ${activeTab.offsetTop + (vertical ? 0 : activeTab.offsetHeight + 3)}px)`
    underline.style.width = `${vertical ? 2 : activeTab.offsetWidth}px`
    underline.style.height = `${vertical ? activeTab.offsetHeight : 2}px`
    if (!animate) {
        underline.getBoundingClientRect()
        underline.style.transition = ''
    }
}

watchPostEffect(() => updateIndicator(false))
useMutationObserver(tabList, () => updateIndicator(), { attributes: true, attributeFilter: ['data-state'], subtree: true })
useResizeObserver(() => {
    const list = unrefElement(tabList)
    return list ? [list, ...list.querySelectorAll<HTMLElement>('[role="tab"]')] : []
}, () => updateIndicator(false))
</script>

<template>
    <TabsList
        ref="tabList"
        data-slot="tabs-list"
        :data-variant="variant"
        v-bind="delegatedProps"
        :class="cn(tabsListVariants({ variant }), props.class)"
    >
        <slot />
        <span
            v-if="variant === 'line'"
            ref="indicator"
            class="t-tabs-indicator"
            aria-hidden="true"
        />
    </TabsList>
</template>
