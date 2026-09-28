<script setup lang="ts">
import { cn } from '@/lib/utils'
import type { HTMLAttributes } from 'vue'
import { reactiveOmit } from '@vueuse/core'
import { contextMenuContentClass } from '.'
import type { ContextMenuContentEmits, ContextMenuContentProps } from 'reka-ui'
import {
    ContextMenuContent,
    ContextMenuPortal,
    useForwardPropsEmits,
} from 'reka-ui'

defineOptions({
    inheritAttrs: false,
})

const props = defineProps<ContextMenuContentProps & { class?: HTMLAttributes['class'] }>()
const emits = defineEmits<ContextMenuContentEmits>()

const delegatedProps = reactiveOmit(props, 'class')

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
    <ContextMenuPortal>
        <ContextMenuContent
            data-slot="context-menu-content"
            v-bind="{ ...$attrs, ...forwarded }"
            :class="cn(
                contextMenuContentClass,
                'max-h-(--reka-context-menu-content-available-height)',
                props.class,
            )"
        >
            <slot />
        </ContextMenuContent>
    </ContextMenuPortal>
</template>
