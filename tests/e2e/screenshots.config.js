/**
 * Makes the screenshots in .wordpress-org from the test images, with the
 * same fixtures and environment as the end-to-end tests:
 *
 *   PP_E2E_WP_PATH=/path/to/wordpress npx playwright test --config tests/e2e/screenshots.config.js
 *
 * With PP_E2E_OFFLOAD=1 it also makes the Offload Media one. Needs
 * ImageMagick (convert) for the recording.
 */
const base = require( './playwright.config' );

module.exports = { ...base, testMatch: 'screenshots.js' };
