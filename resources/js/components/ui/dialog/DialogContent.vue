<script setup lang="ts">
import DialogOverlay from './DialogOverlay.vue'
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

defineOptions({
    inheritAttrs: false,
})

const props = withDefaults(defineProps<DialogContentProps & { class?: HTMLAttributes['class'], showCloseButton?: boolean }>(), {
    showCloseButton: true,
})
const emits = defineEmits<DialogContentEmits>()

const delegatedProps = reactiveOmit(props, 'class')

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
    <DialogPortal>
        <DialogOverlay />
        <DialogContent
            data-slot="dialog-content"
            v-bind="{ ...$attrs, ...forwarded }"
            :class="cn('t-modal bg-popover text-popover-foreground ring-inset ring-foreground/10 grid grid-cols-1 max-w-[calc(100%-2rem)] gap-6 rounded-xl p-6 text-sm ring-1 retina:ring-[0.5px] sm:max-w-md fixed top-1/2 left-1/2 z-50 w-full -translate-x-1/2 -translate-y-1/2 outline-none', props.class)"
        >
            <slot />

            <DialogClose
                v-if="showCloseButton"
                data-slot="dialog-close"
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
