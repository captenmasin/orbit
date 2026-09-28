<script setup lang="ts">
import { CollapsibleContent } from 'reka-ui'
import type { CollapsibleContentProps } from 'reka-ui'

const props = defineProps<CollapsibleContentProps>()
</script>

<template>
    <CollapsibleContent
        data-slot="collapsible-content"
        class="t-acc-content"
        v-bind="props"
    >
        <slot />
    </CollapsibleContent>
</template>
