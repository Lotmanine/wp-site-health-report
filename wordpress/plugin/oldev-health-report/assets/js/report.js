( function () {
	const progress = document.querySelector( '[data-shr-progress]' );

	if ( progress ) {
		const statusUrl = progress.dataset.statusUrl;
		let checks = 0;

		// The summary is written a moment after the scan completes, so a plain reload covers both states.
		const timer = setInterval( async () => {
			checks += 1;
			if ( checks > 200 ) {
				clearInterval( timer );
				return;
			}
			if ( ! statusUrl ) {
				if ( checks > 3 ) {
					window.location.reload();
				}
				return;
			}
			try {
				const response = await fetch( statusUrl, { headers: { Accept: 'application/json' } } );
				const data = await response.json();
				if ( 'complete' === data.status || 'failed' === data.status ) {
					window.location.reload();
				}
			} catch ( error ) {
				// A missed poll is fine, the next one retries.
			}
		}, 3000 );
	}

	const deleteButton = document.querySelector( '[data-shr-delete]' );
	if ( deleteButton ) {
		deleteButton.addEventListener( 'click', async () => {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( 'Delete this report? This cannot be undone.' ) ) {
				return;
			}
			const response = await fetch( deleteButton.dataset.shrDelete, { method: 'POST' } );
			if ( response.ok ) {
				window.location.assign( '/' );
			}
		} );
	}
}() );
