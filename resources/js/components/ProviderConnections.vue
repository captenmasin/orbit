<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';
import { router, useHttp } from '@inertiajs/vue3';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import type { ProviderConnection } from '@/types';

defineProps<{ connections: ProviderConnection[]; native: boolean }>();
const editing = ref<ProviderConnection | null>(null);
const removing = ref<ProviderConnection | null>(null);
const open = ref(false);
const error = ref('');
const credential = ref('');
const form = useHttp({ provider: 'github', label: '', token: '', revision: 1 });
const removal = useHttp({ revision: 1 });
function clear() { credential.value = ''; form.token = ''; form.defaults('token', ''); }
onBeforeUnmount(() => { form.cancel(); clear(); });
function edit(connection: ProviderConnection | null) {
    clear(); error.value = ''; editing.value = connection;
    Object.assign(form, { provider: connection?.provider ?? 'github', label: connection?.label ?? '', revision: connection?.revision ?? 1 });
    open.value = true;
}
function message(exception: unknown) {
    const body = (exception as { response?: { data?: string } })?.response?.data;
    if (body) { try { return JSON.parse(body).message; } catch { /* Use the local fallback. */ } }
    return Object.values(form.errors).flat().join(' ') || 'Connection could not be saved. Check the details and try again.';
}
async function save() {
    error.value = ''; form.token = credential.value; credential.value = '';
    try {
        const result = editing.value ? await form.put(`/connections/${editing.value.id}`) : await form.post('/connections');
        if (!result) { error.value = message(null); return; }
        open.value = false; router.reload({ only: ['connections'] });
    } catch (exception) { error.value = message(exception); }
    finally { clear(); }
}
async function remove() {
    if (!removing.value) return;
    removal.revision = removing.value.revision;
    try { await removal.delete(`/connections/${removing.value.id}`); removing.value = null; router.reload({ only: ['connections'] }); }
    catch (exception) { error.value = message(exception); }
}
</script>

<template>
    <div class="flex items-center justify-between gap-4"><h1 class="text-2xl font-semibold tracking-tight">Connections</h1><Button :disabled="!native" @click="edit(null)">Add connection</Button></div>
    <p v-if="!native" class="text-sm text-muted-foreground">Manage tokens in the desktop app to use macOS credential storage.</p>
    <Alert v-if="error && !open" variant="destructive"><AlertDescription>{{ error }}</AlertDescription></Alert>
    <Table v-if="connections.length">
        <TableHeader><TableRow><TableHead>Connection</TableHead><TableHead>Account</TableHead><TableHead>Status</TableHead><TableHead><span class="sr-only">Actions</span></TableHead></TableRow></TableHeader>
        <TableBody><TableRow v-for="connection in connections" :key="connection.id">
            <TableCell>{{ connection.label }}<div class="text-muted-foreground">{{ connection.provider === 'github' ? 'GitHub' : 'GitLab' }}</div></TableCell>
            <TableCell>{{ connection.login }}</TableCell><TableCell>{{ connection.state }}<div v-if="connection.retry_at">Retry after {{ new Date(connection.retry_at).toLocaleString() }}</div></TableCell>
            <TableCell><div class="flex gap-2"><Button variant="outline" :disabled="!native" @click="edit(connection)">Replace token</Button><Button variant="ghost" @click="removing = connection">Remove</Button></div></TableCell>
        </TableRow></TableBody>
    </Table>
    <p v-else class="text-sm text-muted-foreground">No connections yet.</p>
    <Dialog v-model:open="open" @update:open="clear">
        <DialogContent><DialogHeader><DialogTitle>{{ editing ? 'Replace token' : 'Add connection' }}</DialogTitle><DialogDescription>Tokens are encrypted using macOS credential storage. Orbit reads repository activity.</DialogDescription></DialogHeader>
            <form class="grid gap-4" @submit.prevent="save">
                <Field><FieldLabel for="provider">Provider</FieldLabel><NativeSelect id="provider" v-model="form.provider" :disabled="!!editing"><NativeSelectOption value="github">GitHub.com</NativeSelectOption><NativeSelectOption value="gitlab">GitLab.com</NativeSelectOption></NativeSelect></Field>
                <Field><FieldLabel for="connection-label">Label</FieldLabel><Input id="connection-label" v-model="form.label" maxlength="100" required /></Field>
                <p class="text-sm text-muted-foreground">{{ form.provider === 'github' ? 'Use a fine-grained token for selected repositories with read access to Metadata, Contents, Issues, Pull requests, Actions, and Commit statuses.' : 'Use a personal access token with read_api access.' }}</p>
                <Field><FieldLabel for="connection-token">Token</FieldLabel><Input id="connection-token" v-model="credential" type="password" autocomplete="off" :spellcheck="false" maxlength="4096" required /></Field>
                <Alert v-if="error" variant="destructive"><AlertDescription>{{ error }}</AlertDescription></Alert>
                <DialogFooter><Button type="button" variant="outline" @click="open = false; clear()">Cancel</Button><Button type="submit" :disabled="form.processing || !native">{{ form.processing ? 'Verifying…' : 'Verify and save' }}</Button></DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
    <Dialog :open="!!removing" @update:open="removing = null"><DialogContent><DialogHeader><DialogTitle>Remove {{ removing?.label }}?</DialogTitle><DialogDescription>This removes its saved token and cached provider activity from Orbit. Local repositories and tasks remain. It does not revoke the token at the provider.</DialogDescription></DialogHeader><DialogFooter><Button variant="outline" @click="removing = null">Cancel</Button><Button variant="destructive" :disabled="removal.processing" @click="remove">Remove connection</Button></DialogFooter></DialogContent></Dialog>
</template>
