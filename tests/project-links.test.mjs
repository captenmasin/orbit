import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { computed, ref } from 'vue';
import * as vue from 'vue';
import ts from 'typescript';
import * as icons from '@lucide/vue';

const categorySource = readFileSync(new URL('../resources/js/lib/link-categories.ts', import.meta.url), 'utf8');
const categoryScript = ts.transpileModule(categorySource, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
const categoryContext = { exports: {}, require: () => icons };
runInNewContext(categoryScript, categoryContext);
const { linkCategoryIcon } = categoryContext.exports;

function creationDialog(project) {
    const source = readFileSync(new URL('../resources/js/components/CreateProjectLink.vue', import.meta.url), 'utf8');
    const { descriptor } = parse(source);
    const script = compileScript(descriptor, { id: 'create-link-test' });
    const requests = [];
    let nextId = 0;
    const router = { put: (url, data, callbacks) => requests.push({ url, data, callbacks }) };
    const context = {
        exports: {}, crypto: { randomUUID: () => `new-link-${++nextId}` },
        require: name => name === 'vue' ? vue : name === '@inertiajs/vue3' ? { router } : {},
    };
    runInNewContext(ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText, context);
    const props = vue.reactive({ project, disabled: false });
    const state = context.exports.default.setup(props, { expose() {} });
    return { props, state, requests };
}

test('adding a link appends its fields while retaining saved links and the opening revision', () => {
    const saved = { id: 'saved', label: 'Docs', url: 'https://example.com/docs', category: 'Docs', description: 'Saved notes', important: true };
    const { props, state, requests } = creationDialog({ id: 'beta', name: 'Beta', description: 'Project notes', status: 'Paused', revision: 7, links: [saved] });
    state.setOpen(true);
    Object.assign(state.draft.value, { label: 'Dashboard', url: 'https://example.com/dashboard', category: 'Analytics', description: 'New notes' });
    props.project.revision = 8;

    state.createLink();

    assert.equal(requests.length, 1);
    assert.equal(requests[0].url, '/projects/beta');
    assert.deepEqual(JSON.parse(JSON.stringify(requests[0].data)), {
        name: 'Beta', description: 'Project notes', status: 'Paused', revision: 7, return_back: true,
        links: [
            { id: 'saved', label: 'Docs', url: 'https://example.com/docs', category: 'Docs', description: 'Saved notes' },
            { id: 'new-link-1', label: 'Dashboard', url: 'https://example.com/dashboard', category: 'Analytics', description: 'New notes' },
        ],
    });
    assert.equal(props.project.links.length, 1);
    assert.equal(props.project.links[0].important, true);
    assert.equal(requests[0].callbacks.preserveScroll, true);
    requests[0].callbacks.onSuccess();
    requests[0].callbacks.onFinish();
    assert.equal(state.creating.value, false);
    assert.equal(state.saving.value, false);
});

test('link creation keeps the draft on validation or network failure and blocks duplicate saves', () => {
    const { state, requests } = creationDialog({ id: 'beta', name: 'Beta', status: 'Paused', revision: 7, links: [{ id: 'saved', url: 'https://example.com' }] });
    state.setOpen(true);
    state.draft.value.url = 'ftp://example.com';
    state.createLink();
    state.createLink();
    state.setOpen(false);
    assert.equal(requests.length, 1);
    assert.equal(state.creating.value, true);

    requests[0].callbacks.onError({ 'links.1.url': 'Enter a valid URL.', 'links.1.category': 'Category is too long.' });
    requests[0].callbacks.onFinish();

    assert.deepEqual({ ...state.errors.value }, { url: 'Enter a valid URL.', category: 'Category is too long.' });
    assert.equal(state.creating.value, true);
    assert.equal(state.draft.value.url, 'ftp://example.com');
    state.draft.value.url = 'https://example.com/new';
    state.createLink();
    assert.deepEqual({ ...state.errors.value }, {});
    assert.equal(requests[1].data.links[1].id, requests[0].data.links[1].id);
    requests[1].callbacks.onError({ revision: 'This project changed. Reload it before saving again.' });
    requests[1].callbacks.onFinish();
    assert.equal(state.errors.value.general, 'This project changed. Reload it before saving again.');
    state.createLink();
    requests[2].callbacks.onNetworkError();
    requests[2].callbacks.onFinish();
    assert.equal(state.errors.value.general, 'Could not add link. Try again.');
    assert.equal(state.creating.value, true);
    assert.equal(state.draft.value.url, 'https://example.com/new');
});

test('first-link creation resets cancelled drafts and respects disabled controls', () => {
    const { props, state, requests } = creationDialog({ id: 'empty', name: 'Empty', status: 'Idea', revision: 1, links: [] });
    props.disabled = true;
    state.setOpen(true);
    state.createLink();
    assert.equal(state.creating.value, false);
    assert.equal(requests.length, 0);
    props.disabled = false;
    state.setOpen(true);
    state.draft.value.url = 'https://example.com/cancelled';
    state.errors.value.general = 'Old error';
    state.setOpen(false);
    props.project.revision = 2;
    state.setOpen(true);
    assert.deepEqual({ ...state.draft.value }, { label: '', url: '', category: '', description: '' });
    assert.deepEqual({ ...state.errors.value }, {});
    state.draft.value.url = 'https://example.com/first';
    props.disabled = true;
    state.createLink();
    assert.equal(requests.length, 0);
    props.disabled = false;

    state.createLink();

    assert.equal(requests[0].data.revision, 2);
    assert.deepEqual(JSON.parse(JSON.stringify(requests[0].data.links)), [{ id: 'new-link-2', label: '', url: 'https://example.com/first', category: '', description: '' }]);
});

test('link categories show the matching icons for common service categories', () => {
    const expected = [
        ['Analytics', icons.ChartNoAxesCombinedIcon], ['Social', icons.UsersIcon],
        ['Billing', icons.CreditCardIcon], ['Docs', icons.BookOpenIcon],
        ['Security', icons.ShieldIcon], ['Email', icons.MailIcon],
        ['Infrastructure', icons.NetworkIcon], ['Deployment', icons.RocketIcon],
        ['OAuth', icons.KeyRoundIcon], ['Monitoring', icons.ActivityIcon],
        ['Hosting', icons.ServerIcon], ['Domain', icons.GlobeIcon],
        ['Browser Renderer', icons.PanelsTopLeftIcon], ['Website', icons.GlobeIcon],
        ['Inbox', icons.InboxIcon],
    ];

    for (const [category, icon] of expected) {
        assert.equal(linkCategoryIcon(category), icon, category);
    }
});

test('category icons recognise aliases, casing, whitespace and word separators', () => {
    for (const [category, icon] of [
        ['  DOCUMENTATION  ', icons.BookOpenIcon], ['authentication', icons.KeyRoundIcon],
        ['OAuth2', icons.KeyRoundIcon], ['social-media', icons.UsersIcon],
        ['Browser_Renderer', icons.PanelsTopLeftIcon], ['browser   rendering', icons.PanelsTopLeftIcon],
        ['E-mail', icons.MailIcon], ['payments', icons.CreditCardIcon], ['DNS', icons.GlobeIcon],
    ]) {
        assert.equal(linkCategoryIcon(category), icon, category);
    }
});

test('category icons recognise common alternatives and singular or plural category names', () => {
    const expected = [
        [['Metrics', 'Statistics', 'Stats', 'Insights', 'Reporting', 'Reports'], icons.ChartNoAxesCombinedIcon],
        [['Social Networks', 'Community', 'Communities'], icons.UsersIcon],
        [['Payment', 'Finance', 'Invoices', 'Invoicing', 'Subscriptions'], icons.CreditCardIcon],
        [['Doc', 'Document', 'Documents', 'Guide', 'Guides', 'Reference', 'References', 'Wiki', 'Knowledge Base', 'Handbook'], icons.BookOpenIcon],
        [['Cybersecurity', 'Cyber Security', 'Protection'], icons.ShieldIcon],
        [['Emails', 'E-mails'], icons.MailIcon], [['Inboxes'], icons.InboxIcon],
        [['Infra', 'Network', 'Networking'], icons.NetworkIcon],
        [['Deploy', 'Deploys', 'Release', 'Releases', 'CI/CD', 'CI-CD', 'Continuous Integration', 'Continuous Delivery'], icons.RocketIcon],
        [['OAuth 2', 'OAuth2.0', 'OAuth 2.0', 'Authorization', 'Authorisation', 'Login', 'Log In', 'Sign In', 'SSO', 'Single Sign-On', 'Identity'], icons.KeyRoundIcon],
        [['Observability', 'Uptime', 'Health Checks', 'Logging', 'Logs'], icons.ActivityIcon],
        [['Host', 'Hosts', 'Server', 'Servers', 'Web Hosting', 'Cloud Hosting'], icons.ServerIcon],
        [['Domain Names', 'Web Site', 'Web Sites', 'Site', 'Sites'], icons.GlobeIcon],
        [['Browsers', 'Web Browsers', 'Browser Automation', 'Headless Browser', 'Renderer', 'Renderers', 'Rendering'], icons.PanelsTopLeftIcon],
    ];

    for (const [categories, icon] of expected) {
        for (const category of categories) {
            assert.equal(linkCategoryIcon(category), icon, category);
        }
    }
});

test('custom and uncategorised links remain iconless without accidental partial matches', () => {
    for (const category of ['', '   ', 'Client resources', 'Email campaigns', 'constructor', '__proto__', 'toString']) {
        assert.equal(linkCategoryIcon(category), undefined, category);
    }
});

test('favourites stay above the remaining groups and the grid retains every saved link', () => {
    const source = readFileSync(new URL('../resources/js/pages/ShowProject.vue', import.meta.url), 'utf8');
    const script = ts.transpile(source.slice(source.indexOf('function groupLinks('), source.indexOf('const previewDocuments =')), { target: ts.ScriptTarget.ES2022 });
    const links = Array.from({ length: 12 }, (_, id) => ({ id, label: `Link ${id}`, url: `https://example.com/${id}`, category: id === 11 ? 'Docs' : '', important: id === 10 }));
    const project = ref({ links });
    const state = runInNewContext(`${script}\n({ importantLinks, overviewLinkGroups, overviewLinkColumns })`, { project, computed, ref, useLocalStorage: () => ref({}) });

    assert.deepEqual(Array.from(state.importantLinks.value, item => item.id), [10]);
    assert.deepEqual(Array.from(state.overviewLinkGroups.value, ([category, items]) => [category, Array.from(items, item => item.id)]), [
        ['Docs', [11]], ['', [0, 1, 2, 3, 4, 5, 6, 7, 8, 9]],
    ]);
    assert.deepEqual(Array.from(state.overviewLinkColumns.value, groups => Array.from(groups, group => [group.category, group.index])), [[['Docs', 0]], [['', 1]]]);
    assert.equal(project.value.links[0].id, 0);
    assert.equal(state.overviewLinkGroups.value.flatMap(([, items]) => items).length, 11);

    project.value.links.forEach(link => { link.important = true; });
    assert.equal(state.importantLinks.value.length, 12);
    assert.equal(state.overviewLinkGroups.value.length, 0);

    project.value.links = [
        { id: 'uncategorised', category: null }, { id: 'docs', category: ' Docs ' },
        { id: 'docs-too', category: 'Docs' }, { id: 'named-other', category: 'Other links' },
        { id: 'prototype', category: '__proto__' },
    ];
    assert.deepEqual(Array.from(state.overviewLinkGroups.value, ([category, items]) => [category, Array.from(items, item => item.id)]), [
        ['Docs', ['docs', 'docs-too']], ['Other links', ['named-other']], ['__proto__', ['prototype']], ['', ['uncategorised']],
    ]);
    project.value.links = [];
    assert.equal(state.importantLinks.value.length, 0);
    assert.equal(state.overviewLinkGroups.value.length, 0);
});
