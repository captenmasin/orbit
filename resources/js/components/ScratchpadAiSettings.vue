<script setup lang="ts">
import ChoiceSelect from '@/components/ChoiceSelect.vue';
import { toast } from 'vue-sonner';
import { useHttp } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { onBeforeUnmount, ref, watch } from 'vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Field, FieldDescription, FieldLabel, FieldError } from '@/components/ui/field';

type AiStatus = { configured: boolean; provider: string | null; model: string | null; providers: string[]; default_models: Record<string, string> };
const props = defineProps<{ ai: AiStatus; revision: number; native: boolean }>();
const emit = defineEmits<{ saved: [revision: number, ai: AiStatus] }>();
const status = ref(props.ai);
const replacing = ref(!props.ai.configured);
const form = useHttp({ provider: props.ai.provider ?? 'openai', model: props.ai.model ?? props.ai.default_models[props.ai.provider ?? 'openai'] ?? '', key: '', revision: props.revision });
const error = ref('');
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
    error.value = ''; clearKey();
    try {
        const result = await form.transform(() => ({ revision: props.revision })).delete('/settings/connections/ai') as { revision: number; ai: AiStatus } | undefined;
        form.transform(data => data);
        if (!result) return;
        status.value = result.ai; replacing.value = true; toast.success('AI connection removed. Your notes are unchanged.'); emit('saved', result.revision, result.ai);
    } catch { error.value = 'Could not remove the AI connection. Try again.'; }
    finally { form.transform(data => data); }
}
onBeforeUnmount(() => { form.cancel(); clearKey(); });
</script>

<template>
    <Card
        as="section"
        aria-labelledby="scratchpad-ai-title">
        <CardHeader>
            <h2
                id="scratchpad-ai-title"
                class="text-sm font-normal">
                Scratchpad AI
            </h2>
        </CardHeader>
        <CardContent class="gap-6">
            <FieldDescription>Generating actions sends your scratchpad and limited project context (name, description, status and tags) to this provider. Review every action before applying it.</FieldDescription>
            <p class="text-sm">
                {{ status.configured ? `Connected to ${status.provider} · ${status.model}` : 'No AI connection configured.' }}
            </p>
            <Alert v-if="!native">
                <AlertDescription>Open the desktop app to configure encrypted AI credentials.</AlertDescription>
            </Alert>
            <div
                v-if="native && status.configured"
                class="flex gap-2">
                <Button
                    variant="outline"
                    :disabled="form.processing"
                    @click="replacing = !replacing; clearKey()">
                    {{ replacing ? 'Cancel replacement' : 'Replace' }}
                </Button><Button
                    variant="outline"
                    :disabled="form.processing"
                    @click="remove">
                    Remove
                </Button>
            </div>
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
                        :options="status.providers"
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
                            Each provider has a default model. You can enter a different model ID.
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
        </CardContent>
    </Card>
</template>
