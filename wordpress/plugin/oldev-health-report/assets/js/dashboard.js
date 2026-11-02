( function () {
	const root = document.querySelector( '[data-shr-dashboard]' );
	if ( ! root ) {
		return;
	}

	const errorBox = root.querySelector( '.shr-error' );

	function showError( message ) {
		if ( errorBox ) {
			errorBox.textContent = message;
			errorBox.hidden = false;
		}
	}

	async function post( url, body, token ) {
		const headers = { 'Content-Type': 'application/json' };
		if ( token ) {
			headers[ 'X-SHR-Token' ] = token;
		}
		const response = await fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers,
			body: JSON.stringify( body || {} ),
		} );
		const result = await response.json();
		if ( ! response.ok ) {
			throw new Error( result.message || 'That did not work.' );
		}
		return result;
	}

	const consume = root.querySelector( '[data-shr-consume]' );
	if ( consume ) {
		consume.addEventListener( 'click', async () => {
			try {
				await post( root.dataset.consume, { token: consume.dataset.shrConsume } );
				window.location.assign( window.location.pathname );
			} catch ( error ) {
				showError( error.message );
			}
		} );
	}

	const request = root.querySelector( '[data-shr-request]' );
	if ( request ) {
		const script = document.createElement( 'script' );
		script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
		script.async = true;
		document.head.appendChild( script );

		request.addEventListener( 'submit', async ( event ) => {
			event.preventDefault();
			const field = request.querySelector( '[name="cf-turnstile-response"]' );
			try {
				await post( root.dataset.request, {
					email: request.email.value,
					turnstile_token: field ? field.value : '',
				} );
				request.querySelector( '[data-shr-sent]' ).hidden = false;
			} catch ( error ) {
				showError( error.message );
			}
		} );
	}

	const token = root.dataset.token;

	root.querySelectorAll( '[data-shr-rescan]' ).forEach( ( button ) => {
		button.addEventListener( 'click', async () => {
			button.disabled = true;
			try {
				const result = await post( root.dataset.rescan, { uuid: button.dataset.shrRescan }, token );
				window.location.assign( result.report_url );
			} catch ( error ) {
				showError( error.message );
				button.disabled = false;
			}
		} );
	} );

	const logout = root.querySelector( '[data-shr-logout]' );
	if ( logout ) {
		logout.addEventListener( 'click', async () => {
			await post( root.dataset.logout, {}, token );
			window.location.assign( window.location.pathname );
		} );
	}

	const erase = root.querySelector( '[data-shr-erase]' );
	if ( erase ) {
		erase.addEventListener( 'click', async () => {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( 'Delete your reports, your email address and your contact record? This cannot be undone.' ) ) {
				return;
			}
			try {
				await post( root.dataset.delete, {}, token );
				window.location.assign( '/' );
			} catch ( error ) {
				showError( error.message );
			}
		} );
	}
}() );
