<script setup lang="ts">
import { toast } from 'vue-sonner';
import { BellIcon } from '@lucide/vue';
import { Head, useHttp } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';

const props = defineProps<{ native: boolean }>();
const notification = useHttp({});

async function sendNotification() {
    if (!props.native || notification.processing) return;
    try {
        await notification.post('/debug/notification');
        toast.success('Test notification sent.');
    } catch {
        toast.error('The test notification could not be sent. Try again.');
    }
}
</script>

<template>
    <div class="flex w-full max-w-[1200px] flex-col gap-8 pb-8">
        <Head title="Debug" />
        <div class="flex flex-col gap-2">
            <h1 class="text-[2rem] leading-tight font-semibold tracking-[-0.035em]">
                Debug
            </h1>
            <p class="text-sm text-muted-foreground">
                Temporary tools for testing Orbit.
            </p>
        </div>
        <Card>
            <CardHeader>
                <h2 class="text-lg font-medium">
                    Desktop notification
                </h2>
                <p class="text-sm text-muted-foreground">
                    Send a test notification to your operating system.
                </p>
            </CardHeader>
            <CardContent class="flex flex-col items-start gap-3">
                <Button
                    type="button"
                    :disabled="!native || notification.processing"
                    @click="sendNotification">
                    <BellIcon aria-hidden="true" />
                    {{ notification.processing ? 'Sending…' : 'Send test notification' }}
                </Button>
                <p
                    v-if="!native"
                    class="text-sm text-muted-foreground">
                    Open Orbit desktop to test native notifications.
                </p>
            </CardContent>
        </Card>
    </div>
</template>
