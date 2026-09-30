import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

function harness(reduced = false) {
    const reducedMotion = { value: reduced };
    const { outputText } = ts.transpileModule(readFileSync(new URL('../resources/js/lib/menu-selection.ts', import.meta.url), 'utf8'),
        { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } });
    const context = { exports: {}, require: () => ({ reducedMotion }) };
    runInNewContext(outputText, context);
    const { blinkMenuSelection } = context.exports;
    const menu = { dataset: { state: 'open' } };
    const animations = [];
    const actions = [];
    function item(name) {
        return {
            isConnected: true, disabled: false,
            closest: () => menu,
            hasAttribute() { return this.disabled; },
            animate(frames, options) {
                const animation = { ...Promise.withResolvers(), frames, options };
                animations.push(animation);
                return { finished: animation.promise };
            },
            click() {
                const { event } = select(this);
                if (!event.defaultPrevented) actions.push(name);
            },
        };
    }
    function select(item, options = {}) {
        const event = new Event('click', { cancelable: true });
        Object.defineProperties(event, { currentTarget: { value: item }, button: { value: 0 } });
        Object.assign(event, options);
        return { event, finished: blinkMenuSelection(event) };
    }
    return { item, select, animations, actions, menu };
}

test('selection waits for one blink, blocks repeated selections and replays the action once', async () => {
    const h = harness();
    const first = h.item('first');
    const selected = h.select(first);
    h.select(first);
    h.select(h.item('second'));

    assert.equal(selected.event.defaultPrevented, true);
    assert.equal(h.animations.length, 1);
    assert.deepEqual(h.actions, []);
    assert.equal(h.animations[0].options.duration, 180);
    assert.equal(h.animations[0].options.easing, 'steps(2, jump-none)');
    h.animations[0].resolve();
    await selected.finished;
    assert.deepEqual(h.actions, ['first']);
    assert.equal(h.animations.length, 1);
});

test('reduced motion, modified links and disabled items keep their existing behavior', async () => {
    for (const options of [{ reduced: true }, { metaKey: true }, { ctrlKey: true }, { shiftKey: true }, { altKey: true }, { disabled: true }]) {
        const h = harness(options.reduced);
        const item = h.item('item');
        item.disabled = options.disabled;

        const selected = h.select(item, options);
        await selected.finished;

        assert.equal(selected.event.defaultPrevented, false);
        assert.equal(h.animations.length, 0);
    }
});

test('a dismissed menu, unmounted item or newly disabled action never runs after its blink', async () => {
    for (const change of [(h) => { h.menu.dataset.state = 'closed'; }, (_, item) => { item.isConnected = false; }, (_, item) => { item.disabled = true; }]) {
        const h = harness();
        const item = h.item('item');
        const selected = h.select(item);

        change(h, item);
        h.animations[0].resolve();
        await selected.finished;

        assert.deepEqual(h.actions, []);
    }
});

test('cancelling the blink releases the menu for another selection', async () => {
    const h = harness();
    const selected = h.select(h.item('cancelled'));
    h.animations[0].reject(new Error('Animation cancelled'));
    await selected.finished;

    const retried = h.select(h.item('retried'));
    h.animations[1].resolve();
    await retried.finished;

    assert.deepEqual(h.actions, ['retried']);
});
