import { defineConfig } from 'vitest/config';
import { transformWithOxc } from 'vite';

/*
 * The block sources use JSX in .js files. Vite takes the language from the
 * extension and has no option to change that, so transform them here.
 */
const jsxInJs = {
	name: 'photopress-jsx-in-js',
	enforce: 'pre',
	transform( code, id ) {
		if ( ! /\/src\/.*\.js$/.test( id ) ) {
			return null;
		}
		return transformWithOxc( code, id, { lang: 'jsx', jsx: { runtime: 'automatic' } } );
	},
};

export default defineConfig( {
	plugins: [ jsxInJs ],
	resolve: {
		alias: {
			'@wordpress/block-editor': new URL( './tests/js/stubs/block-editor.js', import.meta.url ).pathname,
		},
	},
	test: {
		environment: 'jsdom',
		include: [ 'tests/js/**/*.test.js' ],
		globals: false,
		restoreMocks: true,
		// Let Vite transform the WordPress packages. Loaded by Node directly,
		// @wordpress/blocks fails on a JSON import without an import attribute.
		server: {
			deps: {
				inline: [ /@wordpress\// ],
			},
		},
	},
} );
