import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as inertia from '@inertiajs/vue3';
import ts from 'typescript';
import * as vue from 'vue';

function mount(t, project) {
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/ProjectAssets.vue', import.meta.url), 'utf8'));
    const script = compileScript(descriptor, { id: 'assets-test' });
    const { outputText } = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const modules = { vue, '@inertiajs/vue3': inertia };
    const context = { exports: {}, require: name => modules[name] ?? {} };
    runInNewContext(outputText, context);
    const props = vue.reactive({ project });
    const scope = vue.effectScope();
    t.after(() => scope.stop());
    return { props, state: scope.run(() => context.exports.default.setup(props, { expose() {} })) };
}

test('asset filters use detected types, include every file, and update after uploads and removal', t => {
    const props = vue.reactive({ project: { id: 'project', revision: 1, assets: [
        { name: 'photo.png', mime_type: 'image/png' },
        { name: 'scan.tiff', mime_type: 'image/tiff', preview_url: null },
        { name: 'logo', mime_type: 'image/svg+xml' },
        { name: 'actually-text.png', mime_type: 'text/plain' },
        { name: 'brief.pdf', mime_type: 'application/pdf' },
        { name: 'proposal.docx', mime_type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' },
        { name: 'budget.xlsx', mime_type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' },
        { name: 'notes.odt', mime_type: 'application/vnd.oasis.opendocument.text' },
        { name: 'data.json', mime_type: 'application/json' },
        { name: 'recording.mp3', mime_type: 'audio/mpeg' },
        { name: 'demo.mp4', mime_type: 'video/mp4' },
        { name: 'bundle.zip', mime_type: 'application/zip' },
        { name: 'backup.tar.gz', mime_type: 'application/gzip' },
        { name: 'unknown.bin', mime_type: 'application/octet-stream' },
        { name: 'missing.png', mime_type: null },
    ] } });
    const { state } = mount(t, props.project);

    assert.equal(state.filteredAssets.value.length, 15);
    assert.deepEqual(Object.fromEntries(state.typeFilters.value.map(filter => [filter.type, filter.count])), {
        Images: 3, Documents: 6, Audio: 1, Video: 1, Archives: 2, Other: 2,
    });
    for (const [type, names] of Object.entries({
        Images: ['photo.png', 'scan.tiff', 'logo'],
        Documents: ['actually-text.png', 'brief.pdf', 'proposal.docx', 'budget.xlsx', 'notes.odt', 'data.json'],
        Audio: ['recording.mp3'], Video: ['demo.mp4'], Archives: ['bundle.zip', 'backup.tar.gz'], Other: ['unknown.bin', 'missing.png'],
    })) {
        state.selectedType.value = type;
        assert.deepEqual(Array.from(state.filteredAssets.value, file => file.name), names);
    }

    state.selectedType.value = 'Documents';
    props.project.assets = [{ name: 'photo.png', mime_type: 'image/png' }];
    assert.equal(state.filteredAssets.value.length, 0);
    assert.equal(state.typeFilters.value.find(filter => filter.type === 'Documents').count, 0);
    props.project.assets.push({ name: 'new.pdf', mime_type: 'application/pdf' });
    assert.deepEqual(Array.from(state.filteredAssets.value, file => file.name), ['new.pdf']);
    state.selectedType.value = '';
    assert.deepEqual(Array.from(state.filteredAssets.value, file => file.name), ['photo.png', 'new.pdf']);
    props.project.assets = undefined;
    assert.equal(state.filteredAssets.value.length, 0);
});

test('asset folders scope filters and upload and move destinations, and return to Assets when removed', async t => {
    const { props, state } = mount(t, { id: 'project', revision: 3,
        asset_folders: [{ id: 'logos', name: 'logo' }, { id: 'empty', name: 'Empty' }],
        assets: [
            { id: 'root', name: 'legacy.txt', mime_type: 'text/plain' },
            { id: 'logo', name: 'logo.svg', mime_type: 'image/svg+xml', folder_id: 'logos' },
            { id: 'brief', name: 'brief.pdf', mime_type: 'application/pdf', folder_id: 'logos' },
        ],
    });
    assert.deepEqual(Array.from(state.filteredAssets.value, file => file.name), ['legacy.txt']);
    state.selectedFolder.value = 'logos';
    await vue.nextTick();
    assert.deepEqual(Array.from(state.filteredAssets.value, file => file.name), ['logo.svg', 'brief.pdf']);
    assert.equal(state.typeFilters.value.find(filter => filter.type === 'Images').count, 1);
    state.selectedType.value = 'Documents';
    assert.deepEqual(Array.from(state.filteredAssets.value, file => file.name), ['brief.pdf']);

    props.project.revision = 4;
    let upload;
    t.mock.method(inertia.router, 'post', (url, data) => { upload = { url, data }; });
    state.submit();
    assert.equal(upload.data.folder_id, 'logos');
    assert.equal(upload.data.revision, 4);
    let move;
    t.mock.method(inertia.router, 'put', (url, data) => { move = { url, data }; });
    state.move({ id: 'logo' }, '');
    assert.equal(move.url, '/projects/project/assets/logo');
    assert.equal(move.data.folder_id, null);

    state.selectedFolder.value = 'empty';
    await vue.nextTick();
    assert.equal(state.selectedType.value, '');
    assert.equal(state.filteredAssets.value.length, 0);
    props.project.asset_folders = [{ id: 'logos', name: 'logo' }];
    await vue.nextTick();
    assert.equal(state.selectedFolder.value, '');
    assert.deepEqual(Array.from(state.filteredAssets.value, file => file.name), ['legacy.txt']);
});

test('folder drops retain nested and empty directories, and internal drags move assets', async t => {
    const { state } = mount(t, { id: 'project', revision: 4,
        asset_folders: [{ id: 'source', name: 'Source', parent_id: null }, { id: 'target', name: 'Target', parent_id: null }],
        assets: [{ id: 'file', name: 'notes.txt', folder_id: 'source', mime_type: 'text/plain' }],
    });
    let upload;
    let move;
    t.mock.method(inertia.router, 'post', (url, data) => { upload = { url, data }; });
    t.mock.method(inertia.router, 'put', (url, data) => { move = { url, data }; });

    const file = { name: 'logo.svg' };
    const fileEntry = { name: 'logo.svg', isDirectory: false, file: resolve => resolve(file) };
    const emptyEntry = { name: 'Empty', isDirectory: true, createReader: () => ({ readEntries: resolve => resolve([]) }) };
    let reads = 0;
    const folderEntry = { name: 'Brand', isDirectory: true, createReader: () => ({ readEntries: resolve => resolve(reads++ === 0 ? [fileEntry, emptyEntry] : []) }) };
    await state.dropOn({ dataTransfer: { getData: () => '', items: [{ kind: 'file', webkitGetAsEntry: () => folderEntry }], files: [] } }, 'target');
    assert.equal(upload.url, '/projects/project/assets');
    assert.equal(upload.data.folder_id, 'target');
    assert.deepEqual(Array.from(upload.data.paths), ['Brand/logo.svg']);
    assert.deepEqual(Array.from(upload.data.directories), ['Brand', 'Brand/Empty']);
    assert.equal(upload.data.files[0].name, file.name);

    await state.dropOn({ dataTransfer: { getData: () => JSON.stringify({ projectId: 'project', kind: 'file', id: 'file' }) } }, 'target');
    assert.equal(move.url, '/projects/project/assets/file');
    assert.equal(move.data.folder_id, 'target');
    await state.dropOn({ dataTransfer: { getData: () => JSON.stringify({ projectId: 'project', kind: 'folder', id: 'source' }) } }, 'target');
    assert.equal(move.url, '/projects/project/asset-folders/source');
    assert.equal(move.data.parent_id, 'target');
});
