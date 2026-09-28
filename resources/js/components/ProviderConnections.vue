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
                Manage tokens in the desktop app to use macOS credential storage.
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
                                {{ connection.state }}
                            </Badge>
                        </div>
                        <p class="break-words text-sm text-muted-foreground">
                            {{ connection.provider === 'github' ? 'GitHub' : 'GitLab' }} · {{ connection.login }}
                        </p>
                        <p
                            v-if="connection.retry_at"
                            class="text-xs text-muted-foreground">
                            Retry after {{ new Date(connection.retry_at).toLocaleString() }}
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-2">
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
                <DialogContent>
                    <DialogHeader><DialogTitle>{{ editing ? 'Replace token' : 'Add connection' }}</DialogTitle><DialogDescription>Tokens are encrypted using macOS credential storage. Orbit reads repository activity.</DialogDescription></DialogHeader>
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
                        <p class="text-sm text-muted-foreground">
                            {{ form.provider === 'github' ? 'Use a fine-grained token for selected repositories with read access to Metadata, Contents, Issues, Pull requests, Actions, and Commit statuses.' : 'Use a personal access token with read_api access.' }}
                        </p>
                        <Field>
                            <FieldLabel for="connection-token">
                                Token
                            </FieldLabel><Input
                                id="connection-token"
                                v-model="credential"
                                variant="filled"
                                type="password"
                                autocomplete="off"
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
