const { test, expect } = require( '@playwright/test' );
const { adminCredentials, contact, fillScanForm, latestMail, noSidewaysScroll, shot } = require( './helpers' );

test.describe.configure( { mode: 'serial' } );

test( 'landing page explains the service and fits the screen', async ( { page }, info ) => {
	await page.goto( '/' );
	await expect( page.getByRole( 'heading', { level: 1 } ) ).toHaveText( 'How healthy is your website?' );
	await expect( page.getByLabel( 'Website address' ) ).toBeVisible();
	expect( await noSidewaysScroll( page ) ).toBe( true );
	await page.screenshot( { path: shot( 'landing-' + info.project.name ), fullPage: true } );
} );

test( 'the form needs the consent box', async ( { page } ) => {
	await page.goto( '/' );
	await page.getByLabel( 'Website address' ).fill( 'oldev.site' );
	await page.getByRole( 'button', { name: 'Check my website' } ).click();
	await expect( page.locator( '.shr-error' ) ).toContainText( 'Confirm that you own this website' );
} );

test( 'a private address is refused with a clear message', async ( { page } ) => {
	await page.goto( '/' );
	await fillScanForm( page, 'http://127.0.0.1/' );
	await page.getByRole( 'button', { name: 'Check my website' } ).click();
	await expect( page.locator( '.shr-error' ) ).toContainText( 'cannot be scanned' );
} );

test( 'a full scan goes from form to report, then PDF and CRM', async ( { page }, info ) => {
	const email = `scan-${ info.project.name }@example.com`;

	await page.goto( '/' );
	await fillScanForm( page, 'https://oldev.site/', { email, marketing: true } );
	await page.getByRole( 'button', { name: 'Check my website' } ).click();

	await page.waitForURL( /\/report\/[0-9a-f-]{36}\/[A-Za-z0-9_-]{43}\/$/ );
	await expect( page.getByRole( 'heading', { name: 'Checking your website' } ).or( page.getByTestId( 'overall-score' ) ) ).toBeVisible();
	if ( await page.locator( '.shr-progress' ).isVisible() ) {
		await page.screenshot( { path: shot( 'progress-' + info.project.name ), fullPage: true } );
	}

	await expect( page.getByTestId( 'overall-score' ) ).toBeVisible( { timeout: 200000 } );
	await expect( page.getByTestId( 'summary' ) ).toBeVisible( { timeout: 60000 } );

	await expect( page.getByRole( 'heading', { name: 'oldev.site' } ) ).toBeVisible();
	await expect( page.getByTestId( 'fix-list' ).locator( 'li' ).first() ).toBeVisible();
	await expect( page.getByRole( 'link', { name: 'See the WordPress audit' } ) ).toHaveAttribute( 'href', 'https://oldev.site/wordpress-audit/' );
	await expect( page.getByRole( 'link', { name: 'Contact OLDEV' } ) ).toHaveAttribute( 'href', 'https://oldev.site/contact' );
	expect( await noSidewaysScroll( page ) ).toBe( true );
	await page.screenshot( { path: shot( 'report-' + info.project.name ), fullPage: true } );

	const headers = ( await page.request.get( page.url() ) ).headers();
	expect( headers[ 'referrer-policy' ] ).toBe( 'no-referrer' );
	expect( headers[ 'x-robots-tag' ] ).toContain( 'noindex' );
	expect( headers[ 'cache-control' ] ).toContain( 'no-store' );

	await expect.poll( () => latestMail( email )?.subject, { timeout: 30000 } ).toContain( 'oldev.site' );
	expect( latestMail( email ).pdf ).toMatch( /\.pdf$/ );

	await expect.poll( () => contact( email ), { timeout: 30000 } ).not.toBeNull();
	const crm = contact( email );
	expect( crm.status ).toBe( 'subscribed' );
	expect( crm.tags ).toEqual( expect.arrayContaining( [ 'shr-report', 'shr-consent-pdf', 'shr-consent-marketing', 'shr-fail-security' ] ) );
} );

test( 'without marketing consent the contact stays transactional', async ( { page }, info ) => {
	const email = `nomarketing-${ info.project.name }@example.com`;

	await page.goto( '/' );
	await fillScanForm( page, 'https://oldev.site/', { email } );
	await page.getByRole( 'button', { name: 'Check my website' } ).click();
	await page.waitForURL( /\/report\// );
	await expect( page.getByTestId( 'summary' ) ).toBeVisible( { timeout: 200000 } );

	await expect.poll( () => contact( email ), { timeout: 30000 } ).not.toBeNull();
	const crm = contact( email );
	expect( crm.status ).toBe( 'transactional' );
	expect( crm.tags ).toContain( 'shr-no-marketing' );
} );

test( 'the dashboard signs in by link, rescans, compares and deletes', async ( { page }, info ) => {
	const email = `scan-${ info.project.name }@example.com`;

	await page.goto( '/dashboard/' );
	await page.waitForFunction( () => document.querySelector( '[name="cf-turnstile-response"]' )?.value, null, { timeout: 30000 } );
	await page.getByLabel( 'Email address' ).fill( email );
	await page.getByRole( 'button', { name: 'Send sign-in link' } ).click();
	await expect( page.getByText( 'If that address has reports' ) ).toBeVisible();

	await expect.poll( () => latestMail( email )?.subject, { timeout: 15000 } ).toContain( 'sign-in link' );
	const link = latestMail( email ).html.match( /href="([^"]+)"/ )[ 1 ].replace( /&#0?38;|&amp;/g, '&' );

	await page.goto( link );
	await page.getByRole( 'button', { name: 'Continue' } ).click();
	await expect( page.getByTestId( 'history' ) ).toBeVisible();
	expect( await noSidewaysScroll( page ) ).toBe( true );

	await page.getByRole( 'button', { name: 'Rescan' } ).first().click();
	await page.waitForURL( /\/report\// );
	await expect( page.getByTestId( 'summary' ) ).toBeVisible( { timeout: 200000 } );

	await page.goto( '/dashboard/' );
	await expect( page.getByTestId( 'history' ).locator( 'tbody tr' ) ).toHaveCount( 2 );
	await page.getByRole( 'button', { name: 'Compare' } ).click();
	await expect( page.getByTestId( 'comparison' ) ).toBeVisible();
	await expect( page.getByTestId( 'comparison' ) ).toContainText( 'Overall' );
	await page.screenshot( { path: shot( 'dashboard-' + info.project.name ), fullPage: true } );

	page.on( 'dialog', ( dialog ) => dialog.accept() );
	await page.getByRole( 'button', { name: 'Delete my data' } ).click();
	await page.waitForURL( ( url ) => '/' === url.pathname );

	await expect.poll( () => contact( email ), { timeout: 15000 } ).toBeNull();
} );

test( 'a report can be deleted from its own page', async ( { page } ) => {
	await page.goto( '/' );
	await fillScanForm( page, 'https://oldev.site/' );
	await page.getByRole( 'button', { name: 'Check my website' } ).click();
	await page.waitForURL( /\/report\// );
	await expect( page.getByTestId( 'summary' ) ).toBeVisible( { timeout: 200000 } );
	const reportUrl = page.url();

	page.on( 'dialog', ( dialog ) => dialog.accept() );
	await page.getByRole( 'button', { name: 'Delete this report' } ).click();
	await page.waitForURL( ( url ) => '/' === url.pathname );

	await page.goto( reportUrl );
	await expect( page.getByRole( 'heading', { name: 'Report not found' } ) ).toBeVisible();
} );

test( 'a guessed report URL shows nothing', async ( { page } ) => {
	await page.goto( '/report/00000000-0000-4000-8000-000000000000/' + 'a'.repeat( 43 ) + '/' );
	await expect( page.getByRole( 'heading', { name: 'Report not found' } ) ).toBeVisible();
} );

test( 'the privacy page covers retention and deletion', async ( { page }, info ) => {
	await page.goto( '/privacy/' );
	await expect( page.getByText( 'deleted after 30 days' ).first() ).toBeVisible();
	await expect( page.getByRole( 'heading', { name: 'How to delete your data' } ) ).toBeVisible();
	expect( await noSidewaysScroll( page ) ).toBe( true );
	await page.screenshot( { path: shot( 'privacy-' + info.project.name ), fullPage: true } );
} );

test( 'the admin screen shows queue health, usage and abuse', async ( { page }, info ) => {
	const { user, password } = adminCredentials();
	await page.goto( '/wp-login.php' );
	await page.getByLabel( 'Username or Email Address' ).fill( user );
	await page.getByLabel( 'Password', { exact: true } ).fill( password );
	await page.getByRole( 'button', { name: 'Log In' } ).click();
	await page.goto( '/wp-admin/admin.php?page=shr-operations' );

	await expect( page.getByRole( 'heading', { name: 'Queue health' } ) ).toBeVisible();
	await expect( page.getByRole( 'heading', { name: 'Daily usage' } ) ).toBeVisible();
	await expect( page.getByRole( 'heading', { name: 'Abuse log' } ) ).toBeVisible();
	await expect( page.locator( 'body' ) ).toContainText( 'url_rejected' );
	await page.screenshot( { path: shot( 'admin-' + info.project.name ), fullPage: true } );
} );
