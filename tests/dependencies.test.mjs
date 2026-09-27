import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as vue from 'vue';
import ts from 'typescript';

test('dependency rows distinguish direct requirements, transitive versions, aliases, links and stale values', () => {
    const source = readFileSync(new URL('../resources/js/lib/dependencies.ts', import.meta.url), 'utf8');
    const context = { exports: {} };
    runInNewContext(ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText, context);
    const rows = context.exports.dependencyRows({ files: {
        'package.json': { state: 'Current', entries: [{ name: 'shared', required: '^1', scope: 'Production' }, { name: 'alias', required: 'npm:other@^2', scope: 'Development' }, { name: 'workspace', required: '*', scope: 'Production' }, { name: 'unlocked', required: '^4', scope: 'Peer' }] },
        'package-lock.json': { state: 'Malformed file', entries: [
            { name: 'shared', version: '1.0.0', location: 'node_modules/shared', scope: 'Production' },
            { name: 'shared', version: '2.0.0', location: 'node_modules/parent/node_modules/shared', scope: 'Development' },
            { name: 'other', version: '2.1.0', location: 'node_modules/alias', scope: 'Development' },
            { name: 'workspace', link: 'packages/workspace', location: 'node_modules/workspace', scope: 'Production' },
        ] },
    } });
    assert.equal(rows.length, 5);
    assert.equal(rows[0].required, '^1');
    assert.equal(rows[0].version, '1.0.0');
    assert.equal(rows[0].stale, true);
    assert.equal(rows[1].version, '2.1.0');
    assert.equal(rows[2].link, 'packages/workspace');
    assert.equal(rows[3].version, undefined);
    assert.equal(rows[3].scope, 'Peer');
    assert.equal(rows[4].identity, 'Transitive');
    assert.equal(rows[4].version, '2.0.0');
    assert.equal(rows[4].required, undefined);
});

const context = { exports: {} };
runInNewContext(ts.transpileModule(readFileSync(new URL('../resources/js/lib/dependencies.ts', import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText, context);
const { dependencyRows, dependencyHealth, dependencyReleaseUrl } = context.exports;

function root(id, version = '3.5.20') {
    return {
        id, relative_path: id === 'ui' ? 'packages/ui' : '.', scan_state: 'Current',
        snapshot: { fingerprint: id, unsupported_lockfiles: [], files: {
            'package.json': { state: 'Current', entries: [{ name: 'vue', required: '^3', scope: 'Production' }] },
            'package-lock.json': { state: 'Current', entries: [{ name: 'vue', version, location: 'node_modules/vue', scope: 'Production' }] },
        } },
        outdated: { fingerprint: id, checked: 1, unavailable: 0, skipped: 0, packages: [{ name: 'vue', ecosystem: 'npm', current: version, latest: '3.5.30' }] },
        security: { fingerprint: id, checked: 1, unavailable: 0, skipped: 0, packages: [] },
    };
}

const advisory = severity => ({ id: 'GHSA-1234', title: 'Unsafe input', severity, url: 'https://osv.dev/vulnerability/GHSA-1234', fixed_versions: ['3.5.25'] });

test('project queue merges security and updates for one installation while preserving separate roots and severity order', () => {
    const ui = root('ui');
    ui.snapshot.files['package-lock.json'].entries.push({ name: 'nested', version: '1.0.0', location: 'node_modules/parent/node_modules/nested', scope: 'Development' });
    ui.snapshot.files['package-lock.json'].entries.push({ name: 'vue', version: '3.5.20', location: 'node_modules/parent/node_modules/vue', scope: 'Development' });
    ui.security.packages = [
        { name: 'vue', ecosystem: 'npm', current: '3.5.20', advisories: [advisory('High')] },
        { name: 'nested', ecosystem: 'npm', current: '1.0.0', advisories: [advisory('Critical')] },
    ];
    const folders = [{ id: 'web', path: '/projects/web', package_roots: [ui, root('app', '3.5.22')] }];
    const health = dependencyHealth(folders);
    assert.equal(health.security, 2);
    assert.equal(health.outdated, 2);
    assert.equal(health.issueCount, 3);
    assert.equal(health.checked, 2);
    assert.equal(health.complete, true);
    assert.equal(health.issues[0].name, 'nested');
    assert.equal(health.issues[0].identity, 'Transitive');
    assert.equal(health.issues[0].scope, 'Development');
    assert.equal(health.issues[1].root.id, 'ui');
    assert.equal(health.issues[1].latest, '3.5.30');
    assert.equal(health.issues[1].advisories.length, 1);
    assert.equal(health.issues[1].identity, 'Direct & Transitive');
    assert.equal(health.issues[1].scope, 'Production, Development');
    assert.equal(health.issues[2].root.id, 'app');
    assert.notEqual(health.issues[1].key, health.issues[2].key);
});

test('coverage excludes stale findings and distinguishes unchecked, missing locks, skipped packages and unconfigured folders', () => {
    const stale = root('stale');
    stale.snapshot.fingerprint = 'changed';
    const unchecked = root('unchecked');
    delete unchecked.outdated;
    delete unchecked.security;
    const missing = root('missing');
    missing.snapshot.files['package-lock.json'].state = 'Missing file';
    missing.outdated.packages = [];
    const skipped = root('skipped');
    skipped.security.skipped = 1;
    const health = dependencyHealth([{ id: 'web', path: '/web', package_roots: [stale, unchecked, missing, skipped] }, { id: 'admin', path: '/admin', package_roots: [] }]);
    assert.equal(health.complete, false);
    assert.equal(health.checked, 0);
    assert.equal(health.outdated, 1);
    assert.equal(health.locations[0].state, 'Stale');
    assert.equal(health.locations[1].state, 'Not checked');
    assert.equal(health.locations[2].state, 'Incomplete');
    assert.match(health.locations[2].reason, /Missing file/);
    assert.equal(health.locations[3].state, 'Incomplete');
    assert.equal(health.unconfigured.length, 1);
    skipped.scan_state = 'Failed';
    assert.equal(dependencyHealth([{ id: 'web', path: '/web', package_roots: [skipped] }]).outdated, 0);
});

test('selected manager lock data is used and explicit ambiguous selection never falls back to npm data', () => {
    const item = root('pnpm');
    item.snapshot.npm_lockfile = 'pnpm-lock.yaml';
    item.snapshot.files['pnpm-lock.yaml'] = { state: 'Current', entries: [{ name: 'vue', version: '3.5.21', location: 'node_modules/vue', scope: 'Production' }] };
    assert.equal(dependencyRows(item.snapshot)[0].version, '3.5.21');
    item.snapshot.npm_lockfile = null;
    assert.equal(dependencyRows(item.snapshot)[0].version, undefined);
    assert.equal(dependencyHealth([{ id: 'web', path: '/web', package_roots: [item] }]).complete, false);
    item.snapshot.npm_lockfile = 'pnpm-lock.yaml';
    item.snapshot.files['pnpm-lock.yaml'].state = 'Parser unavailable';
    assert.equal(dependencyHealth([{ id: 'web', path: '/web', package_roots: [item] }]).complete, false);
    item.snapshot.npm_lockfile = 'bun.lock';
    item.snapshot.files['bun.lock'] = { state: 'Current', entries: [{ name: 'vue', version: '3.5.20', location: 'node_modules/vue', scope: 'Production' }] };
    const bun = dependencyHealth([{ id: 'web', path: '/web', package_roots: [item] }]);
    assert.equal(bun.complete, true);
    assert.equal(bun.issues[0].manager, 'Bun');
    item.snapshot.npm_lockfile = 'bun.lockb';
    item.snapshot.files['bun.lockb'] = { state: 'Unsupported lockfile format', entries: [] };
    assert.equal(dependencyHealth([{ id: 'web', path: '/web', package_roots: [item] }]).complete, false);
});

test('release links use trusted registries and reject unsupported ecosystems or unsafe names', () => {
    assert.equal(dependencyReleaseUrl({ name: 'laravel/framework', ecosystem: 'composer' }), 'https://packagist.org/packages/laravel/framework');
    assert.equal(dependencyReleaseUrl({ name: '@orbit/ui', ecosystem: 'npm', latest: '1.2.3' }), 'https://www.npmjs.com/package/%40orbit%2Fui/v/1.2.3');
    assert.equal(dependencyReleaseUrl({ name: '../../private', ecosystem: 'npm' }), undefined);
    assert.equal(dependencyReleaseUrl({ name: 'https://evil.test', ecosystem: 'composer' }), undefined);
    assert.equal(dependencyReleaseUrl({ name: 'vue', ecosystem: 'other' }), undefined);
});

test('checking project locations continues after failures, refreshes coverage and respects the folder filter', async t => {
    const requests = [];
    const emitted = [];
    let active = 0;
    let maximum = 0;
    const modules = { vue, '@/lib/dependencies': context.exports, '@inertiajs/vue3': { useHttp: data => ({
        ...data, errors: {}, clearErrors() { this.errors = {}; },
        async post(url) {
            requests.push(url);
            maximum = Math.max(maximum, ++active);
            await Promise.resolve();
            active--;
            if (url.includes('/bad/')) {
                this.errors.root = 'Missing folder';
                throw new Error('Check failed');
            }
            return { ok: true };
        },
    }) } };
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/ProjectDependencies.vue', import.meta.url), 'utf8'));
    const component = { exports: {}, require: name => modules[name] ?? {} };
    runInNewContext(ts.transpileModule(compileScript(descriptor, { id: 'dependency-check-test' }).content, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText, component);
    const scope = vue.effectScope();
    t.after(() => scope.stop());
    const state = scope.run(() => component.exports.default.setup(vue.reactive({ project: { id: 'project' }, folders: [
        { id: 'one', path: '/one', package_roots: [root('bad')] },
        { id: 'two', path: '/two', package_roots: [root('good')] },
    ], native: false, busy: false }), { expose() {}, emit: event => emitted.push(event) }));

    await state.checkLocations();

    assert.deepEqual(requests, ['/projects/project/roots/bad/check', '/projects/project/roots/good/check']);
    assert.deepEqual(emitted, ['changed', 'changed']);
    assert.equal(state.checkErrors.value.bad, 'Missing folder');
    assert.equal(state.checking.value, false);
    assert.equal(maximum, 1);
    state.folderId.value = 'two';
    await state.checkLocations();
    assert.equal(requests.length, 3);
    assert.equal(requests[2], '/projects/project/roots/good/check');
});

test('synthetic pnpm store entries do not turn direct packages into transitive findings', () => {
    const item = root('workspace');
    item.snapshot.npm_lockfile = 'pnpm-lock.yaml';
    item.snapshot.files['pnpm-lock.yaml'] = { state: 'Current', entries: [
        { name: 'vue', version: '3.5.20', location: 'node_modules/vue', scope: 'Production' },
        { name: 'vue', version: '3.5.20', location: 'lock:vue@3.5.20', scope: 'Unknown' },
        { name: 'other', version: '1.0.0', location: 'lock:other@1.0.0', scope: 'Unknown' },
    ] };
    const folders = [{ id: 'web', path: '/web', package_roots: [item] }];
    assert.equal(dependencyRows(item.snapshot).length, 2);
    assert.equal(dependencyHealth(folders).issues[0].identity, 'Direct');
    assert.equal(dependencyHealth(folders).issues[0].scope, 'Production');
    assert.equal(dependencyRows(item.snapshot)[1].scope, 'Unknown');
    item.snapshot.files['pnpm-lock.yaml'].entries.push({ name: 'vue', version: '3.5.20', location: 'apps/web/node_modules/vue', scope: 'Development' });
    assert.equal(dependencyRows(item.snapshot).length, 3);
    assert.equal(dependencyHealth(folders).issues[0].identity, 'Direct & Transitive');
    assert.equal(dependencyHealth(folders).issues[0].scope, 'Production, Development');
});
