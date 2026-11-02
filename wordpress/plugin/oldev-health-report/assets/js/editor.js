( function ( blocks, element, blockEditor, ServerSideRender ) {
	const render = ( name ) => function Edit() {
		return element.createElement(
			'div',
			blockEditor.useBlockProps(),
			element.createElement( ServerSideRender, { block: name } )
		);
	};

	[ 'shr/scan-form', 'shr/report', 'shr/dashboard' ].forEach( ( name ) => {
		const type = blocks.getBlockType( name );
		if ( type ) {
			blocks.unregisterBlockType( name );
		}
		blocks.registerBlockType( name, { ...( type || {} ), edit: render( name ), save: () => null } );
	} );
}( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.serverSideRender ) );
