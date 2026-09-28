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
import { GripVerticalIcon, Trash2Icon } from '@lucide/vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Head, router, useHttp, usePage } from '@inertiajs/vue3';
import { boardColumnColors, boardColumnColor } from '@/lib/project';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
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
const activeSection = ref(selectedSection());
watch(() => props.section, () => { activeSection.value = selectedSection(); });
function selectSection(value: string) {
    if (!sections.some(item => item.value === value) || value === activeSection.value) return;
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
const draftForms = { ...forms, login };
type DraftSection = keyof typeof draftForms;
type SavedDraft = { data: Record<string, unknown>; baseline: Record<string, unknown> };
const clone = <T,>(value: T): T => JSON.parse(JSON.stringify(value));
const savedForms = Object.fromEntries(Object.entries(draftForms).map(([name, form]) => [name, clone(form.data())])) as unknown as Record<DraftSection, Record<string, unknown>>;
const recoveredSections = new Set<DraftSection>();
for (const [name, form] of Object.entries(draftForms)) {
    const recovered = router.restore(`settings:${name}:draft`) as SavedDraft | null;
    if (recovered) {
        savedForms[name as DraftSection] = clone(recovered.baseline);
        form.defaults(recovered.baseline as never);
        Object.assign(form, recovered.data);
        recoveredSections.add(name as DraftSection);
    }
    watch(() => form.data(), () => {
        router.remember(form.isDirty ? { data: clone(form.data()), baseline: clone(savedForms[name as DraftSection]) } : null, `settings:${name}:draft`);
    }, { deep: true });
}
const dirtySections = computed(() => Object.entries(draftForms).filter(([, form]) => form.isDirty).map(([name]) => name as DraftSection));
const sectionLabel = (name: string) => name === 'login' ? 'Launch at login' : sections.find(item => item.value === name)?.label ?? name;
const departureOpen = ref(false);
const departureSections = ref<DraftSection[]>([]);
let pendingNavigation: string | null = null;
let pendingRestore: ((approved: boolean) => void) | null = null;
const settingsPage = usePage();
function finishDeparture(approved: boolean) {
    departureOpen.value = false;
    pendingRestore?.(approved);
    pendingRestore = null;
    const destination = pendingNavigation;
    pendingNavigation = null;
    if (approved && destination) router.visit(destination);
}
function discardSection(name: DraftSection) {
    const form = draftForms[name];
    if (form.processing) return;
    if (name !== 'login' && props.preferences.revision >= Number(savedForms[name].revision ?? 0)) savedForms[name] = { revision: props.preferences.revision, ...clone(props.preferences.values[name]) };
    form.defaults(clone(savedForms[name]) as never);
    (form.reset as () => void)();
    form.defaults();
    (form.clearErrors as () => void)();
    recoveredSections.delete(name);
    router.remember(null, `settings:${name}:draft`);
    if (name === 'appearance') { savedTheme.value = appearance.theme; savedMotion.value = appearance.reduce_motion; void previewTheme(savedTheme.value); applyMotionPreference(savedMotion.value); }
}
function discardDeparture() {
    for (const name of departureSections.value) discardSection(name);
    finishDeparture(true);
}
async function saveDeparture() {
    for (const name of departureSections.value) {
        if (name === 'login') await saveLogin();
        else await save(name);
        if (draftForms[name].isDirty || draftForms[name].hasErrors) return;
    }
    finishDeparture(true);
}
function prepareRestore(): Promise<boolean> {
    if (board.processing) return Promise.resolve(false);
    if (!board.isDirty) return Promise.resolve(true);
    departureSections.value = ['project_defaults'];
    departureOpen.value = true;
    return new Promise(resolve => { pendingRestore = resolve; });
}
function restoredDefaults() {
    const baseline = { revision: props.preferences.revision, columns: clone(props.preferences.values.project_defaults.columns) };
    board.defaults(baseline); board.reset(); board.clearErrors();
    savedForms.project_defaults = clone(baseline);
    recoveredSections.delete('project_defaults');
    router.remember(null, 'settings:project_defaults:draft');
    syncRevision(props.preferences.revision);
}
const stopNavigationGuard = router.on('before', event => {
    if (event.detail.visit.method !== 'get' || (!dirtySections.value.length && !saving.value && !login.processing)) return;
    const destination = String(event.detail.visit.url);
    const target = new URL(destination, new URL(settingsPage.url ?? '/settings', 'https://orbit.local'));
    if (['/settings', '/connections', '/backups'].includes(target.pathname)) return;
    pendingNavigation = destination;
    departureSections.value = [...dirtySections.value];
    departureOpen.value = true;
    return false;
});
if (typeof window !== 'undefined') {
    const warnUnsaved = (event: BeforeUnloadEvent) => { if (dirtySections.value.length || saving.value || login.processing) { event.preventDefault(); event.returnValue = ''; } };
    window.addEventListener('beforeunload', warnUnsaved);
    onBeforeUnmount(() => window.removeEventListener('beforeunload', warnUnsaved));
}
function reconcileDrafts() {
    for (const name of recoveredSections) {
        if (name === 'login') continue;
        const { revision: _revision, ...baseline } = savedForms[name];
        if (JSON.stringify(baseline) !== JSON.stringify(props.preferences.values[name])) (draftForms[name].setError as (field: string, message: string) => void)('revision', 'Saved settings changed. Review the latest settings before saving this recovered draft.');
        else {
            draftForms[name].revision = props.preferences.revision;
            draftForms[name].defaults({ revision: props.preferences.revision });
            recoveredSections.delete(name);
        }
    }
}
function reviewDraft(name: DraftSection) {
    if (name === 'login') return;
    router.reload({ only: ['preferences'], onSuccess: () => {
        const baseline = { revision: props.preferences.revision, ...clone(props.preferences.values[name]) };
        savedForms[name] = baseline;
        draftForms[name].defaults(baseline as never);
        draftForms[name].revision = props.preferences.revision;
        (draftForms[name].clearErrors as (field: string) => void)('revision');
        recoveredSections.delete(name);
        error.value = 'Latest saved settings loaded. Your draft is kept; review it before saving.';
    } });
}
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
function syncRevision(value: number) {
    revision.value = value;
    for (const [name, form] of Object.entries(forms)) {
        if (recoveredSections.has(name as DraftSection)) continue;
        form.revision = value; form.defaults({ revision: value });
        savedForms[name as DraftSection].revision = value;
    }
}
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
watch(() => appearance.theme, mode => { if (activeSection.value === 'appearance') void previewTheme(mode); });
watch(() => appearance.reduce_motion, mode => { if (activeSection.value === 'appearance') applyMotionPreference(mode); });
watch(activeSection, (next, previous) => {
    error.value = '';
    if (previous === 'appearance') {
        void previewTheme(savedTheme.value);
        applyMotionPreference(savedMotion.value);
    }
    if (next === 'appearance') { void previewTheme(appearance.theme); applyMotionPreference(appearance.reduce_motion); }
    if (next === 'tools') void probeTools();
    if (next === 'about' && props.about.updatesAvailable) void refreshUpdates();
});
async function save(name: keyof typeof forms) {
    const form = forms[name];
    if (form.processing || form.errors.revision) return;
    error.value = '';
    const submitted = clone(form.data());
    try {
        const result = await form.put(`/settings/${name}`, { onSuccess: result => {
            const snapshot = result as { preferences: { revision: number; values: Values } };
            const unchanged = JSON.stringify(form.data()) === JSON.stringify(submitted);
            const baseline = { revision: snapshot.preferences.revision, ...clone(snapshot.preferences.values[name]) };
            recoveredSections.delete(name);
            savedForms[name] = clone(baseline);
            syncRevision(snapshot.preferences.revision);
            form.defaults(baseline as never);
            if (unchanged) (form.reset as () => void)();
            router.remember(null, `settings:${name}:draft`);
        } }) as { preferences: { revision: number; values: Values } } | undefined;
        if (!result) return;
        if (name === 'appearance') {
            savedTheme.value = result.preferences.values.appearance.theme;
            savedMotion.value = result.preferences.values.appearance.reduce_motion;
            void previewTheme(activeSection.value === 'appearance' ? appearance.theme : savedTheme.value);
            applyMotionPreference(activeSection.value === 'appearance' ? appearance.reduce_motion : savedMotion.value);
        }
        toast.success('Settings saved.');
    } catch (exception) {
        error.value = message(exception, 'Settings could not be saved. Try again.');
        if ((exception as { response?: { status?: number } })?.response?.status === 409) {
            (form.setError as (field: string, message: string) => void)('revision', error.value);
            recoveredSections.add(name);
        }
    }
}
const columnKeys = new WeakMap<Column, number>();
let nextColumnKey = 0;
function columnKey(column: Column) {
    if (!columnKeys.has(column)) columnKeys.set(column, ++nextColumnKey);
    return columnKeys.get(column);
}
function restoreDefaultColumns() {
    if (board.processing) return;
    board.columns = props.defaultColumns.map(column => ({ ...column }));
}
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
    if (login.processing) return;
    error.value = '';
    try { const result = await login.put('/settings/general/login') as { enabled: boolean } | undefined; if (result) { login.enabled = result.enabled; login.defaults({ enabled: result.enabled }); savedForms.login = { enabled: result.enabled }; router.remember(null, 'settings:login:draft'); toast.success('Launch at login confirmed.'); } }
    catch (exception) { error.value = message(exception, 'The operating system could not confirm launch at login. Try again.'); }
}
async function probeTools() {
    if (tools.processing || runtimeProbe.processing) return;
    runtimeProbe.paths = { ...tools.paths }; const submittedPaths = JSON.stringify(tools.paths); error.value = '';
    try { const result = await runtimeProbe.post('/settings/tools/probe') as { runtimes: typeof runtimeResults.value } | undefined; if (result && submittedPaths === JSON.stringify(tools.paths)) runtimeResults.value = result.runtimes; }
    catch (exception) { error.value = message(exception, 'The runtime paths could not be checked.'); }
}
async function pickTool(tool: string) {
    if (tools.processing || runtimePicker.processing) return;
    runtimePicker.tool = tool;
    try { const result = await runtimePicker.post('/settings/tools/pick') as { path: string | null } | undefined; if (result?.path) tools.paths[tool] = result.path; }
    catch { error.value = 'The file picker could not be opened.'; }
}
async function copyConfiguration() { try { await navigator.clipboard.writeText(mcpConfiguration.value); toast.success('Configuration copied.'); } catch { toast.error('Copy failed. Select the configuration below.'); } }
async function refreshUpdates() {
    if (!props.about.updatesAvailable || updates.processing) return;
    try { const result = await updates.get('/settings/updates') as typeof updateState.value | undefined; if (result) updateState.value = result; } catch { updateState.value = { status: 'error', message: 'The update service could not be reached. Retry when connected.' }; } }
async function updateApp(action: string) {
    if (action === 'install' && (dirtySections.value.length || saving.value || login.processing || !window.confirm('Restart Orbit and install the downloaded update? Save your work in other windows first.'))) return;
    updates.confirmed = action === 'install';
    try { const result = await updates.post(`/settings/updates/${action}`) as typeof updateState.value | undefined; if (result) updateState.value = result; }
    catch (exception) { error.value = message(exception, 'The update action failed. Try again.'); }
}
onMounted(() => {
    if (recoveredSections.size) router.reload({ only: ['preferences'], onSuccess: reconcileDrafts });
    if (activeSection.value === 'tools') void probeTools();
    if (props.about.updatesAvailable) updateTimer = setInterval(() => { if (activeSection.value === 'about') void refreshUpdates(); }, 2000);
});
onBeforeUnmount(() => {
    stopNavigationGuard(); pendingRestore?.(false);
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
                    :model-value="activeSection"
                    :options="sections"
                    @update:model-value="selectSection" />
            </Field>
            <nav
                aria-label="Settings sections"
                class="hidden items-start gap-1 self-start lg:grid">
                <Button
                    v-for="item in sections"
                    :key="item.value"
                    :variant="activeSection === item.value ? 'secondary' : 'ghost'"
                    class="justify-start font-normal"
                    :aria-current="activeSection === item.value ? 'page' : undefined"
                    @click="selectSection(item.value)">
                    {{ item.label }}<span
                        v-if="dirtySections.includes(item.value as DraftSection) || (item.value === 'general' && login.isDirty)"
                        class="ml-auto text-xs text-muted-foreground">Unsaved</span>
                </Button>
            </nav>
            <div class="grid min-w-0 max-w-3xl self-start gap-6">
                <template v-if="activeSection === 'connections'">
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
                                    </p><p class="text-sm text-muted-foreground">
                                        In a compatible AI client's local MCP settings, add the shown command, arguments and environment, then reload its server connection.
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        For <a
                                            href="https://code.claude.com/docs/en/mcp"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="underline">Claude Code</a>, merge this mcpServers block into your project's .mcp.json. Restart Claude Code and approve the server when prompted. Run <code>claude mcp get orbit</code>, then ask “List my Orbit projects” to check the read-only workspace tool. If connection fails, check that the command and paths exist.
                                    </p>
                                    <pre class="overflow-x-auto rounded-md bg-muted p-3 text-xs"><code>{{ mcpConfiguration }}</code></pre>
                                </CardContent>
                            </Card>
                </template>
                <WorkspaceBackups
                    v-else-if="activeSection === 'backups'"
                    :native="native"
                    :pin-set="pinConfigured"
                    :preferences-revision="revision"
                    :prepare-restore="prepareRestore"
                    @restored="restoredDefaults"
                    @saved="syncRevision" />
                <Card
                    v-else
                    as="section"
                    aria-labelledby="settings-section-title">
                    <CardHeader>
                        <h2
                            id="settings-section-title"
                            class="text-sm font-normal">
                            {{ sections.find(item => item.value === activeSection)?.label }}
                        </h2>
                    </CardHeader>
                    <CardContent class="grid content-start gap-6">
                        <div
                            v-if="forms[activeSection as keyof typeof forms]?.isDirty"
                            class="flex items-center gap-2">
                            <span
                                role="status"
                                class="text-sm text-muted-foreground">Unsaved changes</span><Button
                                    variant="outline"
                                    size="sm"
                                    :disabled="forms[activeSection as keyof typeof forms]?.processing"
                                    @click="discardSection(activeSection as DraftSection)">
                                    Discard changes
                                </Button>
                        </div>
                        <template v-if="activeSection === 'general'">
                            <form
                                class="grid gap-6"
                                @submit.prevent="save('general')">
                                <Field>
                                    <FieldLabel for="startup-destination">
                                        Startup destination
                                    </FieldLabel><ChoiceSelect
                                        id="startup-destination"
                                        v-model="general.startup_destination"
                                        :disabled="general.processing"
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
                                <Button
                                    v-if="login.isDirty"
                                    type="button"
                                    variant="outline"
                                    :disabled="login.processing"
                                    @click="discardSection('login')">
                                    Discard launch at login changes
                                </Button>
                            </form>
                        </template>
                        <form
                            v-else-if="activeSection === 'appearance'"
                            class="grid gap-6"
                            @submit.prevent="save('appearance')">
                            <Field>
                                <FieldLabel for="appearance-theme">
                                    Theme
                                </FieldLabel><ChoiceSelect
                                    id="appearance-theme"
                                    v-model="appearance.theme"
                                    :disabled="appearance.processing"
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
                                    :disabled="appearance.processing"
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
                        <template v-else-if="activeSection === 'security'">
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
                                        :disabled="security.processing"
                                        variant="filled"
                                        :model-value="String(security.lock_minutes)"
                                        :options="[{ value: '5', label: '5 minutes' }, { value: '15', label: '15 minutes' }, { value: '60', label: '1 hour' }, { value: '480', label: '8 hours' }]"
                                        @update:model-value="security.lock_minutes = Number($event)" /><FieldDescription>Elapsed time after unlocking. Saving a new duration locks secrets immediately.</FieldDescription><FieldError v-if="security.errors.lock_minutes">
                                            {{ security.errors.lock_minutes }}
                                        </FieldError>
                                </Field><Field>
                                    <FieldLabel for="secret-clipboard-duration">
                                        Clear copied secrets after
                                    </FieldLabel><ChoiceSelect
                                        id="secret-clipboard-duration"
                                        :disabled="security.processing"
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
                            v-else-if="activeSection === 'project_statuses'"
                            :names="preferences.values.project_statuses.names"
                            :colors="preferences.values.project_statuses.colors"
                            :revision="revision"
                            :usage="statusUsage"
                            @saved="syncRevision" />
                        <form
                            v-else-if="activeSection === 'project_defaults'"
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
                                                :disabled="board.processing"
                                                :aria-invalid="!!board.errors[`columns.${index}.name`]"
                                                :aria-describedby="board.errors[`columns.${index}.name`] ? `column-name-error-${columnKey(column)}` : undefined" /><FieldError
                                                    v-if="board.errors[`columns.${index}.name`]"
                                                    :id="`column-name-error-${columnKey(column)}`">
                                                    {{ board.errors[`columns.${index}.name`] }}
                                                </FieldError>
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
                                                :aria-invalid="!!board.errors[`columns.${index}.color`]"
                                                :aria-describedby="board.errors[`columns.${index}.color`] ? `column-color-error-${columnKey(column)}` : undefined"
                                                @update:model-value="column.color = $event || null" /><FieldError
                                                    v-if="board.errors[`columns.${index}.color`]"
                                                    :id="`column-color-error-${columnKey(column)}`">
                                                    {{ board.errors[`columns.${index}.color`] }}
                                                </FieldError>
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
                            <FieldError v-if="board.errors.columns">
                                {{ board.errors.columns }}
                            </FieldError>
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
                            v-else-if="activeSection === 'tools'"
                            class="grid gap-6"
                            @submit.prevent="save('tools')">
                            <p class="text-sm leading-6 text-muted-foreground">
                                Leave paths empty for automatic detection. Per-project package location overrides take priority over these workspace defaults. Changed defaults apply on the next runtime scan.
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
                                        :aria-invalid="!!tools.errors[`paths.${tool}`] || !!runtimeProbe.errors[`paths.${tool}`]"
                                        :aria-describedby="tools.errors[`paths.${tool}`] || runtimeProbe.errors[`paths.${tool}`] ? `tool-error-${tool}` : undefined"
                                        @update:model-value="tools.paths[tool] = String($event) || null" /><Button
                                            v-if="native"
                                            type="button"
                                            variant="outline"
                                            size="input"
                                            :disabled="tools.processing || runtimePicker.processing"
                                            @click="pickTool(tool)">
                                            Browse
                                        </Button>
                                </div><FieldError
                                    v-if="tools.errors[`paths.${tool}`] || runtimeProbe.errors[`paths.${tool}`]"
                                    :id="`tool-error-${tool}`">
                                    {{ tools.errors[`paths.${tool}`] || runtimeProbe.errors[`paths.${tool}`] }}
                                </FieldError><p
                                    v-if="runtimeResults[tool]"
                                    class="break-all text-sm text-muted-foreground">
                                    <span class="block">Status: {{ runtimeResults[tool]?.state }}</span>
                                    <span class="block">Version: {{ runtimeResults[tool]?.version ?? 'Unavailable' }}</span>
                                    <span class="block break-all">Executable path: {{ runtimeResults[tool]?.path ?? 'No executable found' }}</span>
                                    <span class="block">Source: {{ ({ automatic: 'Automatic detection', global: 'Workspace default', root: 'Project override' })[runtimeResults[tool]?.source as 'automatic' | 'global' | 'root'] ?? runtimeResults[tool]?.source }}</span>
                                    <span
                                        v-if="runtimeResults[tool]?.state !== 'Current'"
                                        class="block mt-1">For {{ tool }}, browse to an installed executable or package-manager entry file, then Check paths. Shell wrappers are unsupported.</span>
                                </p>
                            </Field><div class="flex gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    :disabled="tools.processing || runtimeProbe.processing"
                                    @click="probeTools">
                                    {{ runtimeProbe.processing ? 'Checking…' : 'Check paths' }}
                                </Button><Button :disabled="tools.processing">
                                    Save
                                </Button>
                            </div><FieldError v-if="tools.errors.paths || runtimeProbe.errors.paths">
                                {{ tools.errors.paths || runtimeProbe.errors.paths }}
                            </FieldError>
                        </form>

                        <section
                            v-else-if="activeSection === 'about'"
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
                                </p>
                                <p
                                    v-if="updateState.status === 'downloaded' && (saving || login.processing)"
                                    role="status"
                                    class="text-sm text-muted-foreground">
                                    Wait for settings to finish saving before restarting.
                                </p>
                                <div
                                    v-if="updateState.status === 'downloaded' && dirtySections.length"
                                    class="text-sm text-muted-foreground">
                                    Save or discard changes before restarting:
                                    <Button
                                        v-for="name in dirtySections"
                                        :key="name"
                                        variant="link"
                                        size="sm"
                                        @click="selectSection(name === 'login' ? 'general' : name)">
                                        {{ sectionLabel(name) }}
                                    </Button>
                                </div>
                                <div class="flex flex-wrap gap-2">
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
                                        :disabled="updates.processing || saving || login.processing || !!dirtySections.length"
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
                <FieldError v-if="forms[activeSection as keyof typeof forms]?.errors.revision">
                    {{ forms[activeSection as keyof typeof forms]?.errors.revision }}
                </FieldError>
                <Button
                    v-if="forms[activeSection as keyof typeof forms]?.errors.revision"
                    variant="outline"
                    @click="reviewDraft(activeSection as DraftSection)">
                    Review latest saved settings and keep draft
                </Button>
                <Alert
                    v-if="error"
                    variant="destructive">
                    <AlertDescription>{{ error }}</AlertDescription>
                </Alert>
            </div>
        </div>
        <Dialog
            :open="departureOpen"
            @update:open="open => { if (!open) finishDeparture(false); }">
            <DialogContent>
                <DialogHeader><DialogTitle>Unsaved settings</DialogTitle><DialogDescription>Save or discard changes in {{ departureSections.map(sectionLabel).join(', ') }} before continuing.</DialogDescription></DialogHeader>
                <DialogFooter>
                    <Button
                        variant="outline"
                        :disabled="saving || login.processing"
                        @click="finishDeparture(false)">
                        Keep editing
                    </Button>
                    <Button
                        variant="destructive"
                        :disabled="saving || login.processing"
                        @click="discardDeparture">
                        Discard changes
                    </Button>
                    <Button
                        :disabled="saving || login.processing"
                        @click="saveDeparture">
                        Save changes
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
