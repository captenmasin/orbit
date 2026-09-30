<script setup lang="ts">
import { ImageIcon } from '@lucide/vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';

defineProps<{ name: string; type?: string; emoji?: string | null; image?: string | null; size?: 'sm' | 'default' | 'lg' }>();
</script>

<template>
    <Avatar
        :key="type === 'image' && image ? 'image' : 'text'"
        :size="size"
        aria-hidden="true">
        <AvatarImage
            v-if="type === 'image' && image"
            :src="image"
            alt="" />
        <AvatarFallback>
            <template v-if="type === 'emoji' && emoji">
                {{ emoji }}
            </template>
            <template v-else-if="name.trim()">
                {{ name.trim().split(/\s+/).slice(0, 2).map(word => Array.from(word)[0]).join('').toLocaleUpperCase() }}
            </template>
            <ImageIcon
                v-else
                class="size-4" />
        </AvatarFallback>
    </Avatar>
</template>
