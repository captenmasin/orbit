import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as inertia from '@inertiajs/vue3';
import ts from 'typescript';
import * as vue from 'vue';

test('board drags save final positions once, preserve server data, and recover after failed saves', async t => {
    // Run the component's setup with real Vue/Inertia state and intercept only the HTTP boundary.
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/ProjectBoard.vue', import.meta.url), 'utf8'));
    const script = compileScript(descriptor, { id: 'board-test' });
    const { outputText } = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const context = { exports: {}, require: name => name === 'vue' ? vue : name === '@inertiajs/vue3' ? inertia : {} };
    runInNewContext(outputText, context);
    const requests = [];
    t.mock.method(inertia.router, 'post', (url, data, callbacks) => {
        callbacks.onStart();
        requests.push({ url, data, callbacks });
    });
    const task = { id: 'task-a', board_column_id: 'todo', position: 0, description: '**Keep me**', attachments: [{ id: 'file-a' }] };
    const props = vue.reactive({ project: { id: 'project-a', revision: 7, board_columns: [
        { id: 'todo', position: 0, tasks: [task, { id: 'task-b', position: 1 }] },
        { id: 'done', position: 1, tasks: [] },
    ] } });
    const saved = JSON.stringify(props.project.board_columns);
    const scope = vue.effectScope();
    t.after(() => scope.stop());
    const board = scope.run(() => context.exports.default.setup(props, { expose() {} }));
    const todo = { dataset: { columnId: 'todo' } };
    const done = { dataset: { columnId: 'done' } };

    for (const newDraggableIndex of [0, undefined]) {
        board.startDrag({ data: task }, 'task');
        board.finishDrag({ from: todo, to: todo, oldDraggableIndex: 0, newDraggableIndex });
    }
    assert.equal(requests.length, 0, 'Unchanged and cancelled drops do not write');

    board.startDrag({ data: task }, 'task');
    props.project.revision = 8;
    board.columns.value[1].tasks.push(board.columns.value[0].tasks.shift());
    board.finishDrag({ from: todo, to: done, oldDraggableIndex: 0, newDraggableIndex: 0 });

    assert.equal(requests.length, 1);
    const move = requests[0];
    assert.equal(move.url, '/projects/project-a/board');
    assert.equal(move.data._method, 'put');
    assert.equal(move.data.action, 'task.move');
    assert.equal(move.data.id, 'task-a');
    assert.equal(move.data.column_id, 'done');
    assert.equal(move.data.position, 0);
    assert.equal(move.data.revision, 7, 'A drag keeps the revision from when it started');
    assert.equal(JSON.stringify(props.project.board_columns), saved, 'Optimistic moves never mutate Inertia props');
    assert.equal(board.form.processing, true);
    move.callbacks.onError({ revision: 'This project changed.' });
    move.callbacks.onFinish();
    assert.equal(JSON.stringify(board.columns.value), saved);
    assert.equal(board.form.errors.revision, 'This project changed.');

    board.startDrag({ data: task }, 'task');
    board.columns.value[0].tasks.reverse();
    board.finishDrag({ from: todo, to: todo, oldDraggableIndex: 0, newDraggableIndex: 1 });
    assert.equal(requests.length, 2);
    assert.equal(requests[1].data.position, 1, 'Downward moves use the final index without adjusting it');
    assert.equal(requests[1].data.column_id, 'todo');
    requests[1].callbacks.onCancel();
    requests[1].callbacks.onFinish();
    assert.equal(JSON.stringify(board.columns.value), saved, 'Cancelled requests restore the saved order');

    board.startDrag({ data: props.project.board_columns[1] }, 'column');
    board.columns.value.reverse();
    board.finishDrag({ from: todo, to: todo, oldDraggableIndex: 1, newDraggableIndex: 0 });
    assert.equal(requests.length, 3);
    assert.equal(requests[2].data.action, 'column.move');
    assert.equal(requests[2].data.id, 'done');
    assert.equal(requests[2].data.position, 0);
    props.project = { ...props.project, revision: 9, board_columns: [...props.project.board_columns].reverse() };
    await vue.nextTick();
    await requests[2].callbacks.onSuccess({});
    requests[2].callbacks.onFinish();
    assert.equal(board.columns.value[0].id, 'done');
    assert.equal(board.columns.value[1].tasks[0].description, '**Keep me**');
    assert.equal(board.columns.value[1].tasks[0].attachments[0].id, 'file-a');
    assert.equal(board.announcement.value, 'Moved');
    board.finishDrag({ from: todo, to: todo, oldDraggableIndex: 1, newDraggableIndex: 0 });
    assert.equal(requests.length, 3, 'Repeated end events do not save twice');
});
