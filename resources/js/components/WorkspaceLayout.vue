<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeftIcon, ArrowRightIcon } from '@lucide/vue';
import { Toaster, toast } from 'vue-sonner';
import 'vue-sonner/style.css';
import WorkspaceSidebar from '@/components/WorkspaceSidebar.vue';
import ContentSearch from '@/components/ContentSearch.vue';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb';
import { Separator } from '@/components/ui/separator';
import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar';
import type { Project, SidebarProject } from '@/types';

const page = usePage<{ sidebarProjects: SidebarProject[]; selectedProject?: Project; message?: string | null; native: boolean }>();
const project = computed(() => page.props.selectedProject);
const titles: Record<string, string> = { Dashboard: 'Dashboard', CreateProject: 'New project', EditProject: 'Edit project', Connections: 'Connections', Backups: 'Backups' };
const title = computed(() => titles[page.component] ?? project.value?.name ?? 'Orbit');
const searchOpen = ref(false);
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
    if (page.props.message) {
        toast.success(page.props.message);
        router.replaceProp('message', null);
    }
}
onMounted(() => {
    handleNavigation();
    stopTrackingHistory = router.on('navigate', handleNavigation);
    if (page.props.native && navigator.platform.startsWith('Mac')) {
        window.addEventListener('wheel', handleTrackpadSwipe, { passive: false });
    }
});
onBeforeUnmount(() => {
    stopTrackingHistory?.();
    window.removeEventListener('wheel', handleTrackpadSwipe);
    clearTimeout(resetSwipe);
});
function goBack() { window.history.back(); }
function goForward() { window.history.forward(); }
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
    <SidebarProvider>
        <Button as-child class="sr-only focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:z-50"><a href="#main">Skip to content</a></Button>
        <WorkspaceSidebar :projects="page.props.sidebarProjects" :selected-project="project ?? null" :page="page.component" @search-workspace="searchOpen = true" />
        <SidebarInset class="min-w-0">
            <header class="flex h-16 shrink-0 items-center gap-2 px-4">
                <div class="flex items-center gap-1">
                    <SidebarTrigger class="-ml-1 text-muted-foreground" />
                    <Button type="button" variant="ghost" size="icon-sm" class="text-muted-foreground" aria-label="Back" :disabled="!canGoBack" @click="goBack"><ArrowLeftIcon aria-hidden="true" /></Button>
                    <Button type="button" variant="ghost" size="icon-sm" class="text-muted-foreground" aria-label="Forward" :disabled="!canGoForward" @click="goForward"><ArrowRightIcon aria-hidden="true" /></Button>
                </div>
                <Separator orientation="vertical" class="mr-2 data-[orientation=vertical]:h-4" />
                <Breadcrumb>
                    <BreadcrumbList>
                        <template v-if="page.component !== 'Dashboard'">
                            <BreadcrumbItem class="hidden md:block"><BreadcrumbLink as-child><Link href="/">Dashboard</Link></BreadcrumbLink></BreadcrumbItem>
                            <BreadcrumbSeparator class="hidden md:block" />
                        </template>
                        <template v-if="project && page.component === 'EditProject'">
                            <BreadcrumbItem><BreadcrumbLink as-child><Link :href="`/projects/${project.id}`">{{ project.name }}</Link></BreadcrumbLink></BreadcrumbItem>
                            <BreadcrumbSeparator />
                        </template>
                        <BreadcrumbItem><BreadcrumbPage class="line-clamp-1 break-all">{{ title }}</BreadcrumbPage></BreadcrumbItem>
                    </BreadcrumbList>
                </Breadcrumb>
            </header>
            <main id="main" tabindex="-1" class="flex min-w-0 flex-1 flex-col gap-6 p-4 pt-0 lg:p-6 lg:pt-0">
                <slot />
            </main>
        </SidebarInset>
        <Dialog v-model:open="searchOpen"><DialogContent class="max-h-[85vh] overflow-y-auto"><DialogHeader><DialogTitle>Search workspace</DialogTitle><DialogDescription>Find project documents, tasks, links, and non-secret credential context.</DialogDescription></DialogHeader><ContentSearch @navigate="searchOpen = false" /></DialogContent></Dialog>
        <Toaster position="bottom-right" />
    </SidebarProvider>
</template>
