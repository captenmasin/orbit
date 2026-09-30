<script setup lang="ts">
import type Picker from 'emoji-picker-element/picker';
import ProjectIcon from '@/components/ProjectIcon.vue';
import emojiDataUrl from 'emoji-picker-element-data/en/emojibase/data.json?url';
import { ImagePlusIcon } from '@lucide/vue';
import { appearance } from '@/lib/appearance';
import { usePreferredDark } from '@vueuse/core';
import { Button } from '@/components/ui/button';
import { computed, nextTick, ref, watch } from 'vue';
import { FieldDescription } from '@/components/ui/field';
import type { EmojiClickEvent } from 'emoji-picker-element/shared';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { PopoverContent, PopoverPortal, PopoverRoot, PopoverTrigger } from 'reka-ui';

const props = defineProps<{ id: string; name: string; type: string; emoji: string; image?: string | null; invalid?: boolean }>();
const emit = defineEmits<{ 'update:type': [value: string]; 'update:emoji': [value: string]; 'update:file': [value: File | null] }>();
defineOptions({ inheritAttrs: false });

const open = ref(false);
const tab = ref(props.type);
const ready = ref(false);
const loadError = ref(false);
const picker = ref<Picker>();
const imageInput = ref<HTMLInputElement>();
const systemDark = usePreferredDark();
const dark = computed(() => appearance.value === 'dark' || (appearance.value === 'system' && systemDark.value));

watch(picker, element => {
    if (!element?.shadowRoot) return;
    const style = document.createElement('style');
    style.textContent = `
        input.search {
            background: var(--muted);
            outline: none;
        }
    `;
    element.shadowRoot.append(style);
});

watch(open, value => { if (value) tab.value = props.type; });
watch([open, tab], async ([isOpen, type]) => {
    if (!isOpen || type !== 'emoji') return;
    loadError.value = false;
    try {
        await import('emoji-picker-element/picker');
        ready.value = true;
        await nextTick();
        picker.value?.shadowRoot?.querySelector<HTMLInputElement>('input')?.focus();
    } catch {
        loadError.value = true;
    }
});

function selectEmoji(event: EmojiClickEvent) {
    if (!event.detail.unicode) return;
    emit('update:emoji', event.detail.unicode);
    selectType('emoji');
}

function selectType(type: string) {
    if (type !== 'image') emit('update:file', null);
    emit('update:type', type);
    open.value = false;
}

function selectImage(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;
    emit('update:file', file);
    selectType('image');
}
</script>

<template>
    <PopoverRoot v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                :id="id"
                v-bind="$attrs"
                type="button"
                variant="ghost"
                size="icon"
                title="Change project icon"
                aria-label="Change project icon"
                :aria-invalid="invalid">
                <ProjectIcon
                    :name="name"
                    :type="type"
                    :emoji="emoji"
                    :image="image" />
            </Button>
        </PopoverTrigger>
        <PopoverPortal>
            <PopoverContent
                align="start"
                :side-offset="4"
                :collision-padding="16"
                aria-label="Choose a project icon"
                class="t-dropdown z-50 w-80 max-w-[calc(100vw-2rem)] origin-(--reka-popover-content-transform-origin) overflow-hidden rounded-md border bg-popover text-popover-foreground shadow-md outline-none retina:border-[0.5px]">
                <Tabs
                    v-model="tab"
                    class="gap-0">
                    <div class="border-b p-3">
                        <TabsList
                            class="w-full"
                            aria-label="Icon style">
                            <TabsTrigger value="initials">
                                Initials
                            </TabsTrigger>
                            <TabsTrigger value="emoji">
                                Emoji
                            </TabsTrigger>
                            <TabsTrigger value="image">
                                Image
                            </TabsTrigger>
                        </TabsList>
                    </div>
                    <TabsContent
                        value="initials"
                        class="grid justify-items-center gap-4 p-4">
                        <ProjectIcon
                            :name="name"
                            type="initials"
                            size="lg" />
                        <FieldDescription class="text-center">
                            Generated from the project name.
                        </FieldDescription>
                        <Button
                            type="button"
                            variant="secondary"
                            @click="selectType('initials')">
                            Use initials
                        </Button>
                    </TabsContent>
                    <TabsContent value="emoji">
                        <p
                            v-if="loadError"
                            class="p-4 text-sm text-destructive"
                            role="alert">
                            Could not load the emoji picker. Close it and try again.
                        </p>
                        <emoji-picker
                            v-else-if="ready"
                            ref="picker"
                            :class="dark ? 'dark' : 'light'"
                            :data-source="emojiDataUrl"
                            @emoji-click="selectEmoji" />
                        <p
                            v-else
                            class="p-4 text-sm text-muted-foreground"
                            role="status">
                            Loading emoji…
                        </p>
                    </TabsContent>
                    <TabsContent
                        value="image"
                        class="grid justify-items-center gap-4 p-4">
                        <ProjectIcon
                            v-if="image"
                            :name="name"
                            type="image"
                            :image="image"
                            size="lg" />
                        <Button
                            type="button"
                            variant="secondary"
                            @click="imageInput?.click()">
                            <ImagePlusIcon aria-hidden="true" />{{ image ? 'Replace image' : 'Choose image' }}
                        </Button>
                        <input
                            ref="imageInput"
                            type="file"
                            accept="image/png,image/jpeg,image/gif,image/webp"
                            class="hidden"
                            aria-label="Project icon image"
                            @change="selectImage">
                        <FieldDescription class="text-center text-xs">
                            PNG, JPEG, GIF or WebP. Up to 5 MB and 2048 × 2048 pixels.
                        </FieldDescription>
                        <Button
                            v-if="image"
                            type="button"
                            @click="selectType('image')">
                            Use image
                        </Button>
                    </TabsContent>
                </Tabs>
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>

<style scoped>
emoji-picker {
    width: 100%;
    height: min(24rem, calc(var(--reka-popover-content-available-height) - 4rem));
    --num-columns: 7;
    --background: var(--popover);
    --border-size: 0;
    --button-active-background: var(--muted);
    --button-hover-background: var(--accent);
    --category-emoji-padding: 0.375rem;
    --category-emoji-size: 1.125rem;
    --category-font-color: var(--foreground);
    --indicator-color: var(--foreground);
    --input-border-radius: 9999px;
    --input-border-size: 0;
    --input-font-color: var(--foreground);
    --input-font-size: 13px;
    --input-line-height: 20px;
    --input-padding: 0.5rem 0.875rem;
    --input-placeholder-color: var(--muted-foreground);
    --outline-color: var(--ring);
}
</style>
