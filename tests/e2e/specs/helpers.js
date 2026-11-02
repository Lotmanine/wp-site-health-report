const { execFileSync } = require( 'node:child_process' );
const { readFileSync } = require( 'node:fs' );
const path = require( 'node:path' );

const root = path.resolve( __dirname, '../../..' );
const compose = [ 'compose', '-f', 'docker-compose.yml', '-f', 'docker-compose.local.yml', '--env-file', '.env' ];

function dockerExec( script ) {
	return execFileSync( 'docker', [ ...compose, 'exec', '-T', 'wordpress', 'sh', '-c', script ], {
		cwd: root,
		encoding: 'utf8',
		env: { ...process.env, MSYS_NO_PATHCONV: '1' },
	} );
}

function wpEval( php ) {
	const encoded = Buffer.from( php ).toString( 'base64' );
	return dockerExec( `echo ${ encoded } | base64 -d > /tmp/e2e.php && wp --allow-root --path=/var/www/html eval-file /tmp/e2e.php` ).trim();
}

function latestMail( to ) {
	const raw = dockerExec( 'cat /tmp/shr-mail/*.json 2>/dev/null | sed "s/}{/}\\n{/g" || true' );
	const mails = raw.split( '\n' ).filter( Boolean ).map( ( line ) => JSON.parse( line ) );
	return mails.filter( ( mail ) => mail.to === to ).pop();
}

function contact( email ) {
	const output = wpEval( `<?php
		$c = \\FluentCrm\\App\\Models\\Subscriber::where( 'email', '${ email }' )->first();
		echo wp_json_encode( $c ? array( 'status' => $c->status, 'tags' => $c->tags->pluck( 'slug' )->toArray() ) : null );
	` );
	return JSON.parse( output );
}

function adminCredentials() {
	const env = Object.fromEntries(
		readFileSync( path.join( root, '.env' ), 'utf8' ).split( '\n' ).filter( ( line ) => line.includes( '=' ) && ! line.startsWith( '#' ) ).map( ( line ) => {
			const at = line.indexOf( '=' );
			return [ line.slice( 0, at ), line.slice( at + 1 ).trim() ];
		} )
	);
	return { user: env.WP_ADMIN_USER, password: env.WP_ADMIN_PASSWORD };
}

async function noSidewaysScroll( page ) {
	return page.evaluate( () => {
		window.scrollTo( 500, 0 );
		const moved = window.scrollX;
		window.scrollTo( 0, 0 );
		return 0 === moved;
	} );
}

async function fillScanForm( page, url, options = {} ) {
	await page.getByLabel( 'Website address' ).fill( url );
	await page.getByLabel( 'I own this website or have permission to test it.' ).check();
	if ( options.email ) {
		await page.getByText( 'Email me the PDF report (optional)' ).click();
		await page.getByLabel( 'Email address' ).fill( options.email );
		await page.getByLabel( 'Send the PDF report to this address.' ).check();
		if ( options.marketing ) {
			await page.getByLabel( 'I also agree to receive occasional website health advice from OLDEV.' ).check();
		}
	}
	await page.waitForFunction( () => {
		const field = document.querySelector( '[name="cf-turnstile-response"]' );
		return field && field.value;
	}, null, { timeout: 30000 } );
}

const shot = ( name ) => path.join( root, 'docs/screenshots', name + '.png' );

module.exports = { adminCredentials, contact, dockerExec, fillScanForm, latestMail, noSidewaysScroll, shot, wpEval };
