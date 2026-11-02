const { defineConfig, devices } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './specs',
	timeout: 240000,
	workers: 1,
	fullyParallel: false,
	reporter: [ [ 'list' ] ],
	use: {
		baseURL: process.env.E2E_BASE_URL || 'http://localhost:18081',
		colorScheme: 'light',
		trace: 'retain-on-failure',
	},
	projects: [
		{ name: 'desktop-light', use: { ...devices[ 'Desktop Chrome' ], viewport: { width: 1280, height: 900 } } },
		{ name: 'phone', use: { ...devices[ 'Pixel 7' ] } },
	],
} );
