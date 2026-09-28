<script setup lang="ts">
import { cn } from '@/lib/utils'
import type { HTMLAttributes } from 'vue'

const props = defineProps<{
    class?: HTMLAttributes['class']
}>()
</script>

<template>
    <div
        data-slot="card-header"
        :class="cn('group/card-header @container/card-header grid auto-rows-min items-start gap-1 px-5 pt-1.5 pb-2 sm:px-7 has-data-[slot=card-action]:grid-cols-[1fr_auto] has-data-[slot=card-description]:grid-rows-[auto_auto]', props.class)"
    >
        <slot />
    </div>
</template>
