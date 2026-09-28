<script setup lang="ts">
import ChoiceSelect from '@/components/ChoiceSelect.vue';
import TextTransition from '@/components/TextTransition.vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Link, useHttp } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import type { ProviderConnection } from '@/types';
import { Field, FieldLabel } from '@/components/ui/field';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import { CheckIcon, GitBranchIcon, LoaderCircleIcon } from '@lucide/vue';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';

interface ConnectedRepository {
    id: string;
    full_name: string;
    name: string;
    remote_url: string;
    private: boolean;
    description: string | null;
}

const props = withDefaults(defineProps<{ connections: ProviderConnection[]; existingUrls: string[]; native: boolean; clone?: boolean; busy?: boolean }>(), { clone: false, busy: false });
const emit = defineEmits<{ select: [repository: ConnectedRepository & { connection_id: string }] }>();
const fieldId = useId();
const open = ref(false);
const connectionId = ref(props.connections[0]?.id ?? '');
const query = ref('');
const repositories = ref<ConnectedRepository[]>([]);
const nextPage = ref<number | null>(null);
const error = ref('');
const listing = useHttp<{ page: number }, { repositories: ConnectedRepository[]; next_page: number | null }>({ page: 1 });
const visible = computed(() => repositories.value.filter(repository => repository.full_name.toLowerCase().includes(query.value.trim().toLowerCase())));
const normalize = (url: string) => url.trim().replace(/\.git\/?$/i, '').replace(/\/$/, '').toLowerCase();
const added = (repository: ConnectedRepository) => props.existingUrls.some(url => normalize(url) === normalize(repository.remote_url));
const providerName = computed(() => props.connections.find(connection => connection.id === connectionId.value)?.provider === 'gitlab' ? 'GitLab' : 'GitHub');

async function load(page = 1) {
    if (!props.native || !connectionId.value) return;
    listing.cancel();
    listing.page = page;
    error.value = '';
    if (page === 1) { repositories.value = []; nextPage.value = null; }
    const requestedConnection = connectionId.value;
    try {
        const result = await listing.get(`/connections/${requestedConnection}/repositories`);
        if (!open.value || connectionId.value !== requestedConnection) return;
        if (!result) { error.value = Object.values(listing.errors)[0] || 'Could not load repositories. Check this connection and try again.'; return; }
        repositories.value = page === 1 ? result.repositories : [...repositories.value, ...result.repositories.filter(repository => !repositories.value.some(existing => existing.id === repository.id))];
        nextPage.value = result.next_page;
    } catch (exception) {
        if (!open.value || connectionId.value !== requestedConnection) return;
        const body = (exception as { response?: { data?: string } }).response?.data;
        try { error.value = body ? JSON.parse(body).message : ''; } catch { /* Use the local fallback. */ }
        error.value ||= 'Could not load repositories. Check this connection and try again.';
    }
}

function choose(repository: ConnectedRepository) {
    if (added(repository)) return;
    emit('select', { ...repository, connection_id: connectionId.value });
    open.value = false;
    query.value = '';
}

watch(open, value => { if (value) void load(); else listing.cancel(); });
watch(connectionId, () => { if (open.value) void load(); });
onBeforeUnmount(() => listing.cancel());
</script>

<template>
    <div class="contents">
        <Button
            type="button"
            variant="outline"
            :disabled="busy"
            @click="open = true">
            <GitBranchIcon aria-hidden="true" />{{ clone ? 'Choose repository' : 'From connected account' }}
        </Button>
        <Dialog v-model:open="open">
            <DialogContent class="max-h-[calc(100dvh-2rem)] overflow-hidden sm:max-w-xl">
                <DialogHeader><DialogTitle>Choose a repository</DialogTitle><DialogDescription>{{ clone ? 'Choose a repository, then select where to clone it.' : 'Choose from a connected GitHub or GitLab account. It will be linked when you save the project.' }}</DialogDescription></DialogHeader>
                <p
                    v-if="!native"
                    class="text-sm text-muted-foreground">
                    Browse connected repositories in the Orbit desktop app. You can still add a remote URL manually here.
                </p>
                <div
                    v-else-if="!connections.length"
                    class="grid gap-4">
                    <p class="text-sm text-muted-foreground">
                        No Git connection is set up yet.
                    </p>
                    <div>
                        <Button
                            as-child
                            variant="outline">
                            <Link href="/settings/connections">
                                Manage connections
                            </Link>
                        </Button>
                    </div>
                </div>
                <template v-else>
                    <Field
                        class="gap-2">
                        <FieldLabel :for="`${fieldId}-connection`">
                            Connected account
                        </FieldLabel><ChoiceSelect
                            :id="`${fieldId}-connection`"
                            v-model="connectionId"
                            :options="connections.map(connection => ({ value: connection.id, label: `${connection.label} · ${connection.login} · ${connection.provider === 'gitlab' ? 'GitLab' : 'GitHub'}` }))" />
                    </Field>
                    <Field class="gap-2">
                        <FieldLabel :for="`${fieldId}-search`">
                            Search {{ providerName }} repositories
                        </FieldLabel><Input
                            :id="`${fieldId}-search`"
                            v-model="query"
                            placeholder="Filter loaded repositories" />
                    </Field>
                    <Alert
                        v-if="error"
                        variant="destructive">
                        <AlertDescription>
                            {{ error }} <button
                                type="button"
                                class="font-medium underline underline-offset-2"
                                @click="load()">
                                Try again
                            </button>
                        </AlertDescription>
                    </Alert>
                    <div
                        v-if="listing.processing && !repositories.length"
                        class="flex items-center gap-2 text-sm text-muted-foreground"
                        role="status">
                        <LoaderCircleIcon
                            class="size-4 animate-spin motion-reduce:animate-none"
                            aria-hidden="true" /><TextTransition
                                text="Loading repositories…"
                                shimmer />
                    </div>
                    <div
                        v-else-if="!repositories.length && !error"
                        class="rounded-xl border border-dashed px-4 py-6 text-center text-sm text-muted-foreground">
                        No repositories are available to this connection. Check its repository access in {{ providerName }}.
                    </div>
                    <div
                        v-if="repositories.length"
                        class="max-h-[min(45vh,22rem)] overflow-y-auto rounded-xl border">
                        <ul class="divide-y">
                            <li
                                v-for="repository in visible"
                                :key="repository.id">
                                <button
                                    type="button"
                                    class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-muted/50 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring disabled:cursor-default disabled:opacity-55"
                                    :disabled="added(repository)"
                                    @click="choose(repository)">
                                    <span class="min-w-0"><span class="flex flex-wrap items-center gap-2 font-medium"><span class="truncate">{{ repository.full_name }}</span><Badge
                                        v-if="repository.private"
                                        variant="secondary">Private</Badge></span><span
                                            v-if="repository.description"
                                            class="mt-1 block truncate text-xs text-muted-foreground">{{ repository.description }}</span></span>
                                    <span
                                        v-if="added(repository)"
                                        class="flex shrink-0 items-center gap-1 text-xs text-muted-foreground"><CheckIcon
                                            class="size-3.5"
                                            aria-hidden="true" />Added</span>
                                </button>
                            </li>
                        </ul>
                        <p
                            v-if="!visible.length"
                            class="p-4 text-sm text-muted-foreground">
                            No matches in the repositories loaded so far.
                        </p>
                    </div>
                    <div
                        v-if="nextPage"
                        class="flex justify-center">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            :disabled="listing.processing"
                            @click="load(nextPage)">
                            <TextTransition :text="listing.processing ? 'Loading…' : 'Load more repositories'" />
                        </Button>
                    </div>
                </template>
            </DialogContent>
        </Dialog>
    </div>
</template>
