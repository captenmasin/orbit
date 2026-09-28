<script setup lang="ts">
import SheetTitle from '@/components/ui/sheet/SheetTitle.vue'
import SheetHeader from '@/components/ui/sheet/SheetHeader.vue'
import SheetDescription from '@/components/ui/sheet/SheetDescription.vue'
import { cn } from '@/lib/utils'
import type { HTMLAttributes } from 'vue'
import { SIDEBAR_WIDTH_MOBILE, useSidebar } from './utils'
import { Sheet, SheetContent } from '@/components/ui/sheet'

defineOptions({
    inheritAttrs: false,
})

const props = defineProps<{ class?: HTMLAttributes['class'] }>()

const { isMobile, state, openMobile, setOpenMobile } = useSidebar()
</script>

<template>
    <Sheet
        v-if="isMobile"
        :open="openMobile"
        v-bind="$attrs"
        @update:open="setOpenMobile">
        <SheetContent
            data-sidebar="sidebar"
            data-slot="sidebar"
            data-mobile="true"
            side="left"
            class="bg-sidebar text-sidebar-foreground w-(--sidebar-width) p-0 [&>button]:hidden"
            :style="{
                '--sidebar-width': SIDEBAR_WIDTH_MOBILE,
            }"
        >
            <SheetHeader class="sr-only">
                <SheetTitle>Sidebar</SheetTitle>
                <SheetDescription>Displays the mobile sidebar.</SheetDescription>
            </SheetHeader>
            <div class="flex h-full w-full flex-col">
                <slot />
            </div>
        </SheetContent>
    </Sheet>

    <div
        v-else
        class="group peer text-sidebar-foreground hidden md:block"
        data-slot="sidebar"
        :data-state="state"
        :data-collapsible="state === 'collapsed' ? 'icon' : ''"
    >
        <div
            data-slot="sidebar-gap"
            class="t-resize relative w-(--sidebar-width) bg-transparent group-data-[collapsible=icon]:w-(--sidebar-width-icon)"
        />
        <div
            data-slot="sidebar-container"
            :class="cn(
                't-resize fixed inset-y-0 left-0 z-10 hidden h-svh w-(--sidebar-width) border-r md:flex group-data-[collapsible=icon]:w-(--sidebar-width-icon)',
                props.class,
            )"
            v-bind="$attrs"
        >
            <div
                data-sidebar="sidebar"
                data-slot="sidebar-inner"
                class="bg-sidebar flex size-full flex-col"
            >
                <slot />
            </div>
        </div>
    </div>
</template>
