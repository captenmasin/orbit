import '../css/app.css';
import { createApp, h } from 'vue';
import type { DefineComponent } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import WorkspaceLayout from '@/components/WorkspaceLayout.vue';

createInertiaApp({
    layout: () => WorkspaceLayout,
    title: title => `${title} · Orbit`,
    resolve: name => resolvePageComponent<DefineComponent>(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        createApp({
            render: () => h(App, props),
        }).use(plugin).mount(el);
    },
    progress: { color: 'var(--primary)' },
});
