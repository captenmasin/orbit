<script setup lang="ts">
import ProjectIcon from '@/components/ProjectIcon.vue';
import ChoiceSelect from '@/components/ChoiceSelect.vue';
import ProjectStatusDot from '@/components/ProjectStatusDot.vue';
import { Link } from '@inertiajs/vue3';
import type { Project } from '@/types';
import { PencilIcon } from '@lucide/vue';
import { Button } from '@/components/ui/button';

defineProps<{ project: Project; statuses: string[]; statusDisabled: boolean; statusError: string; statusNeedsReload: boolean }>();
const emit = defineEmits<{ 'change-status': [status: string]; reload: [] }>();
</script>

<template>
    <header class="flex w-full max-w-[1900px] flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
        <div class="flex min-w-0 flex-1 items-start gap-4">
            <ProjectIcon
                :name="project.name"
                :type="project.icon_type"
                :emoji="project.icon_emoji"
                :image="project.icon_url"
                size="lg" />
            <div class="min-w-0 space-y-2">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="min-w-0 break-words text-[2rem] leading-tight font-semibold tracking-[-0.035em]">
                        {{ project.name }}
                    </h1>
                    <span class="flex items-center gap-1.5">
                        <ProjectStatusDot
                            :status="project.status"
                            class="size-2" />
                        <ChoiceSelect
                            :model-value="project.status"
                            :options="statuses"
                            aria-label="Project status"
                            :disabled="statusDisabled"
                            class="data-[size=default]:h-6 gap-1 rounded-sm border-0 bg-transparent py-0 pr-0 pl-0 text-xs text-muted-foreground shadow-none hover:bg-transparent hover:text-foreground dark:bg-transparent dark:hover:bg-transparent focus-visible:ring-2"
                            @update:model-value="emit('change-status', $event)" />
                    </span>
                </div>
                <p
                    v-if="statusError"
                    role="alert"
                    class="flex flex-wrap items-center gap-2 text-xs text-destructive">
                    <span>{{ statusError }}</span><Button
                        v-if="statusNeedsReload"
                        type="button"
                        size="sm"
                        variant="outline"
                        @click="emit('reload')">
                        Reload project
                    </Button>
                </p>
                <p
                    v-if="project.description"
                    class="max-w-2xl text-sm leading-6 whitespace-pre-line break-words text-muted-foreground">
                    {{ project.description }}
                </p>
                <div
                    v-if="project.tags.length"
                    class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                    <template
                        v-for="(tag, index) in project.tags"
                        :key="tag.id">
                        <span
                            v-if="index"
                            aria-hidden="true">·</span><span>{{ tag.name }}</span>
                    </template>
                </div>
                <p
                    v-if="project.archived_at"
                    class="text-xs text-muted-foreground">
                    Archived {{ new Date(project.archived_at).toLocaleString() }}
                </p>
            </div>
        </div>
        <Button
            as-child
            variant="outline"
            class="shrink-0">
            <Link :href="'/projects/' + project.id + '/edit'">
                <PencilIcon aria-hidden="true" />Edit project
            </Link>
        </Button>
    </header>
</template>
