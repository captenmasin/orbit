<script setup lang="ts">
import { cn } from '@/lib/utils'
import { buttonVariants } from '.'
import type { ButtonVariants } from '.'
import type { HTMLAttributes } from 'vue'
import type { PrimitiveProps } from 'reka-ui'
import { Primitive, useForwardExpose } from 'reka-ui'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'

defineOptions({ inheritAttrs: false })

interface Props extends PrimitiveProps {
    variant?: ButtonVariants['variant']
    size?: ButtonVariants['size']
    class?: HTMLAttributes['class']
}

const props = withDefaults(defineProps<Props>(), {
    as: 'button',
})

const { forwardRef } = useForwardExpose()
</script>

<template>
    <Tooltip
        v-if="size?.startsWith('icon') && ($attrs.title || $attrs['aria-label'])"
        ignore-non-keyboard-focus
    >
        <TooltipTrigger
            :ref="forwardRef"
            data-slot="button"
            :data-variant="variant"
            :data-size="size"
            :as="as"
            :as-child="asChild"
            :class="cn(buttonVariants({ variant, size }), props.class)"
            v-bind="{ ...$attrs, title: undefined }"
        >
            <slot />
        </TooltipTrigger>
        <TooltipContent :side-offset="6">
            {{ $attrs.title || $attrs['aria-label'] }}
        </TooltipContent>
    </Tooltip>
    <Primitive
        v-else
        :ref="forwardRef"
        data-slot="button"
        :data-variant="variant"
        :data-size="size"
        :as="as"
        :as-child="asChild"
        :class="cn(buttonVariants({ variant, size }), props.class)"
        v-bind="$attrs"
    >
        <slot />
    </Primitive>
</template>
