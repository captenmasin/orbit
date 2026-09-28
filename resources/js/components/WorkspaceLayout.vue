<script setup lang="ts">
import ContentSearch from '@/components/ContentSearch.vue';
import WorkspaceSidebar from '@/components/WorkspaceSidebar.vue';
import { Toaster, toast } from 'vue-sonner';
import { useLocalStorage } from '@vueuse/core';
import { Button } from '@/components/ui/button';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeftIcon, ArrowRightIcon } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { Project, ProjectPage, SearchResult, SidebarProject } from '@/types';
import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { appearance, applyAppearance, applyMotionPreference, type Appearance, type MotionPreference } from '@/lib/appearance';
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb';

const page = usePage<{ sidebarProjects: SidebarProject[]; statuses: string[]; projects?: ProjectPage; selectedProject?: Project; message?: string | null; native: boolean; appearance: Appearance; reduceMotion: MotionPreference }>();
const nativeMac = page.props.native && typeof navigator !== 'undefined' && navigator.platform.startsWith('Mac');
watch(() => page.props.appearance, value => { if (value) applyAppearance(value); });
watch(() => page.props.reduceMotion, value => { if (value) applyMotionPreference(value); });
watch(() => page.props.message, message => {
    if (!message) return;
    toast.success(message);
    router.replaceProp('message', null);
}, { immediate: true, flush: 'sync' });
const project = computed(() => page.props.selectedProject);
const titles: Record<string, string> = { Dashboard: 'Dashboard', CreateProject: 'New project', EditProject: 'Edit project', Connections: 'Connections', Backups: 'Backups', Settings: 'Settings' };
const title = computed(() => titles[page.component] ?? project.value?.name ?? 'Orbit');
const searchOpen = ref(false);
const storedRecentItems = useLocalStorage<SearchResult[]>('orbit:recent-items', [], { flush: 'sync' });
const resultTabs: Record<string, string> = { project: 'overview', document: 'documents', task: 'board', link: 'overview', secret: 'secrets' };
const currentItems = computed<Record<string, { id: string; title?: string; label?: string; name?: string }[] | undefined>>(() => ({
    document: project.value?.documents,
    task: project.value?.board_columns?.flatMap(column => column.tasks),
    link: project.value?.links,
    secret: project.value?.secrets,
}));
function recentDestination(value: unknown): SearchResult | undefined {
    if (!value || typeof value !== 'object') return;
    const result = value as SearchResult;
    if (typeof result.id !== 'string' || typeof result.project_id !== 'string' || !/^[\w-]+$/.test(result.id) || !/^[\w-]+$/.test(result.project_id) || typeof result.title !== 'string' || typeof result.project !== 'string' || typeof result.type !== 'string' || !Object.hasOwn(resultTabs, result.type)) return;
    const projectUrl = `/projects/${result.project_id}`;
    const url = projectUrl + '?tab=' + resultTabs[result.type] + (result.type === 'project' ? '' : '&' + result.type + '=' + result.id);
    if (result.url !== url && !(result.type === 'project' && result.url === projectUrl)) return;
    return { id: result.id, project_id: result.project_id, project: result.project, type: result.type, title: result.title, excerpt: '', url };
}
const recentItems = computed(() => {
    if (!Array.isArray(storedRecentItems.value)) return [];
    return storedRecentItems.value.flatMap(value => {
        const result = recentDestination(value);
        const owner = result && page.props.sidebarProjects?.find(item => item.id === result.project_id);
        if (!result || !owner) return [];
        const records = owner.id === project.value?.id ? currentItems.value[result.type] : undefined;
        const item = records?.find(item => item.id === result.id);
        if (records && !item) return [];
        return [{ ...result, project: owner.name, title: result.type === 'project' ? owner.name : item?.title ?? item?.label ?? item?.name ?? result.title }];
    }).slice(0, 8);
});
function rememberRecent(result?: SearchResult) {
    const destination = recentDestination(result);
    if (!destination) return;
    storedRecentItems.value = [destination, ...recentItems.value.filter(item => item.url !== destination.url)].slice(0, 8);
}
function navigateFromSearch(result?: SearchResult) {
    rememberRecent(result);
    searchOpen.value = false;
}
function clearRecentItems() { storedRecentItems.value = []; }
const canGoBack = ref(false);
const canGoForward = ref(false);
let stopTrackingHistory: (() => void) | undefined;
let swipeDistance = 0;
let swipeTriggered = false;
let resetSwipe: ReturnType<typeof setTimeout> | undefined;
function updateHistoryButtons() {
    const navigation = (window as Window & { navigation?: { canGoBack: boolean; canGoForward: boolean } }).navigation;
    canGoBack.value = navigation?.canGoBack ?? window.history.length > 1;
    canGoForward.value = navigation?.canGoForward ?? false;
}
function handleNavigation() {
    updateHistoryButtons();
    const selectedProject = project.value;
    const pageUrl = new URL(page.url, 'https://orbit.local');
    if (selectedProject && pageUrl.pathname === `/projects/${selectedProject.id}`) {
        const parameters = pageUrl.searchParams;
        const type = Object.keys(currentItems.value).find(type => currentItems.value[type]?.some(item => item.id === parameters.get(type)));
        const item = type ? currentItems.value[type]?.find(item => item.id === parameters.get(type)) : undefined;
        rememberRecent({
            id: item?.id ?? selectedProject.id,
            project_id: selectedProject.id,
            project: selectedProject.name,
            type: type ?? 'project',
            title: item?.title ?? item?.label ?? item?.name ?? selectedProject.name,
            excerpt: '',
            url: '/projects/' + selectedProject.id + '?tab=' + resultTabs[type ?? 'project'] + (type ? '&' + type + '=' + item!.id : ''),
        });
    }
}
function handleNativeMenu(event: MessageEvent) {
    if (event.source !== window || event.origin !== window.location.origin || event.data?.type !== 'native-event' || event.data.event !== 'Native\\Desktop\\Events\\Menu\\MenuItemClicked') return;
    const id = event.data.payload?.item?.id;
    if (id === 'search') {
        searchOpen.value = true;
        return;
    }
    const destinations: Record<string, string> = {
        'new-project': '/projects/create', dashboard: '/', settings: '/settings', backups: '/settings/backups',
        connections: '/settings/connections', tools: '/settings?section=tools', about: '/settings?section=about',
    };
    if (typeof id !== 'string' || !Object.hasOwn(destinations, id) || page.url === destinations[id] || (id === 'new-project' && page.component === 'CreateProject')) return;
    searchOpen.value = false;
    router.visit(destinations[id]);
}
onMounted(() => {
    handleNavigation();
    window.addEventListener('keydown', handleWorkspaceShortcut);
    stopTrackingHistory = router.on('navigate', handleNavigation);
    if (page.props.native) window.addEventListener('message', handleNativeMenu);
    if (nativeMac) {
        window.addEventListener('wheel', handleTrackpadSwipe, { passive: false });
    }
});
onBeforeUnmount(() => {
    stopTrackingHistory?.();
    window.removeEventListener('keydown', handleWorkspaceShortcut);
    window.removeEventListener('message', handleNativeMenu);
    window.removeEventListener('wheel', handleTrackpadSwipe);
    clearTimeout(resetSwipe);
});
function goBack() { window.history.back(); }
function goForward() { window.history.forward(); }
function handleWorkspaceShortcut(event: KeyboardEvent) {
    if (event.isComposing || !(event.metaKey || event.ctrlKey) || event.altKey || event.shiftKey) return;
    if (event.key.toLowerCase() === 'k') {
        event.preventDefault();
        searchOpen.value = !searchOpen.value;
        return;
    }
    if (event.key.toLowerCase() === 'n' && !event.defaultPrevented && !event.repeat) {
        event.preventDefault();
        searchOpen.value = false;
        if (page.component !== 'CreateProject') router.visit('/projects/create');
        return;
    }
    if (event.defaultPrevented || event.repeat || page.component !== 'Dashboard' || searchOpen.value || !/^[1-9]$/.test(event.key)) return;
    if (event.target instanceof HTMLElement && (event.target.isContentEditable || event.target.closest('input, textarea, select, [role="dialog"], [role="menu"]'))) return;
    const targetProject = page.props.projects?.data[Number(event.key) - 1];
    if (!targetProject) return;
    event.preventDefault();
    router.visit(`/projects/${targetProject.id}`);
}
function handleTrackpadSwipe(event: WheelEvent) {
    if (event.deltaMode !== 0 || event.ctrlKey || event.shiftKey || event.altKey || event.metaKey || Math.abs(event.deltaX) <= Math.abs(event.deltaY)) return;

    for (let element = event.target instanceof HTMLElement ? event.target : null; element && element !== document.body; element = element.parentElement) {
        if (element.scrollWidth > element.clientWidth && ['auto', 'scroll'].includes(getComputedStyle(element).overflowX)) return;
    }

    event.preventDefault();
    clearTimeout(resetSwipe);
    resetSwipe = setTimeout(() => { swipeDistance = 0; swipeTriggered = false; }, 250);
    if (swipeTriggered) return;

    swipeDistance += event.deltaX;
    if (Math.abs(swipeDistance) < 90) return;
    swipeTriggered = true;
    if (swipeDistance < 0) goBack(); else goForward();
}
</script>

<template>
    <SidebarProvider
        class="bg-sidebar"
        :class="{ 'native-macos': nativeMac }"
        style="--sidebar-width: 17rem">
        <Button
            as-child
            class="sr-only focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:z-50">
            <a href="#main">Skip to content</a>
        </Button>
        <WorkspaceSidebar
            :projects="page.props.sidebarProjects"
            :statuses="page.props.statuses"
            :selected-project="project ?? null"
            :page="page.component"
            @search-workspace="searchOpen = true" />
        <SidebarInset class="min-w-0 md:my-3 md:mr-0 md:ml-1 md:overflow-hidden md:rounded-l-2xl md:shadow-sm">
            <header
                data-slot="window-toolbar"
                class="flex h-11 shrink-0 items-center gap-2 border-b border-border/70 px-4">
                <div class="flex items-center gap-1">
                    <SidebarTrigger class="-ml-1 text-muted-foreground" />
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        class="text-muted-foreground"
                        aria-label="Back"
                        :disabled="!canGoBack"
                        @click="goBack">
                        <ArrowLeftIcon aria-hidden="true" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        class="text-muted-foreground"
                        aria-label="Forward"
                        :disabled="!canGoForward"
                        @click="goForward">
                        <ArrowRightIcon aria-hidden="true" />
                    </Button>
                </div>
                <Breadcrumb>
                    <BreadcrumbList>
                        <template v-if="page.component !== 'Dashboard'">
                            <BreadcrumbItem class="hidden md:block">
                                <BreadcrumbLink as-child>
                                    <Link href="/">
                                        Dashboard
                                    </Link>
                                </BreadcrumbLink>
                            </BreadcrumbItem>
                            <BreadcrumbSeparator class="hidden md:block" />
                        </template>
                        <template v-if="project && page.component === 'EditProject'">
                            <BreadcrumbItem>
                                <BreadcrumbLink as-child>
                                    <Link :href="`/projects/${project.id}`">
                                        {{ project.name }}
                                    </Link>
                                </BreadcrumbLink>
                            </BreadcrumbItem>
                            <BreadcrumbSeparator />
                        </template>
                        <BreadcrumbItem>
                            <BreadcrumbPage class="line-clamp-1 break-all">
                                {{ title }}
                            </BreadcrumbPage>
                        </BreadcrumbItem>
                    </BreadcrumbList>
                </Breadcrumb>
            </header>
            <main
                id="main"
                tabindex="-1"
                class="flex min-w-0 flex-1 flex-col gap-6 p-4 lg:p-6">
                <Transition
                    name="t-page-navigation"
                    mode="out-in">
                    <slot />
                </Transition>
            </main>
        </SidebarInset>
        <Dialog v-model:open="searchOpen">
            <DialogContent
                :show-close-button="false"
                class="top-[min(20vh,8rem)] translate-y-0 gap-0 overflow-hidden rounded-3xl bg-sidebar/95 p-0 shadow-2xl backdrop-blur-xl sm:max-w-3xl">
                <DialogHeader class="sr-only">
                    <DialogTitle>Search workspace</DialogTitle><DialogDescription>Search projects, documents, cards, links and secret metadata. Secret values, scratchpad notes, assets and source folders are not included. Use the arrow keys to navigate and Enter to open a result.</DialogDescription>
                </DialogHeader><ContentSearch
                    launcher
                    :preferred-project-id="project?.id"
                    :recent-items="recentItems"
                    @navigate="navigateFromSearch"
                    @clear-recents="clearRecentItems"
                    @close="searchOpen = false" />
            </DialogContent>
        </Dialog>
        <Toaster
            position="bottom-right"
            :theme="appearance"
            :toast-options="{ class: 't-toast' }" />
    </SidebarProvider>
</template>
