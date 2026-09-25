<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router, useHttp } from '@inertiajs/vue3';
import { useDocumentVisibility, useIntervalFn, useOnline, useWindowFocus } from '@vueuse/core';
import { toast } from 'vue-sonner';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import ChoiceSelect from '@/components/ChoiceSelect.vue';
import type { ProviderConnection, Repository } from '@/types';

const props = defineProps<{ projectId: string; repositories: Repository[]; connections: ProviderConnection[]; active: boolean }>();
const selected = ref<Repository | null>(null);
const connectionId = ref('');
const name = ref('');
const error = ref('');
const form = useHttp({ revision: 1, connection_id: null as string | null, name: '' });
const refreshing = useHttp({ resource: 'overview', more: false, only_stale: false });
const visible = useDocumentVisibility();
const focused = useWindowFocus();
const online = useOnline();
const pending = computed(() => props.repositories.some(repo => repo.provider_snapshots?.some(snapshot => ['Queued', 'Refreshing'].includes(snapshot.state))));
let lastCheck = 0;
let reloading = false;
const titles: Record<string, string> = { overview: 'Default branch', issues: 'Open issues', requests: 'Pull / merge requests', statuses: 'Commit statuses' };
const date = (value?: string | null) => value ? new Date(value).toLocaleString() : 'Never';
function reload() {
    if (reloading) return;
    reloading = true;
    router.reload({ only: ['activity', 'connections'], onFinish: () => { reloading = false; } });
}
function edit(repository: Repository) {
    selected.value = repository; connectionId.value = repository.provider_connection_id ?? '';
    name.value = repository.provider_name ?? repository.remote_url.replace(/^git@[^:]+:/, '').replace(/^https?:\/\/[^/]+\//, '').replace(/\.git\/?$/, '');
    error.value = '';
}
async function save() {
    if (!selected.value) return;
    Object.assign(form, { revision: selected.value.provider_revision ?? 1, connection_id: connectionId.value || null, name: name.value });
    try {
        const result = await form.post(`/projects/${props.projectId}/repositories/${selected.value.id}/connection`);
        if (!result) { error.value = Object.values(form.errors).flat().join(' ') || 'Repository could not be connected.'; return; }
        selected.value = null; reload();
    }
    catch (exception) { error.value = (exception as { response?: { data?: { message?: string } } }).response?.data?.message ?? 'Repository could not be connected. Check its name and token access.'; }
}
async function refresh(repository: Repository, resource = 'overview', more = false, onlyStale = false) {
    if (refreshing.processing || !online.value) return;
    Object.assign(refreshing, { resource, more, only_stale: onlyStale });
    try {
        const result = await refreshing.post(`/projects/${props.projectId}/repositories/${repository.id}/refresh`);
        if (!result) { toast.error(String(Object.values(refreshing.errors)[0] ?? 'Refresh could not be queued. Try again.'), { id: 'provider-refresh' }); return; }
        reload();
    }
    catch { toast.error('Refresh could not be queued. Try again.', { id: 'provider-refresh' }); }
}
function disabled(repository: Repository) {
    return refreshing.processing || !online.value || repository.provider_connection?.state === 'Token required' || (repository.provider_connection?.retry_at ? new Date(repository.provider_connection.retry_at).getTime() > Date.now() : false);
}
async function check() {
    if (!props.active || visible.value !== 'visible' || !focused.value || !online.value) return;
    if (Date.now() - lastCheck >= 60000) {
        lastCheck = Date.now();
        for (const repository of props.repositories.filter(repo => repo.provider_connection_id)) await refresh(repository, 'overview', false, true);
    } else if (pending.value) reload();
}
watch(() => props.active && focused.value && online.value, () => { lastCheck = 0; void check(); }, { immediate: true });
useIntervalFn(check, 2000);
</script>

<template>
    <section v-if="repositories.length" class="grid gap-4" aria-label="Provider activity">
        <h2 class="text-lg font-medium">Provider activity</h2>
        <Alert v-if="!online"><AlertDescription>Offline. Showing cached activity.</AlertDescription></Alert>
        <div v-for="repository in repositories" :key="repository.id" class="grid gap-4 rounded-lg border p-4">
            <div class="flex flex-wrap items-center justify-between gap-2"><div><h3 class="font-medium">{{ repository.provider_name ?? repository.name }}</h3><p v-if="repository.provider_connection" class="text-sm text-muted-foreground">{{ repository.provider_connection.label }} · {{ repository.provider_connection.login }} · {{ repository.provider_connection.state }}<template v-if="repository.provider_connection.retry_at"> · Retry after {{ date(repository.provider_connection.retry_at) }}</template></p></div><div class="flex gap-2"><Button type="button" variant="outline" @click="edit(repository)">{{ repository.provider_connection_id ? 'Connection settings' : 'Connect provider' }}</Button><Button v-if="repository.provider_connection_id" type="button" variant="outline" :disabled="disabled(repository)" @click="refresh(repository)">Refresh activity</Button></div></div>
            <div v-for="snapshot in repository.provider_snapshots ?? []" :key="snapshot.id" class="grid gap-2">
                <div class="flex flex-wrap items-center gap-2"><h4 class="text-sm font-medium">{{ snapshot.resource === 'checks' ? (repository.provider_connection?.provider === 'github' ? 'GitHub Actions' : 'Pipelines') : titles[snapshot.resource] }}</h4><Badge variant="outline">{{ snapshot.state }}</Badge><span class="text-xs text-muted-foreground">Last checked: {{ date(snapshot.checked_at) }}</span><Button type="button" size="sm" variant="ghost" :disabled="disabled(repository) || ['Queued', 'Refreshing'].includes(snapshot.state)" @click="refresh(repository, snapshot.resource)">Refresh</Button></div>
                <p v-if="snapshot.error" class="text-sm text-destructive">{{ snapshot.error }}<template v-if="snapshot.payload"> Previous results from {{ date(snapshot.succeeded_at) }} are retained.</template></p>
                <template v-if="snapshot.resource === 'overview' && snapshot.payload">
                    <p class="text-sm">{{ snapshot.payload.default_branch ?? 'No default branch' }}<template v-if="snapshot.payload.commit"> · {{ snapshot.payload.commit.sha.slice(0, 8) }} · {{ date(snapshot.payload.commit.committed_at) }}</template></p>
                    <a v-if="snapshot.payload.commit" :href="snapshot.payload.commit.url" target="_blank" rel="noreferrer" class="text-sm underline underline-offset-4">{{ snapshot.payload.commit.title }}</a><p v-else class="text-sm text-muted-foreground">No default-branch commit.</p>
                </template>
                <template v-else-if="snapshot.payload">
                    <p v-if="['checks', 'statuses'].includes(snapshot.resource)" class="text-xs text-muted-foreground">{{ snapshot.payload.branch ?? 'No branch' }} · {{ snapshot.payload.sha?.slice(0, 8) ?? 'No commit' }}</p>
                    <ul v-if="snapshot.payload.items?.length" class="grid gap-2"><li v-for="item in snapshot.payload.items" :key="item.id" class="flex flex-wrap items-baseline gap-2 text-sm"><span v-if="item.number" class="text-muted-foreground">#{{ item.number }}</span><a v-if="item.url" :href="item.url" target="_blank" rel="noreferrer" class="underline underline-offset-4">{{ item.title }}</a><span v-else>{{ item.title }}</span><Badge variant="secondary">{{ item.state }}</Badge></li></ul>
                    <p v-else class="text-sm text-muted-foreground">{{ snapshot.next_page ? 'No matching items on this page.' : ['checks', 'statuses'].includes(snapshot.resource) ? 'No CI results for this commit.' : 'No open items.' }}</p>
                    <div class="flex items-center gap-2"><span class="text-xs text-muted-foreground">{{ snapshot.payload.items?.length ?? 0 }} loaded · up to 1,000 per resource</span><Button v-if="snapshot.next_page" type="button" size="sm" variant="outline" :disabled="disabled(repository) || ['Queued', 'Refreshing'].includes(snapshot.state)" @click="refresh(repository, snapshot.resource, true)">Load more</Button></div>
                </template>
            </div>
        </div>
        <Dialog :open="!!selected" @update:open="selected = null"><DialogContent><DialogHeader><DialogTitle>Repository connection</DialogTitle><DialogDescription>Choose an account and confirm the hosted repository. Changing or disconnecting it clears the previous cached activity.</DialogDescription></DialogHeader>
            <Field><FieldLabel for="repo-connection">Connection</FieldLabel><ChoiceSelect id="repo-connection" v-model="connectionId" :options="[{ value: '', label: 'Disconnected' }, ...connections.map(connection => ({ value: connection.id, label: `${connection.label} · ${connection.login}` }))]" /></Field>
            <Field v-if="connectionId"><FieldLabel for="provider-repo-name">Repository</FieldLabel><Input id="provider-repo-name" v-model="name" placeholder="owner/name or group/project" maxlength="255" /></Field>
            <Button v-if="!connections.length" as-child variant="outline"><Link href="/settings/connections">Add a connection</Link></Button>
            <Alert v-if="error" variant="destructive"><AlertDescription>{{ error }}</AlertDescription></Alert>
            <DialogFooter><Button type="button" variant="outline" @click="selected = null">Cancel</Button><Button type="button" :disabled="form.processing" @click="save">{{ form.processing ? 'Verifying…' : connectionId ? 'Verify and connect' : 'Disconnect' }}</Button></DialogFooter>
        </DialogContent></Dialog>
    </section>
</template>
