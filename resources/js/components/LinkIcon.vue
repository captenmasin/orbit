<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { GlobeIcon } from '@lucide/vue';
const props = defineProps<{ url: string }>();
const failed = ref(false);
const favicon = computed(() => {
    try {
        const url = new URL(props.url);
        return ['https:', 'http:'].includes(url.protocol) && !url.username && !url.password ? `${url.origin}/favicon.ico` : null;
    } catch { return null; }
});
watch(favicon, () => { failed.value = false; });
</script>

<template>
    <img v-if="favicon && !failed" :src="favicon" alt="" class="size-5 shrink-0 object-contain" loading="lazy" referrerpolicy="no-referrer" @error="failed = true" />
    <GlobeIcon v-else class="size-5 shrink-0 text-muted-foreground" aria-hidden="true" />
</template>
