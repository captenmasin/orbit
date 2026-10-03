<script setup lang="ts">
import ProjectIcon from '@/components/ProjectIcon.vue';
import ChoiceSelect from '@/components/ChoiceSelect.vue';
import MarkdownContent from '@/components/MarkdownContent.vue';
import ProjectStatusDot from '@/components/ProjectStatusDot.vue';
import { Link } from '@inertiajs/vue3';
import type { Project } from '@/types';
import { PencilIcon } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { useResizeObserver } from '@vueuse/core';
import { nextTick, onMounted, ref, watch } from 'vue';

const props = defineProps<{ project: Project; statuses: string[]; statusDisabled: boolean; statusError: string; statusNeedsReload: boolean }>();
const emit = defineEmits<{ 'change-status': [status: string]; reload: [] }>();
const descriptionElement = ref<HTMLElement | null>(null);
const descriptionExpanded = ref(false);
const descriptionOverflows = ref(false);

function measureDescription() {
    const element = descriptionElement.value;
    if (!element) {
        descriptionOverflows.value = false;
        return;
    }
    if (descriptionExpanded.value) element.classList.add('line-clamp-2');
    descriptionOverflows.value = element.scrollHeight > element.clientHeight;
    if (descriptionExpanded.value) element.classList.remove('line-clamp-2');
}

onMounted(measureDescription);
useResizeObserver(descriptionElement, measureDescription);
watch(() => [props.project.id, props.project.description, props.project.description_html], async () => {
    descriptionExpanded.value = false;
    await nextTick();
    measureDescription();
}, { flush: 'post' });
</script>

<template>
    <header class="flex w-full max-w-[1900px] flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
        <div class="flex min-w-0 flex-1 items-start gap-4">
            <ProjectIcon
                :name="project.name"
                :type="project.icon_type"
                :emoji="project.icon_emoji"
                :image="project.icon_url"
                size="default" />
            <div class="min-w-0 space-y-2">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="min-w-0 break-words text-[1.5rem] leading-tight font-semibold tracking-[-0.035em]">
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
                <div
                    v-if="project.description"
                    class="relative max-w-2xl text-sm leading-6 break-words text-muted-foreground">
                    <div
                        :id="`project-description-${project.id}`"
                        ref="descriptionElement"
                        :class="{ 'line-clamp-2': !descriptionExpanded }">
                        <MarkdownContent :html="project.description_html" />
                    </div>
                    <span
                        v-if="descriptionOverflows"
                        class="inline-flex items-baseline gap-1"
                        :class="{ 'absolute right-0 bottom-0 bg-background pl-2': !descriptionExpanded }">
                        <span
                            v-if="!descriptionExpanded"
                            aria-hidden="true">…</span>
                        <Button
                            type="button"
                            variant="link"
                            size="sm"
                            :aria-expanded="descriptionExpanded"
                            :aria-controls="`project-description-${project.id}`"
                            @click="descriptionExpanded = !descriptionExpanded">
                            {{ descriptionExpanded ? 'Show less' : 'Show more' }}
                        </Button>
                    </span>
                </div>
                <div
                    v-if="project.tags.length"
                    class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                    <template
                        v-for="(tag, index) in project.tags"
                        :key="tag.id">
                        <span
                            v-if="index"
                            aria-hidden="true">·</span><Link
                                :href="`/?tag=${encodeURIComponent(tag.name)}`"
                                :aria-label="`Filter projects by ${tag.name}`"
                                class="rounded-sm hover:text-foreground hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
                                {{ tag.name }}
                            </Link>
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
