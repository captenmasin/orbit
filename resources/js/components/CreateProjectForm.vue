<script setup lang="ts">
import ChoiceSelect from '@/components/ChoiceSelect.vue';
import ProjectTagsInput from '@/components/ProjectTagsInput.vue';
import ProjectIconPicker from '@/components/ProjectIconPicker.vue';
import type { ProjectLink } from '@/types';
import { useObjectUrl } from '@vueuse/core';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { VueDraggable } from 'vue-draggable-plus';
import { Textarea } from '@/components/ui/textarea';
import { projectStatusColors } from '@/lib/project';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { computed, nextTick, onScopeDispose, reactive, ref, watch } from 'vue';
import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { ChevronsUpDownIcon, GripVerticalIcon, PlusIcon, SlidersHorizontalIcon, Trash2Icon } from '@lucide/vue';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { AutocompleteAnchor, AutocompleteContent, AutocompleteInput, AutocompleteItem, AutocompletePortal, AutocompleteRoot, AutocompleteTrigger } from 'reka-ui';

const props = defineProps<{ statuses: string[] }>();
const emit = defineEmits<{ cancel: []; saved: [] }>();
const formId = 'create';
const page = usePage<{ statusColors: Record<string, string> }>();
const statusOptions = computed(() => props.statuses.map(status => ({
    value: status, label: status,
    dotClass: projectStatusColors[page.props.statusColors?.[status] ?? 'gray']?.dotClass ?? projectStatusColors.gray!.dotClass,
})));
function values() {
    return {
        name: '', description: '', status: props.statuses[0]!,
        icon_type: 'initials', icon_emoji: '', icon_file: null as File | null,
        tags: [] as string[], links: [] as ProjectLink[],
    };
}
const form = useForm(values());
const formElement = ref<HTMLFormElement>();
const generalErrors = computed(() => Object.fromEntries(Object.entries(form.errors).filter(([key]) => !/^(name|status|description|tags|icon_(type|emoji|file)|links\.\d+\.(label|url|category|description))$/.test(key))));
const draftKey = 'project-form:create';
const recovered = router.restore(draftKey) as { data: ReturnType<typeof values>; imageSelected: boolean } | undefined;
const imageNeedsSelection = ref(!!recovered?.imageSelected);
if (recovered) {
    for (const key of Object.keys(values()) as (keyof ReturnType<typeof values>)[]) {
        if (key in recovered.data) Object.assign(form, { [key]: recovered.data[key] });
    }
    form.icon_file = null;
}
watch(() => form.data(), () => {
    const { icon_file, ...data } = form.data();
    router.remember(form.isDirty ? { data: JSON.parse(JSON.stringify(data)), imageSelected: !!icon_file || imageNeedsSelection.value } : null, draftKey);
}, { deep: true, flush: 'post' });
const departureOpen = ref(false);
let destination: string | null = null;
const stopDeparture = router.on('before', event => {
    const visit = event.detail.visit;
    if (event.defaultPrevented || !form.isDirty || visit.method.toLowerCase() !== 'get') return;
    if (new URL(visit.url, 'https://orbit.local').pathname === new URL(usePage().url, 'https://orbit.local').pathname) return;
    event.preventDefault(); destination = visit.url.toString(); departureOpen.value = true;
});
function discardDraft() {
    form.reset(); form.defaults(); form.clearErrors(); imageNeedsSelection.value = false;
    router.remember(null, draftKey); departureOpen.value = false;
    const next = destination; destination = null;
    if (next) router.visit(next); else emit('cancel');
}
function requestClose() {
    if (form.processing) return;
    destination = null;
    if (form.isDirty) departureOpen.value = true; else emit('cancel');
}
defineExpose({ requestClose });
function warnBeforeUnload(event: BeforeUnloadEvent) {
    if (form.isDirty) { event.preventDefault(); event.returnValue = ''; }
}
if (typeof window !== 'undefined') window.addEventListener('beforeunload', warnBeforeUnload);
onScopeDispose(() => { stopDeparture(); if (typeof window !== 'undefined') window.removeEventListener('beforeunload', warnBeforeUnload); });
const linkDetailsOpen = reactive<Record<string, boolean>>(Object.fromEntries(form.links.map(link => [link.id, !!(link.category || link.description)])));
const linkAnnouncement = ref('');
const imagePreview = useObjectUrl(computed(() => form.icon_file));

function submit() {
    if (form.processing) return;
    if (form.icon_file && form.icon_file.size > 5242880) {
        form.setError('icon_file', 'Choose an image no larger than 5 MB.');
        return;
    }
    form.post('/projects', {
        preserveScroll: true,
        errorBag: 'createProject',
        onSuccess: () => {
            router.remember(null, draftKey);
            form.defaults();
            emit('saved');
        },
        onFinish: () => {
            if (form.hasErrors) nextTick(() => formElement.value?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus());
        },
    });
}
async function moveLink(index: number, direction: number, event?: KeyboardEvent) {
    const target = index + direction;
    if (form.processing || index < 0 || index >= form.links.length || target < 0 || target >= form.links.length) return;
    const handle = event?.currentTarget as HTMLElement | undefined;
    const [link] = form.links.splice(index, 1);
    form.links.splice(target, 0, link!);
    form.clearErrors();
    linkAnnouncement.value = `${link!.label || 'Link'} moved to position ${target + 1} of ${form.links.length}.`;
    await nextTick();
    handle?.focus();
}
function addLink() {
    form.links.push({ id: crypto.randomUUID(), label: '', url: '', category: '', description: '' });
}
</script>

<template>
    <div class="flex min-h-0 w-full flex-1 flex-col gap-5">
        <form
            ref="formElement"
            class="@container/project-form flex min-h-0 w-full flex-1 flex-col"
            novalidate
            @submit.prevent="submit">
            <FieldGroup class="min-h-0 flex-1 gap-4 overflow-y-auto px-6 pt-1 pb-6">
                <Alert
                    v-if="Object.keys(generalErrors).length"
                    variant="destructive"
                    role="alert">
                    <AlertDescription>
                        <div class="space-y-1">
                            <p
                                v-for="(error, key) in generalErrors"
                                :id="`${formId}-${key}-error`"
                                :key="key">
                                {{ error }}
                            </p>
                        </div>
                    </AlertDescription>
                </Alert>
                <div class="grid gap-6">
                    <section class="min-w-0">
                        <div>
                            <div class="grid gap-4">
                                <div>
                                    <FieldGroup class="gap-5">
                                        <div class="grid gap-5 @md/field-group:grid-cols-[minmax(0,1fr)_10rem]">
                                            <Field :data-invalid="!!form.errors.name">
                                                <FieldLabel
                                                    class="sr-only"
                                                    :for="`${formId}-name`">
                                                    Name
                                                </FieldLabel>
                                                <div class="flex items-center gap-3">
                                                    <ProjectIconPicker
                                                        :id="`${formId}-icon`"
                                                        v-model:type="form.icon_type"
                                                        v-model:emoji="form.icon_emoji"
                                                        :name="form.name"
                                                        :image="imagePreview"
                                                        :invalid="!!(form.errors.icon_type || form.errors.icon_emoji || form.errors.icon_file)"
                                                        :aria-describedby="(form.errors.icon_type || form.errors.icon_emoji || form.errors.icon_file) ? `${formId}-icon-error` : undefined"
                                                        @update:file="form.icon_file = $event"
                                                        @update:type="form.clearErrors('icon_type', 'icon_emoji', 'icon_file')" />
                                                    <Input
                                                        :id="`${formId}-name`"
                                                        v-model="form.name"
                                                        variant="filled"
                                                        name="name"
                                                        maxlength="255"
                                                        autofocus
                                                        :aria-invalid="!!form.errors.name"
                                                        :aria-describedby="form.errors.name ? `${formId}-name-error` : undefined"
                                                        placeholder="Project name&hellip;" />
                                                </div>
                                                <FieldError
                                                    :id="`${formId}-name-error`"
                                                    :errors="[form.errors.name]" />
                                                <FieldError
                                                    v-if="form.errors.icon_type || form.errors.icon_emoji || form.errors.icon_file"
                                                    :id="`${formId}-icon-error`">
                                                    {{ form.errors.icon_type || form.errors.icon_emoji || form.errors.icon_file }}
                                                </FieldError>
                                                <!--                                            <FieldDescription v-if="imageNeedsSelection && form.icon_type === 'image' && !form.icon_file">-->
                                                <!--                                                Choose your image again to restore this draft.-->
                                                <!--                                            </FieldDescription>-->
                                            </Field>
                                            <Field :data-invalid="!!form.errors.status">
                                                <FieldLabel
                                                    class="sr-only"
                                                    :for="`${formId}-status`">
                                                    Status
                                                </FieldLabel>
                                                <ChoiceSelect
                                                    :id="`${formId}-status`"
                                                    v-model="form.status"
                                                    variant="filled"
                                                    name="status"
                                                    :options="statusOptions"
                                                    :aria-invalid="!!form.errors.status"
                                                    :aria-describedby="form.errors.status ? `${formId}-status-error` : undefined" />
                                                <FieldError
                                                    :id="`${formId}-status-error`"
                                                    :errors="[form.errors.status]" />
                                            </Field>
                                        </div>
                                        <Field :data-invalid="!!form.errors.description">
                                            <FieldLabel :for="`${formId}-description`">
                                                Description
                                            </FieldLabel>
                                            <Textarea
                                                :id="`${formId}-description`"
                                                v-model="form.description"
                                                variant="filled"
                                                name="description"
                                                class="resize-none"
                                                maxlength="10000"
                                                :rows="4"
                                                :aria-invalid="!!form.errors.description"
                                                :aria-describedby="form.errors.description ? `${formId}-description-error` : undefined"
                                                placeholder="A short note about this project" />
                                            <FieldError
                                                :id="`${formId}-description-error`"
                                                :errors="[form.errors.description]" />
                                        </Field>
                                        <Field :data-invalid="!!form.errors.tags">
                                            <FieldLabel :for="`${formId}-tags`">
                                                Tags
                                            </FieldLabel>
                                            <ProjectTagsInput
                                                :id="`${formId}-tags`"
                                                v-model="form.tags"
                                                :invalid="!!form.errors.tags" />
                                            <FieldError
                                                :id="`${formId}-tags-error`"
                                                :errors="[form.errors.tags]" />
                                        </Field>
                                    </FieldGroup>
                                </div>
                            </div>
                        </div>
                    </section>
                    <section class="min-w-0">
                        <div class="grid gap-2">
                            <div>
                                <h2 class="text-sm font-normal tracking-[-0.025em]">
                                    Useful links
                                </h2>
                            </div>
                            <div>
                                <FieldGroup class="gap-4">
                                    <!--                                    <FieldDescription v-if="!form.links.length">-->
                                    <!--                                        No links yet. Add a site, document, or other useful destination.-->
                                    <!--                                    </FieldDescription>-->
                                    <p
                                        v-if="form.links.length"
                                        :id="`${formId}-link-order-help`"
                                        class="sr-only">
                                        Drag to reorder links, or focus a reorder handle and use the up and down arrow keys.
                                    </p>
                                    <VueDraggable
                                        v-if="form.links.length"
                                        v-model="form.links"
                                        tag="ol"
                                        handle=".project-link-handle"
                                        :disabled="form.processing"
                                        ghost-class="opacity-50"
                                        class="divide-y"
                                        aria-label="Useful links"
                                        @update="form.clearErrors(); linkAnnouncement = 'Link order updated.'">
                                        <Collapsible
                                            v-for="(link, index) in form.links"
                                            :key="link.id"
                                            as-child
                                            :open="linkDetailsOpen[link.id] || !!(form.errors[`links.${index}.category`] || form.errors[`links.${index}.description`])"
                                            @update:open="linkDetailsOpen[link.id] = $event">
                                            <li
                                                :aria-label="link.label || `Link ${index + 1}`"
                                                class="grid gap-3 py-4">
                                                <div class="flex min-w-0 items-start gap-3 @lg/project-form:items-center">
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        class="project-link-handle touch-none cursor-grab text-muted-foreground active:cursor-grabbing"
                                                        :aria-label="`Reorder ${link.label || 'link'}`"
                                                        :aria-describedby="`${formId}-link-order-help`"
                                                        :disabled="form.processing || form.links.length < 2"
                                                        @keydown.up.prevent="moveLink(index, -1, $event)"
                                                        @keydown.down.prevent="moveLink(index, 1, $event)">
                                                        <GripVerticalIcon aria-hidden="true" />
                                                    </Button>
                                                    <div class="grid min-w-0 flex-1 gap-3 @lg/project-form:grid-cols-[minmax(0,0.9fr)_minmax(0,1.6fr)]">
                                                        <Field
                                                            :data-invalid="!!form.errors[`links.${index}.label`]">
                                                            <FieldLabel
                                                                class="sr-only"
                                                                :for="`${link.id}-label`">
                                                                Label (optional)
                                                            </FieldLabel>
                                                            <Input
                                                                :id="`${link.id}-label`"
                                                                v-model="link.label"
                                                                variant="filled"
                                                                maxlength="2048"
                                                                :placeholder="link.url || 'Label (optional)'"
                                                                :aria-invalid="!!form.errors[`links.${index}.label`]"
                                                                :aria-describedby="form.errors[`links.${index}.label`] ? `${formId}-links.${index}.label-error` : undefined" />
                                                            <FieldError
                                                                :id="`${formId}-links.${index}.label-error`"
                                                                :errors="[form.errors[`links.${index}.label`]]" />
                                                        </Field>
                                                        <Field
                                                            :data-invalid="!!form.errors[`links.${index}.url`]">
                                                            <FieldLabel
                                                                class="sr-only"
                                                                :for="`${link.id}-url`">
                                                                URL
                                                            </FieldLabel>
                                                            <Input
                                                                :id="`${link.id}-url`"
                                                                v-model="link.url"
                                                                variant="filled"
                                                                maxlength="2048"
                                                                placeholder="https://example.com"
                                                                :aria-invalid="!!form.errors[`links.${index}.url`]"
                                                                :aria-describedby="form.errors[`links.${index}.url`] ? `${formId}-links.${index}.url-error` : undefined" />
                                                            <FieldError
                                                                :id="`${formId}-links.${index}.url-error`"
                                                                :errors="[form.errors[`links.${index}.url`]]" />
                                                        </Field>
                                                    </div>
                                                    <CollapsibleTrigger as-child>
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon-sm"
                                                            :aria-label="`Category and notes for ${link.label || 'link'}`"
                                                            title="Category & notes">
                                                            <SlidersHorizontalIcon aria-hidden="true" />
                                                        </Button>
                                                    </CollapsibleTrigger>
                                                    <Button
                                                        type="button"
                                                        variant="destructive"
                                                        size="icon-sm"
                                                        :aria-label="`Remove ${link.label || 'link'}`"
                                                        @click="form.links.splice(index, 1); form.clearErrors()">
                                                        <Trash2Icon aria-hidden="true" />
                                                    </Button>
                                                </div>
                                                <CollapsibleContent class="grid gap-4 pl-11">
                                                    <Field
                                                        class="gap-2"
                                                        :data-invalid="!!form.errors[`links.${index}.category`]">
                                                        <FieldLabel :for="`${link.id}-category`">
                                                            Category (optional)
                                                        </FieldLabel>
                                                        <AutocompleteRoot
                                                            :model-value="link.category ?? ''"
                                                            open-on-click
                                                            @update:model-value="link.category = $event">
                                                            <AutocompleteAnchor class="relative">
                                                                <AutocompleteInput as-child>
                                                                    <Input
                                                                        :id="`${link.id}-category`"
                                                                        :model-value="link.category ?? ''"
                                                                        variant="filled"
                                                                        maxlength="50"
                                                                        placeholder="Choose or type a category"
                                                                        :aria-invalid="!!form.errors[`links.${index}.category`]"
                                                                        :aria-describedby="form.errors[`links.${index}.category`] ? `${formId}-links.${index}.category-error` : undefined"
                                                                        class="pr-10"
                                                                        @update:model-value="link.category = String($event)" />
                                                                </AutocompleteInput>
                                                                <AutocompleteTrigger as-child>
                                                                    <Button
                                                                        type="button"
                                                                        variant="ghost"
                                                                        size="icon"
                                                                        class="absolute inset-y-0 right-0 text-muted-foreground"
                                                                        aria-label="Show category suggestions">
                                                                        <ChevronsUpDownIcon aria-hidden="true" />
                                                                    </Button>
                                                                </AutocompleteTrigger>
                                                            </AutocompleteAnchor>
                                                            <AutocompletePortal>
                                                                <AutocompleteContent
                                                                    position="popper"
                                                                    align="start"
                                                                    hide-when-empty
                                                                    class="z-50 max-h-56 min-w-(--reka-combobox-trigger-width) overflow-y-auto rounded-xl border retina:border-[0.5px] border-border bg-popover p-1 text-popover-foreground shadow-lg">
                                                                    <AutocompleteItem
                                                                        v-for="category in ['Website', 'Social', 'Analytics', 'Inbox', 'Documentation', 'Hosting']"
                                                                        :key="category"
                                                                        :value="category"
                                                                        class="flex cursor-default items-center rounded-lg px-3 py-2 text-sm outline-none data-[highlighted]:bg-accent data-[highlighted]:text-accent-foreground">
                                                                        {{ category }}
                                                                    </AutocompleteItem>
                                                                </AutocompleteContent>
                                                            </AutocompletePortal>
                                                        </AutocompleteRoot>
                                                        <FieldError
                                                            :id="`${formId}-links.${index}.category-error`"
                                                            :errors="[form.errors[`links.${index}.category`]]" />
                                                    </Field>
                                                    <Field
                                                        class="gap-2"
                                                        :data-invalid="!!form.errors[`links.${index}.description`]">
                                                        <FieldLabel :for="`${link.id}-description`">
                                                            Notes
                                                        </FieldLabel>
                                                        <Textarea
                                                            :id="`${link.id}-description`"
                                                            :model-value="link.description ?? ''"
                                                            variant="filled"
                                                            class="resize-none"
                                                            maxlength="10000"
                                                            :rows="3"
                                                            :aria-invalid="!!form.errors[`links.${index}.description`]"
                                                            :aria-describedby="form.errors[`links.${index}.description`] ? `${formId}-links.${index}.description-error` : undefined"
                                                            placeholder="Access notes or setup steps"
                                                            @update:model-value="link.description = String($event)" />
                                                        <FieldError
                                                            :id="`${formId}-links.${index}.description-error`"
                                                            :errors="[form.errors[`links.${index}.description`]]" />
                                                    </Field>
                                                </CollapsibleContent>
                                            </li>
                                        </Collapsible>
                                    </VueDraggable>
                                    <p
                                        class="sr-only"
                                        role="status">
                                        {{ linkAnnouncement }}
                                    </p>
                                    <div>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            @click="addLink">
                                            <PlusIcon aria-hidden="true" />{{ form.links.length ? 'Add another link' : 'Add link' }}
                                        </Button>
                                    </div>
                                </FieldGroup>
                            </div>
                        </div>
                    </section>
                </div>
            </FieldGroup>
            <Field
                orientation="horizontal"
                class="shrink-0 flex-wrap justify-end gap-3 border-t p-4">
                <Button
                    type="button"
                    variant="ghost"
                    :disabled="form.processing"
                    @click="requestClose">
                    Cancel
                </Button>
                <Button
                    type="submit"
                    :disabled="form.processing">
                    {{ form.processing ? 'Saving…' : 'Create project' }}
                </Button>
            </Field>
        </form>
        <Dialog v-model:open="departureOpen">
            <DialogContent>
                <DialogHeader><DialogTitle>Discard project changes?</DialogTitle><DialogDescription>Your unsaved details and links will be discarded.</DialogDescription></DialogHeader><DialogFooter>
                    <Button
                        variant="outline"
                        @click="departureOpen = false; destination = null">
                        Stay
                    </Button><Button
                        variant="destructive"
                        @click="discardDraft">
                        Discard changes
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
