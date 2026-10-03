import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import * as vue from 'vue';
import ts from 'typescript';

test('folder labels show the last directory for Unix, Windows drive and UNC paths', () => {
    const source = readFileSync(new URL('../resources/js/lib/dependencies.ts', import.meta.url), 'utf8');
    const context = { exports: {} };
    runInNewContext(ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText, context);
    for (const path of ['/Users/mason/Orbit/', 'C:\\Projects\\Orbit\\', '\\\\server\\share\\Orbit']) {
        assert.equal(context.exports.folderName({ path }), 'Orbit');
    }
});

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

test('coverage excludes stale findings and distinguishes unchecked, missing locks and skipped packages', () => {
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
    assert.equal(health.checked, 1);
    assert.equal(health.outdated, 1);
    assert.equal(health.locations[0].state, 'Stale');
    assert.equal(health.locations[1].state, 'Not checked');
    assert.equal(health.locations[2].state, 'Incomplete');
    assert.match(health.locations[2].reason, /Missing file/);
    assert.equal(health.locations[3].state, 'Checked');
    assert.equal(health.total, 4);
    skipped.scan_state = 'Failed';
    assert.equal(dependencyHealth([{ id: 'web', path: '/web', package_roots: [skipped] }]).outdated, 0);
});

test('folders without supported package files are ignored in coverage and findings', () => {
    const docs = root('docs');
    docs.snapshot.files['package.json'].state = 'Missing file';
    docs.snapshot.files['package-lock.json'].state = 'Missing file';
    docs.security.packages = [{ name: 'vue', ecosystem: 'npm', current: '3.5.20', advisories: [advisory('High')] }];
    const app = root('app');
    app.outdated.packages = [];
    const folders = [
        { id: 'web', path: '/web', package_roots: [app, docs] },
        { id: 'docs', path: '/docs', package_roots: [docs] },
        { id: 'notes', path: '/notes', package_roots: [] },
    ];

    const health = dependencyHealth(folders);

    assert.equal(health.total, 1);
    assert.equal(health.checked, 1);
    assert.equal(health.complete, true);
    assert.equal(health.locations[0].root.id, 'app');
    assert.equal(health.issueCount, 0);

    const empty = dependencyHealth(folders.slice(1));

    assert.equal(empty.total, 0);
    assert.equal(empty.locations.length, 0);
    assert.equal(empty.issueCount, 0);
    assert.equal(empty.complete, false);
});

test('empty package files, lock-only sources, malformed files and pending inspections remain visible', () => {
    const item = root('app');
    item.snapshot.files = {
        'package.json': { state: 'Current', entries: [] },
        'package-lock.json': { state: 'Missing file', entries: [] },
    };
    const folders = [{ id: 'web', path: '/web', package_roots: [item] }];

    assert.equal(dependencyHealth(folders).total, 1);

    item.snapshot.files['package.json'].state = 'Missing file';
    item.snapshot.files['package-lock.json'].state = 'Current';
    assert.equal(dependencyHealth(folders).total, 1);

    item.snapshot.files['package-lock.json'].state = 'Missing file';
    for (const state of ['Malformed file', 'Permission denied']) {
        item.snapshot.files['package.json'].state = state;
        const health = dependencyHealth(folders);
        assert.equal(health.total, 1);
        assert.equal(health.locations[0].state, 'Incomplete');
        assert.equal(health.locations[0].reason, `package.json: ${state}.`);
    }

    item.snapshot = null;
    delete item.security;
    delete item.outdated;
    item.scan_state = 'Queued';
    assert.equal(dependencyHealth(folders).total, 1);
    assert.equal(dependencyHealth(folders).locations[0].reason, 'Inspection queued.');
});

test('pending inspections retain matching security and update findings without reviving failed results', () => {
    const item = root('app');
    item.security.packages = [{ name: 'vue', ecosystem: 'npm', current: '3.5.20', advisories: [advisory('High')] }];
    const folder = { id: 'web', path: '/web', availability: 'Available', package_roots: [item] };
    for (const scanState of ['Queued', 'Scanning', 'Current', 'Partial']) {
        item.scan_state = scanState;
        const health = dependencyHealth([folder]);
        assert.equal(health.issueCount, 1);
        assert.equal(health.security, 1);
        assert.equal(health.outdated, 1);
    }
    item.scan_state = 'Scanning';
    item.scan_error = 'Inspection failed';
    assert.equal(dependencyHealth([folder]).issueCount, 0);
    item.scan_error = null;
    folder.availability = 'Missing folder';
    assert.equal(dependencyHealth([folder]).issueCount, 0);
    folder.availability = 'Available';
    item.snapshot.fingerprint = 'changed';
    assert.equal(dependencyHealth([folder]).issueCount, 0);
});

test('skipped packages do not need attention while unavailable checks still do', () => {
    const item = root('app');
    item.security.checked = 12;
    item.security.skipped = 2;
    item.outdated.checked = 3;
    item.outdated.skipped = 1;
    const folders = [{ id: 'web', path: '/web', package_roots: [item] }];

    const health = dependencyHealth(folders);

    assert.equal(health.complete, true);
    assert.equal(health.checked, 1);
    assert.equal(health.locations[0].state, 'Checked');
    assert.equal(health.locations[0].reason, '');
    assert.equal(health.locations.filter(location => !location.complete).length, 0);

    item.outdated.unavailable = 1;
    const unavailable = dependencyHealth(folders);

    assert.equal(unavailable.complete, false);
    assert.equal(unavailable.locations[0].reason, 'Updates: 3 packages checked, 1 unavailable (request failed or response incomplete).');
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

test('release links in findings and reviews open externally on desktop and preserve browser navigation', async t => {
    const source = readFileSync(new URL('../resources/js/components/ProjectDependencies.vue', import.meta.url), 'utf8');
    const { descriptor } = parse(source);
    const requests = []; const errors = []; let finish;
    const request = { url: '', processing: false, post(url) {
        this.processing = true;
        requests.push({ url, data: { url: this.url } });
        return new Promise(resolve => { finish = result => { this.processing = false; resolve(result); }; });
    } };
    const modules = { vue: { ...vue, resolveComponent: name => name }, '@/lib/dependencies': context.exports,
        '@inertiajs/vue3': { useHttp: data => Object.hasOwn(data, 'url') ? request : { ...data, errors: {} } },
        'vue-sonner': { toast: { error: message => errors.push(message) } } };
    const compile = source => {
        const target = { exports: {}, require: name => modules[name] ?? {} };
        runInNewContext(ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText, target);
        return target.exports;
    };
    const scope = vue.effectScope(); t.after(() => scope.stop());
    const props = { project: { id: 'project' }, folders: [], native: false, busy: false };
    const state = scope.run(() => compile(compileScript(descriptor, { id: 'release-test' }).content).default.setup(props, { expose() {}, emit() {} }));
    const anchors = [...source.matchAll(/<a\s+:href="dependencyReleaseUrl\((issue|review)\)"[\s\S]*?<\/a>/g)];
    assert.equal(anchors.length, 2);
    for (const match of anchors) {
        const template = compileTemplate({ source: match[0], filename: 'ProjectDependencies.vue', id: 'release-test' });
        assert.deepEqual(template.errors, []);
        const { render } = compile(template.code);
        for (const [issue, url] of [
            [{ ecosystem: 'npm', name: '@orbit/ui', latest: '1.2.3' }, 'https://www.npmjs.com/package/%40orbit%2Fui/v/1.2.3'],
            [{ ecosystem: 'composer', name: 'laravel/framework', latest: '13.0.0' }, 'https://packagist.org/packages/laravel/framework'],
        ]) {
            const anchor = render({ ...vue.proxyRefs(state), issue, review: issue }, []);
            assert.equal(anchor.props.href, url);
            assert.equal(anchor.props.target, '_blank');
            assert.equal(anchor.props.rel, 'noopener noreferrer');
            props.native = false;
            const browserEvent = new Event('click', { cancelable: true });
            const previousRequests = requests.length;
            await anchor.props.onClick(browserEvent);
            assert.equal(browserEvent.defaultPrevented, false);
            assert.equal(requests.length, previousRequests);
            props.native = true;
            const event = new Event('click', { cancelable: true });
            const opening = anchor.props.onClick(event);
            await anchor.props.onClick(event);
            assert.equal(event.defaultPrevented, true);
            assert.equal(requests.length, previousRequests + 1);
            assert.deepEqual(requests.at(-1), { url: '/projects/project/open-url', data: { url } });
            finish({ opened: true });
            await opening;
        }
    }
    assert.deepEqual(errors, []);
    const issue = { ecosystem: 'npm', name: 'vue', latest: '3.5.30' };
    const failed = state.openRelease(new Event('click', { cancelable: true }), issue);
    finish(null);
    await failed;
    t.mock.method(request, 'post', async () => { throw new Error('Offline'); });
    await state.openRelease(new Event('click', { cancelable: true }), issue);
    assert.deepEqual(errors, ['The package page could not be opened. Try again.', 'The package page could not be opened. Try again.']);
});

test('release links use trusted registries and reject unsupported ecosystems or unsafe names', () => {
    assert.equal(dependencyReleaseUrl({ name: 'laravel/framework', ecosystem: 'composer' }), 'https://packagist.org/packages/laravel/framework');
    assert.equal(dependencyReleaseUrl({ name: '@orbit/ui', ecosystem: 'npm', latest: '1.2.3' }), 'https://www.npmjs.com/package/%40orbit%2Fui/v/1.2.3');
    assert.equal(dependencyReleaseUrl({ name: '../../private', ecosystem: 'npm' }), undefined);
    assert.equal(dependencyReleaseUrl({ name: 'https://evil.test', ecosystem: 'composer' }), undefined);
    assert.equal(dependencyReleaseUrl({ name: 'vue', ecosystem: 'other' }), undefined);
});

function ecosystemRoot(ecosystem, source, files) {
    return {
        id: ecosystem, relative_path: '.', scan_state: 'Current',
        snapshot: { fingerprint: ecosystem, unsupported_lockfiles: [], additional_ecosystems: { [ecosystem]: source }, files },
        outdated: { fingerprint: ecosystem, checked: 1, unavailable: 0, skipped: 0, packages: [] },
        security: { fingerprint: ecosystem, checked: 1, unavailable: 0, skipped: 0, packages: [] },
    };
}

test('additional ecosystems preserve resolved versions, normalized names and explicit lock identities', () => {
    const rows = dependencyRows({ additional_ecosystems: {
        python: { manifest: 'pyproject.toml', lockfile: 'uv.lock' },
        nuget: { manifest: null, lockfile: 'packages.lock.json' },
    }, files: {
        'pyproject.toml': { state: 'Current', entries: [{ name: 'My.Package', required: '>=1', scope: 'Production' }] },
        'uv.lock': { state: 'Current', entries: [
            { name: 'my-package', version: '1.2.0', scope: 'Unknown' },
            { name: 'other', version: '2.0.0', scope: 'Unknown' },
            { name: 'local-project', version: null, scope: 'Unknown', root: true },
        ] },
        'packages.lock.json': { state: 'Current', entries: [
            { name: 'Newtonsoft.Json', version: '13.0.3', scope: 'net8.0', identity: 'Direct' },
            { name: 'Other.Library', version: '1.0.0', scope: 'net8.0', identity: 'Transitive' },
        ] },
    } });

    assert.equal(rows.length, 4);
    assert.equal(rows[0].version, '1.2.0');
    assert.equal(rows[0].required, '>=1');
    assert.equal(rows[0].identity, 'Direct');
    assert.equal(rows[1].identity, 'Transitive');
    assert.equal(rows[2].identity, 'Direct');
    assert.equal(rows[3].identity, 'Transitive');
});

test('additional lock-only sources preserve unresolved and requested constraints', () => {
    const rows = dependencyRows({ additional_ecosystems: {
        maven: { manifest: null, lockfile: 'pom.xml' },
        nuget: { manifest: null, lockfile: 'packages.lock.json' },
        ruby: { manifest: null, lockfile: 'Gemfile.lock' },
    }, files: {
        'pom.xml': { state: 'Current', entries: [{ name: 'org.example:library', required: '${parent.property}', version: null, scope: 'Production' }] },
        'packages.lock.json': { state: 'Current', entries: [{ name: 'Example.Library', required: '[1.0.0, 2.0.0)', version: '1.2.0', scope: 'net8.0' }] },
        'Gemfile.lock': { state: 'Current', entries: [{ name: 'rake', required: '~> 13.0', version: '13.2.1', scope: 'Production' }] },
        'composer.lock': { state: 'Current', entries: [{ name: 'example/library', required: '^1.0', version: '1.2.0', scope: 'Production' }] },
        'package-lock.json': { state: 'Current', entries: [{ name: 'example', required: '^1.0', version: '1.2.0', scope: 'Production' }] },
    } });

    assert.equal(rows.find(row => row.ecosystem === 'maven').required, '${parent.property}');
    assert.equal(rows.find(row => row.ecosystem === 'maven').version, null);
    assert.equal(rows.find(row => row.ecosystem === 'nuget').required, '[1.0.0, 2.0.0)');
    assert.equal(rows.find(row => row.ecosystem === 'ruby').required, '~> 13.0');
    assert.equal(rows.find(row => row.ecosystem === 'composer').required, undefined);
    assert.equal(rows.find(row => row.ecosystem === 'npm').required, undefined);
});

test('standalone manifests and lockfiles have complete coverage and ecosystem issue labels', () => {
    const cases = [
        ['python', 'requirements.txt', 'Requests', 'requests', 'Python', true],
        ['rust', 'Cargo.lock', 'serde', 'serde', 'Cargo', false],
        ['go', 'go.mod', 'golang.org/x/text', 'golang.org/x/text', 'Go', true],
        ['ruby', 'Gemfile.lock', 'rake', 'rake', 'RubyGems', false],
        ['nuget', 'packages.lock.json', 'Newtonsoft.Json', 'newtonsoft.json', 'NuGet', false],
        ['dart', 'pubspec.lock', 'http', 'http', 'Dart', false],
        ['maven', 'pom.xml', 'org.example:library', 'org.example:library', 'Maven', true],
    ];
    for (const [ecosystem, file, name, checkedName, manager, standaloneManifest] of cases) {
        const item = ecosystemRoot(ecosystem, { manifest: standaloneManifest ? file : null, lockfile: file }, {
            [file]: { state: 'Current', entries: [{ name, version: '1.0.0', required: '=1.0.0', scope: 'Production' }] },
        });
        item.outdated.packages = [{ name: checkedName, ecosystem, current: '1.0.0', latest: '1.1.0' }];
        const health = dependencyHealth([{ id: ecosystem, path: `/projects/${ecosystem}`, package_roots: [item] }]);

        assert.equal(dependencyRows(item.snapshot).length, 1, ecosystem);
        assert.equal(health.complete, true, ecosystem);
        assert.equal(health.issues[0].manager, manager, ecosystem);
        assert.equal(health.issues[0].identity, standaloneManifest ? 'Direct' : 'Lock only', ecosystem);
        assert.equal(health.issues[0].scope, 'Production', ecosystem);
    }
});

test('unresolved requirements and ambiguous lock selections remain incomplete without hiding declarations', () => {
    const item = ecosystemRoot('python', { manifest: 'requirements.txt', lockfile: 'requirements.txt' }, {
        'requirements.txt': { state: 'Current', entries: [{ name: 'requests', required: '>=2', scope: 'Production' }] },
    });
    const folders = [{ id: 'python', path: '/python', package_roots: [item] }];

    assert.equal(dependencyRows(item.snapshot).length, 1);
    assert.equal(dependencyRows(item.snapshot)[0].version, undefined);
    assert.equal(dependencyHealth(folders).complete, false);
    assert.match(dependencyHealth(folders).locations[0].reason, /Unresolved version: requests/);

    item.snapshot.additional_ecosystems.python = { manifest: 'pyproject.toml', lockfile: null };
    item.snapshot.files['pyproject.toml'] = { state: 'Current', entries: [{ name: 'requests', required: '>=2', scope: 'Production' }] };
    item.snapshot.files['uv.lock'] = { state: 'Current', entries: [{ name: 'requests', version: '2.0.0', scope: 'Production' }] };
    item.snapshot.files['poetry.lock'] = { state: 'Current', entries: [{ name: 'requests', version: '3.0.0', scope: 'Production' }] };

    assert.equal(dependencyRows(item.snapshot)[0].version, undefined);
    assert.equal(dependencyHealth(folders).complete, false);
    assert.match(dependencyHealth(folders).locations[0].reason, /could not be selected/);
});

test('standalone requirements retain distinct pinned versions for the same package', () => {
    const rows = dependencyRows({ additional_ecosystems: { python: { manifest: 'requirements.txt', lockfile: 'requirements.txt' } }, files: {
        'requirements.txt': { state: 'Current', entries: [
            { name: 'requests', required: '==2.0.0', version: '2.0.0', scope: 'Production' },
            { name: 'requests', required: '==3.0.0', version: '3.0.0', scope: 'Production' },
        ] },
    } });

    assert.equal(rows.length, 2);
    assert.equal(rows[0].version, '2.0.0');
    assert.equal(rows[1].version, '3.0.0');
    assert.equal(rows[1].identity, 'Direct');
});

test('versioned non-registry dependencies retain declaration links and incomplete coverage', () => {
    const item = ecosystemRoot('python', { manifest: 'pyproject.toml', lockfile: 'uv.lock' }, {
        'pyproject.toml': { state: 'Current', entries: [{ name: 'local-package', required: '==1.0.0', link: '../local-package', scope: 'Production' }] },
        'uv.lock': { state: 'Current', entries: [{ name: 'local-package', version: '1.0.0', scope: 'Production' }] },
    });
    const folders = [{ id: 'python', path: '/python', package_roots: [item] }];

    assert.equal(dependencyRows(item.snapshot)[0].version, '1.0.0');
    assert.equal(dependencyRows(item.snapshot)[0].link, '../local-package');
    assert.equal(dependencyHealth(folders).complete, false);
    assert.equal(dependencyHealth(folders).locations[0].reason, 'Non-registry dependency: local-package.');

    delete item.snapshot.files['pyproject.toml'].entries[0].link;
    item.snapshot.files['uv.lock'].entries[0].link = 'git+https://example.test/package';

    assert.equal(dependencyRows(item.snapshot)[0].link, 'git+https://example.test/package');
    assert.equal(dependencyHealth(folders).complete, false);
});

test('incomplete additional checks need attention while ordinary skipped packages remain ignored', () => {
    const item = ecosystemRoot('ruby', { manifest: null, lockfile: 'Gemfile.lock' }, {
        'Gemfile.lock': { state: 'Current', entries: [{ name: 'rake', version: '13.0.0', scope: 'Production' }] },
    });
    item.security.skipped = 10;
    item.security.incomplete = 2;
    item.outdated.incomplete = 1;
    item.outdated.unavailable = 1;

    const health = dependencyHealth([{ id: 'ruby', path: '/ruby', package_roots: [item] }]);

    assert.equal(health.complete, false);
    assert.equal(health.locations[0].state, 'Incomplete');
    assert.equal(health.locations[0].reason, 'Security: 1 packages checked, 2 packages skipped (incomplete coverage). Updates: 1 packages checked, 1 packages skipped (incomplete coverage), 1 unavailable (request failed or response incomplete).');
});

test('POM declared dependency checks expose the unresolved transitive graph limitation', () => {
    const coverage = 'Only declared dependencies; transitive dependencies are not resolved.';
    const item = ecosystemRoot('maven', { manifest: null, lockfile: 'pom.xml', lockfiles: ['pom.xml'] }, {
        'pom.xml': { state: 'Current', entries: [{ name: 'org.example:library', version: '1.0.0', scope: 'Production' }], requirements: { securityCoverage: coverage } },
    });
    const folders = [{ id: 'java', path: '/java', package_roots: [item] }];

    assert.equal(dependencyRows(item.snapshot).length, 1);
    assert.equal(dependencyHealth(folders).complete, false);
    assert.equal(dependencyHealth(folders).locations[0].reason, coverage);

    item.snapshot.additional_ecosystems.maven = { manifest: null, lockfile: 'gradle.lockfile', lockfiles: ['gradle.lockfile'] };
    item.snapshot.files['gradle.lockfile'] = { state: 'Current', entries: [{ name: 'org.example:library', version: '1.0.0', scope: 'compileClasspath' }] };

    assert.equal(dependencyHealth(folders).complete, true);
    assert.equal(dependencyHealth(folders).locations[0].reason, '');
});

test('NuGet findings merge normalized package names while keeping direct identity', () => {
    const item = ecosystemRoot('nuget', { manifest: null, lockfile: 'packages.lock.json' }, {
        'packages.lock.json': { state: 'Current', entries: [{ name: 'Newtonsoft.Json', version: '13.0.3', scope: 'net8.0', identity: 'Direct' }] },
    });
    item.outdated.packages = [{ name: 'newtonsoft.json', ecosystem: 'nuget', current: '13.0.3', latest: '13.0.4' }];
    item.security.packages = [{ name: 'Newtonsoft.Json', ecosystem: 'nuget', current: '13.0.3', advisories: [advisory('High')] }];

    const health = dependencyHealth([{ id: 'dotnet', path: '/dotnet', package_roots: [item] }]);

    assert.equal(health.issueCount, 1);
    assert.equal(health.issues[0].latest, '13.0.4');
    assert.equal(health.issues[0].advisories.length, 1);
    assert.equal(health.issues[0].identity, 'Direct');
});

test('Gradle lockfiles combine findings and report stale or missing source files', () => {
    const item = ecosystemRoot('maven', { manifest: null, lockfile: 'gradle.lockfile', lockfiles: ['gradle.lockfile', 'testRuntimeClasspath.lockfile'] }, {
        'gradle.lockfile': { state: 'Current', entries: [{ name: 'org.example:app', version: '1.0.0', scope: 'compileClasspath' }] },
        'testRuntimeClasspath.lockfile': { state: 'Current', entries: [{ name: 'org.example:test', version: '2.0.0', scope: 'testRuntimeClasspath' }] },
    });
    item.outdated.packages = [{ name: 'org.example:test', ecosystem: 'maven', current: '2.0.0', latest: '3.0.0' }];
    const folders = [{ id: 'java', path: '/java', package_roots: [item] }];

    assert.equal(dependencyRows(item.snapshot).length, 2);
    assert.equal(dependencyHealth(folders).complete, true);
    assert.equal(dependencyHealth(folders).issues[0].manager, 'Gradle');
    assert.equal(dependencyHealth(folders).issues[0].scope, 'testRuntimeClasspath');

    item.snapshot.files['testRuntimeClasspath.lockfile'].state = 'Malformed file';
    assert.equal(dependencyRows(item.snapshot)[0].stale, true);
    assert.equal(dependencyRows(item.snapshot)[1].stale, true);
    assert.equal(dependencyHealth(folders).complete, false);
    assert.match(dependencyHealth(folders).locations[0].reason, /testRuntimeClasspath.lockfile: Malformed file/);

    delete item.snapshot.files['testRuntimeClasspath.lockfile'];
    assert.match(dependencyHealth(folders).locations[0].reason, /testRuntimeClasspath.lockfile: Missing file/);
});

test('additional ecosystem release links use their registries and encode versions safely', () => {
    const cases = [
        ['python', 'requests', 'https://pypi.org/project/requests/1.2.3/'],
        ['rust', 'serde', 'https://crates.io/crates/serde/1.2.3'],
        ['go', 'golang.org/x/text', 'https://pkg.go.dev/golang.org/x/text@1.2.3'],
        ['ruby', 'rake', 'https://rubygems.org/gems/rake/versions/1.2.3'],
        ['nuget', 'Newtonsoft.Json', 'https://www.nuget.org/packages/Newtonsoft.Json/1.2.3'],
        ['dart', 'http', 'https://pub.dev/packages/http/versions/1.2.3'],
        ['maven', 'org.example:library', 'https://central.sonatype.com/artifact/org.example/library/1.2.3'],
    ];
    for (const [ecosystem, name, url] of cases) {
        assert.equal(dependencyReleaseUrl({ ecosystem, name, latest: '1.2.3' }), url);
        assert.equal(dependencyReleaseUrl({ ecosystem, name: '../../private', latest: '1.2.3' }), undefined);
    }
    assert.equal(dependencyReleaseUrl({ ecosystem: 'python', name: 'requests', latest: '1/2' }), 'https://pypi.org/project/requests/1%2F2/');
});

test('checking project locations continues after failures and filters out folders without package files', async t => {
    const requests = [];
    const emitted = [];
    const errors = [];
    let active = 0;
    let maximum = 0;
    let throwFailure = true;
    const modules = { vue, '@/lib/dependencies': context.exports, 'vue-sonner': { toast: { error: message => errors.push(message) } }, '@inertiajs/vue3': { useHttp: data => ({
        ...data, errors: {}, clearErrors() { this.errors = {}; },
        async post(url) {
            requests.push(url);
            maximum = Math.max(maximum, ++active);
            await Promise.resolve();
            active--;
            if (url.includes('/bad/')) {
                this.errors.root = 'Missing folder';
                if (!throwFailure) return null;
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
    const docs = root('docs');
    docs.snapshot.files['package.json'].state = 'Missing file';
    docs.snapshot.files['package-lock.json'].state = 'Missing file';
    const props = vue.reactive({ project: { id: 'project' }, folders: [
        { id: 'one', path: '/one', package_roots: [root('bad')] },
        { id: 'two', path: '/two', package_roots: [root('good')] },
        { id: 'docs', path: '/docs', package_roots: [docs] },
        { id: 'notes', path: '/notes', package_roots: [] },
    ], native: false, busy: false });
    const state = scope.run(() => component.exports.default.setup(props, { expose() {}, emit: event => emitted.push(event) }));

    await state.checkLocations();

    assert.deepEqual(requests, ['/projects/project/roots/bad/check', '/projects/project/roots/good/check']);
    assert.deepEqual(emitted, ['changed', 'changed']);
    assert.deepEqual(errors, ['one: Missing folder']);
    assert.equal(state.checking.value, false);
    assert.equal(maximum, 1);
    assert.equal(state.packageFolders.value.map(folder => folder.id).join(','), 'one,two');
    state.folderId.value = 'two';
    await state.checkLocations();
    assert.equal(requests.length, 3);
    assert.equal(requests[2], '/projects/project/roots/good/check');

    state.folderId.value = 'one';
    throwFailure = false;
    await state.checkLocations();
    assert.equal(requests.length, 4);
    assert.equal(errors.length, 2);
    assert.equal(errors[1], 'one: Missing folder');
    for (const file of Object.values(props.folders[0].package_roots[0].snapshot.files)) file.state = 'Missing file';
    await vue.nextTick();

    assert.equal(state.folderId.value, '');
    assert.equal(state.packageFolders.value.map(folder => folder.id).join(','), 'two');
    assert.equal(state.health.value.total, 1);
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
