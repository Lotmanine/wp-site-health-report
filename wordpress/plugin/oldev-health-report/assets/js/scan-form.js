( function () {
	const form = document.querySelector( '[data-shr-scan-form]' );
	if ( ! form ) {
		return;
	}

	const errorBox = form.querySelector( '.shr-error' );
	const button = form.querySelector( 'button[type="submit"]' );
	let widgetRequested = false;

	// Turnstile loads on first interaction so the landing page stays fast.
	function loadTurnstile() {
		if ( widgetRequested ) {
			return;
		}
		widgetRequested = true;
		const script = document.createElement( 'script' );
		script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
		script.async = true;
		document.head.appendChild( script );
	}

	function showError( message ) {
		errorBox.textContent = message;
		errorBox.hidden = false;
	}

	function waitForToken() {
		return new Promise( ( resolve ) => {
			const started = Date.now();
			const timer = setInterval( () => {
				const field = form.querySelector( '[name="cf-turnstile-response"]' );
				if ( ( field && field.value ) || Date.now() - started > 10000 ) {
					clearInterval( timer );
					resolve( field ? field.value : '' );
				}
			}, 150 );
		} );
	}

	form.addEventListener( 'focusin', loadTurnstile );
	form.addEventListener( 'pointerenter', loadTurnstile );

	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		errorBox.hidden = true;
		loadTurnstile();

		const data = new FormData( form );
		if ( ! form.url.value.trim() ) {
			showError( 'Enter a website address.' );
			form.url.focus();
			return;
		}
		if ( ! form.consent.checked ) {
			showError( 'Confirm that you own this website or may test it.' );
			form.consent.focus();
			return;
		}

		button.disabled = true;
		const label = button.textContent;
		button.textContent = 'Starting...';

		try {
			const token = await waitForToken();
			const response = await fetch( form.dataset.endpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( {
					url: form.url.value,
					consent: form.consent.checked,
					email: String( data.get( 'email' ) || '' ),
					email_consent: form.email_consent.checked,
					marketing_consent: form.marketing_consent.checked,
					turnstile_token: token,
				} ),
			} );
			const result = await response.json();
			if ( ! response.ok ) {
				throw new Error( result.message || 'The scan could not start.' );
			}
			window.location.assign( result.report_url );
		} catch ( error ) {
			showError( error.message );
			button.disabled = false;
			button.textContent = label;
			if ( window.turnstile ) {
				window.turnstile.reset();
			}
		}
	} );
}() );
