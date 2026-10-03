<script setup lang="ts">
import type { TooltipTriggerProps } from 'reka-ui'
import { injectTooltipRootContext, Primitive, TooltipTrigger } from 'reka-ui'

defineOptions({ inheritAttrs: false })

const props = defineProps<TooltipTriggerProps>()
const tooltip = injectTooltipRootContext()
</script>

<template>
    <TooltipTrigger
        data-slot="tooltip-trigger"
        v-bind="props"
        as-child
    >
        <Primitive
            :as="as || 'button'"
            :as-child="asChild"
            v-bind="$attrs"
            :aria-describedby="[$attrs['aria-describedby'], tooltip.open.value ? tooltip.contentId : undefined].filter(Boolean).join(' ') || undefined"
        >
            <slot />
        </Primitive>
    </TooltipTrigger>
</template>
