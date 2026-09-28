<script setup lang="ts">
import { cn } from "@/lib/utils"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import type { ComboboxContentEmits, ComboboxContentProps } from "reka-ui"
import { ComboboxContent, ComboboxPortal, useForwardPropsEmits } from "reka-ui"

defineOptions({
    inheritAttrs: false,
})

const props = withDefaults(defineProps<ComboboxContentProps & { class?: HTMLAttributes["class"] }>(), {
    position: "popper",
    align: "center",
    sideOffset: 4,
})
const emits = defineEmits<ComboboxContentEmits>()

const delegatedProps = reactiveOmit(props, "class")
const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
    <ComboboxPortal>
        <ComboboxContent
            data-slot="combobox-list"
            v-bind="{ ...$attrs, ...forwarded }"
            :class="cn('t-dropdown z-50 w-[200px] rounded-md border bg-popover text-popover-foreground origin-(--reka-combobox-content-transform-origin) overflow-hidden shadow-md outline-none', props.class)"
        >
            <slot />
        </ComboboxContent>
    </ComboboxPortal>
</template>
