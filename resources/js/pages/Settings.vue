<script setup lang="ts">
import ChoiceSelect from '@/components/ChoiceSelect.vue';
import WorkspaceBackups from '@/components/WorkspaceBackups.vue';
import SecretsPinSettings from '@/components/SecretsPinSettings.vue';
import ProviderConnections from '@/components/ProviderConnections.vue';
import ScratchpadAiSettings from '@/components/ScratchpadAiSettings.vue';
import ProjectStatusSettings from '@/components/ProjectStatusSettings.vue';
import { toast } from 'vue-sonner';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { VueDraggable } from 'vue-draggable-plus';
import type { ProviderConnection } from '@/types';
import { Head, router, useHttp } from '@inertiajs/vue3';
import { GripVerticalIcon, Trash2Icon } from '@lucide/vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { boardColumnColors, boardColumnColor } from '@/lib/project';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';
import { applyAppearance, applyMotionPreference, reducedMotion, type Appearance, type MotionPreference } from '@/lib/appearance';

type Column = { name: string; color: string | null };
type Values = { general: { startup_destination: string }; appearance: { theme: Appearance; reduce_motion: MotionPreference }; security: { lock_minutes: number; clipboard_seconds: number }; project_defaults: { columns: Column[] }; project_statuses: { names: string[]; colors: Record<string, string> }; tools: { paths: Record<string, string | null> } };
const props = defineProps<{ native: boolean; pinSet: boolean | null; preferences: { revision: number; values: Values }; section: string; statusUsage: { name: string; count: number }[]; columnColors: string[]; defaultColumns: Column[]; connections: ProviderConnection[]; ai: { configured: boolean; provider: string | null; model: string | null; providers: string[]; default_models: Record<string, string> }; launchAtLogin: boolean | null; nativeError: string | null; about: { version: string; updatesAvailable: boolean; releaseNotes: string | null }; mcp: { command: string; args: string[]; env: Record<string, string> } | null }>();
const sections = [
    { value: 'general', label: 'General' }, { value: 'appearance', label: 'Appearance' }, { value: 'security', label: 'Security' },
    { value: 'project_defaults', label: 'Board columns' }, { value: 'project_statuses', label: 'Project statuses' }, { value: 'connections', label: 'Connections' },
    { value: 'tools', label: 'Tools & runtimes' }, { value: 'backups', label: 'Backups & restore' }, { value: 'about', label: 'About & updates' },
];
const pinConfigured = ref(props.pinSet);
const aiStatus = ref(props.ai);
watch(() => props.pinSet, value => { pinConfigured.value = value; });
watch(() => props.ai, value => { aiStatus.value = value; });
const selectedSection = () => sections.some(item => item.value === props.section) ? props.section : 'general';
const section = ref(selectedSection());
watch(() => props.section, () => { section.value = selectedSection(); });
function selectSection(value: string) {
    if (!sections.some(item => item.value === value) || value === section.value) return;
    router.get(`/settings?section=${encodeURIComponent(value)}`, {}, { preserveState: true, preserveScroll: true });
}
const revision = ref(props.preferences.revision);
const savedTheme = ref(props.preferences.values.appearance.theme);
const savedMotion = ref(props.preferences.values.appearance.reduce_motion);
const general = useHttp({ revision: revision.value, ...props.preferences.values.general });
const appearance = useHttp({ revision: revision.value, ...props.preferences.values.appearance });
const security = useHttp({ revision: revision.value, ...props.preferences.values.security });
const board = useHttp({ revision: revision.value, columns: props.preferences.values.project_defaults.columns.map(column => ({ ...column })) });
const columnAnnouncement = ref('');
const tools = useHttp({ revision: revision.value, paths: { ...props.preferences.values.tools.paths } });
const forms = { general, appearance, security, project_defaults: board, tools };
const saving = computed(() => Object.values(forms).some(form => form.processing));
const error = ref('');
const themePreview = useHttp({ theme: savedTheme.value });
const login = useHttp({ enabled: props.launchAtLogin ?? false });
const runtimeProbe = useHttp({ paths: { ...tools.paths } });
const runtimePicker = useHttp({ tool: '' });
const runtimeResults = ref<Record<string, { path?: string | null; version?: string | null; state: string; source?: string }>>({});
watch(() => tools.paths, () => { runtimeResults.value = {}; }, { deep: true });
const toolNames = ['php', 'node', 'composer', 'npm', 'pnpm', 'yarn'];
const updates = useHttp({ confirmed: false });
const updateState = ref<{ status: string; message?: string; version?: string; percent?: number }>({ status: props.about.updatesAvailable ? 'idle' : 'unavailable' });
let updateTimer: ReturnType<typeof setInterval> | undefined;
const mcpConfiguration = computed(() => JSON.stringify({ mcpServers: { orbit: props.mcp } }, null, 2));

function savedPin(value: number) { pinConfigured.value = true; syncRevision(value); }
function savedAi(value: number, status: typeof props.ai) { aiStatus.value = status; syncRevision(value); }
function syncRevision(value: number) { revision.value = value; for (const form of Object.values(forms)) { form.revision = value; form.defaults({ revision: value }); } }
watch(() => props.preferences.revision, syncRevision);
function message(exception: unknown, fallback: string): string {
    try { const data = (exception as { response?: { data?: string } })?.response?.data; if (data) return JSON.parse(data).message ?? fallback; } catch { /* Use the fallback. */ }
    return fallback;
}
async function previewTheme(mode: Appearance) {
    applyAppearance(mode);
    if (!props.native) return;
    themePreview.theme = mode;
    try { await themePreview.post('/settings/appearance/preview'); } catch { toast.error('The native window appearance could not be previewed.'); }
}
watch(() => appearance.theme, mode => { if (section.value === 'appearance') void previewTheme(mode); });
watch(() => appearance.reduce_motion, mode => { if (section.value === 'appearance') applyMotionPreference(mode); });
watch(section, (next, previous) => {
    error.value = '';
    if (previous === 'appearance') {
        appearance.theme = savedTheme.value; void previewTheme(savedTheme.value);
        appearance.reduce_motion = savedMotion.value; applyMotionPreference(savedMotion.value);
    }
    if (next === 'tools') void probeTools();
    if (next === 'about' && props.about.updatesAvailable) void refreshUpdates();
});
async function save(name: keyof typeof forms) {
    error.value = '';
    const form = forms[name];
    try {
        const result = await form.put(`/settings/${name}`) as { preferences: { revision: number; values: Values } } | undefined;
        if (!result) return;
        syncRevision(result.preferences.revision);
        form.defaults();
        if (name === 'appearance') {
            savedTheme.value = result.preferences.values.appearance.theme; applyAppearance(savedTheme.value);
            savedMotion.value = result.preferences.values.appearance.reduce_motion; applyMotionPreference(savedMotion.value);
        }
        toast.success('Settings saved.');
    } catch (exception) { error.value = message(exception, 'Settings could not be saved. Try again.'); }
}
const columnKeys = new WeakMap<Column, number>();
let nextColumnKey = 0;
function columnKey(column: Column) {
    if (!columnKeys.has(column)) columnKeys.set(column, ++nextColumnKey);
    return columnKeys.get(column);
}
function restoreDefaultColumns() { board.columns = props.defaultColumns.map(column => ({ ...column })); }
async function moveColumn(index: number, offset: number, event?: KeyboardEvent) {
    const target = index + offset;
    if (board.processing || target < 0 || target >= board.columns.length) return;
    const handle = event?.currentTarget as HTMLElement | undefined;
    const column = board.columns.splice(index, 1)[0];
    if (column) {
        board.columns.splice(target, 0, column);
        columnAnnouncement.value = `${column.name || 'New column'} moved to position ${target + 1} of ${board.columns.length}.`;
        await nextTick();
        handle?.focus();
    }
}
async function saveLogin() {
    error.value = '';
    try { const result = await login.put('/settings/general/login') as { enabled: boolean } | undefined; if (result) { login.enabled = result.enabled; toast.success('Launch at login confirmed by macOS.'); } }
    catch (exception) { error.value = message(exception, 'macOS could not confirm launch at login. Try again.'); }
}
async function probeTools() {
    runtimeProbe.paths = { ...tools.paths }; const submittedPaths = JSON.stringify(tools.paths); error.value = '';
    try { const result = await runtimeProbe.post('/settings/tools/probe') as { runtimes: typeof runtimeResults.value } | undefined; if (result && submittedPaths === JSON.stringify(tools.paths)) runtimeResults.value = result.runtimes; }
    catch (exception) { error.value = message(exception, 'The runtime paths could not be checked.'); }
}
async function pickTool(tool: string) {
    runtimePicker.tool = tool;
    try { const result = await runtimePicker.post('/settings/tools/pick') as { path: string | null } | undefined; if (result?.path) tools.paths[tool] = result.path; }
    catch { error.value = 'The file picker could not be opened.'; }
}
async function copyConfiguration() { try { await navigator.clipboard.writeText(mcpConfiguration.value); toast.success('Configuration copied.'); } catch { toast.error('Copy failed. Select the configuration below.'); } }
async function refreshUpdates() { if (!props.about.updatesAvailable || updates.processing) return; try { const result = await updates.get('/settings/updates') as typeof updateState.value | undefined; if (result) updateState.value = result; } catch { updateState.value = { status: 'error', message: 'The update service could not be reached. Retry when connected.' }; } }
async function updateApp(action: string) {
    if (action === 'install' && (Object.values(forms).some(form => form.isDirty) || !window.confirm('Restart Orbit and install the downloaded update? Save your work in other windows first.'))) return;
    updates.confirmed = action === 'install';
    try { const result = await updates.post(`/settings/updates/${action}`) as typeof updateState.value | undefined; if (result) updateState.value = result; }
    catch (exception) { error.value = message(exception, 'The update action failed. Try again.'); }
}
onMounted(() => { if (section.value === 'tools') void probeTools(); if (props.about.updatesAvailable) updateTimer = setInterval(() => { if (section.value === 'about') void refreshUpdates(); }, 2000); });
onBeforeUnmount(() => {
    for (const form of Object.values(forms)) form.cancel();
    login.cancel(); runtimeProbe.cancel(); runtimePicker.cancel(); updates.cancel(); clearInterval(updateTimer);
    if (appearance.theme !== savedTheme.value) void previewTheme(savedTheme.value);
    if (appearance.reduce_motion !== savedMotion.value) applyMotionPreference(savedMotion.value);
});
</script>

<template>
    <div class="grid w-full max-w-[1200px] gap-8 pb-8">
        <Head title="Settings" /><h1 class="text-[2rem] leading-tight font-semibold tracking-[-0.035em]">
            Settings
        </h1>
        <div class="grid gap-6 lg:grid-cols-[12rem_minmax(0,1fr)]">
            <Field class="lg:hidden">
                <FieldLabel for="settings-section">
                    Section
                </FieldLabel><ChoiceSelect
                    id="settings-section"
                    variant="filled"
                    :model-value="section"
                    :options="sections"
                    @update:model-value="selectSection" />
            </Field>
            <nav
                aria-label="Settings sections"
                class="hidden items-start gap-1 self-start lg:grid">
                <Button
                    v-for="item in sections"
                    :key="item.value"
                    :variant="section === item.value ? 'secondary' : 'ghost'"
                    class="justify-start font-normal"
                    :aria-current="section === item.value ? 'page' : undefined"
                    @click="selectSection(item.value)">
                    {{ item.label }}
                </Button>
            </nav>
            <div class="grid min-w-0 max-w-3xl self-start gap-6">
                <template v-if="section === 'connections'">
                    <ProviderConnections
                        :native="native"
                        :connections="connections" /><ScratchpadAiSettings
                            :native="native"
                            :ai="aiStatus"
                            :revision="revision"
                            @saved="savedAi" /><Card
                                v-if="mcp"
                                as="section"
                                aria-labelledby="mcp-title">
                                <CardHeader>
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <h2
                                            id="mcp-title"
                                            class="text-sm font-normal">
                                            Connect an AI client
                                        </h2><Button
                                            variant="outline"
                                            size="sm"
                                            @click="copyConfiguration">
                                            Copy configuration
                                        </Button>
                                    </div>
                                </CardHeader><CardContent class="grid gap-3">
                                    <p class="text-sm text-muted-foreground">
                                        Manage this workspace through local MCP. This is separate from scratchpad AI and cannot reveal secret values.
                                    </p><pre class="overflow-x-auto rounded-md bg-muted p-3 text-xs"><code>{{ mcpConfiguration }}</code></pre>
                                </CardContent>
                            </Card>
                </template>
                <WorkspaceBackups
                    v-else-if="section === 'backups'"
                    :native="native"
                    @saved="syncRevision" />
                <Card
                    v-else
                    as="section"
                    aria-labelledby="settings-section-title">
                    <CardHeader>
                        <h2
                            id="settings-section-title"
                            class="text-sm font-normal">
                            {{ sections.find(item => item.value === section)?.label }}
                        </h2>
                    </CardHeader>
                    <CardContent class="grid content-start gap-6">
                        <template v-if="section === 'general'">
                            <form
                                class="grid gap-6"
                                @submit.prevent="save('general')">
                                <Field>
                                    <FieldLabel for="startup-destination">
                                        Startup destination
                                    </FieldLabel><ChoiceSelect
                                        id="startup-destination"
                                        v-model="general.startup_destination"
                                        variant="filled"
                                        :options="[{ value: 'dashboard', label: 'Dashboard' }, { value: 'last_project', label: 'Last project' }]" /><FieldDescription>Resume the last project's overview. Unavailable or archived projects fall back to Dashboard.</FieldDescription><FieldError v-if="general.errors.startup_destination">
                                            {{ general.errors.startup_destination }}
                                        </FieldError>
                                </Field><Button
                                    class="justify-self-start"
                                    :disabled="general.processing">
                                    Save
                                </Button>
                            </form>
                            <form
                                class="grid gap-4 border-t pt-6"
                                @submit.prevent="saveLogin">
                                <Field>
                                    <FieldLabel for="launch-at-login">
                                        Launch at login
                                    </FieldLabel><ChoiceSelect
                                        id="launch-at-login"
                                        variant="filled"
                                        :model-value="String(login.enabled)"
                                        :options="[{ value: 'false', label: 'Off' }, { value: 'true', label: 'On' }]"
                                        :disabled="!native || launchAtLogin === null || login.processing"
                                        @update:model-value="login.enabled = $event === 'true'" /><FieldError v-if="login.errors.enabled">
                                            {{ login.errors.enabled }}
                                        </FieldError>
                                </Field><p
                                    v-if="!native"
                                    class="text-sm text-muted-foreground">
                                    Open the desktop app to manage launch at login.
                                </p><p
                                    v-if="nativeError"
                                    class="text-sm text-destructive">
                                    {{ nativeError }}
                                </p><Button
                                    class="justify-self-start"
                                    :disabled="!native || launchAtLogin === null || login.processing">
                                    Save launch at login
                                </Button>
                            </form>
                        </template>
                        <form
                            v-else-if="section === 'appearance'"
                            class="grid gap-6"
                            @submit.prevent="save('appearance')">
                            <Field>
                                <FieldLabel for="appearance-theme">
                                    Theme
                                </FieldLabel><ChoiceSelect
                                    id="appearance-theme"
                                    v-model="appearance.theme"
                                    variant="filled"
                                    :options="[{ value: 'light', label: 'Light' }, { value: 'dark', label: 'Dark' }, { value: 'system', label: 'System' }]" /><FieldDescription>Preview changes immediately. Save to keep them after restart.</FieldDescription><FieldError v-if="appearance.errors.theme">
                                        {{ appearance.errors.theme }}
                                    </FieldError>
                            </Field>
                            <Field>
                                <FieldLabel for="appearance-motion">
                                    Reduce motion
                                </FieldLabel><ChoiceSelect
                                    id="appearance-motion"
                                    v-model="appearance.reduce_motion"
                                    variant="filled"
                                    :options="[{ value: 'system', label: 'System' }, { value: 'on', label: 'On' }, { value: 'off', label: 'Off' }]" /><FieldDescription>Limit animations and transitions. System follows your device's motion preference.</FieldDescription><FieldError v-if="appearance.errors.reduce_motion">
                                        {{ appearance.errors.reduce_motion }}
                                    </FieldError>
                            </Field>
                            <Button
                                class="justify-self-start"
                                :disabled="appearance.processing">
                                Save
                            </Button>
                        </form>
                        <template v-else-if="section === 'security'">
                            <SecretsPinSettings
                                v-if="pinConfigured !== null"
                                :native="native"
                                :pin-set="pinConfigured"
                                @saved="savedPin" /><Alert
                                    v-else
                                    variant="destructive">
                                    <AlertDescription>PIN settings are unavailable. Reopen Settings to try again.</AlertDescription>
                                </Alert>
                            <form
                                class="grid gap-6 border-t pt-6"
                                @submit.prevent="save('security')">
                                <Field>
                                    <FieldLabel for="secret-lock-duration">
                                        Lock secrets after unlock
                                    </FieldLabel><ChoiceSelect
                                        id="secret-lock-duration"
                                        variant="filled"
                                        :model-value="String(security.lock_minutes)"
                                        :options="[{ value: '5', label: '5 minutes' }, { value: '15', label: '15 minutes' }, { value: '60', label: '1 hour' }, { value: '480', label: '8 hours' }]"
                                        @update:model-value="security.lock_minutes = Number($event)" /><FieldDescription>Elapsed time after unlocking. Changing this locks secrets immediately.</FieldDescription><FieldError v-if="security.errors.lock_minutes">
                                            {{ security.errors.lock_minutes }}
                                        </FieldError>
                                </Field><Field>
                                    <FieldLabel for="secret-clipboard-duration">
                                        Clear copied secrets after
                                    </FieldLabel><ChoiceSelect
                                        id="secret-clipboard-duration"
                                        variant="filled"
                                        :model-value="String(security.clipboard_seconds)"
                                        :options="[{ value: '30', label: '30 seconds' }, { value: '60', label: '60 seconds' }, { value: '0', label: 'Off' }]"
                                        @update:model-value="security.clipboard_seconds = Number($event)" /><FieldDescription>A later clipboard copy is preserved.</FieldDescription><FieldError v-if="security.errors.clipboard_seconds">
                                            {{ security.errors.clipboard_seconds }}
                                        </FieldError>
                                </Field><Button
                                    class="justify-self-start"
                                    :disabled="security.processing">
                                    Save
                                </Button>
                            </form>
                        </template>
                        <ProjectStatusSettings
                            v-else-if="section === 'project_statuses'"
                            :names="preferences.values.project_statuses.names"
                            :colors="preferences.values.project_statuses.colors"
                            :revision="revision"
                            :usage="statusUsage"
                            @saved="syncRevision" />
                        <form
                            v-else-if="section === 'project_defaults'"
                            class="grid gap-6"
                            @submit.prevent="save('project_defaults')">
                            <p class="text-sm leading-6 text-muted-foreground">
                                New projects use these columns. Existing projects keep their boards.
                            </p>
                            <p
                                id="default-column-order-help"
                                class="sr-only">
                                Drag a handle to reorder columns, or focus it and use the up and down arrow keys. Save to apply the new order.
                            </p>
                            <VueDraggable
                                v-model="board.columns"
                                tag="ol"
                                handle=".default-column-handle"
                                :animation="reducedMotion ? 0 : 150"
                                :disabled="board.processing"
                                ghost-class="opacity-50"
                                class="divide-y"
                                aria-label="Default board columns"
                                @update="columnAnnouncement = 'Column order updated. Save to apply.'">
                                <li
                                    v-for="(column, index) in board.columns"
                                    :key="columnKey(column)"
                                    class="flex items-start gap-3 py-4 sm:items-center">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon-sm"
                                        class="default-column-handle touch-none cursor-grab text-muted-foreground active:cursor-grabbing"
                                        :aria-label="`Reorder ${column.name || 'new column'}`"
                                        aria-describedby="default-column-order-help"
                                        :disabled="board.processing"
                                        @keydown.up.prevent="moveColumn(index, -1, $event)"
                                        @keydown.down.prevent="moveColumn(index, 1, $event)">
                                        <GripVerticalIcon aria-hidden="true" />
                                    </Button>
                                    <div class="grid min-w-0 flex-1 gap-3 sm:grid-cols-[minmax(0,1fr)_10rem]">
                                        <Field>
                                            <FieldLabel
                                                class="sr-only"
                                                :for="`default-column-${columnKey(column)}`">
                                                Column {{ index + 1 }}
                                            </FieldLabel><Input
                                                :id="`default-column-${columnKey(column)}`"
                                                v-model="column.name"
                                                variant="filled"
                                                maxlength="100"
                                                required
                                                :disabled="board.processing" />
                                        </Field>
                                        <Field>
                                            <FieldLabel
                                                class="sr-only"
                                                :for="`default-color-${columnKey(column)}`">
                                                Colour
                                            </FieldLabel><ChoiceSelect
                                                :id="`default-color-${columnKey(column)}`"
                                                variant="filled"
                                                :model-value="column.color ?? ''"
                                                :options="[{ value: '', label: 'Default', dotClass: boardColumnColors[boardColumnColor({ name: column.name })].dotClass }, ...columnColors.map(value => ({ value, ...boardColumnColors[value] }))]"
                                                :disabled="board.processing"
                                                :aria-label="`Colour for ${column.name || 'new column'}`"
                                                @update:model-value="column.color = $event || null" />
                                        </Field>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="destructive"
                                        size="icon-sm"
                                        :disabled="board.columns.length <= 1 || board.processing"
                                        :aria-label="`Remove ${column.name || 'column'}`"
                                        @click="board.columns.splice(index, 1)">
                                        <Trash2Icon aria-hidden="true" />
                                    </Button>
                                </li>
                            </VueDraggable>
                            <p
                                class="sr-only"
                                role="status">
                                {{ columnAnnouncement }}
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    :disabled="board.processing || board.columns.length >= 100"
                                    @click="board.columns.push({ name: '', color: null })">
                                    Add column
                                </Button><Button
                                    type="button"
                                    variant="outline"
                                    :disabled="board.processing"
                                    @click="restoreDefaultColumns">
                                    Restore default columns
                                </Button><Button :disabled="board.processing">
                                    Save
                                </Button>
                            </div>
                        </form>

                        <form
                            v-else-if="section === 'tools'"
                            class="grid gap-6"
                            @submit.prevent="save('tools')">
                            <p class="text-sm leading-6 text-muted-foreground">
                                Leave paths empty for automatic detection. Package-root overrides take priority. Changed defaults apply on the next runtime scan.
                            </p><Field
                                v-for="tool in toolNames"
                                :key="tool">
                                <FieldLabel :for="`tool-${tool}`">
                                    {{ tool }}
                                </FieldLabel><div class="flex gap-2">
                                    <Input
                                        :id="`tool-${tool}`"
                                        variant="filled"
                                        :model-value="tools.paths[tool] ?? ''"
                                        placeholder="Automatic detection"
                                        :disabled="tools.processing"
                                        @update:model-value="tools.paths[tool] = String($event) || null" /><Button
                                            v-if="native"
                                            type="button"
                                            variant="outline"
                                            size="input"
                                            :disabled="runtimePicker.processing"
                                            @click="pickTool(tool)">
                                            Browse
                                        </Button>
                                </div><p
                                    v-if="runtimeResults[tool]"
                                    class="break-all text-sm text-muted-foreground">
                                    {{ runtimeResults[tool]?.state }} · {{ runtimeResults[tool]?.version ?? 'No version' }} · {{ runtimeResults[tool]?.path ?? 'No executable found' }} ({{ runtimeResults[tool]?.source }})
                                </p>
                            </Field><div class="flex gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    :disabled="runtimeProbe.processing"
                                    @click="probeTools">
                                    {{ runtimeProbe.processing ? 'Checking…' : 'Check paths' }}
                                </Button><Button :disabled="tools.processing">
                                    Save
                                </Button>
                            </div><FieldError
                                v-for="(value, key) in runtimeProbe.errors"
                                :key="key">
                                {{ value }}
                            </FieldError>
                        </form>

                        <section
                            v-else-if="section === 'about'"
                            class="grid gap-5"
                            aria-labelledby="settings-section-title">
                            <p class="text-sm text-muted-foreground">
                                Orbit {{ about.version }}
                            </p><p
                                v-if="!about.updatesAvailable"
                                class="text-sm text-muted-foreground">
                                Update checks are unavailable in development or until a production release feed is configured.
                            </p><template v-else>
                                <p
                                    role="status"
                                    class="text-sm">
                                    {{ updateState.message ?? (updateState.status === 'current' ? 'Orbit is up to date.' : updateState.status === 'available' ? `Version ${updateState.version} is available.` : updateState.status === 'downloaded' ? 'Update downloaded. Ready to restart.' : updateState.status === 'downloading' ? `Downloading: ${Math.round(updateState.percent ?? 0)}%` : updateState.status === 'installing' ? 'Restarting to install the update…' : updateState.status === 'checking' ? 'Checking for updates…' : 'Check for an update.') }}
                                </p><div class="flex flex-wrap gap-2">
                                    <Button
                                        :disabled="updates.processing || ['checking', 'downloading', 'installing'].includes(updateState.status)"
                                        @click="updateApp('check')">
                                        {{ updateState.status === 'error' ? 'Retry update check' : 'Check for updates' }}
                                    </Button><Button
                                        v-if="updateState.status === 'available'"
                                        variant="outline"
                                        :disabled="updates.processing"
                                        @click="updateApp('download')">
                                        Download update
                                    </Button><Button
                                        v-if="updateState.status === 'downloaded'"
                                        variant="outline"
                                        :disabled="updates.processing || saving || Object.values(forms).some(form => form.isDirty)"
                                        @click="updateApp('install')">
                                        Restart and install
                                    </Button>
                                </div>
                            </template><Button
                                v-if="about.releaseNotes"
                                as-child
                                variant="outline"
                                class="justify-self-start">
                                <a
                                    :href="about.releaseNotes"
                                    target="_blank"
                                    rel="noopener noreferrer">Release notes</a>
                            </Button>
                        </section>
                    </CardContent>
                </Card>
                <FieldError
                    v-for="(value, key) in (forms[section as keyof typeof forms]?.errors ?? {})"
                    :key="key">
                    {{ value }}
                </FieldError>
                <Alert
                    v-if="error"
                    variant="destructive">
                    <AlertDescription>{{ error }}</AlertDescription>
                </Alert>
            </div>
        </div>
    </div>
</template>
