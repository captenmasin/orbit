<script setup lang="ts">
import { TagsInputRoot, TagsInputInput, TagsInputItem, TagsInputItemText, TagsInputItemDelete } from 'reka-ui';
import { XIcon } from '@lucide/vue';
const tags = defineModel<string[]>({ required: true });
defineProps<{ id: string; invalid?: boolean }>();
</script>

<template>
    <TagsInputRoot v-model="tags" :max="100" :convert-value="value => value.trim().toLowerCase()" add-on-blur add-on-paste add-on-tab class="flex min-h-10 flex-wrap items-center gap-2 rounded-md border border-input bg-transparent px-3 py-2 shadow-xs focus-within:ring-2 focus-within:ring-ring">
        <TagsInputItem v-for="tag in tags" :key="tag" :value="tag" class="flex items-center gap-1 rounded-md bg-secondary px-2 py-1 text-sm data-[state=active]:ring-2 data-[state=active]:ring-ring">
            <TagsInputItemText />
            <TagsInputItemDelete type="button" :aria-label="`Remove ${tag}`" aria-labelledby="" class="rounded-sm p-0.5 focus-visible:outline-2"><XIcon class="size-3" aria-hidden="true" /></TagsInputItemDelete>
        </TagsInputItem>
        <TagsInputInput :id="id" :aria-invalid="invalid" :aria-describedby="`${id}-hint`" maxlength="50" placeholder="Add a tag…" class="min-w-28 flex-1 bg-transparent text-sm outline-none" />
    </TagsInputRoot>
    <p :id="`${id}-hint`" class="text-sm text-muted-foreground">Press Enter or comma to add a tag.</p>
</template>
