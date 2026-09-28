import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as inertia from '@inertiajs/vue3';
import ts from 'typescript';
import * as vue from 'vue';
import { renderToString } from 'vue/server-renderer';

const { outputText: projectScript } = ts.transpileModule(readFileSync(new URL('../resources/js/lib/project.ts', import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS } });
const projectHelpers = { exports: {}, URL };
runInNewContext(projectScript, projectHelpers);

test('board list and card drags follow changes to the reduced motion preference', async () => {
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/ProjectBoard.vue', import.meta.url), 'utf8'));
    const script = compileScript(descriptor, { id: 'board-motion-test', inlineTemplate: true });
    const { outputText } = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const reducedMotion = vue.ref(false);
    const animations = [];
    const passthrough = (_, { slots }) => slots.default?.();
    const controls = new Proxy({ default: passthrough }, { get: (target, name) => target[name] ?? passthrough });
    const modules = {
        vue, '@/lib/utils': { cn: (...values) => twMerge(clsx(values)) }, '@inertiajs/vue3': inertia, '@/lib/appearance': { reducedMotion }, '@/lib/project': projectHelpers.exports,
        'vue-draggable-plus': { VueDraggable: vue.defineComponent({
            inheritAttrs: false,
            props: ['animation'],
            setup: (props, { slots }) => () => { animations.push(props.animation); return slots.default?.(); },
        }) },
    };
    const context = { exports: {}, require: name => modules[name] ?? controls };
    runInNewContext(outputText, context);
    const props = { project: { id: 'project-a', revision: 7, board_columns: [{ id: 'todo', name: 'To Do', tasks: [] }] } };

    for (const [reduced, expected] of [[false, [150, 150]], [true, [0, 0]], [false, [150, 150]]]) {
        reducedMotion.value = reduced;
        animations.length = 0;
        await renderToString(vue.createSSRApp(context.exports.default, props));
        assert.deepEqual(animations, expected);
    }
});

test('board drags save final positions once, preserve server data, and recover after failed saves', async t => {
    // Run the component's setup with real Vue/Inertia state and intercept only the HTTP boundary.
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/ProjectBoard.vue', import.meta.url), 'utf8'));
    const script = compileScript(descriptor, { id: 'board-test' });
    const { outputText } = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const toasts = [];
    const context = { exports: {}, require: name => name === 'vue' ? vue : name === '@inertiajs/vue3' ? inertia : name === '@/lib/project' ? projectHelpers.exports : name === 'vue-sonner' ? { toast: { error: message => toasts.push(message) } } : {} };
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
    requests[1].callbacks.onError({ position: 'This task could not be moved.' });
    requests[1].callbacks.onFinish();
    assert.deepEqual(toasts, ['This task could not be moved.']);
    assert.equal(board.form.hasErrors, false);

    board.startDrag({ data: task }, 'task');
    board.columns.value[0].tasks.reverse();
    board.finishDrag({ from: todo, to: todo, oldDraggableIndex: 0, newDraggableIndex: 1 });
    assert.equal(requests.length, 3);
    assert.equal(requests[2].data.position, 1, 'Downward moves use the final index without adjusting it');
    assert.equal(requests[2].data.column_id, 'todo');
    requests[2].callbacks.onCancel();
    requests[2].callbacks.onFinish();
    assert.equal(JSON.stringify(board.columns.value), saved, 'Cancelled requests restore the saved order');

    board.startDrag({ data: props.project.board_columns[1] }, 'column');
    board.columns.value.reverse();
    board.finishDrag({ from: todo, to: todo, oldDraggableIndex: 1, newDraggableIndex: 0 });
    assert.equal(requests.length, 4);
    assert.equal(requests[3].data.action, 'column.move');
    assert.equal(requests[3].data.id, 'done');
    assert.equal(requests[3].data.position, 0);
    props.project = { ...props.project, revision: 9, board_columns: [...props.project.board_columns].reverse() };
    await vue.nextTick();
    await requests[3].callbacks.onSuccess({});
    requests[3].callbacks.onFinish();
    assert.equal(board.columns.value[0].id, 'done');
    assert.equal(board.columns.value[1].tasks[0].description, '**Keep me**');
    assert.equal(board.columns.value[1].tasks[0].attachments[0].id, 'file-a');
    board.finishDrag({ from: todo, to: todo, oldDraggableIndex: 1, newDraggableIndex: 0 });
    assert.equal(requests.length, 4, 'Repeated end events do not save twice');
});

test('board opens cards, edits list names and colours, and resets new-list defaults', async t => {
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/ProjectBoard.vue', import.meta.url), 'utf8'));
    const script = compileScript(descriptor, { id: 'board-inline-test' });
    const { outputText } = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const input = { focus() {}, select() {} };
    const context = { exports: {}, document: { getElementById: () => input }, require: name => name === 'vue' ? vue : name === '@inertiajs/vue3' ? inertia : name === '@/lib/project' ? projectHelpers.exports : {} };
    runInNewContext(outputText, context);
    const requests = [];
    t.mock.method(inertia.router, 'post', (url, data, callbacks) => {
        callbacks.onStart();
        requests.push({ url, data, callbacks });
    });
    const task = { id: 'task-a', title: 'Write notes', description: '', attachments: [] };
    const column = { id: 'todo', name: 'To Do', color: 'purple', tasks: [task] };
    const props = vue.reactive({ project: { id: 'project-a', revision: 7, board_columns: [column] } });
    const scope = vue.effectScope();
    t.after(() => scope.stop());
    const board = scope.run(() => context.exports.default.setup(props, { expose() {} }));

    board.openTaskCard({ target: { closest: () => null } }, column, task);
    assert.equal(board.editor.value, 'task');
    assert.equal(board.form.id, 'task-a');
    board.editor.value = null;
    board.openTaskCard({ target: { closest: () => ({}) } }, column, task);
    assert.equal(board.editor.value, null, 'Links and buttons keep their own action');

    board.editColumn(column);
    assert.equal(board.dialogTitle.value, 'Edit list');
    board.form.name = 'Ready';
    board.submit();
    assert.equal(requests.length, 1);
    assert.equal(requests[0].url, '/projects/project-a/board');
    assert.equal(requests[0].data.action, 'column.save');
    assert.equal(requests[0].data.id, 'todo');
    assert.equal(requests[0].data.name, 'Ready');
    assert.equal(requests[0].data.color, 'purple');
    await requests[0].callbacks.onSuccess({});
    requests[0].callbacks.onFinish();
    assert.equal(board.editor.value, null);

    props.project.revision = 8;
    board.editColumn();
    assert.equal(board.form.id, null, 'Adding a list does not rename the previously saved list');
    assert.equal(board.form.name, '', 'New list names start blank');
    assert.equal(board.form.color, 'gray', 'New lists do not inherit the renamed list colour');
    board.form.name = 'Review';
    board.form.color = 'red';
    board.submit();
    assert.equal(requests[1].data.action, 'column.save');
    assert.equal(requests[1].data.id, null);
    assert.equal(requests[1].data.name, 'Review');
    assert.equal(requests[1].data.color, 'red');
    assert.equal(requests[1].data.revision, 8);
    await requests[1].callbacks.onSuccess({});
    requests[1].callbacks.onFinish();
    board.editColumn();
    assert.equal(board.form.color, 'gray', 'A successful add does not change the next list default');

    props.project.revision = 9;
    board.editColumn(column);
    assert.equal(board.dialogTitle.value, 'Edit list');
    assert.equal(board.form.id, 'todo');
    assert.equal(board.form.name, 'To Do');
    assert.equal(board.form.color, 'purple');
    board.form.color = 'green';
    board.submit();
    assert.equal(requests[2].data.action, 'column.save');
    assert.equal(requests[2].data.id, 'todo', 'Colour changes update the existing list');
    assert.equal(requests[2].data.name, 'To Do');
    assert.equal(requests[2].data.color, 'green');
    assert.equal(requests[2].data.revision, 9);
    board.editColumn();
    assert.equal(board.form.id, 'todo', 'An in-flight colour edit cannot be replaced');
    props.project = { ...props.project, revision: 10, board_columns: [{ ...column, color: 'green' }] };
    await requests[2].callbacks.onSuccess({});
    requests[2].callbacks.onFinish();
    assert.equal(board.columns.value[0].color, 'green');
    assert.equal(board.columns.value[0].tasks[0].title, 'Write notes');
    board.editColumn();
    assert.equal(board.dialogTitle.value, 'New list');
    assert.equal(board.form.id, null);
    assert.equal(board.form.name, '');
    assert.equal(board.form.color, 'gray');
});

test('task deep links open once and leave the board closed after remount or reload', async t => {
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/ProjectBoard.vue', import.meta.url), 'utf8'));
    const script = compileScript(descriptor, { id: 'board-task-link-test' });
    const { outputText } = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const page = vue.reactive({ url: '/projects/project-a?tab=board&task=task-a&filter=mine' });
    const replacements = [];
    const context = { exports: {}, URL, require: name => name === 'vue' ? vue : name === '@inertiajs/vue3' ? { ...inertia, usePage: () => page } : name === '@/lib/project' ? projectHelpers.exports : {} };
    runInNewContext(outputText, context);
    t.mock.method(inertia.router, 'replace', options => {
        replacements.push(options);
        page.url = options.url;
    });
    const task = { id: 'task-a', title: 'Write notes', description: '', attachments: [] };
    const column = { id: 'todo', name: 'To Do', tasks: [task] };
    const project = { id: 'project-a', revision: 7, board_columns: [column] };
    const mount = () => {
        const scope = vue.effectScope();
        t.after(() => scope.stop());
        const props = vue.reactive({ project, targetTaskId: new URL(page.url, 'http://orbit.local').searchParams.get('task') });
        return { board: scope.run(() => context.exports.default.setup(props, { expose() {} })), unmount: () => scope.stop() };
    };

    const linked = mount();
    assert.equal(linked.board.editor.value, 'task');
    assert.equal(linked.board.form.id, 'task-a');
    assert.equal(replacements.length, 1);
    assert.equal(replacements[0].url, '/projects/project-a?tab=board&filter=mine');
    linked.unmount();

    const switchedBack = mount();
    assert.equal(switchedBack.board.editor.value, null, 'Returning to Board does not reopen the task');
    switchedBack.unmount();

    const reloaded = mount();
    assert.equal(reloaded.board.editor.value, null, 'Reloading the cleaned URL does not reopen the task');
    reloaded.board.openTaskCard({ target: { closest: () => null } }, column, task);
    assert.equal(reloaded.board.editor.value, 'task', 'Clicking the card still opens the editor');
});

test('quick-add keeps drafts on errors, uses the latest revision, and stays ready for another card', async t => {
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/ProjectBoard.vue', import.meta.url), 'utf8'));
    const script = compileScript(descriptor, { id: 'board-quick-add-test' });
    const { outputText } = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    let focused = 0;
    const context = { exports: {}, document: { getElementById: () => ({ focus: () => focused++ }) }, require: name => name === 'vue' ? vue : name === '@inertiajs/vue3' ? inertia : name === '@/lib/project' ? projectHelpers.exports : {} };
    runInNewContext(outputText, context);
    const requests = [];
    t.mock.method(inertia.router, 'post', (url, data, callbacks) => {
        callbacks.onStart();
        requests.push({ url, data, callbacks });
    });
    t.mock.method(inertia.router, 'reload', options => options.onSuccess());
    const task = { id: 'task-a', title: 'Write notes', description: 'Monitor uptime', attachments: [{ id: 'file-a', name: 'deploy.sh' }] };
    const column = { id: 'todo', name: 'To Do', tasks: [task] };
    const props = vue.reactive({ project: { id: 'project-a', revision: 7, board_columns: [column] } });
    const scope = vue.effectScope();
    t.after(() => scope.stop());
    const board = scope.run(() => context.exports.default.setup(props, { expose() {} }));

    for (const query of ['NOTES', 'uptime', 'deploy.sh']) {
        board.query.value = query;
        assert.equal(board.matchingTaskIds.value.has('task-a'), true);
    }
    board.query.value = 'unknown';
    assert.equal(board.matchingTaskIds.value.size, 0);
    await board.openQuickAdd(column);
    assert.equal(board.query.value, '');
    assert.equal(focused, 1);
    board.saveQuickAdd();
    assert.equal(requests.length, 0, 'Blank titles are rejected without a request');
    assert.equal(board.quickAdd.errors.title, 'Enter a card title.');

    board.quickAdd.title = '  Keep this draft  ';
    props.project.revision = 8;
    board.saveQuickAdd();
    assert.equal(requests[0].url, '/projects/project-a/board');
    assert.equal(requests[0].data.action, 'task.save');
    assert.equal(requests[0].data.column_id, 'todo');
    assert.equal(requests[0].data.title, 'Keep this draft');
    assert.equal(requests[0].data.revision, 8);
    assert.equal(requests[0].data._method, 'put');
    board.saveQuickAdd();
    board.cancelQuickAdd();
    assert.equal(requests.length, 1, 'Saving cannot be duplicated');
    assert.equal(board.quickAddColumnId.value, 'todo', 'An in-flight draft cannot be discarded');
    requests[0].callbacks.onError({ revision: 'This project changed.' });
    requests[0].callbacks.onFinish();
    assert.equal(board.quickAdd.title, '  Keep this draft  ');
    assert.equal(board.quickAdd.errors.revision, 'This project changed.');
    board.reload();
    assert.equal(board.quickAdd.title, '  Keep this draft  ', 'Reloading preserves the draft');
    assert.equal(board.quickAdd.hasErrors, false);

    props.project.revision = 9;
    board.saveQuickAdd();
    assert.equal(requests[1].data.revision, 9);
    await requests[1].callbacks.onSuccess({});
    requests[1].callbacks.onFinish();
    await vue.nextTick();
    assert.equal(board.quickAdd.title, '');
    assert.equal(board.quickAddColumnId.value, 'todo', 'The composer stays open for another card');
    board.quickAdd.title = 'Another draft';
    board.editTask(column, task);
    assert.equal(board.form.title, 'Write notes');
    assert.equal(board.quickAdd.title, 'Another draft', 'Card details use a separate form');
    board.saveQuickAdd();
    assert.equal(requests.length, 2, 'The background composer cannot submit behind card details');
    board.editor.value = null;
    board.cancelQuickAdd();
    assert.equal(board.quickAddColumnId.value, null);
    assert.equal(board.quickAdd.title, '');
    board.editTask(column, task);
    board.form.title = 'Unsaved card';
    board.form.description = 'Unsaved body';
    board.form.attachments = [{ name: 'new.txt' }];
    board.form.removed_attachment_ids = ['file-a'];
    props.project.revision = 10;
    board.form.setError('revision', 'Conflict');
    board.reload();
    assert.equal(board.editor.value, 'task');
    assert.equal(board.form.title, 'Unsaved card');
    assert.equal(board.form.description, 'Unsaved body');
    assert.equal(board.form.attachments[0].name, 'new.txt');
    assert.equal(board.form.removed_attachment_ids[0], 'file-a');
    assert.equal(board.form.revision, 10);
    props.project = { ...props.project, board_columns: [{ ...column, tasks: [] }] };
    await vue.nextTick();
    board.reload();
    assert.match(board.form.errors.id, /removed/);
    board.submit();
    assert.equal(requests.length, 2, 'A removed target cannot be recreated silently');
});

test('dropping a card does not open its details and cards cannot open during a save', t => {
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/ProjectBoard.vue', import.meta.url), 'utf8'));
    const script = compileScript(descriptor, { id: 'board-drag-click-test' });
    const { outputText } = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    let now = 1000;
    const context = { exports: {}, Date: { now: () => now }, require: name => name === 'vue' ? vue : name === '@inertiajs/vue3' ? inertia : name === '@/lib/project' ? projectHelpers.exports : {} };
    runInNewContext(outputText, context);
    const task = { id: 'task-a', title: 'Write notes', description: '', attachments: [] };
    const column = { id: 'todo', name: 'To Do', tasks: [task] };
    const scope = vue.effectScope();
    t.after(() => scope.stop());
    const board = scope.run(() => context.exports.default.setup({ project: { id: 'project-a', revision: 7, board_columns: [column] } }, { expose() {} }));
    const click = { target: { closest: () => null } };
    const list = { dataset: { columnId: 'todo' } };

    board.startDrag({ data: task }, 'task');
    board.openTaskCard(click, column, task);
    assert.equal(board.editor.value, null);
    board.finishDrag({ from: list, to: list, oldDraggableIndex: 0, newDraggableIndex: 0 });
    board.openTaskCard(click, column, task);
    assert.equal(board.editor.value, null, 'The release click after a drop is ignored');
    now = 1300;
    board.quickAdd.processing = true;
    board.editTask(column, task);
    assert.equal(board.editor.value, null, 'Keyboard activation also respects the save lock');
    board.quickAdd.processing = false;
    board.openTaskCard(click, column, task);
    assert.equal(board.editor.value, 'task');
});

test('card menu moves append to the chosen list and deletion waits for confirmation', async t => {
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/ProjectBoard.vue', import.meta.url), 'utf8'));
    const script = compileScript(descriptor, { id: 'board-card-menu-test' });
    const { outputText } = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const context = { exports: {}, require: name => name === 'vue' ? vue : name === '@inertiajs/vue3' ? inertia : name === '@/lib/project' ? projectHelpers.exports : {} };
    runInNewContext(outputText, context);
    const requests = [];
    t.mock.method(inertia.router, 'post', (url, data, callbacks) => {
        callbacks.onStart();
        requests.push({ url, data, callbacks });
    });
    const task = { id: 'task-a', title: 'Review deployment', description: '**Keep this**', attachments: [{ id: 'file-a', name: 'deploy.sh' }] };
    const source = { id: 'todo', name: 'To Do', tasks: [task] };
    const destination = { id: 'done', name: 'Done', tasks: [{ id: 'task-b', title: 'Already done', description: '', attachments: [] }] };
    const props = vue.reactive({ project: { id: 'project-a', revision: 7, board_columns: [source, destination] } });
    const saved = JSON.stringify(props.project.board_columns);
    const scope = vue.effectScope();
    t.after(() => scope.stop());
    const board = scope.run(() => context.exports.default.setup(props, { expose() {} }));
    board.quickAdd.title = 'Unfinished draft';

    board.moveTask(source, task, source);
    assert.equal(requests.length, 0, 'Moving to the current list is a no-op');
    props.project.revision = 8;
    board.cardMenuTaskId.value = task.id;
    board.openTaskCard({ target: { closest: () => null } }, source, task);
    assert.equal(board.editor.value, null, 'Right-click or long-press release does not open details underneath the menu');
    board.moveTask(source, task, destination);
    assert.equal(requests[0].url, '/projects/project-a/board');
    assert.equal(requests[0].data.action, 'task.move');
    assert.equal(requests[0].data.id, 'task-a');
    assert.equal(requests[0].data.column_id, 'done');
    assert.equal(requests[0].data.position, 1, 'Menu moves append after the existing cards');
    assert.equal(requests[0].data.revision, 8);
    assert.equal(requests[0].data._method, 'put');
    assert.equal(JSON.stringify(props.project.board_columns), saved, 'The original card details remain intact');
    assert.equal(board.quickAdd.title, 'Unfinished draft');
    board.moveTask(source, task, destination);
    board.deleteTask(source, task);
    assert.equal(requests.length, 1, 'Menu actions cannot overlap an in-flight move');
    assert.equal(board.editor.value, null);
    await requests[0].callbacks.onSuccess({});
    requests[0].callbacks.onFinish();
    assert.equal(board.cardMenuTaskId.value, null);

    board.editColumn();
    assert.equal(board.form.id, null, 'Adding a list does not reuse the moved card ID');
    assert.equal(board.form.column_id, '', 'A new action clears the previous move destination');
    board.editor.value = null;

    board.deleteTask(source, task);
    assert.equal(board.editor.value, 'delete-task');
    assert.equal(board.form.id, 'task-a');
    assert.equal(board.form.title, 'Review deployment');
    assert.equal(requests.length, 1, 'Selecting Delete only opens its confirmation');
    board.editor.value = null;
    assert.equal(requests.length, 1, 'Cancelling confirmation leaves the card untouched');
    board.deleteTask(source, task);
    board.submit();
    assert.equal(requests[1].data.action, 'task.delete');
    assert.equal(requests[1].data.id, 'task-a');
    assert.equal(requests[1].data.revision, 8);
    requests[1].callbacks.onCancel();
    requests[1].callbacks.onFinish();
    assert.equal(JSON.stringify(board.columns.value), saved, 'A cancelled request keeps the saved board');
});

test('keyboard ordering respects boundaries and busy state, submits exact positions and announces conflicts', async t => {
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/ProjectBoard.vue', import.meta.url), 'utf8'));
    const { outputText } = ts.transpileModule(compileScript(descriptor, { id: 'keyboard-board' }).content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const context = { exports: {}, require: name => name === 'vue' ? vue : name === '@inertiajs/vue3' ? inertia : name === 'vue-sonner' ? { toast: { error() {} } } : {} };
    runInNewContext(outputText, context);
    const requests = [];
    t.mock.method(inertia.router, 'post', (url, data, callbacks) => { callbacks.onStart(); requests.push({ data, callbacks }); });
    const scope = vue.effectScope(); t.after(() => scope.stop());
    const props = vue.reactive({ project: { id: 'one', revision: 8, board_columns: [
        { id: 'left', tasks: [{ id: 'first' }, { id: 'second' }] }, { id: 'right', tasks: [] },
    ] } });
    const board = scope.run(() => context.exports.default.setup(props, { expose() {} }));
    const left = board.columns.value[0], right = board.columns.value[1];
    board.reorderList(left, -1); board.reorderList(right, 1); board.reorderCard(left, left.tasks[0], -1);
    assert.equal(requests.length, 0);
    board.reorderList(left, 1); board.reorderCard(left, left.tasks[0], 1);
    assert.equal(requests.length, 1); assert.equal(requests[0].data.action, 'column.move'); assert.equal(requests[0].data.position, 1); assert.equal(requests[0].data.revision, 8);
    requests[0].callbacks.onSuccess({}); await requests[0].callbacks.onFinish();
    assert.match(board.announcement.value, /Order saved/);
    board.reorderCard(left, left.tasks[0], 1);
    assert.equal(requests[1].data.action, 'task.move'); assert.equal(requests[1].data.column_id, 'left'); assert.equal(requests[1].data.position, 1);
    requests[1].callbacks.onError({ revision: 'Board changed. Reload.' }); await requests[1].callbacks.onFinish();
    assert.equal(board.announcement.value, 'Board changed. Reload.'); assert.equal(board.form.errors.revision, 'Board changed. Reload.');
});
