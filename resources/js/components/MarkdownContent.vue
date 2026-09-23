<script setup lang="ts">
import { nextTick, onMounted, ref, useId, watch } from 'vue';

const props = defineProps<{ html: string; navigation?: boolean }>();
const content = ref<HTMLElement | null>(null);
const headings = ref<{ id: string; text: string }[]>([]);
const copyMessage = ref('');
const prefix = useId();
async function copyCode(text: string) {
    try {
        await navigator.clipboard.writeText(text);
        copyMessage.value = 'Code copied.';
    } catch { copyMessage.value = 'Code could not be copied. Select the code and copy it manually.'; }
}
function goToHeading(id: string) {
    const heading = document.getElementById(id);
    heading?.scrollIntoView({ block: 'start' });
    heading?.focus({ preventScroll: true });
}
async function enhance() {
    await nextTick();
    if (!content.value) return;
    headings.value = Array.from(content.value.querySelectorAll<HTMLElement>('h1,h2,h3,h4,h5,h6')).map((heading, index) => {
        heading.id = `${prefix}-heading-${index}`;
        heading.tabIndex = -1;
        return { id: heading.id, text: heading.textContent ?? '' };
    });
    content.value.querySelectorAll<HTMLPreElement>('pre').forEach(block => {
        if (block.querySelector('button')) return;
        const code = block.querySelector('code');
        if (!code) return;
        const text = code.textContent ?? '';
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = 'Copy code';
        button.className = 'mb-2 block rounded border px-2 py-1 font-sans text-xs focus-visible:outline-2';
        button.onclick = () => copyCode(text);
        block.prepend(button);
    });
}
onMounted(enhance);
watch(() => props.html, () => { copyMessage.value = ''; void enhance(); }, { flush: 'post' });
</script>

<template>
    <div class="min-w-0 space-y-3">
        <nav v-if="navigation && headings.length" aria-label="Document headings" class="flex flex-wrap gap-x-4 gap-y-2 rounded-md border p-3 text-sm"><button v-for="heading in headings" :key="heading.id" type="button" class="text-left underline underline-offset-4 focus-visible:outline-2" @click="goToHeading(heading.id)">{{ heading.text }}</button></nav>
        <p v-if="copyMessage" role="status" class="text-xs text-muted-foreground">{{ copyMessage }}</p>
    <div
        ref="content"
        class="min-w-0 space-y-3 text-sm break-words [&_a]:underline [&_a]:underline-offset-4 [&_blockquote]:border-l-2 [&_blockquote]:pl-3 [&_blockquote]:text-muted-foreground [&_code]:rounded [&_code]:bg-muted [&_code]:px-1 [&_code]:font-mono [&_h1]:text-xl [&_h2]:text-lg [&_h3]:text-base [&_h1]:font-semibold [&_h2]:font-semibold [&_h3]:font-semibold [&_h4]:font-semibold [&_h5]:font-semibold [&_h6]:font-semibold [&_ol]:list-decimal [&_ol]:pl-5 [&_ul]:list-disc [&_ul]:pl-5 [&_li]:my-1 [&_pre]:overflow-x-auto [&_pre]:rounded-md [&_pre]:bg-muted [&_pre]:p-3 [&_pre_code]:p-0 [&_table]:block [&_table]:overflow-x-auto [&_th]:border [&_th]:p-2 [&_td]:border [&_td]:p-2 [&_img]:max-w-full [&_img]:rounded-md"
        v-html="html"
    />
    </div>
</template>
