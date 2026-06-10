// Tiny zero-dependency test framework for the UI suite.
//
// Test files import { test, assert } from './harness.mjs' and register cases:
//
//   import { test, assert } from './harness.mjs';
//   test('dashboard mounts', async (page) => {
//     assert.ok(await page.exists('.side-nav'), 'sidebar present');
//   });
//
// run.mjs imports every *.test.mjs (which populates the registry below), then
// runs each case against a shared, authenticated `page`. A test passes if its
// function returns without throwing; throwing AssertionError-style errors fails
// it; calling page.skip(reason) (which throws { skip:true }) skips it.

const _tests = [];

export function test(name, fn) {
  if (typeof name !== 'string' || typeof fn !== 'function') {
    throw new Error('test(name, fn): expects a string name and a function');
  }
  _tests.push({ name, fn });
}

export function registeredTests() {
  return _tests;
}

function fail(message, fallback) {
  throw new Error(message || fallback);
}

export const assert = {
  ok(cond, message) { if (!cond) fail(message, 'expected a truthy value'); },
  notOk(cond, message) { if (cond) fail(message, 'expected a falsy value'); },
  equal(actual, expected, message) {
    if (actual !== expected) {
      fail(message && `${message} (expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)})`,
        `expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
    }
  },
  atLeast(actual, min, message) {
    if (!(Number(actual) >= min)) {
      fail(message && `${message} (expected >= ${min}, got ${actual})`, `expected >= ${min}, got ${actual}`);
    }
  },
  includes(haystack, needle, message) {
    if (!String(haystack).includes(needle)) {
      fail(message && `${message} (expected "${haystack}" to include "${needle}")`,
        `expected "${haystack}" to include "${needle}"`);
    }
  },
};
