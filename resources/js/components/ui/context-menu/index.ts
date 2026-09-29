export { default as ContextMenu } from './ContextMenu.vue'
export { default as ContextMenuContent } from './ContextMenuContent.vue'
export { default as ContextMenuItem } from './ContextMenuItem.vue'
export { default as ContextMenuTrigger } from './ContextMenuTrigger.vue'

export const contextMenuContentClass = 't-dropdown z-50 min-w-48 overflow-x-hidden overflow-y-auto rounded-xl bg-popover/95 p-1.5 text-popover-foreground shadow-xl shadow-black/10 ring-1 ring-foreground/10 backdrop-blur-xl dark:shadow-black/30 retina:ring-[0.5px]'
export const contextMenuItemClass = 'relative flex cursor-default items-center gap-2.5 rounded-lg px-2.5 py-2 text-[13px] leading-4 font-normal outline-hidden select-none transition-colors duration-100 focus:bg-accent focus:text-accent-foreground data-highlighted:bg-accent data-highlighted:text-accent-foreground data-[state=open]:bg-accent data-disabled:pointer-events-none data-disabled:opacity-50 motion-reduce:transition-none [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg]:text-muted-foreground [&_svg:not([class*=size-])]:size-4'
