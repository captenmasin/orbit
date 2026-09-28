<script setup lang="ts">
import { cn } from '@/lib/utils'
import { TabsContent } from 'reka-ui'
import type { HTMLAttributes } from 'vue'
import { reactiveOmit } from '@vueuse/core'
import type { TabsContentProps } from 'reka-ui'

const props = defineProps<TabsContentProps & { class?: HTMLAttributes['class'] }>()

const delegatedProps = reactiveOmit(props, 'class')
</script>

<template>
    <TabsContent
        data-slot="tabs-content"
        :class="cn('t-tabs-panel text-sm flex-1 outline-none', props.class)"
        v-bind="delegatedProps"
    >
        <slot />
    </TabsContent>
</template>
