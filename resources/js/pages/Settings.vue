<script setup lang="ts">
import ChoiceSelect from '@/components/ChoiceSelect.vue';
import SecretsPinSettings from '@/components/SecretsPinSettings.vue';
import ProviderConnections from '@/components/ProviderConnections.vue';
import ScratchpadAiSettings from '@/components/ScratchpadAiSettings.vue';
import ProjectStatusSettings from '@/components/ProjectStatusSettings.vue';
import { toast } from 'vue-sonner';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { VueDraggable } from 'vue-draggable-plus';
import type { ProviderConnection } from '@/types';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Head, router, useHttp, usePage } from '@inertiajs/vue3';
import { boardColumnColors, boardColumnColor } from '@/lib/project';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';
import { ChevronDownIcon, CircleCheckIcon, GripVerticalIcon, Trash2Icon } from '@lucide/vue';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { applyAppearance, applyMotionPreference, applyPointerCursors, reducedMotion, type Appearance, type MotionPreference } from '@/lib/appearance';

type Column = { name: string; color: string | null };
type Values = { general: { startup_destination: string }; appearance: { theme: Appearance; reduce_motion: MotionPreference; pointer_cursors: boolean }; security: { lock_minutes: number; clipboard_seconds: number }; project_defaults: { columns: Column[] }; project_statuses: { names: string[]; colors: Record<string, string> }; tools: { paths: Record<string, string | null> } };
const props = defineProps<{ native: boolean; pinSet: boolean | null; preferences: { revision: number; values: Values }; section: string; statusUsage: { name: string; count: number }[]; columnColors: string[]; defaultColumns: Column[]; connections: ProviderConnection[]; ai: { configured: boolean; provider: string | null; model: string | null; providers: string[]; default_models: Record<string, string> }; launchAtLogin: boolean | null; nativeError: string | null; about: { version: string; updatesAvailable: boolean; releaseNotes: string | null }; mcp: { command: string; args: string[]; env: Record<string, string> } | null }>();
const sections = [
    { value: 'general', label: 'General' }, { value: 'appearance', label: 'Appearance' }, { value: 'security', label: 'Security' },
    { value: 'project_defaults', label: 'Task columns' }, { value: 'project_statuses', label: 'Project statuses' }, { value: 'connections', label: 'Connections' },
    { value: 'tools', label: 'Tools & runtimes' }, { value: 'about', label: 'About' },
];
const sectionGroups = [
    { label: 'Preferences', sections: sections.slice(0, 3) },
    { label: 'Projects', sections: sections.slice(3, 5) },
    { label: 'Workspace', sections: sections.slice(5) },
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
const savedPointerCursors = ref(props.preferences.values.appearance.pointer_cursors);
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
const preferenceStatus = computed(() => {
    const form = activeSection.value === 'appearance' ? appearance : general;
    const loginVisible = activeSection.value === 'general';
    if (form.processing || (loginVisible && login.processing)) return 'Saving…';
    if (form.isDirty || (loginVisible && login.isDirty)) return 'Changes haven’t been saved';
    if (form.recentlySuccessful || (loginVisible && login.recentlySuccessful)) return 'Saved';
    return 'Changes save automatically';
});
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
const settingsPage = usePage();
function finishDeparture(approved: boolean) {
    departureOpen.value = false;
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
    if (name === 'appearance') { savedTheme.value = appearance.theme; savedMotion.value = appearance.reduce_motion; savedPointerCursors.value = appearance.pointer_cursors; void previewTheme(savedTheme.value); applyMotionPreference(savedMotion.value); applyPointerCursors(savedPointerCursors.value); }
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
const stopNavigationGuard = router.on('before', event => {
    if (event.detail.visit.method !== 'get' || (!dirtySections.value.length && !saving.value && !login.processing)) return;
    const destination = String(event.detail.visit.url);
    const target = new URL(destination, new URL(settingsPage.url ?? '/settings', 'https://orbit.local'));
    if (['/settings', '/connections'].includes(target.pathname)) return;
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
const toolLabels: Record<string, string> = { php: 'PHP', node: 'Node.js', composer: 'Composer', npm: 'npm', pnpm: 'pnpm', yarn: 'Yarn' };
const expandedTools = ref<Record<string, boolean>>({});
watch(() => [tools.errors, runtimeProbe.errors], () => {
    for (const tool of toolNames) {
        if (tools.errors[`paths.${tool}`] || runtimeProbe.errors[`paths.${tool}`]) expandedTools.value[tool] = true;
    }
}, { deep: true });
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
watch(() => appearance.pointer_cursors, enabled => { if (activeSection.value === 'appearance') applyPointerCursors(enabled); });
watch(activeSection, (next, previous) => {
    error.value = '';
    if (previous === 'appearance') {
        void previewTheme(savedTheme.value);
        applyMotionPreference(savedMotion.value);
        applyPointerCursors(savedPointerCursors.value);
    }
    if (next === 'appearance') { void previewTheme(appearance.theme); applyMotionPreference(appearance.reduce_motion); applyPointerCursors(appearance.pointer_cursors); }
    if (next === 'tools') void probeTools();
    if (next === 'about' && props.about.updatesAvailable) void refreshUpdates();
});
async function savePreference(name: 'general' | 'appearance', values: Partial<Values['general']> | Partial<Values['appearance']>) {
    if (saving.value || login.processing || forms[name].errors.revision) return;
    if (Object.entries(values).every(([field, value]) => Reflect.get(forms[name], field) === value)) return;
    Object.assign(forms[name], values);
    await save(name, true);
}
async function save(name: keyof typeof forms, quiet = false) {
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
            savedPointerCursors.value = result.preferences.values.appearance.pointer_cursors;
            void previewTheme(activeSection.value === 'appearance' ? appearance.theme : savedTheme.value);
            applyMotionPreference(activeSection.value === 'appearance' ? appearance.reduce_motion : savedMotion.value);
            applyPointerCursors(activeSection.value === 'appearance' ? appearance.pointer_cursors : savedPointerCursors.value);
        }
        if (!quiet) toast.success('Settings saved.');
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
async function saveLogin(enabled = login.enabled, quiet = false) {
    if (login.processing || saving.value || !props.native || props.launchAtLogin === null) return;
    login.enabled = enabled;
    error.value = '';
    try { const result = await login.put('/settings/general/login') as { enabled: boolean } | undefined; if (result) { login.enabled = result.enabled; login.defaults({ enabled: result.enabled }); savedForms.login = { enabled: result.enabled }; router.remember(null, 'settings:login:draft'); if (!quiet) toast.success('Launch at login confirmed.'); } }
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
    stopNavigationGuard();
    for (const form of Object.values(forms)) form.cancel();
    login.cancel(); runtimeProbe.cancel(); runtimePicker.cancel(); updates.cancel(); clearInterval(updateTimer);
    if (appearance.theme !== savedTheme.value) void previewTheme(savedTheme.value);
    if (appearance.reduce_motion !== savedMotion.value) applyMotionPreference(savedMotion.value);
    if (appearance.pointer_cursors !== savedPointerCursors.value) applyPointerCursors(savedPointerCursors.value);
});
</script>

<template>
    <div class="mx-auto grid w-full max-w-5xl gap-8 py-4">
        <Head title="Settings" /><h1 class="text-[2rem] leading-tight font-semibold tracking-[-0.035em]">
            Settings
        </h1>
        <div class="grid gap-8 lg:grid-cols-[11rem_minmax(0,1fr)] lg:gap-12">
            <Field class="lg:hidden">
                <FieldLabel for="settings-section">
                    Section
                </FieldLabel><ChoiceSelect
                    id="settings-section"
                    class="w-full"
                    variant="filled"
                    :model-value="activeSection"
                    :options="sections"
                    @update:model-value="selectSection" />
            </Field>
            <nav
                aria-label="Settings sections"
                class="hidden self-start lg:sticky lg:top-6 lg:grid lg:gap-6">
                <div
                    v-for="group in sectionGroups"
                    :key="group.label"
                    class="grid gap-1">
                    <h2 class="px-4 pb-1 text-sm font-medium text-muted-foreground">
                        {{ group.label }}
                    </h2>
                    <Button
                        v-for="item in group.sections"
                        :key="item.value"
                        :variant="activeSection === item.value ? 'secondary' : 'ghost'"
                        class="justify-start font-normal"
                        :aria-current="activeSection === item.value ? 'page' : undefined"
                        @click="selectSection(item.value)">
                        {{ item.label }}
                        <span
                            v-if="dirtySections.includes(item.value as DraftSection) || (item.value === 'general' && login.isDirty)"
                            class="ml-auto size-1.5 shrink-0 rounded-full bg-muted-foreground"
                            title="Unsaved changes"><span class="sr-only">Unsaved changes</span></span>
                    </Button>
                </div>
            </nav>
            <div class="grid min-w-0 self-start gap-8">
                <template v-if="activeSection === 'connections'">
                    <ProviderConnections
                        :native="native"
                        :connections="connections" /><ScratchpadAiSettings
                            :native="native"
                            :ai="aiStatus"
                            :revision="revision"
                            @saved="savedAi" /><Collapsible
                                v-if="mcp"
                                class="overflow-hidden rounded-xl border">
                                <CollapsibleTrigger
                                    type="button"
                                    class="group/client flex w-full items-center justify-between gap-3 px-4 py-3 text-left text-sm font-medium hover:bg-muted/40 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-ring">
                                    <span class="min-w-0 space-y-1">
                                        <span class="block">Connect an AI client</span>
                                        <span class="block text-xs font-normal text-muted-foreground">Manage your workspace through local MCP.</span>
                                    </span>
                                    <ChevronDownIcon
                                        class="size-4 shrink-0 text-muted-foreground transition-[color,transform] group-hover/client:text-foreground group-data-[state=open]/client:rotate-180"
                                        aria-hidden="true" />
                                </CollapsibleTrigger>
                                <CollapsibleContent class="grid gap-4 border-t p-4">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        class="justify-self-start"
                                        @click="copyConfiguration">
                                        Copy configuration
                                    </Button>

                                    <p class="text-sm text-muted-foreground">
                                        Add this configuration to your AI client's local MCP settings, then reload its server connection. Clients can manage workspace content but cannot reveal secret values.
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        For <a
                                            href="https://code.claude.com/docs/en/mcp"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="underline">Claude Code</a>, merge this mcpServers block into your project's .mcp.json. Restart Claude Code and approve the server when prompted. Run <code>claude mcp get orbit</code>, then ask “List my Orbit projects” to check the read-only workspace tool. If connection fails, check that the command and paths exist.
                                    </p>
                                    <pre class="overflow-x-auto rounded-md bg-muted p-3 text-xs"><code>{{ mcpConfiguration }}</code></pre>
                                </CollapsibleContent>
                            </Collapsible>
                </template>
                <section
                    v-else
                    class="grid gap-6"
                    aria-labelledby="settings-section-title">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2
                            id="settings-section-title"
                            class="text-lg font-semibold">
                            {{ sections.find(item => item.value === activeSection)?.label }}
                        </h2>
                        <span
                            v-if="['general', 'appearance'].includes(activeSection)"
                            role="status"
                            class="text-sm text-muted-foreground">{{ preferenceStatus }}</span>
                    </div>
                    <div class="grid content-start gap-6">
                        <div
                            v-if="forms[activeSection as keyof typeof forms]?.isDirty && !forms[activeSection as keyof typeof forms]?.processing"
                            class="flex flex-wrap items-center gap-2">
                            <Button
                                v-if="['general', 'appearance'].includes(activeSection)"
                                variant="outline"
                                size="sm"
                                :disabled="saving || login.processing || !!forms[activeSection as keyof typeof forms]?.errors.revision"
                                @click="save(activeSection as 'general' | 'appearance', true)">
                                Retry save
                            </Button>
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
                        <div
                            v-if="activeSection === 'general'"
                            class="divide-y overflow-hidden rounded-xl border">
                            <Field class="px-4 py-4 sm:grid sm:grid-cols-[minmax(0,1fr)_12rem] sm:items-center sm:gap-6">
                                <div class="grid gap-1.5">
                                    <FieldLabel for="startup-destination">
                                        On startup
                                    </FieldLabel>
                                    <FieldDescription id="startup-destination-help">
                                        Choose where Orbit opens.
                                    </FieldDescription>
                                </div>
                                <div class="grid gap-2">
                                    <ChoiceSelect
                                        id="startup-destination"
                                        class="w-full"
                                        variant="filled"
                                        :model-value="general.startup_destination"
                                        :disabled="saving || login.processing || !!general.errors.revision"
                                        aria-describedby="startup-destination-help"
                                        :options="[{ value: 'dashboard', label: 'Dashboard' }, { value: 'last_project', label: 'Last project' }]"
                                        @update:model-value="savePreference('general', { startup_destination: $event })" />
                                    <FieldError v-if="general.errors.startup_destination">
                                        {{ general.errors.startup_destination }}
                                    </FieldError>
                                </div>
                            </Field>
                            <Field class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-6 px-4 py-4 sm:grid-cols-[minmax(0,1fr)_12rem]">
                                <div class="grid gap-1.5">
                                    <FieldLabel for="launch-at-login">
                                        Launch at login
                                    </FieldLabel>
                                    <FieldDescription id="launch-at-login-help">
                                        {{ native ? 'Open Orbit when you sign in.' : 'Available in the desktop app.' }}
                                    </FieldDescription>
                                    <FieldError v-if="nativeError">
                                        {{ nativeError }}
                                    </FieldError>
                                </div>
                                <div class="grid justify-items-end gap-2">
                                    <input
                                        id="launch-at-login"
                                        type="checkbox"
                                        role="switch"
                                        :checked="login.enabled"
                                        :aria-checked="login.enabled"
                                        aria-describedby="launch-at-login-help"
                                        :disabled="!native || launchAtLogin === null || saving || login.processing"
                                        class="h-5 w-9 shrink-0 appearance-none rounded-full bg-input p-0.5 outline-none transition-colors checked:bg-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:opacity-50 after:block after:size-4 after:rounded-full after:bg-background dark:after:bg-foreground dark:checked:after:bg-primary-foreground after:shadow-sm after:transition-transform checked:after:translate-x-4"
                                        @change="saveLogin(($event.target as HTMLInputElement).checked, true)">
                                    <FieldError v-if="login.errors.enabled">
                                        {{ login.errors.enabled }}
                                    </FieldError>
                                    <div
                                        v-if="login.isDirty && !login.processing"
                                        class="flex flex-wrap gap-2">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            :disabled="saving"
                                            @click="saveLogin(login.enabled, true)">
                                            Retry
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            @click="discardSection('login')">
                                            Discard
                                        </Button>
                                    </div>
                                </div>
                            </Field>
                        </div>
                        <div
                            v-else-if="activeSection === 'appearance'"
                            class="divide-y overflow-hidden rounded-xl border">
                            <Field class="px-4 py-4 sm:grid sm:grid-cols-[minmax(0,1fr)_12rem] sm:items-center sm:gap-6">
                                <div class="grid gap-1.5">
                                    <FieldLabel for="appearance-theme">
                                        Theme
                                    </FieldLabel>
                                    <FieldDescription id="appearance-theme-help">
                                        Light, dark, or your system theme.
                                    </FieldDescription>
                                </div>
                                <div class="grid gap-2">
                                    <ChoiceSelect
                                        id="appearance-theme"
                                        class="w-full"
                                        variant="filled"
                                        :model-value="appearance.theme"
                                        :disabled="saving || login.processing || !!appearance.errors.revision"
                                        aria-describedby="appearance-theme-help"
                                        :options="[{ value: 'light', label: 'Light' }, { value: 'dark', label: 'Dark' }, { value: 'system', label: 'System' }]"
                                        @update:model-value="savePreference('appearance', { theme: $event as Appearance })" />
                                    <FieldError v-if="appearance.errors.theme">
                                        {{ appearance.errors.theme }}
                                    </FieldError>
                                </div>
                            </Field>
                            <Field class="px-4 py-4 sm:grid sm:grid-cols-[minmax(0,1fr)_12rem] sm:items-center sm:gap-6">
                                <div class="grid gap-1.5">
                                    <FieldLabel for="appearance-motion">
                                        Reduce motion
                                    </FieldLabel>
                                    <FieldDescription id="appearance-motion-help">
                                        Limit animations or follow your system.
                                    </FieldDescription>
                                </div>
                                <div class="grid gap-2">
                                    <ChoiceSelect
                                        id="appearance-motion"
                                        class="w-full"
                                        variant="filled"
                                        :model-value="appearance.reduce_motion"
                                        :disabled="saving || login.processing || !!appearance.errors.revision"
                                        aria-describedby="appearance-motion-help"
                                        :options="[{ value: 'system', label: 'System' }, { value: 'on', label: 'On' }, { value: 'off', label: 'Off' }]"
                                        @update:model-value="savePreference('appearance', { reduce_motion: $event as MotionPreference })" />
                                    <FieldError v-if="appearance.errors.reduce_motion">
                                        {{ appearance.errors.reduce_motion }}
                                    </FieldError>
                                </div>
                            </Field>
                            <Field class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-6 px-4 py-4 sm:grid-cols-[minmax(0,1fr)_12rem]">
                                <div class="grid gap-1.5">
                                    <FieldLabel for="appearance-pointer-cursors">
                                        Pointer cursors
                                    </FieldLabel>
                                    <FieldDescription id="appearance-pointer-cursors-description">
                                        Show a hand over interactive controls.
                                    </FieldDescription>
                                </div>
                                <div class="grid justify-items-end gap-2">
                                    <input
                                        id="appearance-pointer-cursors"
                                        type="checkbox"
                                        role="switch"
                                        :checked="appearance.pointer_cursors"
                                        :aria-checked="appearance.pointer_cursors"
                                        aria-describedby="appearance-pointer-cursors-description"
                                        :disabled="saving || login.processing || !!appearance.errors.revision"
                                        class="h-5 w-9 shrink-0 appearance-none rounded-full bg-input p-0.5 outline-none transition-colors checked:bg-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:opacity-50 after:block after:size-4 after:rounded-full after:bg-background dark:after:bg-foreground dark:checked:after:bg-primary-foreground after:shadow-sm after:transition-transform checked:after:translate-x-4"
                                        @change="savePreference('appearance', { pointer_cursors: ($event.target as HTMLInputElement).checked })">
                                    <FieldError v-if="appearance.errors.pointer_cursors">
                                        {{ appearance.errors.pointer_cursors }}
                                    </FieldError>
                                </div>
                            </Field>
                        </div>
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
                                class="grid gap-4"
                                @submit.prevent="save('security')">
                                <h3 class="text-sm font-medium">
                                    Locking and clipboard
                                </h3>
                                <div class="divide-y overflow-hidden rounded-xl border">
                                    <Field class="px-4 py-4 sm:grid sm:grid-cols-[minmax(0,1fr)_12rem] sm:items-center sm:gap-6">
                                        <div class="grid gap-1.5">
                                            <FieldLabel for="secret-lock-duration">
                                                Lock after unlocking
                                            </FieldLabel>
                                            <FieldDescription id="secret-lock-help">
                                                Changing this locks secrets immediately.
                                            </FieldDescription>
                                        </div>
                                        <div class="grid gap-2">
                                            <ChoiceSelect
                                                id="secret-lock-duration"
                                                class="w-full"
                                                :disabled="security.processing"
                                                variant="filled"
                                                :model-value="String(security.lock_minutes)"
                                                aria-describedby="secret-lock-help"
                                                :options="[{ value: '5', label: '5 minutes' }, { value: '15', label: '15 minutes' }, { value: '60', label: '1 hour' }, { value: '480', label: '8 hours' }]"
                                                @update:model-value="security.lock_minutes = Number($event)" />
                                            <FieldError v-if="security.errors.lock_minutes">
                                                {{ security.errors.lock_minutes }}
                                            </FieldError>
                                        </div>
                                    </Field>
                                    <Field class="px-4 py-4 sm:grid sm:grid-cols-[minmax(0,1fr)_12rem] sm:items-center sm:gap-6">
                                        <div class="grid gap-1.5">
                                            <FieldLabel for="secret-clipboard-duration">
                                                Clear copied secrets after
                                            </FieldLabel>
                                            <FieldDescription id="secret-clipboard-help">
                                                A later clipboard copy is preserved.
                                            </FieldDescription>
                                        </div>
                                        <div class="grid gap-2">
                                            <ChoiceSelect
                                                id="secret-clipboard-duration"
                                                class="w-full"
                                                :disabled="security.processing"
                                                variant="filled"
                                                :model-value="String(security.clipboard_seconds)"
                                                aria-describedby="secret-clipboard-help"
                                                :options="[{ value: '30', label: '30 seconds' }, { value: '60', label: '60 seconds' }, { value: '0', label: 'Off' }]"
                                                @update:model-value="security.clipboard_seconds = Number($event)" />
                                            <FieldError v-if="security.errors.clipboard_seconds">
                                                {{ security.errors.clipboard_seconds }}
                                            </FieldError>
                                        </div>
                                    </Field>
                                </div>
                                <Button
                                    size="sm"
                                    class="justify-self-end"
                                    :disabled="security.processing">
                                    Save changes
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
                                New projects use these columns. Existing projects keep their task columns.
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
                                aria-label="Default task columns"
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
                            class="grid gap-5"
                            @submit.prevent="save('tools')">
                            <p class="text-sm leading-6 text-muted-foreground">
                                Runtimes are detected automatically. Expand a runtime to set a workspace path. Project overrides take priority; saved changes apply on the next scan.
                            </p>
                            <div class="divide-y overflow-hidden rounded-xl border">
                                <Collapsible
                                    v-for="tool in toolNames"
                                    :key="tool"
                                    v-model:open="expandedTools[tool]">
                                    <CollapsibleTrigger
                                        type="button"
                                        class="group/runtime flex w-full items-center justify-between gap-3 px-4 py-3 text-left text-sm font-medium focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-ring">
                                        <span class="grid min-w-0 gap-1">
                                            <span class="underline-offset-4 group-hover/runtime:underline">{{ toolLabels[tool] }}</span>
                                            <span class="flex items-start gap-1 text-xs font-normal text-muted-foreground">
                                                <CircleCheckIcon
                                                    v-if="runtimeResults[tool]?.state === 'Current'"
                                                    class="size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400"
                                                    aria-hidden="true" />
                                                <span class="min-w-0 break-words">{{ runtimeResults[tool]?.state ?? 'Not checked' }}<template v-if="runtimeResults[tool]?.version"> · {{ runtimeResults[tool]?.version }}</template></span>
                                            </span>
                                        </span>
                                        <ChevronDownIcon
                                            class="size-4 shrink-0 text-muted-foreground transition-[color,transform] group-hover/runtime:text-foreground group-data-[state=open]/runtime:rotate-180"
                                            aria-hidden="true" />
                                    </CollapsibleTrigger>
                                    <CollapsibleContent class="border-t bg-muted/20 px-4 py-4">
                                        <Field>
                                            <FieldLabel :for="`tool-${tool}`">
                                                Executable override
                                            </FieldLabel>
                                            <div class="flex gap-2">
                                                <Input
                                                    :id="`tool-${tool}`"
                                                    variant="filled"
                                                    :model-value="tools.paths[tool] ?? ''"
                                                    placeholder="Automatic detection"
                                                    :disabled="tools.processing"
                                                    :aria-invalid="!!tools.errors[`paths.${tool}`] || !!runtimeProbe.errors[`paths.${tool}`]"
                                                    :aria-describedby="tools.errors[`paths.${tool}`] || runtimeProbe.errors[`paths.${tool}`] ? `tool-error-${tool}` : undefined"
                                                    @update:model-value="tools.paths[tool] = String($event) || null" />
                                                <Button
                                                    v-if="native"
                                                    type="button"
                                                    variant="outline"
                                                    size="input"
                                                    :disabled="tools.processing || runtimePicker.processing"
                                                    @click="pickTool(tool)">
                                                    Browse
                                                </Button>
                                            </div>
                                            <FieldError
                                                v-if="tools.errors[`paths.${tool}`] || runtimeProbe.errors[`paths.${tool}`]"
                                                :id="`tool-error-${tool}`">
                                                {{ tools.errors[`paths.${tool}`] || runtimeProbe.errors[`paths.${tool}`] }}
                                            </FieldError>
                                            <p
                                                v-if="runtimeResults[tool]"
                                                class="break-all text-xs text-muted-foreground">
                                                <span class="block">{{ runtimeResults[tool]?.path ?? 'No executable found' }}</span>
                                                <span class="block">{{ ({ automatic: 'Automatic detection', global: 'Workspace default', root: 'Project override' })[runtimeResults[tool]?.source as 'automatic' | 'global' | 'root'] ?? runtimeResults[tool]?.source }}</span>
                                            </p>
                                            <FieldDescription>Choose an installed executable or package-manager entry file. Shell wrappers are unsupported.</FieldDescription>
                                        </Field>
                                    </CollapsibleContent>
                                </Collapsible>
                            </div>
                            <div class="flex gap-2">
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
                    </div>
                </section>
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
