<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'

const props = withDefaults(defineProps<{
  class?: HTMLAttributes['class']
  as?: string
  size?: 'default' | 'sm'
}>(), {
  as: 'div',
  size: 'default',
})
</script>

<template>
  <component
    :is="as"
    data-slot="card"
    :data-size="size"
    :class="cn('group/card flex min-w-0 flex-col rounded-[1.25rem] border border-black/8 bg-neutral-50 p-2 text-card-foreground dark:border-white/10 dark:bg-neutral-800', props.class)"
  >
    <slot />
  </component>
</template>
