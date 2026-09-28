<script setup lang="ts">
import { cn } from '@/lib/utils'
import type { HTMLAttributes } from 'vue'
import { TooltipProvider } from 'reka-ui'
import { computed, onMounted, ref } from 'vue'
import { defaultDocument, useEventListener, useMediaQuery } from '@vueuse/core'
import { provideSidebarContext, SIDEBAR_COOKIE_MAX_AGE, SIDEBAR_COOKIE_NAME, SIDEBAR_KEYBOARD_SHORTCUT, SIDEBAR_WIDTH, SIDEBAR_WIDTH_ICON } from './utils'

const props = defineProps<{
    class?: HTMLAttributes['class']
}>()

const isMobile = useMediaQuery('(max-width: 768px)')
const openMobile = ref(false)
const tooltipDelay = ref(80)

onMounted(() => {
    tooltipDelay.value = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--tt-delay')) || 80
})

const open = ref(!defaultDocument?.cookie.includes(`${SIDEBAR_COOKIE_NAME}=false`))

function setOpen(value: boolean) {
    open.value = value

    // This sets the cookie to keep the sidebar state.
    document.cookie = `${SIDEBAR_COOKIE_NAME}=${open.value}; path=/; max-age=${SIDEBAR_COOKIE_MAX_AGE}`
}

function setOpenMobile(value: boolean) {
    openMobile.value = value
}

// Helper to toggle the sidebar.
function toggleSidebar() {
    return isMobile.value ? setOpenMobile(!openMobile.value) : setOpen(!open.value)
}

useEventListener('keydown', (event: KeyboardEvent) => {
    if (event.key === SIDEBAR_KEYBOARD_SHORTCUT && (event.metaKey || event.ctrlKey)) {
        event.preventDefault()
        toggleSidebar()
    }
})

// We add a state so that we can do data-state="expanded" or "collapsed".
// This makes it easier to style the sidebar with Tailwind classes.
const state = computed(() => open.value ? 'expanded' : 'collapsed')

provideSidebarContext({
    state,
    open,
    setOpen,
    isMobile,
    openMobile,
    setOpenMobile,
    toggleSidebar,
})
</script>

<template>
    <TooltipProvider :delay-duration="tooltipDelay">
        <div
            data-slot="sidebar-wrapper"
            :style="{
                '--sidebar-width': SIDEBAR_WIDTH,
                '--sidebar-width-icon': SIDEBAR_WIDTH_ICON,
            }"
            :class="cn('group/sidebar-wrapper flex min-h-svh w-full', props.class)"
            v-bind="$attrs"
        >
            <slot />
        </div>
    </TooltipProvider>
</template>
