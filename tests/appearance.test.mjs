import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';
import * as vue from 'vue';

function load({ theme, motion, dark = false, reduced = false } = {}) {
    const classes = new Set();
    const root = {
        dataset: { ...(theme === undefined ? {} : { orbitTheme: theme }), ...(motion === undefined ? {} : { orbitMotion: motion }) },
        style: {},
        classList: {
            toggle(name, enabled) { if (enabled) classes.add(name); else classes.delete(name); },
            contains: name => classes.has(name),
        },
    };
    function mediaQuery(matches) {
        const listeners = new Set();
        return {
            matches,
            addEventListener(event, listener) { assert.equal(event, 'change'); listeners.add(listener); },
            change(matches) { this.matches = matches; for (const listener of listeners) listener({ matches }); },
        };
    }
    const colorScheme = mediaQuery(dark);
    const reducedMotion = mediaQuery(reduced);
    const media = new Map([
        ['(prefers-color-scheme: dark)', colorScheme],
        ['(prefers-reduced-motion: reduce)', reducedMotion],
    ]);
    const { outputText } = ts.transpileModule(readFileSync(new URL('../resources/js/lib/appearance.ts', import.meta.url), 'utf8'),
        { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } });
    const context = {
        exports: {}, document: { documentElement: root },
        window: { matchMedia: query => { assert.ok(media.has(query)); return media.get(query); } },
        require: name => { assert.equal(name, 'vue'); return vue; },
    };
    runInNewContext(outputText, context);
    return { ...context.exports, root, colorScheme, systemMotion: reducedMotion };
}

test('initial defaults follow the operating system for appearance and reduced motion', () => {
    const state = load({ dark: true, reduced: true });

    assert.equal(state.appearance.value, 'system');
    assert.equal(state.root.classList.contains('dark'), true);
    assert.equal(state.root.style.colorScheme, 'dark');
    assert.equal(state.motionPreference.value, 'system');
    assert.equal(state.reducedMotion.value, true);
    assert.equal(state.root.classList.contains('reduce-motion'), true);
});

for (const [mode, osReduced, effective] of [['on', false, true], ['off', true, false]]) {
    test(`saved reduced motion ${mode} overrides the operating system at startup`, () => {
        const state = load({ motion: mode, reduced: osReduced });

        assert.equal(state.motionPreference.value, mode);
        assert.equal(state.root.dataset.orbitMotion, mode);
        assert.equal(state.reducedMotion.value, effective);
        assert.equal(state.root.classList.contains('reduce-motion'), effective);
    });
}

test('a saved system motion preference responds to operating system changes', () => {
    const state = load({ motion: 'system', reduced: false });
    assert.equal(state.reducedMotion.value, false);
    assert.equal(state.root.classList.contains('reduce-motion'), false);

    state.systemMotion.change(true);

    assert.equal(state.motionPreference.value, 'system');
    assert.equal(state.root.dataset.orbitMotion, 'system');
    assert.equal(state.reducedMotion.value, true);
    assert.equal(state.root.classList.contains('reduce-motion'), true);
    state.systemMotion.change(false);
    assert.equal(state.reducedMotion.value, false);
    assert.equal(state.root.classList.contains('reduce-motion'), false);
});

test('applying reduced motion on, off and system updates the effective state without changing the theme', () => {
    const state = load({ theme: 'dark', motion: 'system', reduced: false });

    state.applyMotionPreference('on');

    assert.equal(state.motionPreference.value, 'on');
    assert.equal(state.root.dataset.orbitMotion, 'on');
    assert.equal(state.reducedMotion.value, true);
    assert.equal(state.root.classList.contains('reduce-motion'), true);
    state.applyMotionPreference('off');
    assert.equal(state.motionPreference.value, 'off');
    assert.equal(state.root.dataset.orbitMotion, 'off');
    assert.equal(state.reducedMotion.value, false);
    assert.equal(state.root.classList.contains('reduce-motion'), false);
    state.systemMotion.change(true);
    assert.equal(state.reducedMotion.value, false);
    state.applyMotionPreference('system');
    assert.equal(state.motionPreference.value, 'system');
    assert.equal(state.root.dataset.orbitMotion, 'system');
    assert.equal(state.reducedMotion.value, true);
    assert.equal(state.root.classList.contains('reduce-motion'), true);
    assert.equal(state.appearance.value, 'dark');
    assert.equal(state.root.classList.contains('dark'), true);
    assert.equal(state.root.style.colorScheme, 'dark');
});

for (const [mode, initialOs, changedOs, effective] of [['on', true, false, true], ['off', false, true, false]]) {
    test(`operating system changes preserve explicit reduced motion ${mode}`, () => {
        const state = load({ motion: mode, reduced: initialOs });

        state.systemMotion.change(changedOs);

        assert.equal(state.motionPreference.value, mode);
        assert.equal(state.root.dataset.orbitMotion, mode);
        assert.equal(state.reducedMotion.value, effective);
        assert.equal(state.root.classList.contains('reduce-motion'), effective);
    });
}
