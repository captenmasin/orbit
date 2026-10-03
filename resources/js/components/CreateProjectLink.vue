<script setup lang="ts">
import { ref } from 'vue';
import type { Project } from '@/types';
import { PlusIcon } from '@lucide/vue';
import { router } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';

const props = defineProps<{ project: Project; disabled?: boolean }>();
const creating = ref(false);
const saving = ref(false);
const revision = ref(0);
const linkId = ref('');
const draft = ref({ label: '', url: '', category: '', description: '' });
const errors = ref<Record<string, string>>({});

function setOpen(open: boolean) {
    if (saving.value || (open && props.disabled)) return;
    if (open) {
        draft.value = { label: '', url: '', category: '', description: '' };
        errors.value = {};
        revision.value = props.project.revision;
        linkId.value = crypto.randomUUID();
    }
    creating.value = open;
}
function createLink() {
    if (!creating.value || saving.value || props.disabled) return;
    const project = props.project;
    const index = project.links.length;
    errors.value = {};
    saving.value = true;
    router.put(`/projects/${project.id}`, {
        name: project.name, description: project.description, status: project.status, revision: revision.value,
        links: [...project.links.map(({ id, label, url, category, description }) => ({ id, label, url, category, description })), { id: linkId.value, ...draft.value }],
        return_back: true,
    }, {
        preserveScroll: true,
        errorBag: 'createLink',
        onSuccess: () => { creating.value = false; },
        onError: validationErrors => {
            for (const [key, message] of Object.entries(validationErrors)) {
                const field = key.startsWith(`links.${index}.`) ? key.slice(`links.${index}.`.length) : 'general';
                errors.value[field in draft.value ? field : 'general'] = String(message);
            }
        },
        onNetworkError: () => { errors.value.general = 'Could not add link. Try again.'; },
        onFinish: () => { saving.value = false; },
    });
}
</script>

<template>
    <Dialog
        :open="creating"
        @update:open="setOpen">
        <DialogTrigger as-child>
            <Button
                type="button"
                variant="ghost"
                size="xs"
                :disabled="disabled || saving">
                <PlusIcon aria-hidden="true" />Link
            </Button>
        </DialogTrigger>
        <DialogContent
            :aria-describedby="undefined"
            class="max-h-[calc(100dvh-2rem)] overflow-y-auto">
            <DialogHeader>
                <DialogTitle>Add link</DialogTitle>
            </DialogHeader>
            <form
                class="grid gap-4"
                @submit.prevent="createLink">
                <FieldError :errors="[errors.general]" />
                <Field
                    class="gap-2"
                    :data-invalid="!!errors.label">
                    <FieldLabel :for="`create-link-${project.id}-label`">
                        Name
                    </FieldLabel>
                    <Input
                        :id="`create-link-${project.id}-label`"
                        v-model="draft.label"
                        variant="filled"
                        maxlength="2048"
                        :placeholder="draft.url || 'Name (optional)'"
                        :disabled="saving"
                        :aria-invalid="!!errors.label"
                        :aria-describedby="errors.label ? `create-link-${project.id}-label-error` : undefined" />
                    <FieldError
                        :id="`create-link-${project.id}-label-error`"
                        :errors="[errors.label]" />
                </Field>
                <Field
                    class="gap-2"
                    :data-invalid="!!errors.url">
                    <FieldLabel :for="`create-link-${project.id}-url`">
                        URL
                    </FieldLabel>
                    <Input
                        :id="`create-link-${project.id}-url`"
                        v-model="draft.url"
                        variant="filled"
                        type="url"
                        required
                        maxlength="2048"
                        placeholder="https://example.com"
                        :disabled="saving"
                        :aria-invalid="!!errors.url"
                        :aria-describedby="errors.url ? `create-link-${project.id}-url-error` : undefined" />
                    <FieldError
                        :id="`create-link-${project.id}-url-error`"
                        :errors="[errors.url]" />
                </Field>
                <Field
                    class="gap-2"
                    :data-invalid="!!errors.category">
                    <FieldLabel :for="`create-link-${project.id}-category`">
                        Category (optional)
                    </FieldLabel>
                    <Input
                        :id="`create-link-${project.id}-category`"
                        v-model="draft.category"
                        variant="filled"
                        maxlength="50"
                        placeholder="Category (optional)"
                        :disabled="saving"
                        :aria-invalid="!!errors.category"
                        :aria-describedby="errors.category ? `create-link-${project.id}-category-error` : undefined" />
                    <FieldError
                        :id="`create-link-${project.id}-category-error`"
                        :errors="[errors.category]" />
                </Field>
                <Field
                    class="gap-2"
                    :data-invalid="!!errors.description">
                    <FieldLabel :for="`create-link-${project.id}-description`">
                        Notes
                    </FieldLabel>
                    <Textarea
                        :id="`create-link-${project.id}-description`"
                        v-model="draft.description"
                        variant="filled"
                        class="resize-none"
                        :rows="3"
                        maxlength="10000"
                        placeholder="Access notes or setup steps"
                        :disabled="saving"
                        :aria-invalid="!!errors.description"
                        :aria-describedby="errors.description ? `create-link-${project.id}-description-error` : undefined" />
                    <FieldError
                        :id="`create-link-${project.id}-description-error`"
                        :errors="[errors.description]" />
                </Field>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="saving"
                        @click="setOpen(false)">
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        :disabled="disabled || saving">
                        {{ saving ? 'Adding…' : 'Add link' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
