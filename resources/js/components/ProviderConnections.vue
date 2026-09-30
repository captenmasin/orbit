<script setup lang="ts">
import ChoiceSelect from '@/components/ChoiceSelect.vue';
import TextTransition from '@/components/TextTransition.vue';
import { toast } from 'vue-sonner';
import { onBeforeUnmount, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { router, useHttp } from '@inertiajs/vue3';
import type { ProviderConnection } from '@/types';
import { Field, FieldLabel } from '@/components/ui/field';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

const props = defineProps<{ connections: ProviderConnection[]; native: boolean }>();
const editing = ref<ProviderConnection | null>(null);
const removing = ref<ProviderConnection | null>(null);
const renaming = ref<ProviderConnection | null>(null);
const labelForm = useHttp({ label: '', revision: 1 });
const labelError = ref('');
const open = ref(false);
const error = ref('');
const credential = ref('');
const form = useHttp({ provider: 'github', label: '', token: '', revision: 1 });
const removal = useHttp({ revision: 1 });
const tokenPage = useHttp({ provider: 'github' });
function clear() { credential.value = ''; form.token = ''; form.defaults('token', ''); }
onBeforeUnmount(() => { form.cancel(); labelForm.cancel(); tokenPage.cancel(); clear(); });
async function openTokenPage(event: MouseEvent) {
    if (!props.native) return;
    event.preventDefault();
    if (tokenPage.processing) return;
    tokenPage.provider = form.provider;
    try {
        const result = await tokenPage.post('/connections/token-page');
        if (!result) toast.error('The token creation page could not be opened. Try again.');
    } catch { toast.error('The token creation page could not be opened. Try again.'); }
}
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
function editLabel(connection: ProviderConnection) {
    if (labelForm.processing) return;
    renaming.value = connection;
    labelForm.clearErrors(); labelError.value = '';
    Object.assign(labelForm, { label: connection.label, revision: connection.revision });
}
async function rename() {
    if (!renaming.value || labelForm.processing) return;
    labelError.value = '';
    try {
        const result = await labelForm.put(`/connections/${renaming.value.id}/label`);
        if (!result) return;
        renaming.value = null; router.reload({ only: ['connections'] });
    } catch { labelError.value = 'Could not save the label. Reload connections if it changed, then review your draft and try again.'; }
}
function reloadLabel() {
    const id = renaming.value?.id;
    router.reload({ only: ['connections'], onSuccess: () => {
        const current = props.connections.find(connection => connection.id === id);
        if (current) { renaming.value = current; labelForm.revision = current.revision; labelForm.clearErrors(); }
        else labelError.value = 'This connection was removed. Copy your label before closing.';
    } });
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
    try {
        const result = await removal.delete(`/connections/${removing.value.id}`);
        if (!result) { toast.error(String(Object.values(removal.errors)[0] ?? 'Connection could not be removed. Try again.')); return; }
        removing.value = null; router.reload({ only: ['connections'] });
    } catch { toast.error('Connection could not be removed. Try again.'); }
}
</script>

<template>
    <Card
        as="section"
        aria-labelledby="connections-title">
        <CardHeader>
            <div class="flex items-center justify-between gap-4">
                <h2
                    id="connections-title"
                    class="text-sm font-normal">
                    Connections
                </h2><Button
                    size="sm"
                    :disabled="!native"
                    @click="edit(null)">
                    Add connection
                </Button>
            </div>
        </CardHeader>
        <CardContent class="grid gap-6">
            <p
                v-if="!native"
                class="text-sm text-muted-foreground">
                Manage tokens in the desktop app to use secure system credential storage.
            </p>
            <Alert
                v-if="error && !open"
                variant="destructive">
                <AlertDescription>{{ error }}</AlertDescription>
            </Alert>
            <ul
                v-if="connections.length"
                aria-label="Provider connections"
                class="divide-y">
                <li
                    v-for="connection in connections"
                    :key="connection.id"
                    class="flex flex-col gap-3 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0 space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="min-w-0 break-words text-sm font-medium">{{ connection.label }}</span><Badge variant="outline">
                                {{ connection.state === 'Current' ? 'Account verified' : connection.state }}
                            </Badge>
                        </div>
                        <p class="break-words text-sm text-muted-foreground">
                            {{ connection.provider === 'github' ? 'GitHub' : 'GitLab' }} · {{ connection.login }}
                        </p>
                        <p
                            v-if="connection.state === 'Token required'"
                            class="text-xs text-muted-foreground">
                            Replace the token to reconnect this account.
                        </p>
                        <p
                            v-if="connection.retry_at"
                            class="text-xs text-muted-foreground">
                            Wait, then retry after {{ new Date(connection.retry_at).toLocaleString() }}
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            :aria-label="`Edit label for ${connection.label}`"
                            @click="editLabel(connection)">
                            Edit label
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="!native"
                            :aria-label="`Replace token for ${connection.label}`"
                            @click="edit(connection)">
                            Replace token
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            :aria-label="`Remove ${connection.label}`"
                            @click="removing = connection">
                            Remove
                        </Button>
                    </div>
                </li>
            </ul>
            <p
                v-else
                class="text-sm text-muted-foreground">
                No connections yet.
            </p>
            <Dialog
                v-model:open="open"
                @update:open="clear">
                <DialogContent class="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-xl">
                    <DialogHeader><DialogTitle>{{ editing ? 'Replace token' : 'Add connection' }}</DialogTitle><DialogDescription>Tokens are encrypted using secure system credential storage. Orbit reads repository activity.</DialogDescription></DialogHeader>
                    <form
                        class="grid gap-4"
                        @submit.prevent="save">
                        <Field>
                            <FieldLabel for="provider">
                                Provider
                            </FieldLabel><ChoiceSelect
                                id="provider"
                                v-model="form.provider"
                                variant="filled"
                                :options="[{ value: 'github', label: 'GitHub.com' }, { value: 'gitlab', label: 'GitLab.com' }]"
                                :disabled="!!editing" />
                        </Field>
                        <Field>
                            <FieldLabel for="connection-label">
                                Label
                            </FieldLabel><Input
                                id="connection-label"
                                v-model="form.label"
                                variant="filled"
                                maxlength="100"
                                required />
                        </Field>
                        <div
                            id="connection-token-help"
                            aria-live="polite"
                            class="space-y-2 text-xs text-muted-foreground">
                            <h3 class="font-medium text-foreground">
                                {{ form.provider === 'github' ? 'GitHub token setup' : 'GitLab token setup' }}
                            </h3>
                            <ol
                                v-if="form.provider === 'github'"
                                class="list-decimal space-y-2 pl-5">
                                <li>
                                    <a
                                        href="https://github.com/settings/personal-access-tokens/new"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="underline"
                                        @click="openTokenPage">Create a GitHub fine-grained token</a>. Enter a name and choose an expiration.
                                </li>
                                <li>Choose the resource owner, then select the repositories you want to connect under Repository access.</li>
                                <li>Under Repository permissions, set Contents, Issues, Pull requests, Actions, and Commit statuses to Read-only. Metadata read access is included automatically.</li>
                                <li>Generate the token, copy it, and paste it below. If your organization requires approval, ask an administrator to approve it before accessing private repositories.</li>
                            </ol>
                            <ol
                                v-else
                                class="list-decimal space-y-2 pl-5">
                                <li>
                                    Open <a
                                        href="https://gitlab.com/-/user_settings/personal_access_tokens"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="underline"
                                        @click="openTokenPage">GitLab’s token creation page</a>. Choose Generate token → Legacy token.
                                </li>
                                <li>Enter a name and expiration date. Select <code>read_api</code> to read repository activity.</li>
                                <li>Generate the token, copy it before leaving the page, and paste it below.</li>
                            </ol>
                        </div>
                        <Field>
                            <FieldLabel for="connection-token">
                                Token
                            </FieldLabel><Input
                                id="connection-token"
                                v-model="credential"
                                variant="filled"
                                type="password"
                                autocomplete="off"
                                aria-describedby="connection-token-help"
                                :spellcheck="false"
                                maxlength="4096"
                                required />
                        </Field>
                        <Alert
                            v-if="error"
                            variant="destructive">
                            <AlertDescription>{{ error }}</AlertDescription>
                        </Alert>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                @click="open = false; clear()">
                                Cancel
                            </Button><Button
                                type="submit"
                                :disabled="form.processing || !native">
                                <TextTransition :text="form.processing ? 'Verifying…' : 'Verify and save'" />
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
            <Dialog
                :open="!!renaming"
                @update:open="value => { if (!value && !labelForm.processing) renaming = null; }">
                <DialogContent>
                    <DialogHeader><DialogTitle>Edit connection label</DialogTitle><DialogDescription>Rename this connection without changing its saved token or repository activity.</DialogDescription></DialogHeader>
                    <form
                        class="grid gap-4"
                        @submit.prevent="rename">
                        <Field>
                            <FieldLabel for="rename-label">
                                Label
                            </FieldLabel><Input
                                id="rename-label"
                                v-model="labelForm.label"
                                maxlength="100"
                                required
                                :disabled="labelForm.processing"
                                :aria-invalid="!!labelForm.errors.label"
                                aria-describedby="rename-error" />
                        </Field>
                        <p
                            v-if="labelForm.errors.label || labelError"
                            id="rename-error"
                            class="text-sm text-destructive">
                            {{ labelForm.errors.label || labelError }}
                        </p>
                        <Button
                            v-if="labelError"
                            type="button"
                            variant="outline"
                            @click="reloadLabel">
                            Reload connections and review draft
                        </Button>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="labelForm.processing"
                                @click="renaming = null">
                                Cancel
                            </Button><Button :disabled="labelForm.processing">
                                {{ labelForm.processing ? 'Saving…' : 'Save label' }}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
            <Dialog
                :open="!!removing"
                @update:open="removing = null">
                <DialogContent>
                    <DialogHeader><DialogTitle>Remove {{ removing?.label }}?</DialogTitle><DialogDescription>This removes its saved token and cached provider activity from Orbit. Local repositories and tasks remain. It does not revoke the token at the provider.</DialogDescription></DialogHeader><DialogFooter>
                        <Button
                            variant="outline"
                            @click="removing = null">
                            Cancel
                        </Button><Button
                            variant="destructive"
                            :disabled="removal.processing"
                            @click="remove">
                            Remove connection
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </CardContent>
    </Card>
</template>
