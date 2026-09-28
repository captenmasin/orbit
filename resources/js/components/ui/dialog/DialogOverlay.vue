<script setup lang="ts">
import { cn } from '@/lib/utils'
import { DialogOverlay } from 'reka-ui'
import type { HTMLAttributes } from 'vue'
import { reactiveOmit } from '@vueuse/core'
import type { DialogOverlayProps } from 'reka-ui'

const props = defineProps<DialogOverlayProps & { class?: HTMLAttributes['class'] }>()

const delegatedProps = reactiveOmit(props, 'class')
</script>

<template>
    <DialogOverlay
        data-slot="dialog-overlay"
        v-bind="delegatedProps"
        :class="cn('t-surface-overlay bg-black/10 supports-backdrop-filter:backdrop-blur-xs fixed inset-0 isolate z-50', props.class)"
    >
        <slot />
    </DialogOverlay>
</template>
