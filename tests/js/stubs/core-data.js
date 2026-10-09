/**
 * Stands in for @wordpress/core-data in unit tests (see vitest.config.mjs).
 * It is not installed: the build leaves it to WordPress. Code under test only
 * names its store.
 */
export const store = 'core';
