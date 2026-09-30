<script setup lang="ts">
import type { BoardColumn, BoardTask } from '@/types';
import { boardColumnColor, boardColumnColors } from '@/lib/project';
import { AlignLeftIcon, ArrowRightIcon, ChevronRightIcon, Trash2Icon } from '@lucide/vue';
import { ContextMenuPortal, ContextMenuSeparator, ContextMenuSub, ContextMenuSubContent, ContextMenuSubTrigger } from 'reka-ui';
import { ContextMenu, ContextMenuContent, ContextMenuItem, ContextMenuTrigger, contextMenuContentClass, contextMenuItemClass } from '@/components/ui/context-menu';

const props = defineProps<{ column: BoardColumn; task: BoardTask; columns: BoardColumn[]; disabled?: boolean; preserveFocus?: boolean }>();
const emit = defineEmits<{
    'update:open': [value: boolean];
    'open-details': [];
    reorder: [direction: number];
    move: [destination: BoardColumn];
    delete: [];
}>();
</script>

<template>
    <ContextMenu @update:open="emit('update:open', $event)">
        <ContextMenuTrigger
            as-child
            :disabled="disabled">
            <slot />
        </ContextMenuTrigger>
        <ContextMenuContent
            class="min-w-44"
            @close-auto-focus="event => { if (preserveFocus) event.preventDefault(); }">
            <ContextMenuItem
                :disabled="disabled"
                @select="emit('open-details')">
                <AlignLeftIcon aria-hidden="true" />
                Open details
            </ContextMenuItem>
            <ContextMenuItem
                :class="contextMenuItemClass"
                :disabled="disabled || column.tasks[0]?.id === task.id"
                @select="emit('reorder', -1)">
                Move card up
            </ContextMenuItem>
            <ContextMenuItem
                :class="contextMenuItemClass"
                :disabled="disabled || column.tasks.at(-1)?.id === task.id"
                @select="emit('reorder', 1)">
                Move card down
            </ContextMenuItem>
            <ContextMenuSub>
                <ContextMenuSubTrigger
                    :disabled="disabled || columns.length < 2"
                    :class="contextMenuItemClass">
                    <ArrowRightIcon
                        class="size-4"
                        aria-hidden="true" />
                    Move to list
                    <ChevronRightIcon
                        class="ml-auto size-4"
                        aria-hidden="true" />
                </ContextMenuSubTrigger>
                <ContextMenuPortal>
                    <ContextMenuSubContent
                        :class="contextMenuContentClass"
                        class="max-h-(--reka-context-menu-content-available-height)">
                        <ContextMenuItem
                            v-for="destination in columns"
                            :key="destination.id"
                            :disabled="disabled || destination.id === column.id"
                            @select="emit('move', destination)">
                            <span
                                class="size-1.5 shrink-0 rounded-full"
                                :class="boardColumnColors[boardColumnColor(destination)]?.dotClass ?? boardColumnColors.gray.dotClass"
                                aria-hidden="true" />
                            {{ destination.name }}
                        </ContextMenuItem>
                    </ContextMenuSubContent>
                </ContextMenuPortal>
            </ContextMenuSub>
            <ContextMenuSeparator class="mx-2.5 my-1.5 h-px bg-border/80" />
            <ContextMenuItem
                variant="destructive"
                :disabled="disabled"
                @select="emit('delete')">
                <Trash2Icon aria-hidden="true" />
                Delete card
            </ContextMenuItem>
        </ContextMenuContent>
    </ContextMenu>
</template>
