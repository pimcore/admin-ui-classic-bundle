/**
 * Regression test for the "Show in Tree" binary-search paging logic in
 * treenodelocator.js (PEES-1138).
 *
 * This bundle has no JS test runner wired up (no jest/mocha/karma, only
 * webpack-encore for building assets), so this is a plain, dependency-free
 * Node script. It stubs the minimal global `pimcore.registerNS` and
 * `require()`s treenodelocator.js directly, so the "fixed" side below calls
 * the real, shipped `calculatePagingBounds` production function - if that
 * function is ever reverted to the old `offset / total` formula, this test
 * fails, since it is no longer just checking a local copy of the formula.
 *
 * The binary-search bound-narrowing itself (processPaging()/switchToPage())
 * is re-implemented locally (`runBinarySearch`), since those functions are
 * private closures over module state and aren't exposed for direct testing;
 * only the page-bounds calculation is pulled from the real module.
 *
 * Run with: node tests/js/treenodelocator-paging.test.js
 */

'use strict';

const assert = require('assert');
const path = require('path');

// Minimal stub so treenodelocator.js's top-level `pimcore.registerNS(...)`
// call and `pimcore.treenodelocator = ...` assignment don't throw in Node.
global.pimcore = { registerNS: function () {} };

const treenodelocator = require(path.join(__dirname, '..', '..', 'public', 'js', 'pimcore', 'treenodelocator.js'));

// --- Binary search harness (local re-implementation of the bound-narrowing
// in processPaging()/switchToPage(); those are private and not exposed) ----

// direction: -1 = target is before the currently loaded page, +1 = after, 0 = on it
function direction(cachedPage, targetPage) {
    if (targetPage < cachedPage) return -1;
    if (targetPage > cachedPage) return 1;
    return 0;
}

// Returns { found: true, page } on success, { found: false } if the search
// aborts out-of-bounds (the silent-failure path), or throws if it never
// converges within a sane number of iterations (safety net for the test).
function runBinarySearch(pagingState, cachedPageAtStart, targetPage) {
    let cachedPage = cachedPageAtStart;
    const MAX_ITERATIONS = 20;

    for (let i = 0; i < MAX_ITERATIONS; i++) {
        const dir = direction(cachedPage, targetPage);

        if (dir === 0) {
            return { found: true, page: cachedPage };
        }

        let newPage;
        if (dir === -1) {
            pagingState.maxPage = pagingState.activePage - 1;
            newPage = (pagingState.minPage + pagingState.maxPage) / 2;
        } else {
            pagingState.minPage = pagingState.activePage + 1;
            newPage = (pagingState.minPage + pagingState.maxPage) / 2;
        }

        // switchToPage()
        newPage = Math.floor(newPage);
        if (newPage > pagingState.maxPage || newPage < pagingState.minPage) {
            return { found: false };
        }

        // switchToPage() sets activePage/offset directly for the *next* round
        pagingState.offset = pagingState.limit * (newPage - 1);
        pagingState.activePage = newPage;
        cachedPage = newPage;
    }

    throw new Error('did not converge within ' + MAX_ITERATIONS + ' iterations');
}

// The pre-fix formula, kept only as a hardcoded historical snapshot for
// documentation/comparison in the output below - NOT used to validate
// production code (that's exactly what real regression coverage requires;
// see file header).
function buggyPagingBounds(offset, limit, total) {
    return {
        activePage: (offset / total) + 1,
        pageCount: Math.ceil(total / limit),
        minPage: 1,
        maxPage: Math.ceil(total / limit),
    };
}

// --- Scenarios -------------------------------------------------------------
//
// Each scenario describes: page size, total item count, which page the
// folder happens to be cached/loaded at when the search starts (this is
// what the bug depends on), and which page the target actually lives on.

const scenarios = [
    {
        name: 'fresh folder, first visit (offset=0), target on page 2 of 4',
        limit: 20, total: 70, cachedPageAtStart: 1, targetPage: 2,
        // Matches support's repro and the "atv_..." working example from the ticket.
        expectBuggyFinds: true,
    },
    {
        name: 'fresh folder, target on last page (2 of 2)',
        limit: 20, total: 35, cachedPageAtStart: 1, targetPage: 2,
        // Matches the "flammkopfverl..." working example from the ticket.
        expectBuggyFinds: true,
    },
    {
        name: 'folder already cached on page 3 of 4, target on page 2 (PEES-1138 repro)',
        limit: 20, total: 70, cachedPageAtStart: 3, targetPage: 2,
        // Matches the "duo-s2-pe" failing example from the ticket exactly.
        expectBuggyFinds: false,
    },
    {
        name: 'folder already cached on page 2 of 5, target on page 4',
        limit: 20, total: 90, cachedPageAtStart: 2, targetPage: 4,
        // The old formula happens to still land correctly here - it does NOT
        // fail deterministically for every non-zero offset, only for some
        // (see the two scenarios above/below with expectBuggyFinds: false).
        // This is exactly the "fails for some cases, not others" intermittency
        // reported in the ticket, and why the fixed-formula assertion below
        // (which must ALWAYS converge) is the load-bearing one, not this one.
        expectBuggyFinds: true,
    },
    {
        name: 'folder already cached on page 4 of 5, target on page 1',
        limit: 20, total: 90, cachedPageAtStart: 4, targetPage: 1,
        expectBuggyFinds: false,
    },
];

// --- Run ---------------------------------------------------------------

let failures = 0;

for (const s of scenarios) {
    const offsetAtStart = s.limit * (s.cachedPageAtStart - 1);

    const buggyState = buggyPagingBounds(offsetAtStart, s.limit, s.total);
    buggyState.total = s.total;
    buggyState.limit = s.limit;
    buggyState.offset = offsetAtStart;
    const buggyResult = runBinarySearch(buggyState, s.cachedPageAtStart, s.targetPage);

    // Exercises the real, shipped production function.
    const fixedBounds = treenodelocator.calculatePagingBounds(offsetAtStart, s.limit, s.total);
    const fixedState = Object.assign({ total: s.total, limit: s.limit, offset: offsetAtStart }, fixedBounds);
    const fixedResult = runBinarySearch(fixedState, s.cachedPageAtStart, s.targetPage);

    console.log(`\n[${s.name}]`);
    console.log(`  buggy formula (historical)     : ${buggyResult.found ? `found page ${buggyResult.page}` : 'GAVE UP (silent failure)'}`);
    console.log(`  production calculatePagingBounds: ${fixedResult.found ? `found page ${fixedResult.page}` : 'GAVE UP (silent failure)'}`);

    try {
        // The load-bearing assertion: the real production function must
        // ALWAYS find the real target page. This is what fails if the fix
        // in treenodelocator.js is ever reverted.
        assert.strictEqual(fixedResult.found, true, 'production calculatePagingBounds must always converge');
        assert.strictEqual(fixedResult.page, s.targetPage, 'production calculatePagingBounds must land on the real target page');

        // Documents that the historical formula's behavior matches what we
        // observed/derived from the ticket.
        assert.strictEqual(buggyResult.found, s.expectBuggyFinds, 'historical buggy-formula behavior mismatch vs expectation');

        console.log('  PASS');
    } catch (err) {
        failures++;
        console.error('  FAIL:', err.message);
    }
}

console.log(`\n${scenarios.length - failures}/${scenarios.length} scenarios passed.`);
if (failures > 0) {
    process.exit(1);
}
