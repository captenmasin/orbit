<script setup lang="ts">
import ChoiceSelect from '@/components/ChoiceSelect.vue';
import TextTransition from '@/components/TextTransition.vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Link, useHttp } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import type { ProviderConnection } from '@/types';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldLabel } from '@/components/ui/field';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import { CheckIcon, GitBranchIcon, LoaderCircleIcon } from '@lucide/vue';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

interface ConnectedRepository {
    id: string;
    full_name: string;
    name: string;
    remote_url: string;
    private: boolean;
    description: string | null;
}
type SelectedRepository = ConnectedRepository & { connection_id: string };

const props = withDefaults(defineProps<{ connections: ProviderConnection[]; existingUrls: string[]; native: boolean; clone?: boolean; busy?: boolean }>(), { clone: false, busy: false });
const emit = defineEmits<{ select: [repository: SelectedRepository] }>();
const fieldId = useId();
const open = ref(false);
const connectionId = ref(props.connections[0]?.id ?? '');
const query = ref('');
const repositories = ref<ConnectedRepository[]>([]);
const selectedRepositories = ref<SelectedRepository[]>([]);
const nextPage = ref<number | null>(null);
const error = ref('');
const listing = useHttp<{ page: number }, { repositories: ConnectedRepository[]; next_page: number | null }>({ page: 1 });
const visible = computed(() => repositories.value.filter(repository => repository.full_name.toLowerCase().includes(query.value.trim().toLowerCase())));
const normalize = (url: string) => url.trim().replace(/\.git\/?$/i, '').replace(/\/$/, '').toLowerCase();
const added = (repository: ConnectedRepository) => props.existingUrls.some(url => normalize(url) === normalize(repository.remote_url));
const selected = (repository: ConnectedRepository) => selectedRepositories.value.some(item => normalize(item.remote_url) === normalize(repository.remote_url));
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
    if (props.busy || added(repository)) return;
    if (!props.clone) {
        if (selected(repository)) selectedRepositories.value = selectedRepositories.value.filter(item => normalize(item.remote_url) !== normalize(repository.remote_url));
        else selectedRepositories.value.push({ ...repository, connection_id: connectionId.value });
        return;
    }
    emit('select', { ...repository, connection_id: connectionId.value });
    open.value = false;
}

function addSelected() {
    if (props.busy || !selectedRepositories.value.length) return;
    for (const repository of selectedRepositories.value) {
        if (!added(repository)) emit('select', repository);
    }
    open.value = false;
}

watch(open, value => {
    if (value) void load();
    else { listing.cancel(); selectedRepositories.value = []; query.value = ''; }
});
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
            <DialogContent class="flex max-h-[calc(100dvh-2rem)] flex-col overflow-hidden sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{{ clone ? 'Choose a repository' : 'Add repositories' }}</DialogTitle>
                    <DialogDescription>{{ clone ? 'Choose a repository, then select where to clone it.' : 'Select repositories from your connected accounts. They’ll be linked when you save the project.' }}</DialogDescription>
                </DialogHeader>
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
                <div
                    v-else
                    class="-m-1 grid min-h-0 grid-cols-1 gap-4 overflow-y-auto overscroll-contain p-1">
                    <Field>
                        <FieldLabel :for="`${fieldId}-connection`">
                            Connected account
                        </FieldLabel><ChoiceSelect
                            :id="`${fieldId}-connection`"
                            v-model="connectionId"
                            variant="filled"
                            class="min-w-0 w-full"
                            :options="connections.map(connection => ({ value: connection.id, label: `${connection.label} · ${connection.login} · ${connection.provider === 'gitlab' ? 'GitLab' : 'GitHub'}` }))" />
                    </Field>
                    <Field>
                        <FieldLabel :for="`${fieldId}-search`">
                            Search {{ providerName }} repositories
                        </FieldLabel><Input
                            :id="`${fieldId}-search`"
                            v-model="query"
                            variant="filled"
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
                        class="rounded-lg bg-muted/50 px-4 py-6 text-center text-sm text-muted-foreground">
                        No repositories are available to this connection. Check its repository access in {{ providerName }}.
                    </div>
                    <div
                        v-if="repositories.length"
                        class="max-h-[min(45vh,22rem)] border border-foreground/5 rounded-xl overflow-y-auto overscroll-contain">
                        <ul class="grid grid-cols-1 gap-1">
                            <li
                                v-for="repository in visible"
                                :key="repository.id">
                                <component
                                    :is="clone ? 'button' : 'label'"
                                    :type="clone ? 'button' : undefined"
                                    class="flex w-full items-center gap-3 rounded-none px-3 py-3 text-left focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                                    :class="[selected(repository) ? 'bg-muted' : 'hover:bg-muted/50', added(repository) ? 'cursor-default opacity-55' : 'cursor-pointer']"
                                    :disabled="clone ? added(repository) || busy : undefined"
                                    @click="clone && choose(repository)">
                                    <Checkbox
                                        v-if="!clone"
                                        :model-value="selected(repository) || added(repository)"
                                        :disabled="added(repository) || busy"
                                        :aria-label="repository.full_name"
                                        @update:model-value="choose(repository)" />
                                    <GitBranchIcon
                                        v-else
                                        class="size-4 shrink-0 text-muted-foreground"
                                        aria-hidden="true" />
                                    <span class="min-w-0 flex-1"><span class="flex flex-wrap items-center gap-2 font-medium"><span class="truncate">{{ repository.full_name }}</span><Badge
                                        v-if="repository.private"
                                        variant="secondary">Private</Badge></span><span
                                            v-if="repository.description"
                                            class="mt-1 block truncate text-xs text-muted-foreground">{{ repository.description }}</span></span>
                                    <span
                                        v-if="added(repository)"
                                        class="ml-auto flex shrink-0 items-center gap-1 text-xs text-muted-foreground"><CheckIcon
                                            class="size-3.5"
                                            aria-hidden="true" />Added</span>
                                </component>
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
                </div>
                <DialogFooter v-if="native && connections.length && !clone">
                    <p
                        class="mr-auto self-center text-sm text-muted-foreground"
                        role="status">
                        {{ selectedRepositories.length }} selected
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        @click="open = false">
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        :disabled="!selectedRepositories.length || busy"
                        @click="addSelected">
                        Add {{ selectedRepositories.length || '' }} {{ selectedRepositories.length === 1 ? 'repository' : 'repositories' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
