<script setup lang="ts">
import { XIcon } from '@lucide/vue';
import { TagsInputRoot, TagsInputInput, TagsInputItem, TagsInputItemText, TagsInputItemDelete } from 'reka-ui';
const tags = defineModel<string[]>({ required: true });
defineProps<{ id: string; invalid?: boolean }>();
</script>

<template>
    <TagsInputRoot
        v-model="tags"
        :max="100"
        :convert-value="value => value.trim().toLowerCase()"
        add-on-blur
        add-on-paste
        add-on-tab
        :class="tags.length ? 'px-1.5' : 'px-3.5'"
        class="flex min-h-10 flex-wrap items-center gap-2 rounded-full bg-muted py-1.5 focus-within:ring-2 focus-within:ring-ring">
        <TagsInputItem
            v-for="tag in tags"
            :key="tag"
            :value="tag"
            class="flex select-none items-center gap-1 rounded-full bg-background px-2 py-1 text-[13px] data-[state=active]:ring-2 data-[state=active]:ring-ring">
            <TagsInputItemText />
            <TagsInputItemDelete
                type="button"
                :aria-label="`Remove ${tag}`"
                aria-labelledby=""
                class="rounded-sm p-0.5 focus-visible:outline-2">
                <XIcon
                    class="size-3"
                    aria-hidden="true" />
            </TagsInputItemDelete>
        </TagsInputItem>
        <TagsInputInput
            :id="id"
            :aria-invalid="invalid"
            :aria-describedby="`${id}-hint${invalid ? ` ${id}-error` : ''}`"
            maxlength="50"
            placeholder="Add a tag…"
            class="min-w-28 flex-1 bg-transparent text-[13px] outline-none" />
    </TagsInputRoot>
    <p
        :id="`${id}-hint`"
        class="text-xs -mt-2 ml-2 text-muted-foreground">
        Press Enter or comma to add a tag.
    </p>
</template>
