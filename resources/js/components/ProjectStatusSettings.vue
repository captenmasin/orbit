<script setup lang="ts">
import ChoiceSelect from '@/components/ChoiceSelect.vue';
import { toast } from 'vue-sonner';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { reducedMotion } from '@/lib/appearance';
import { router, useHttp } from '@inertiajs/vue3';
import { VueDraggable } from 'vue-draggable-plus';
import { projectStatusColors } from '@/lib/project';
import { GripVerticalIcon, Trash2Icon } from '@lucide/vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';

type Status = { original: string | null; name: string; color: string };
const props = defineProps<{ names: string[]; colors: Record<string, string>; revision: number; usage: { name: string; count: number }[] }>();
const emit = defineEmits<{ saved: [revision: number] }>();
function statusRows(names: string[], colors: Record<string, string>): Status[] {
    return names.map(name => ({ original: name, name, color: Object.hasOwn(colors, name) ? colors[name]! : 'gray' }));
}
const colorOptions = Object.entries(projectStatusColors).map(([value, option]) => ({ value, ...option }));
const form = useHttp({ revision: props.revision, statuses: statusRows(props.names, props.colors), replacements: [] as { original: string; replacement: string }[] });
const removed = ref<{ status: Status; replacement: string }[]>([]);
const error = ref('');
const needsReload = ref(false);
const announcement = ref('');
const keys = new WeakMap<Status, number>();
let nextKey = 0;
function statusKey(status: Status) {
    if (!keys.has(status)) keys.set(status, ++nextKey);
    return String(keys.get(status));
}
const replacementOptions = computed(() => form.statuses.filter(status => status.name.trim()).map(status => ({ value: statusKey(status), label: status.name.trim(), dotClass: projectStatusColors[status.color]?.dotClass })));
function usage(name: string | null) { return props.usage.find(item => item.name === name)?.count ?? 0; }
watch(() => props.revision, value => { form.revision = value; form.defaults({ revision: value }); });
function reset(names: string[], colors: Record<string, string>) {
    form.statuses = statusRows(names, colors);
    form.replacements = []; removed.value = []; error.value = ''; needsReload.value = false;
    form.clearErrors(); form.defaults();
}
watch([() => props.names, () => props.colors], ([names, colors]) => { if (!form.isDirty) reset(names, colors); });
async function move(index: number, offset: number, event?: KeyboardEvent) {
    const target = index + offset;
    if (form.processing || needsReload.value || target < 0 || target >= form.statuses.length) return;
    const handle = event?.currentTarget as HTMLElement | undefined;
    const status = form.statuses.splice(index, 1)[0];
    if (status) {
        form.statuses.splice(target, 0, status);
        announcement.value = `${status.name || 'New status'} moved to position ${target + 1} of ${form.statuses.length}.`;
        await nextTick();
        handle?.focus();
    }
}
function remove(index: number) {
    if (form.processing || form.statuses.length <= 1) return;
    const status = form.statuses.splice(index, 1)[0];
    if (!status) return;
    for (const item of removed.value) { if (item.replacement === statusKey(status)) item.replacement = ''; }
    if (status.original !== null) removed.value.push({ status, replacement: '' });
}
function undo(index: number) {
    const item = removed.value.splice(index, 1)[0];
    if (item) form.statuses.push(item.status);
}
async function save() {
    if (form.processing || needsReload.value) return;
    error.value = '';
    form.replacements = removed.value.flatMap(item => {
        const target = form.statuses.find(status => statusKey(status) === item.replacement);
        return target ? [{ original: item.status.original!, replacement: target.name.trim() }] : [];
    });
    try {
        const result = await form.put('/settings/project_statuses') as { preferences: { revision: number; values: { project_statuses: { names: string[]; colors: Record<string, string> } } } } | undefined;
        if (!result) return;
        form.revision = result.preferences.revision;
        reset(result.preferences.values.project_statuses.names, result.preferences.values.project_statuses.colors);
        emit('saved', result.preferences.revision);
        router.reload({ only: ['preferences', 'sidebarProjects', 'statuses', 'statusColors', 'statusUsage'] });
        toast.success('Project statuses saved.');
    } catch (exception) {
        needsReload.value = (exception as { response?: { status?: number } })?.response?.status === 409;
        error.value = needsReload.value ? 'Settings changed in another window. Reload before saving.' : 'Statuses could not be saved. Check the fields and try again.';
    }
}
function reload() { router.reload({ onSuccess: () => reset(props.names, props.colors) }); }
onBeforeUnmount(() => form.cancel());
</script>

<template>
    <form
        class="grid gap-6"
        @submit.prevent="save">
        <p class="text-sm leading-6 text-muted-foreground">
            Organize projects with your own statuses. The first status is the default for new projects. This order also applies to menus and sidebar groups.
        </p>
        <p
            id="status-order-help"
            class="sr-only">
            Drag a handle to reorder statuses, or focus it and use the up and down arrow keys. Save statuses to apply the new order.
        </p>
        <VueDraggable
            v-model="form.statuses"
            tag="ol"
            handle=".status-handle"
            :animation="reducedMotion ? 0 : 150"
            :disabled="form.processing || needsReload"
            ghost-class="opacity-50"
            class="divide-y"
            aria-label="Project statuses"
            @update="announcement = 'Status order updated. Save statuses to apply.'">
            <li
                v-for="(status, index) in form.statuses"
                :key="statusKey(status)"
                class="flex items-start gap-3 py-4 sm:items-center">
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    class="status-handle touch-none cursor-grab text-muted-foreground active:cursor-grabbing"
                    :aria-label="`Reorder ${status.name || 'new status'}`"
                    aria-describedby="status-order-help"
                    :disabled="form.processing || needsReload"
                    @keydown.up.prevent="move(index, -1, $event)"
                    @keydown.down.prevent="move(index, 1, $event)">
                    <GripVerticalIcon aria-hidden="true" />
                </Button>
                <div class="grid min-w-0 flex-1 gap-3 sm:grid-cols-[minmax(0,1fr)_10rem]">
                    <Field>
                        <FieldLabel
                            class="sr-only"
                            :for="`project-status-${statusKey(status)}`">
                            Status {{ index + 1 }}{{ index === 0 ? ' (default)' : '' }}
                        </FieldLabel><Input
                            :id="`project-status-${statusKey(status)}`"
                            v-model="status.name"
                            variant="filled"
                            maxlength="100"
                            required
                            :disabled="form.processing || needsReload" />
                    </Field>
                    <Field>
                        <FieldLabel
                            class="sr-only"
                            :for="`project-status-color-${statusKey(status)}`">
                            Colour
                        </FieldLabel><ChoiceSelect
                            :id="`project-status-color-${statusKey(status)}`"
                            v-model="status.color"
                            variant="filled"
                            :options="colorOptions"
                            :disabled="form.processing || needsReload"
                            :aria-label="`Colour for ${status.name || 'new status'}`" />
                    </Field>
                </div>
                <Button
                    type="button"
                    variant="destructive"
                    size="icon-sm"
                    :disabled="form.statuses.length <= 1 || form.processing || needsReload"
                    :aria-label="`Remove ${status.name || 'status'}`"
                    @click="remove(index)">
                    <Trash2Icon aria-hidden="true" />
                </Button>
            </li>
        </VueDraggable>
        <p
            class="sr-only"
            role="status">
            {{ announcement }}
        </p>
        <div
            v-for="(item, index) in removed"
            :key="statusKey(item.status)"
            class="grid gap-4 rounded-xl border p-4 sm:p-5">
            <p class="text-sm">
                Removing <strong>{{ item.status.original }}</strong><span v-if="usage(item.status.original)"> · {{ usage(item.status.original) }} {{ usage(item.status.original) === 1 ? 'project' : 'projects' }}, including archived projects</span>
            </p>
            <Field>
                <FieldLabel :for="`replace-status-${statusKey(item.status)}`">
                    Move projects to
                </FieldLabel><ChoiceSelect
                    :id="`replace-status-${statusKey(item.status)}`"
                    v-model="item.replacement"
                    variant="filled"
                    :options="replacementOptions"
                    :placeholder="usage(item.status.original) ? 'Choose a replacement status' : 'Optional for an unused status'"
                    :disabled="form.processing || needsReload" />
            </Field>
            <Button
                type="button"
                variant="outline"
                size="sm"
                class="justify-self-start"
                :disabled="form.processing || needsReload || form.statuses.length >= 100"
                @click="undo(index)">
                Undo removal
            </Button>
        </div>
        <p class="text-sm text-muted-foreground">
            Archived is built in. Archiving and restoring projects remain available in project actions.
        </p>
        <FieldError
            v-for="(value, key) in form.errors"
            :key="key">
            {{ value }}
        </FieldError>
        <Alert
            v-if="error"
            variant="destructive">
            <AlertDescription>{{ error }}</AlertDescription>
        </Alert>
        <div class="flex flex-wrap gap-2">
            <Button
                type="button"
                variant="outline"
                :disabled="form.processing || needsReload || form.statuses.length >= 100"
                @click="form.statuses.push({ original: null, name: '', color: 'gray' })">
                Add status
            </Button>
            <Button :disabled="form.processing || needsReload">
                {{ form.processing ? 'Saving…' : 'Save statuses' }}
            </Button>
            <Button
                v-if="needsReload"
                type="button"
                variant="outline"
                @click="reload">
                Reload settings
            </Button>
        </div>
    </form>
</template>
