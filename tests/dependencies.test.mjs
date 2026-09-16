import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

test('dependency rows distinguish direct requirements, transitive versions, aliases, links and stale values', () => {
    const source = readFileSync(new URL('../resources/js/lib/dependencies.ts', import.meta.url), 'utf8');
    const context = { exports: {} };
    runInNewContext(ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText, context);
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
