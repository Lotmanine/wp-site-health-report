/* Light and dark theme. Runs in the head so the right theme is set before first paint.
 * A stored choice wins; otherwise the visitor's system preference applies. */
( function () {
	var root = document.documentElement;
	var key = 'oldev-theme';
	var media = window.matchMedia ? window.matchMedia( '(prefers-color-scheme: dark)' ) : null;

	function stored() {
		try {
			return localStorage.getItem( key );
		} catch ( e ) {
			return null;
		}
	}

	function apply( theme ) {
		root.classList.toggle( 'dark', 'dark' === theme );
		var buttons = document.querySelectorAll( '[data-theme-toggle]' );
		for ( var i = 0; i < buttons.length; i++ ) {
			buttons[ i ].setAttribute( 'aria-pressed', 'dark' === theme ? 'true' : 'false' );
		}
	}

	function current() {
		var choice = stored();
		if ( 'dark' === choice || 'light' === choice ) {
			return choice;
		}
		return media && media.matches ? 'dark' : 'light';
	}

	apply( current() );

	if ( media && media.addEventListener ) {
		media.addEventListener( 'change', function () {
			if ( ! stored() ) {
				apply( current() );
			}
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		apply( current() );
	} );

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '[data-theme-toggle]' ) : null;
		if ( ! button ) {
			return;
		}
		var next = root.classList.contains( 'dark' ) ? 'light' : 'dark';
		try {
			localStorage.setItem( key, next );
		} catch ( e ) {}
		apply( next );
	} );
}() );
