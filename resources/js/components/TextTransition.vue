<script setup lang="ts">
defineProps<{ text: string; shimmer?: boolean }>();
</script>

<template>
    <span class="inline-grid">
        <span class="sr-only">{{ text }}</span>
        <Transition
            mode="out-in"
            enter-from-class="is-enter-start"
            leave-to-class="is-exit">
            <span
                :key="text"
                aria-hidden="true"
                class="t-text-swap"
                :class="shimmer ? 't-shimmer' : undefined"
                :data-text="shimmer ? text : undefined">{{ text }}</span>
        </Transition>
    </span>
</template>
