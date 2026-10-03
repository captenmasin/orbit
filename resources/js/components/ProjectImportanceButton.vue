<script setup lang="ts">
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import { StarIcon } from '@lucide/vue';
import type { Project } from '@/types';
import { router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    project: Pick<Project, 'id' | 'revision'>;
    kind: 'links' | 'documents';
    item: { id: string; important: boolean };
    label: string;
    disabled?: boolean;
}>();
const saving = ref(false);

function toggle() {
    if (saving.value || props.disabled) return;
    saving.value = true;
    router.put(`/projects/${props.project.id}/important`, {
        kind: props.kind, id: props.item.id, important: !props.item.important, revision: props.project.revision,
    }, {
        preserveScroll: true,
        errorBag: 'importance',
        onError: errors => toast.error(String(Object.values(errors)[0] ?? 'Could not update important items. Try again.')),
        onNetworkError: () => { toast.error('Could not update important items. Try again.'); },
        onFinish: () => { saving.value = false; },
    });
}
defineExpose({ toggle, saving });
</script>

<template>
    <Button
        type="button"
        variant="ghost"
        size="icon-sm"
        :disabled="disabled || saving"
        :aria-pressed="!!item.important"
        :aria-label="item.important ? `Remove ${label} from important` : `Mark ${label} as important`"
        :title="item.important ? 'Remove from important' : 'Mark as important'"
        @click="toggle">
        <StarIcon
            :class="item.important ? 'fill-current text-amber-600 dark:text-amber-400' : 'text-muted-foreground'"
            aria-hidden="true" />
    </Button>
</template>
