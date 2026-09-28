<script setup lang="ts">
defineProps<{ value: number }>();
</script>

<template>
    <span
        :key="value"
        class="t-digit-group is-animating">
        <span class="sr-only">{{ value }}</span>
        <span
            v-for="(digit, index) in String(value)"
            :key="index"
            class="t-digit"
            :style="{ animationDelay: `calc(var(--digit-stagger) * ${Math.min(index, 6)})` }"
            aria-hidden="true">{{ digit }}</span>
    </span>
</template>
