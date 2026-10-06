/**
 * End-to-end tests, run against a WordPress site with PhotoPress active:
 *
 *   PP_E2E_WP_PATH=/path/to/wordpress npm run test:e2e
 *
 * global-setup.js logs in through WP-CLI and creates the test content
 * (fixtures.php); it is deleted when the run ends. Environment:
 *
 *   PP_E2E_URL      Site URL. Default https://test.photopressdev.com.
 *   PP_E2E_WP_PATH  WordPress root, for WP-CLI. Required.
 *   PP_E2E_WP       WP-CLI command. Default "wp".
 *   PP_E2E_ORIGIN   Send requests to this origin instead, with the site's Host
 *                   header, e.g. http://127.0.0.1:8080 to reach Apache directly.
 *   PP_E2E_CHROMIUM Chromium executable, to use one already installed rather
 *                   than `npx playwright install chromium`.
 */
const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: __dirname,
	testMatch: '*.spec.js',
	globalSetup: require.resolve( './global-setup' ),
	// One browser at a time: the test site runs on a small server.
	workers: 1,
	fullyParallel: false,
	retries: 0,
	timeout: 120000,
	reporter: [ [ 'list' ] ],
	outputDir: `${ __dirname }/.results`,
	use: {
		baseURL: process.env.PP_E2E_URL || 'https://test.photopressdev.com',
		storageState: `${ __dirname }/.auth.json`,
		viewport: { width: 1280, height: 900 },
		launchOptions: {
			args: [ '--disable-dev-shm-usage' ],
			...( process.env.PP_E2E_CHROMIUM ? { executablePath: process.env.PP_E2E_CHROMIUM } : {} ),
		},
		trace: 'retain-on-failure',
	},
} );
