/**
 * Standalone regression test for the "Show in Tree" binary-search paging
 * logic in treenodelocator.js (PEES-1138).
 *
 * This bundle has no JS test runner wired up (no jest/mocha/karma, only
 * webpack-encore for building assets), so this is a plain, dependency-free
 * Node script that re-implements the exact bound math from
 * `reportProcessFullPathFailed` / `processPaging` / `switchToPage` and
 * drives it against mock "current page vs target page" scenarios.
 *
 * Run with: node tests/js/treenodelocator-paging.test.js
 */

'use strict';

const assert = require('assert');

// --- Faithful port of the relevant math from treenodelocator.js ---------

function buildPagingState(offset, limit, total, activePageFormula) {
    return {
        total,
        limit,
        offset,
        activePage: activePageFormula(offset, limit, total),
        pageCount: Math.ceil(total / limit),
        minPage: 1,
        maxPage: Math.ceil(total / limit),
    };
}

// direction: -1 = target is before the currently loaded page, +1 = after, 0 = on it
function direction(cachedPage, targetPage) {
    if (targetPage < cachedPage) return -1;
    if (targetPage > cachedPage) return 1;
    return 0;
}

// Faithful port of processPaging()'s bound-narrowing + switchToPage() gate.
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

// --- The two competing formulas ------------------------------------------

const BUGGY_FORMULA = (offset, limit, total) => (offset / total) + 1;
const FIXED_FORMULA = (offset, limit, total) => (offset / limit) + 1;

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
        // The buggy formula happens to still land correctly here - this is
        // exactly the "fails for some cases, not others" intermittency
        // reported in the ticket. The important, load-bearing assertion is
        // still that the FIXED formula always converges (checked above).
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

    const buggyState = buildPagingState(offsetAtStart, s.limit, s.total, BUGGY_FORMULA);
    const buggyResult = runBinarySearch(buggyState, s.cachedPageAtStart, s.targetPage);

    const fixedState = buildPagingState(offsetAtStart, s.limit, s.total, FIXED_FORMULA);
    const fixedResult = runBinarySearch(fixedState, s.cachedPageAtStart, s.targetPage);

    console.log(`\n[${s.name}]`);
    console.log(`  buggy formula : ${buggyResult.found ? `found page ${buggyResult.page}` : 'GAVE UP (silent failure)'}`);
    console.log(`  fixed formula : ${fixedResult.found ? `found page ${fixedResult.page}` : 'GAVE UP (silent failure)'}`);

    try {
        // The fixed formula must ALWAYS find the real target page.
        assert.strictEqual(fixedResult.found, true, 'fixed formula must always converge');
        assert.strictEqual(fixedResult.page, s.targetPage, 'fixed formula must land on the real target page');

        // Document the buggy formula's behavior matches what we observed/derived
        // from the ticket (works when offset=0 at search start, breaks otherwise).
        assert.strictEqual(buggyResult.found, s.expectBuggyFinds, 'buggy formula behavior mismatch vs expectation');

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
