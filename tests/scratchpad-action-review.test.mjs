import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as vue from 'vue';

function mount(t, post) {
    const form = { revision: 0, actions: [], processing: false, errors: {}, hasErrors: false, clearErrors() { this.errors = {}; this.hasErrors = false; }, setError(key, message) { this.errors[key] = message; this.hasErrors = true; }, post };
    const toasts = [];
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/ScratchpadActionsReview.vue', import.meta.url), 'utf8'));
    const { outputText } = ts.transpileModule(compileScript(descriptor, { id: 'scratchpad-action-review-test' }).content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const context = { exports: {}, require: name => name === 'vue' ? vue : name === '@inertiajs/vue3' ? { useHttp: () => form } : name === 'vue-sonner' ? { toast: { error: message => toasts.push(message) } } : {} };
    runInNewContext(outputText, context);
    const scope = vue.effectScope();
    t.after(() => scope.stop());
    const events = [];
    let exposed;
    const state = scope.run(() => context.exports.default.setup(vue.reactive({ project: { id: 'project', revision: 3, board_columns: [] }, statuses: ['Idea', 'Live'] }), { expose(value) { exposed = value; }, emit: event => events.push(event) }));
    return { state, exposed, form, events, toasts };
}

test('review saves selected links and project details without a board column', async t => {
    const requests = [];
    const review = mount(t, async function (url) { requests.push({ url, revision: this.revision, actions: JSON.parse(JSON.stringify(this.actions)) }); return { revision: 4 }; });
    review.exposed.begin([
        { type: 'link', label: 'Buff', url: 'https://usebuff.app/', description: '' },
        { type: 'task', title: 'Try Buff' },
        { type: 'project_detail', field: 'tag', value: 'Fitness' },
        { type: 'project_detail', field: 'description', value: '' },
    ]);
    assert.equal(review.state.valid.value, false);

    review.state.items.value[1].selected = false;
    await review.state.save();

    assert.deepEqual(requests, [{ url: '/projects/project/scratchpad/actions', revision: 3, actions: [
        { type: 'link', label: 'Buff', url: 'https://usebuff.app/', description: '' },
        { type: 'project_detail', field: 'tag', value: 'Fitness' },
        { type: 'project_detail', field: 'description', value: '' },
    ] }]);
    assert.deepEqual(review.events, ['saved']);
    assert.equal(review.state.open.value, false);
});

test('failed apply keeps reviewed actions available and does not emit saved', async t => {
    const review = mount(t, async () => { throw new Error('Network unavailable'); });
    review.exposed.begin([{ type: 'document', title: 'Launch notes', body: 'Draft' }]);

    await review.state.save();

    assert.equal(review.state.open.value, true);
    assert.equal(review.state.items.value[0].title, 'Launch notes');
    assert.deepEqual(review.events, []);
    assert.deepEqual(review.toasts, ['Could not save these actions. Try again.']);
    assert.deepEqual(review.form.errors, {});
});

test('validation errors keep the review open and scratchpad untouched', async t => {
    const review = mount(t, async function () { this.setError('actions', 'This link already exists.'); });
    review.exposed.begin([{ type: 'link', label: 'Buff', url: 'https://usebuff.app/' }]);

    await review.state.save();

    assert.equal(review.state.open.value, true);
    assert.deepEqual(review.events, []);
    assert.equal(review.form.errors.actions, 'This link already exists.');
});
