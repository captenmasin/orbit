<script setup lang="ts">
import CreateProjectForm from '@/components/CreateProjectForm.vue';
import { ref } from 'vue';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';

defineProps<{ open: boolean; statuses: string[] }>();
const emit = defineEmits<{ close: []; created: [] }>();
const projectForm = ref<InstanceType<typeof CreateProjectForm>>();
const previousFocus = typeof document !== 'undefined' ? document.activeElement as HTMLElement | null : null;
function focusName() {
    document.getElementById('create-name')?.focus();
}
</script>

<template>
    <Dialog
        :open="open"
        @update:open="!$event && projectForm?.requestClose()">
        <DialogContent
            class="flex max-h-[85dvh] flex-col gap-5 p-0 max-sm:inset-0 max-sm:h-dvh max-sm:max-h-dvh max-sm:max-w-none max-sm:translate-x-0 max-sm:translate-y-0 max-sm:rounded-none sm:max-w-[540px]"
            @open-auto-focus.prevent="focusName"
            @close-auto-focus.prevent="previousFocus?.focus()">
            <DialogHeader class="px-6 pt-6 pr-14 text-left">
                <DialogTitle>New project</DialogTitle>
                <DialogDescription class="sr-only">
                    Add project details and optional useful links. Repositories and local folders can be added after creation.
                </DialogDescription>
            </DialogHeader>
            <CreateProjectForm
                ref="projectForm"
                :statuses="statuses"
                @cancel="emit('close')"
                @saved="emit('created')" />
        </DialogContent>
    </Dialog>
</template>
