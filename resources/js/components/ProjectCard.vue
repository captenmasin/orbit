<script setup lang="ts">
import ProjectIcon from '@/components/ProjectIcon.vue';
import MarkdownContent from '@/components/MarkdownContent.vue';
import ProjectStatusDot from '@/components/ProjectStatusDot.vue';
import ProjectContextMenu from '@/components/ProjectContextMenu.vue';
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import type { Project } from '@/types';
import { DropdownMenuTrigger } from 'reka-ui';
import { Button } from '@/components/ui/button';
import { dependencyHealth } from '@/lib/dependencies';
import { EllipsisVerticalIcon, ListTodoIcon, PackageIcon } from '@lucide/vue';
import { Card, CardContent, CardFooter, CardHeader } from '@/components/ui/card';

const props = defineProps<{ project: Project; selectedTags: string[] }>();
const emit = defineEmits<{ toggleTag: [tag: string] }>();
// ponytail: completion uses the Done list name; add a completion flag if custom lists need separate semantics.
const todoCount = computed(() => (props.project.board_columns ?? []).reduce((count, column) => count + (column.name.trim().toLowerCase() === 'done' ? 0 : column.tasks_count ?? 0), 0));
const dependencies = computed(() => dependencyHealth(props.project.folders ?? []));
</script>

<template>
    <ProjectContextMenu
        :project="project"
        class="grid [&>[data-slot=context-menu-trigger]]:grid"
        visible>
        <Card as="article">
            <CardHeader class="flex min-w-0 items-center px-3 pt-2 sm:px-3 gap-3">
                <Link
                    :href="`/projects/${project.id}`"
                    class="flex min-w-0 cursor-pointer! flex-1 items-center gap-3 rounded-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
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
            <CardContent class="min-h-32 pb-2">
                <MarkdownContent
                    v-if="project.description"
                    :html="project.description_html"
                    class="line-clamp-2 text-sm leading-6 text-muted-foreground" />
                <Link
                    v-else
                    :href="`/projects/${project.id}/edit?tab=overview`"
                    :aria-label="`Add a description for ${project.name}`"
                    class="self-start rounded-sm text-sm leading-6 text-muted-foreground/80 hover:text-foreground hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
                    Add a description…
                </Link>
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
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs">
                        <Link
                            :href="`/projects/${project.id}?tab=board`"
                            class="flex items-center gap-1.5 rounded-sm hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
                            <ListTodoIcon
                                class="size-3.5"
                                aria-hidden="true" />{{ todoCount }} to do
                        </Link>
                        <Link
                            v-if="dependencies.issueCount || dependencies.complete"
                            :href="`/projects/${project.id}?tab=dependencies`"
                            class="flex items-center gap-1.5 rounded-sm hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                            :class="{ 'text-amber-600 dark:text-amber-400': dependencies.issueCount }">
                            <PackageIcon
                                class="size-3.5"
                                aria-hidden="true" />
                            {{ dependencies.issueCount }} dependency {{ dependencies.issueCount === 1 ? 'issue' : 'issues' }}
                        </Link>
                    </div>
                    <DropdownMenuTrigger
                        class="-mr-2"
                        as-child>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            :aria-label="`Actions for ${project.name}`">
                            <EllipsisVerticalIcon aria-hidden="true" />
                        </Button>
                    </DropdownMenuTrigger>
                </CardFooter>
            </CardContent>
        </Card>
    </ProjectContextMenu>
</template>
