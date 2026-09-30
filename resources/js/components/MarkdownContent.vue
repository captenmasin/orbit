<script setup lang="ts">
import { buttonVariants } from '@/components/ui/button';
import { nextTick, onMounted, ref, useId, watch } from 'vue';

const copyIcon = '<svg class="t-icon" data-icon="a" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="8" width="14" height="14" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>';
const checkIcon = '<svg class="t-icon" data-icon="b" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
const props = defineProps<{ html: string; navigation?: boolean }>();
const content = ref<HTMLElement | null>(null);
const headings = ref<{ id: string; text: string }[]>([]);
const copyMessage = ref('');
const prefix = useId();
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
        button.setAttribute('aria-label', 'Copy code');
        button.title = 'Copy code';
        button.innerHTML = `<span class="t-icon-swap" data-state="a" aria-hidden="true">${copyIcon}${checkIcon}</span>`;
        button.className = `${buttonVariants({ variant: 'ghost', size: 'icon-sm' })} absolute top-2 right-2 text-muted-foreground`;
        const icon = button.firstElementChild as HTMLElement;
        let resetTimer: ReturnType<typeof setTimeout> | undefined;
        button.onclick = async () => {
            clearTimeout(resetTimer);
            try {
                await navigator.clipboard.writeText(text);
                icon.dataset.state = 'b';
                button.title = 'Code copied';
                copyMessage.value = 'Code copied.';
                resetTimer = setTimeout(() => { icon.dataset.state = 'a'; button.title = 'Copy code'; }, 2000);
            } catch {
                icon.dataset.state = 'a';
                button.title = 'Copy code';
                copyMessage.value = 'Code could not be copied. Select the code and copy it manually.';
            }
        };
        block.prepend(button);
    });
}
onMounted(enhance);
watch(() => props.html, () => { copyMessage.value = ''; void enhance(); }, { flush: 'post' });
</script>

<template>
    <div class="min-w-0 space-y-3">
        <nav
            v-if="navigation && headings.length"
            aria-label="Document headings"
            class="flex flex-wrap gap-x-4 gap-y-2 rounded-md border p-3 text-sm">
            <button
                v-for="heading in headings"
                :key="heading.id"
                type="button"
                class="text-left underline underline-offset-4 focus-visible:outline-2"
                @click="goToHeading(heading.id)">
                {{ heading.text }}
            </button>
        </nav>
        <div
            ref="content"
            class="min-w-0 space-y-3 text-sm break-words [&_a]:underline [&_a]:underline-offset-4 [&_blockquote]:border-l-2 [&_blockquote]:pl-3 [&_blockquote]:text-muted-foreground [&_code]:rounded [&_code]:bg-muted [&_code]:px-1 [&_code]:font-mono [&_h1]:text-xl [&_h2]:text-lg [&_h3]:text-base [&_h1]:font-normal [&_h2]:font-normal [&_h3]:font-normal [&_h4]:font-normal [&_h5]:font-normal [&_h6]:font-normal [&_ol]:list-decimal [&_ol]:pl-5 [&_ul]:list-disc [&_ul]:pl-5 [&_li]:my-1 [&_pre]:relative [&_pre]:overflow-x-auto [&_pre]:rounded-md [&_pre]:bg-muted [&_pre]:p-3 [&_pre]:pr-12 [&_pre_code]:p-0 [&_table]:block [&_table]:overflow-x-auto [&_th]:border [&_th]:p-2 [&_td]:border [&_td]:p-2 [&_img]:max-w-full [&_img]:rounded-md"
            v-html="html"
        />
        <p
            role="status"
            class="sr-only">
            {{ copyMessage }}
        </p>
    </div>
</template>
