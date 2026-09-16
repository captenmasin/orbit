<script setup lang="ts">
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';

defineProps<{ name: string; type?: string; emoji?: string | null; image?: string | null; size?: 'sm' | 'default' | 'lg' }>();
</script>

<template>
    <Avatar :size="size" aria-hidden="true">
        <AvatarImage v-if="type === 'image' && image" :src="image" alt="" />
        <AvatarFallback>{{ type === 'emoji' && emoji ? emoji : name.trim().split(/\s+/).slice(0, 2).map(word => Array.from(word)[0]).join('').toLocaleUpperCase() || '?' }}</AvatarFallback>
    </Avatar>
</template>
