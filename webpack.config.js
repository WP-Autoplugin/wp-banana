const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		'media-library': path.resolve( process.cwd(), 'assets/admin/entry-media-library.tsx' ),
		'attachment-editor': path.resolve( process.cwd(), 'assets/admin/entry-attachment-editor.tsx' ),
		'block-editor': path.resolve( process.cwd(), 'assets/admin/entry-block-editor.tsx' ),
		'generate-page': path.resolve( process.cwd(), 'assets/admin/generate-page.tsx' ),
	},
};
