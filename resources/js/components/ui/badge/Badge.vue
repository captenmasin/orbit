<script setup lang="ts">
import { cn } from '@/lib/utils'
import { badgeVariants } from '.'
import { Primitive } from 'reka-ui'
import type { BadgeVariants } from '.'
import type { HTMLAttributes } from 'vue'
import { reactiveOmit } from '@vueuse/core'
import type { PrimitiveProps } from 'reka-ui'

const props = defineProps<PrimitiveProps & {
    variant?: BadgeVariants['variant']
    class?: HTMLAttributes['class']
}>()

const delegatedProps = reactiveOmit(props, 'class')
</script>

<template>
    <Primitive
        data-slot="badge"
        :data-variant="variant"
        :class="cn(badgeVariants({ variant }), props.class)"
        v-bind="delegatedProps"
    >
        <slot />
    </Primitive>
</template>
