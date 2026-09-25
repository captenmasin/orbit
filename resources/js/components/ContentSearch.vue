<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { ArrowRightIcon, SearchIcon, XIcon } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Field, FieldLabel } from '@/components/ui/field';

const props = withDefaults(defineProps<{ projectId?: string; compact?: boolean }>(), { compact: false });
const emit = defineEmits<{ navigate: []; close: [] }>();
type Result = { id: string; project: string; type: string; title: string; excerpt: string; url: string };
const query = ref('');
const results = ref<Result[]>([]);
const searched = ref(false);
const loading = ref(false);
const hasMore = ref(false);
let request: AbortController | undefined;

async function search() {
    request?.abort();
    const current = new AbortController();
    request = current;
    loading.value = true;
    try {
        const parameters = new URLSearchParams({ q: query.value });
        if (props.projectId) parameters.set('project_id', props.projectId);
        const response = await fetch(`/search?${parameters}`, { signal: current.signal, headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error();
        const body = await response.json() as { results: Result[]; has_more: boolean };
        if (current.signal.aborted) return;
        results.value = body.results;
        hasMore.value = body.has_more;
        searched.value = true;
    } catch {
        if (!current.signal.aborted) toast.error('Search could not be completed. Try again.');
    } finally {
        if (!current.signal.aborted) loading.value = false;
    }
}
watch(() => props.projectId, () => { request?.abort(); results.value = []; searched.value = false; loading.value = false; });
onBeforeUnmount(() => request?.abort());
</script>

<template>
    <div class="relative min-w-0" :class="compact ? 'w-full' : 'grid gap-3'" @keydown.esc="compact && emit('close')">
        <form v-if="compact" role="search" class="flex h-10 min-w-0 items-center gap-1 rounded-full border border-black/8 bg-muted pr-1 pl-3 focus-within:ring-2 focus-within:ring-ring/50 dark:border-white/10" @submit.prevent="search">
            <SearchIcon class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
            <label for="project-content-search" class="sr-only">Search this project</label>
            <Input id="project-content-search" v-model="query" maxlength="255" placeholder="Search this project" class="h-9 min-w-0 flex-1 rounded-full border-0 bg-transparent px-2 shadow-none focus-visible:border-0 focus-visible:ring-0" />
            <Button type="submit" variant="ghost" size="icon-sm" aria-label="Search this project" :disabled="loading"><ArrowRightIcon aria-hidden="true" /></Button>
            <Button type="button" variant="ghost" size="icon-sm" aria-label="Close search" @click="emit('close')"><XIcon aria-hidden="true" /></Button>
        </form>
        <form v-else class="flex items-end gap-2" @submit.prevent="search">
            <Field class="min-w-0 flex-1"><FieldLabel :for="projectId ? 'project-content-search' : 'workspace-content-search'">{{ projectId ? 'Search this project' : 'Search workspace' }}</FieldLabel><Input :id="projectId ? 'project-content-search' : 'workspace-content-search'" v-model="query" maxlength="255" placeholder="Documents, tasks, links, secret names…" /></Field>
            <Button type="submit" variant="outline" :disabled="loading"><SearchIcon aria-hidden="true" />{{ loading ? 'Searching…' : 'Search' }}</Button>
        </form>
        <div v-if="searched || loading" class="grid gap-2" :class="compact ? 'absolute top-full right-0 z-50 mt-2 max-h-[min(70vh,36rem)] w-[min(32rem,calc(100vw-3rem))] overflow-y-auto rounded-[1.25rem] border border-black/8 bg-popover p-4 shadow-xl dark:border-white/10' : ''">
            <p v-if="loading" role="status" class="text-sm text-muted-foreground">Searching…</p>
            <p v-if="searched" class="text-sm text-muted-foreground" role="status">{{ results.length }} results<template v-if="hasMore"> · Showing up to 10 per type. Refine your search for more.</template></p>
            <ul v-if="searched && results.length" class="max-h-96 divide-y overflow-y-auto rounded-xl border">
                <li v-for="result in results" :key="`${result.type}-${result.id}`">
                    <Link :href="result.url" class="block space-y-1 p-3 hover:bg-muted focus-visible:outline-2" @click="emit('navigate')">
                        <div class="flex flex-wrap items-center gap-2"><Badge variant="secondary" class="capitalize">{{ result.type }}</Badge><span class="break-all font-medium">{{ result.title }}</span></div>
                        <p class="text-xs text-muted-foreground">{{ result.project }}</p>
                        <p v-if="result.excerpt" class="break-words text-sm text-muted-foreground">{{ result.excerpt }}</p>
                    </Link>
                </li>
            </ul>
        </div>
    </div>
</template>
