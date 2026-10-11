import assert from 'node:assert/strict';
import { test } from 'node:test';
import { renderToStaticMarkup } from 'react-dom/server';
import { UiProvider } from '../../packages/euisis-ui/src/context';
import { FilterField, FilterPanel, Pagination } from '../../packages/euisis-ui/src/data';

/*
 * The one filter panel and the one pagination bar every admin list uses
 * (docs/ui-components.md). Run:
 *   npx esbuild tests/js/list-kit.test.tsx --bundle --platform=node --format=cjs --outfile=storage/framework/testing/list-kit.test.cjs
 *   node --test storage/framework/testing/list-kit.test.cjs
 */

const meta = (currentPage: number, lastPage: number, total: number, perPage = 25) => ({ currentPage, lastPage, perPage, total });
const pageButtons = (html: string) => [...html.matchAll(/data-page="(\d+)"/g)].map((m) => Number(m[1]));

test('pagination states the visible range with the localized template', () => {
    const html = renderToStaticMarkup(
        <UiProvider messages={{ range: 'ከ:total ውስጥ :from–:to' }}>
            <Pagination meta={meta(2, 14, 340)} onPageChange={() => {}} />
        </UiProvider>,
    );
    assert.match(html, /ከ340 ውስጥ 26–50/);
});

test('pagination shows a bounded window of pages however many there are', () => {
    const html = renderToStaticMarkup(<Pagination meta={meta(5000, 120000, 3000000)} onPageChange={() => {}} />);
    const pages = pageButtons(html);
    assert.ok(pages.length <= 7, `rendered ${pages.length} page buttons`);
    assert.deepEqual([pages[0], pages.at(-1)], [1, 120000]);
    assert.ok(pages.includes(5000));
    assert.match(html, /aria-current="page"[^>]*data-page="5000"|data-page="5000"[^>]*aria-current="page"/);
});

test('pagination disables previous on the first page and next on the last', () => {
    const first = renderToStaticMarkup(<Pagination meta={meta(1, 3, 60)} onPageChange={() => {}} />);
    assert.match(first, /<button[^>]*disabled=""[^>]*aria-label="Previous"|<button[^>]*aria-label="Previous"[^>]*disabled=""/);
    const last = renderToStaticMarkup(<Pagination meta={meta(3, 3, 60)} onPageChange={() => {}} />);
    assert.match(last, /<button[^>]*disabled=""[^>]*aria-label="Next"|<button[^>]*aria-label="Next"[^>]*disabled=""/);
});

test('a single page keeps the range but draws no page buttons', () => {
    const html = renderToStaticMarkup(<Pagination meta={meta(1, 1, 7)} onPageChange={() => {}} />);
    assert.match(html, /1–7 of 7/);
    assert.equal(pageButtons(html).length, 0);
});

test('an empty list draws no pagination at all', () => {
    assert.equal(renderToStaticMarkup(<Pagination meta={meta(1, 1, 0)} onPageChange={() => {}} />), '');
});

test('the filter panel is a labelled search form with Filter first and Reset only when something is filtered', () => {
    const idle = renderToStaticMarkup(
        <FilterPanel label="Employee filters" onSubmit={() => {}} onReset={() => {}} hasActiveFilters={false}>
            <FilterField label="Status"><select name="status" /></FilterField>
        </FilterPanel>,
    );
    assert.match(idle, /<form[^>]*role="search"[^>]*aria-label="Employee filters"/);
    assert.match(idle, /<label[^>]*><span[^>]*>Status<\/span><select name="status"/);
    assert.match(idle, /<button[^>]*type="submit"[^>]*>(<span>)?Filter(<\/span>)?<\/button>/);
    assert.doesNotMatch(idle, />Reset</);

    const active = renderToStaticMarkup(
        <FilterPanel onSubmit={() => {}} onReset={() => {}} hasActiveFilters>
            <FilterField label="Status"><select name="status" /></FilterField>
        </FilterPanel>,
    );
    assert.match(active, /Filter(<\/span>)?<\/button><button[^>]*type="button"[^>]*>(<span>)?Reset/);
});

test('a filter field can label a widget it does not wrap', () => {
    const html = renderToStaticMarkup(<FilterField label="From" htmlFor="from"><div id="from-picker" /></FilterField>);
    assert.match(html, /<div[^>]*><label for="from"[^>]*>From<\/label><div id="from-picker"><\/div><\/div>/);
});
