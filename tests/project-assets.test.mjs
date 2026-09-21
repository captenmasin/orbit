import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as inertia from '@inertiajs/vue3';
import ts from 'typescript';
import * as vue from 'vue';

test('asset filters use detected types, include every file, and update after uploads and removal', t => {
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/ProjectAssets.vue', import.meta.url), 'utf8'));
    const script = compileScript(descriptor, { id: 'assets-test' });
    const { outputText } = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const modules = { vue, '@inertiajs/vue3': inertia };
    const context = { exports: {}, require: name => modules[name] ?? {} };
    runInNewContext(outputText, context);
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
    const scope = vue.effectScope();
    t.after(() => scope.stop());
    const state = scope.run(() => context.exports.default.setup(props, { expose() {} }));

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
