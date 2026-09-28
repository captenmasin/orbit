<script setup lang="ts">
import TextTransition from '@/components/TextTransition.vue';
import { toast } from 'vue-sonner';
import type { SearchResult } from '@/types';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Link, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Field, FieldLabel } from '@/components/ui/field';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { ComboboxGroup, ComboboxItem } from '@/components/ui/combobox';
import { ComboboxContent, ComboboxInput, ComboboxRoot, ComboboxViewport } from 'reka-ui';
import { ArrowDownIcon, ArrowRightIcon, ArrowUpIcon, CommandIcon, CornerDownLeftIcon, FileTextIcon, FolderIcon, KeyRoundIcon, LinkIcon, ListTodoIcon, LoaderCircleIcon, SearchIcon, XIcon } from '@lucide/vue';

const props = withDefaults(defineProps<{ projectId?: string; preferredProjectId?: string; recentItems?: SearchResult[]; compact?: boolean; launcher?: boolean }>(), { compact: false, launcher: false, recentItems: () => [] });
const emit = defineEmits<{ navigate: [result?: SearchResult]; close: []; clearRecents: [] }>();
const query = ref('');
const results = ref<SearchResult[]>([]);
const searched = ref(false);
const loading = ref(false);
const hasMore = ref(false);
const error = ref('');
const combobox = ref<{ highlightFirstItem: () => void }>();
const highlightedResult = ref<SearchResult>();
const matchTerm = ref('');
const filters = ref<{ type: string | null; project: string | null }>({ type: null, project: null });
const resultIcons: Record<string, typeof FolderIcon> = { project: FolderIcon, document: FileTextIcon, task: ListTodoIcon, link: LinkIcon, secret: KeyRoundIcon, command: CommandIcon };
const commands: (SearchResult & { keywords: string })[] = [
    { id: 'new-project', title: 'New project', keywords: 'create add project', url: '/projects/create', type: 'command', project: 'Workspace', excerpt: '' },
    { id: 'settings', title: 'Settings', keywords: 'preferences appearance tools', url: '/settings', type: 'command', project: 'Workspace', excerpt: '' },
    { id: 'backups', title: 'Backups', keywords: 'backup restore export', url: '/settings/backups', type: 'command', project: 'Workspace', excerpt: '' },
    { id: 'dashboard', title: 'Go to dashboard', keywords: 'home workspace projects', url: '/', type: 'command', project: 'Workspace', excerpt: '' },
];
const matchingCommands = computed(() => {
    if (/(?:^|\s)(?:type|project):/i.test(query.value)) return [];
    const words = query.value.trim().toLowerCase().split(/\s+/);
    return commands.filter(command => words.every(word => `${command.title} ${command.keywords}`.toLowerCase().includes(word)));
});
const resultGroups = computed(() => query.value.trim()
    ? [{ heading: 'Commands', items: matchingCommands.value }, { heading: 'Results', items: results.value }]
    : [{ heading: 'Recent items', items: props.recentItems }, { heading: 'Commands', items: matchingCommands.value }]);
const itemCount = computed(() => resultGroups.value.reduce((count, group) => count + group.items.length, 0));
let request: AbortController | undefined;
let searchTimer: ReturnType<typeof setTimeout> | undefined;

function highlightedParts(text: string): string[] {
    if (!matchTerm.value) return [text];
    return text.split(new RegExp(`(${matchTerm.value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'ig'));
}

async function highlightFirstResult() {
    await nextTick();
    combobox.value?.highlightFirstItem();
}

function resetSearch() {
    clearTimeout(searchTimer);
    request?.abort();
    query.value = '';
    results.value = [];
    searched.value = false;
    loading.value = false;
    hasMore.value = false;
    error.value = '';
    highlightedResult.value = undefined;
    matchTerm.value = '';
    filters.value = { type: null, project: null };
}

function handleFocusOut(event: FocusEvent) {
    if (!(event.currentTarget as HTMLElement).contains(event.relatedTarget as Node | null)) resetSearch();
}

async function search() {
    clearTimeout(searchTimer);
    request?.abort();
    const current = new AbortController();
    request = current;
    loading.value = true;
    error.value = '';
    try {
        const parameters = new URLSearchParams({ q: query.value });
        if (props.projectId) parameters.set('project_id', props.projectId);
        if (props.preferredProjectId) parameters.set('preferred_project_id', props.preferredProjectId);
        const response = await fetch(`/search?${parameters}`, { signal: current.signal, headers: { Accept: 'application/json' } });
        const body = await response.json() as { results: SearchResult[]; has_more: boolean; term?: string; filters?: { type: string | null; project: string | null }; errors?: { q?: string[] } };
        if (current.signal.aborted) return;
        if (!response.ok) {
            error.value = body.errors?.q?.[0] ?? 'Search could not be completed. Try again.';
            throw new Error();
        }
        results.value = body.results;
        hasMore.value = body.has_more;
        matchTerm.value = body.term ?? query.value.trim();
        filters.value = body.filters ?? { type: null, project: null };
        searched.value = true;
        await nextTick();
        if (!current.signal.aborted) combobox.value?.highlightFirstItem();
    } catch {
        if (!current.signal.aborted) {
            error.value ||= 'Search could not be completed. Try again.';
            if (!props.launcher) toast.error(error.value);
        }
    } finally {
        if (!current.signal.aborted) loading.value = false;
    }
}
function openResult(result?: SearchResult) {
    if (!result || (loading.value && result.type !== 'command')) return;
    router.visit(result.url);
    emit('navigate', result);
}
watch(query, () => {
    clearTimeout(searchTimer);
    request?.abort();
    results.value = [];
    highlightedResult.value = undefined;
    hasMore.value = false;
    searched.value = false;
    error.value = '';
    matchTerm.value = query.value.trim();
    filters.value = { type: null, project: null };
    loading.value = props.launcher && Boolean(query.value.trim());
    if (loading.value) searchTimer = setTimeout(search, 200);
    void highlightFirstResult();
}, { flush: 'sync' });
watch(() => props.projectId, resetSearch);
watch(() => props.recentItems, () => {
    if (props.launcher && !query.value.trim()) {
        highlightedResult.value = undefined;
        void highlightFirstResult();
    }
});
watch(combobox, () => { if (props.launcher) void highlightFirstResult(); });
onBeforeUnmount(() => { clearTimeout(searchTimer); request?.abort(); });
</script>

<template>
    <div
        class="relative min-w-0"
        :class="compact ? 'group/search w-full after:absolute after:inset-x-0 after:bottom-[-5px] after:h-0.5 after:bg-foreground after:opacity-0 has-[input:focus]:after:opacity-100' : !launcher && 'grid gap-3'"
        @keydown.esc="compact && emit('close')"
        @focusout="compact && handleFocusOut($event)">
        <ComboboxRoot
            v-if="launcher"
            ref="combobox"
            :open="true"
            ignore-filter
            :reset-search-term-on-blur="false"
            :reset-search-term-on-select="false"
            @highlight="highlightedResult = $event?.value as SearchResult | undefined">
            <div
                class="flex h-13 items-center gap-3 bg-muted px-5 sm:gap-4 sm:px-6"
                :class="itemCount ? 'rounded-t-3xl' : 'rounded-full'">
                <span
                    class="t-icon-swap shrink-0 text-muted-foreground/70"
                    :data-state="loading ? 'b' : 'a'"
                    aria-hidden="true"><span
                        class="t-icon"
                        data-icon="a"><SearchIcon class="size-5" /></span><span
                            class="t-icon"
                            data-icon="b"><LoaderCircleIcon
                                class="size-5"
                                :class="loading ? 'animate-spin motion-reduce:animate-none' : undefined" /></span></span>
                <ComboboxInput
                    id="workspace-content-search"
                    v-model="query"
                    auto-focus
                    aria-label="Search workspace"
                    maxlength="255"
                    placeholder="Search or run a command…"
                    class="h-auto min-w-0 flex-1 border-0 bg-transparent p-0 text-base font-normal outline-none placeholder:text-muted-foreground" />
            </div>
            <ComboboxContent
                class="min-w-0"
                @escape-key-down.prevent="emit('close')"
                @pointer-down-outside="!$event.defaultPrevented && emit('close')">
                <div
                    v-if="query.trim() || recentItems.length"
                    class="flex items-center justify-between gap-3 px-6 pb-2 text-xs text-muted-foreground"
                    role="status"
                    aria-live="polite">
                    <span v-if="query.trim()"><TextTransition
                        :text="loading ? 'Searching…' : error ? 'Search unavailable' : `${itemCount}${hasMore ? '+' : ''} result${itemCount === 1 && !hasMore ? '' : 's'}`"
                        :shimmer="loading" /></span>
                    <Button
                        v-if="!query.trim() && recentItems.length"
                        variant="ghost"
                        size="xs"
                        class="ml-auto"
                        aria-label="Clear recent items"
                        @click="emit('clearRecents')">
                        Clear recent
                    </Button>
                </div>
                <div
                    v-if="filters.type || filters.project"
                    class="flex flex-wrap gap-2 px-6 pb-2">
                    <Badge
                        v-if="filters.type"
                        variant="secondary">
                        {{ filters.type === 'task' ? 'Cards (type:task)' : `type:${filters.type}` }}
                    </Badge><Badge
                        v-if="filters.project"
                        variant="secondary">
                        project:{{ filters.project }}
                    </Badge>
                </div>
                <p class="px-6 pb-3 text-xs text-muted-foreground">
                    Search projects, documents, cards, links and secret metadata. Scratchpad notes, assets and source folders are not included.
                </p>
                <ComboboxViewport class="max-h-[min(55vh,28rem)] scroll-py-2 overflow-y-auto px-2 pb-2">
                    <template
                        v-for="group in resultGroups"
                        :key="group.heading">
                        <ComboboxGroup
                            v-if="group.items.length"
                            :heading="group.heading"
                            class="p-0">
                            <ComboboxItem
                                v-for="result in group.items"
                                :key="`${result.type}-${result.id}`"
                                :value="result"
                                :text-value="result.title"
                                :title="result.excerpt || result.title"
                                class="min-h-12 cursor-pointer gap-3 rounded-xl px-3 py-2 data-[highlighted]:bg-foreground/10 sm:px-4"
                                @select.prevent="openResult(result)">
                                <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-background/80 ring-1 ring-foreground/5"><component
                                    :is="resultIcons[result.type] ?? FileTextIcon"
                                    class="size-4 text-muted-foreground"
                                    aria-hidden="true" /></span>
                                <span class="flex min-w-0 flex-1 flex-col gap-1">
                                    <span class="flex min-w-0 flex-col gap-1 sm:flex-row sm:items-baseline sm:gap-3">
                                        <span class="truncate text-base font-medium"><template
                                            v-for="(part, index) in highlightedParts(result.title)"
                                            :key="index"><mark
                                                v-if="index % 2"
                                                class="rounded-sm bg-amber-200/70 text-inherit dark:bg-amber-400/25">{{ part }}</mark><template v-else>{{ part }}</template></template></span>
                                        <span
                                            v-if="result.type !== 'project'"
                                            class="truncate text-xs text-muted-foreground sm:text-sm">{{ result.project }}</span>
                                    </span>
                                    <span
                                        v-if="result.excerpt"
                                        class="truncate text-xs text-muted-foreground"><template
                                            v-for="(part, index) in highlightedParts(result.excerpt)"
                                            :key="index"><mark
                                                v-if="index % 2"
                                                class="rounded-sm bg-amber-200/70 text-inherit dark:bg-amber-400/25">{{ part }}</mark><template v-else>{{ part }}</template></template></span>
                                </span>
                                <span class="shrink-0 text-xs text-muted-foreground capitalize sm:text-sm">{{ result.type === 'task' ? 'card' : result.type }}</span>
                            </ComboboxItem>
                        </ComboboxGroup>
                    </template>
                    <div
                        v-if="error"
                        role="alert"
                        class="flex items-center justify-between gap-3 px-4 py-6">
                        <p class="text-sm text-muted-foreground">
                            {{ error }}
                        </p><Button
                            variant="outline"
                            size="sm"
                            @click="search">
                            Retry
                        </Button>
                    </div>
                    <p
                        v-else-if="searched && !itemCount"
                        class="px-4 py-8 text-center text-sm text-muted-foreground">
                        No results found. Try a different search.
                    </p>
                    <p
                        v-if="hasMore"
                        class="px-4 pt-2 pb-1 text-xs text-muted-foreground">
                        Showing up to 10 per type. Keep typing to narrow your search.
                    </p>
                </ComboboxViewport>
            </ComboboxContent>
            <p
                v-if="!query.trim()"
                class="px-6 pt-2 pb-3 text-xs text-muted-foreground">
                Narrow your search with <code>type:task</code> or <code>project:"My Project"</code>.
            </p>
            <div class="flex items-center justify-between gap-3 border-t border-foreground/5 px-4 py-3 sm:px-5">
                <span class="flex items-center gap-1 text-xs text-muted-foreground"><ArrowUpIcon
                    class="size-3.5"
                    aria-hidden="true" /><ArrowDownIcon
                        class="size-3.5"
                        aria-hidden="true" /><span class="ml-1">Navigate</span><span class="ml-3 hidden sm:inline">Esc to close</span></span>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="!highlightedResult || (loading && highlightedResult.type !== 'command')"
                    @click="openResult(highlightedResult)">
                    <TextTransition :text="highlightedResult?.type === 'command' ? 'Run command' : 'Open result'" /><CornerDownLeftIcon
                        class="size-3.5 text-muted-foreground"
                        aria-hidden="true" />
                </Button>
            </div>
        </ComboboxRoot>
        <template v-else>
            <form
                v-if="compact"
                role="search"
                class="flex h-8 min-w-0 items-center gap-1 overflow-hidden bg-transparent pr-0 pl-2"
                @submit.prevent="search">
                <label
                    for="project-content-search"
                    class="shrink-0 cursor-text"><SearchIcon
                        class="size-4 text-muted-foreground"
                        aria-hidden="true" /><span class="sr-only">Search this project</span></label>
                <Input
                    id="project-content-search"
                    v-model="query"
                    maxlength="255"
                    placeholder="Search"
                    class="h-8 min-w-0 flex-1 rounded-none border-0 bg-transparent px-1 text-base font-medium shadow-none focus-visible:border-0 focus-visible:ring-0 md:text-[13px] dark:bg-transparent" />
                <div class="invisible flex w-0 shrink-0 overflow-hidden transition-[width] duration-(--resize-dur) ease-(--resize-ease) group-focus-within/search:visible group-focus-within/search:w-16 motion-reduce:transition-none">
                    <Button
                        type="submit"
                        variant="ghost"
                        size="icon-sm"
                        aria-label="Search this project"
                        :disabled="loading">
                        <ArrowRightIcon aria-hidden="true" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        aria-label="Close search"
                        @click="emit('close')">
                        <XIcon aria-hidden="true" />
                    </Button>
                </div>
            </form>
            <form
                v-else
                class="flex items-end gap-2"
                @submit.prevent="search">
                <Field class="min-w-0 flex-1">
                    <FieldLabel :for="projectId ? 'project-content-search' : 'workspace-content-search'">
                        {{ projectId ? 'Search this project' : 'Search workspace' }}
                    </FieldLabel><Input
                        :id="projectId ? 'project-content-search' : 'workspace-content-search'"
                        v-model="query"
                        maxlength="255"
                        placeholder="Documents, cards, links, secret metadata…" />
                </Field>
                <Button
                    type="submit"
                    variant="outline"
                    size="input"
                    :disabled="loading">
                    <SearchIcon aria-hidden="true" /><TextTransition :text="loading ? 'Searching…' : 'Search'" />
                </Button>
            </form>
            <div
                v-if="query.trim() || searched || loading"
                class="gap-2"
                :class="compact ? 'hidden group-focus-within/search:grid absolute top-full right-0 z-50 mt-2 max-h-[min(70vh,36rem)] w-[min(32rem,calc(100vw-3rem))] overflow-y-auto rounded-[1.25rem] border border-black/8 bg-popover p-4 shadow-xl dark:border-white/10' : 'grid'">
                <p
                    v-if="compact && query.trim() && !searched && !loading"
                    class="text-sm text-muted-foreground">
                    Press Enter to search.
                </p>
                <p
                    v-if="loading"
                    role="status"
                    class="text-sm text-muted-foreground">
                    <TextTransition
                        text="Searching…"
                        shimmer />
                </p>
                <p
                    v-if="searched"
                    class="text-sm text-muted-foreground"
                    role="status">
                    {{ results.length }} results<template v-if="hasMore">
                        · Showing up to 10 per type. Refine your search for more.
                    </template>
                </p>
                <ul
                    v-if="searched && results.length"
                    class="max-h-96 divide-y overflow-y-auto rounded-xl border">
                    <li
                        v-for="result in results"
                        :key="`${result.type}-${result.id}`">
                        <Link
                            :href="result.url"
                            class="block space-y-1 p-3 hover:bg-muted focus-visible:outline-2"
                            @click="emit('navigate')">
                            <div class="flex flex-wrap items-center gap-2">
                                <Badge
                                    variant="secondary"
                                    class="capitalize">
                                    {{ result.type === 'task' ? 'card' : result.type }}
                                </Badge><span class="break-all font-medium">{{ result.title }}</span>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                {{ result.project }}
                            </p>
                            <p
                                v-if="result.excerpt"
                                class="break-words text-sm text-muted-foreground">
                                {{ result.excerpt }}
                            </p>
                        </Link>
                    </li>
                </ul>
            </div>
        </template>
    </div>
</template>
