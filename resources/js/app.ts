import '../css/app.css';
import { createApp, h } from 'vue';
import type { DefineComponent } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { SidebarProvider } from '@/components/ui/sidebar';

createInertiaApp({
    title: title => `${title} · Orbit`,
    resolve: name => resolvePageComponent<DefineComponent>(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        createApp({
            render: () => h(SidebarProvider, null, { default: () => h(App, props) }),
        }).use(plugin).mount(el);
    },
    progress: { color: 'var(--primary)' },
});
