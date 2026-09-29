<script setup lang="ts">
import { cn } from '@/lib/utils'
import type { HTMLAttributes } from 'vue'
import { reactiveOmit } from '@vueuse/core'
import { SelectScrollDownButton, SelectScrollUpButton } from '.'
import type { SelectContentEmits, SelectContentProps } from 'reka-ui'
import {
    SelectContent,
    SelectPortal,
    SelectViewport,
    useForwardPropsEmits,
} from 'reka-ui'

defineOptions({
    inheritAttrs: false,
})

const props = withDefaults(
    defineProps<SelectContentProps & { class?: HTMLAttributes['class'] }>(),
    {
        position: 'item-aligned',
        align: 'center',
    },
)
const emits = defineEmits<SelectContentEmits>()

const delegatedProps = reactiveOmit(props, 'class')

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
    <SelectPortal>
        <SelectContent
            data-slot="select-content"
            :data-align-trigger="position === 'item-aligned'"
            v-bind="{ ...$attrs, ...forwarded }"
            :class="cn(
                't-dropdown bg-popover p-2 text-popover-foreground ring-foreground/10 min-w-36 rounded-xl shadow-md ring-1 retina:ring-[0.5px] cn-menu-translucent relative z-50 max-h-(--reka-select-content-available-height) origin-(--reka-select-content-transform-origin) overflow-x-hidden overflow-y-auto',
                position === 'popper'
                    && 'data-[side=bottom]:translate-y-1 data-[side=left]:-translate-x-1 data-[side=right]:translate-x-1 data-[side=top]:-translate-y-1',
                props.class,
            )
            "
        >
            <SelectScrollUpButton />
            <SelectViewport
                :data-position="position"
                :class="cn(
                    'data-[position=popper]:h-(--reka-select-trigger-height) data-[position=popper]:w-full data-[position=popper]:min-w-(--reka-select-trigger-width)',
                )"
            >
                <slot />
            </SelectViewport>
            <SelectScrollDownButton />
        </SelectContent>
    </SelectPortal>
</template>
