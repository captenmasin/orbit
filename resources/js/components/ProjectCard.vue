<script setup lang="ts">
import ProjectIcon from '@/components/ProjectIcon.vue';
import ProjectStatusDot from '@/components/ProjectStatusDot.vue';
import ProjectContextMenu from '@/components/ProjectContextMenu.vue';
import { Link } from '@inertiajs/vue3';
import type { Project } from '@/types';
import { Card, CardContent, CardFooter, CardHeader } from '@/components/ui/card';

defineProps<{ project: Project; selectedTags: string[] }>();
const emit = defineEmits<{ toggleTag: [tag: string] }>();
const date = (value: string) => new Date(value).toLocaleDateString();
</script>

<template>
    <ProjectContextMenu :project="project">
        <Card as="article">
            <CardHeader class="flex min-w-0 items-center px-3 pt-2 sm:px-3 gap-3">
                <Link
                    :href="`/projects/${project.id}`"
                    class="flex min-w-0 flex-1 items-center gap-3 rounded-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
                    <ProjectIcon
                        :name="project.name"
                        :type="project.icon_type"
                        :emoji="project.icon_emoji"
                        :image="project.icon_url"
                        size="sm" />
                    <h2 class="min-w-0 flex-1 truncate text-sm font-normal tracking-[-0.02em]">
                        {{ project.name }}
                    </h2>
                </Link>
                <span class="flex shrink-0 items-center gap-1.5 text-xs text-muted-foreground"><ProjectStatusDot
                    :status="project.status"
                    class="size-2" />{{ project.status }}</span>
            </CardHeader>
            <CardContent class="min-h-32">
                <p
                    v-if="project.description"
                    class="line-clamp-2 text-sm leading-6 text-muted-foreground">
                    {{ project.description }}
                </p>
                <div
                    v-if="project.tags.length"
                    class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                    <template
                        v-for="(tag, index) in project.tags"
                        :key="tag.id">
                        <span
                            v-if="index"
                            class="text-muted-foreground/80"
                            aria-hidden="true">·</span><button
                                type="button"
                                class="rounded-sm hover:text-foreground hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                :class="selectedTags.includes(tag.name) ? 'font-medium text-foreground' : 'text-muted-foreground/80'"
                                :aria-label="`Filter projects by ${tag.name}`"
                                :aria-pressed="selectedTags.includes(tag.name)"
                                @click="emit('toggleTag', tag.name)">
                                {{ tag.name }}
                            </button>
                    </template>
                </div>
                <CardFooter class="flex-wrap justify-between gap-x-4 gap-y-1 text-muted-foreground">
                    <span class="text-xs">{{ project.repositories_count ?? 0 }} repos <span
                        class="px-1"
                        aria-hidden="true">·</span> {{ project.folders_count ?? 0 }} folders</span>
                    <div class="flex items-center gap-4">
                        <time
                            v-if="project.last_commit_at"
                            :datetime="project.last_commit_at"
                            class="text-xs">{{ date(project.last_commit_at) }}</time><span
                                v-else
                                class="text-xs">—</span>
                        <Link
                            :href="`/projects/${project.id}/edit`"
                            class="text-sm hover:text-foreground hover:underline focus-visible:rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
                            Edit
                        </Link>
                    </div>
                </CardFooter>
            </CardContent>
        </Card>
    </ProjectContextMenu>
</template>
