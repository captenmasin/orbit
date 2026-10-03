<script setup lang="ts">
import SheetOverlay from './SheetOverlay.vue'
import { cn } from '@/lib/utils'
import { XIcon } from '@lucide/vue'
import type { HTMLAttributes } from 'vue'
import { reactiveOmit } from '@vueuse/core'
import { Button } from '@/components/ui/button'
import type { DialogContentEmits, DialogContentProps } from 'reka-ui'
import {
    DialogClose,
    DialogContent,
    DialogPortal,
    useForwardPropsEmits,
} from 'reka-ui'

interface SheetContentProps extends DialogContentProps {
    class?: HTMLAttributes['class']
    side?: 'top' | 'right' | 'bottom' | 'left'
    showCloseButton?: boolean
}

defineOptions({
    inheritAttrs: false,
})

const props = withDefaults(defineProps<SheetContentProps>(), {
    side: 'right',
    showCloseButton: true,
})
const emits = defineEmits<DialogContentEmits>()

const delegatedProps = reactiveOmit(props, 'class', 'side', 'showCloseButton')

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
    <DialogPortal>
        <SheetOverlay />
        <DialogContent
            data-slot="sheet-content"
            :data-side="side"
            :class="cn('t-panel-slide bg-popover text-popover-foreground fixed z-50 flex flex-col gap-4 bg-clip-padding text-sm shadow-lg data-[side=bottom]:inset-x-0 data-[side=bottom]:bottom-0 data-[side=bottom]:h-auto data-[side=bottom]:border-t retina:data-[side=bottom]:border-t-[0.5px] data-[side=left]:inset-y-0 data-[side=left]:left-0 data-[side=left]:h-full data-[side=left]:w-3/4 data-[side=left]:border-r retina:data-[side=left]:border-r-[0.5px] data-[side=right]:inset-y-0 data-[side=right]:right-0 data-[side=right]:h-full data-[side=right]:w-3/4 data-[side=right]:border-l retina:data-[side=right]:border-l-[0.5px] data-[side=top]:inset-x-0 data-[side=top]:top-0 data-[side=top]:h-auto data-[side=top]:border-b retina:data-[side=top]:border-b-[0.5px] data-[side=left]:sm:max-w-sm data-[side=right]:sm:max-w-sm', props.class)"
            v-bind="{ ...$attrs, ...forwarded }"
        >
            <slot />

            <DialogClose
                v-if="showCloseButton"
                data-slot="sheet-close"
                as-child
            >
                <Button
                    variant="ghost"
                    class="absolute top-4 right-4"
                    aria-label="Close"
                    size="icon-sm">
                    <XIcon />
                    <span class="sr-only">Close</span>
                </Button>
            </DialogClose>
        </DialogContent>
    </DialogPortal>
</template>
