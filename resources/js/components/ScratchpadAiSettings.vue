<script setup lang="ts">
import ChoiceSelect from '@/components/ChoiceSelect.vue';
import { toast } from 'vue-sonner';
import { useHttp } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { onBeforeUnmount, ref, watch } from 'vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Field, FieldDescription, FieldLabel, FieldError } from '@/components/ui/field';
import { CircleCheckIcon, InfoIcon, SlidersHorizontalIcon, Trash2Icon, XIcon } from '@lucide/vue';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

type AiStatus = { configured: boolean; provider: string | null; model: string | null; providers: string[]; default_models: Record<string, string> };
const props = defineProps<{ ai: AiStatus; revision: number; native: boolean }>();
const emit = defineEmits<{ saved: [revision: number, ai: AiStatus] }>();
const status = ref(props.ai);
const replacing = ref(!props.ai.configured);
const form = useHttp({ provider: props.ai.provider ?? 'openai', model: props.ai.model ?? props.ai.default_models[props.ai.provider ?? 'openai'] ?? '', key: '', revision: props.revision });
const error = ref('');
const removing = ref(false);
const providerLabels: Record<string, string> = { openai: 'OpenAI', anthropic: 'Anthropic', gemini: 'Google Gemini' };
const modelGuides: Record<string, string> = { openai: 'https://developers.openai.com/api/docs/models', anthropic: 'https://platform.claude.com/docs/en/models/overview', gemini: 'https://ai.google.dev/gemini-api/docs/models' };
watch(() => props.revision, value => { form.revision = value; });
function selectProvider(provider: string) { form.provider = provider; form.model = status.value.default_models[provider] ?? ''; }
function clearKey() { form.key = ''; form.defaults('key', ''); }
async function save() {
    error.value = '';
    try {
        const result = await form.put('/settings/connections/ai') as { revision: number; ai: AiStatus } | undefined;
        if (!result) return;
        status.value = result.ai; replacing.value = false; toast.success('AI connection verified and saved.'); emit('saved', result.revision, result.ai);
    } catch { error.value = 'Verification failed. Check the provider, API key and model ID. Your saved connection is unchanged.'; }
    finally { clearKey(); }
}
async function remove() {
    if (!removing.value || form.processing) return;
    error.value = ''; clearKey();
    try {
        const result = await form.transform(() => ({ revision: props.revision })).delete('/settings/connections/ai') as { revision: number; ai: AiStatus } | undefined;
        form.transform(data => data);
        if (!result) return;
        removing.value = false; status.value = result.ai; replacing.value = true; toast.success('AI connection removed. Your notes are unchanged.'); emit('saved', result.revision, result.ai);
    } catch { error.value = 'Could not remove the AI connection. Try again.'; }
    finally { form.transform(data => data); }
}
onBeforeUnmount(() => { form.cancel(); clearKey(); });
</script>

<template>
    <section
        class="grid gap-5"
        aria-labelledby="scratchpad-ai-title">
        <div class="flex items-center justify-between gap-4">
            <h2
                id="scratchpad-ai-title"
                class="text-lg font-semibold">
                Scratchpad AI
            </h2>
            <Button
                type="button"
                size="icon-sm"
                variant="ghost"
                class="text-muted-foreground"
                aria-label="About scratchpad AI"
                title="Generating suggestions sends your scratchpad and project name, description, status and tags to your AI provider. Review every action before applying it.">
                <InfoIcon aria-hidden="true" />
            </Button>
        </div>
        <div class="grid gap-3">
            <div
                v-if="status.configured"
                class="flex items-center gap-3 overflow-hidden rounded-xl border px-4 py-3">
                <div class="min-w-0 flex-1 space-y-1">
                    <p class="break-words text-sm font-medium">
                        {{ providerLabels[status.provider ?? ''] ?? status.provider }}
                    </p>
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                        <span class="break-all">{{ status.model }}</span>
                        <span class="inline-flex items-center gap-1">
                            <CircleCheckIcon
                                class="size-3.5 text-emerald-600 dark:text-emerald-400"
                                aria-hidden="true" />
                            Connected
                        </span>
                    </div>
                </div>
                <div
                    v-if="native"
                    class="flex shrink-0 gap-0.5 text-muted-foreground">
                    <Button
                        type="button"
                        size="icon-sm"
                        variant="ghost"
                        :aria-label="replacing ? 'Cancel AI connection replacement' : 'Replace AI connection'"
                        :disabled="form.processing"
                        @click="replacing = !replacing; clearKey()">
                        <XIcon
                            v-if="replacing"
                            aria-hidden="true" />
                        <SlidersHorizontalIcon
                            v-else
                            aria-hidden="true" />
                    </Button>
                    <Button
                        type="button"
                        size="icon-sm"
                        variant="ghost"
                        aria-label="Remove AI connection"
                        :disabled="form.processing"
                        @click="removing = true">
                        <Trash2Icon aria-hidden="true" />
                    </Button>
                </div>
            </div>
            <p
                v-else-if="native"
                class="text-sm text-muted-foreground">
                Connect a provider to generate actions from your scratchpad.
            </p>
            <p
                v-if="!native"
                class="text-sm text-muted-foreground">
                Open the desktop app to connect an AI provider.
            </p>
            <p class="text-xs text-muted-foreground">
                Suggestions share your scratchpad and basic project details with your provider.
            </p>
            <form
                v-if="native && replacing"
                class="grid gap-6"
                @submit.prevent="save">
                <Field>
                    <FieldLabel for="ai-provider">
                        Provider
                    </FieldLabel><ChoiceSelect
                        id="ai-provider"
                        :model-value="form.provider"
                        variant="filled"
                        :options="status.providers.map(value => ({ value, label: providerLabels[value] ?? value }))"
                        :disabled="form.processing"
                        @update:model-value="selectProvider" /><FieldError v-if="form.errors.provider">
                            {{ form.errors.provider }}
                        </FieldError>
                </Field>
                <Field>
                    <FieldLabel for="ai-model">
                        Model ID
                    </FieldLabel><Input
                        id="ai-model"
                        v-model="form.model"
                        variant="filled"
                        autocomplete="off"
                        aria-describedby="ai-model-description"
                        :disabled="form.processing"
                        placeholder="Enter the provider's model ID" /><FieldDescription id="ai-model-description">
                            The prefilled model is this provider's default. A custom value must be an exact model ID offered by the provider.
                            <a
                                :href="modelGuides[form.provider]"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="underline">{{ providerLabels[form.provider] }} model IDs</a>
                        </FieldDescription><FieldError v-if="form.errors.model">
                        {{ form.errors.model }}
                    </FieldError>
                </Field>
                <Field>
                    <FieldLabel for="ai-key">
                        API key
                    </FieldLabel><Input
                        id="ai-key"
                        v-model="form.key"
                        variant="filled"
                        type="password"
                        autocomplete="off"
                        :disabled="form.processing" /><FieldError v-if="form.errors.key">
                            {{ form.errors.key }}
                        </FieldError>
                </Field>
                <Button
                    class="justify-self-start"
                    :disabled="form.processing || !form.key || !form.model">
                    {{ form.processing ? 'Verifying…' : 'Save and verify' }}
                </Button>
            </form>
            <Alert
                v-if="error"
                variant="destructive">
                <AlertDescription>{{ error }}</AlertDescription>
            </Alert><FieldError v-if="form.errors.revision">
                {{ form.errors.revision }}
            </FieldError>
            <Dialog v-model:open="removing">
                <DialogContent>
                    <DialogHeader><DialogTitle>Remove AI connection?</DialogTitle><DialogDescription>Remove {{ providerLabels[status.provider ?? ''] ?? status.provider }} · {{ status.model }}. AI suggestions will require reconnection. Your notes and explicit conversion of bare URLs to links remain available.</DialogDescription></DialogHeader>
                    <Alert
                        v-if="error"
                        variant="destructive">
                        <AlertDescription>{{ error }}</AlertDescription>
                    </Alert>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            :disabled="form.processing"
                            @click="removing = false">
                            Cancel
                        </Button><Button
                            variant="destructive"
                            :disabled="form.processing"
                            @click="remove">
                            Remove AI connection
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    </section>
</template>
