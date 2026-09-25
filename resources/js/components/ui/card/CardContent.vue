<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'

const props = defineProps<{
  class?: HTMLAttributes['class']
}>()
</script>

<template>
  <div
    data-slot="card-content"
    :class="cn('flex min-w-0 flex-1 flex-col rounded-[1rem] border border-black/10 bg-background p-4 shadow-[0_1px_2px_rgba(0,0,0,0.03)] group-data-[size=sm]/card:p-3 dark:border-white/10', props.class)"
  >
    <slot />
  </div>
</template>
