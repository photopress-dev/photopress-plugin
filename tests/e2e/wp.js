/**
 * WP-CLI for the end-to-end tests.
 */
const { execFileSync } = require( 'child_process' );
const path = require( 'path' );

const FIXTURES = path.join( __dirname, 'fixtures.php' );

function wp( ...args ) {
	const wpPath = process.env.PP_E2E_WP_PATH;
	if ( ! wpPath ) {
		throw new Error( 'Set PP_E2E_WP_PATH to the WordPress root of the test site.' );
	}
	const [ command, ...prefix ] = ( process.env.PP_E2E_WP || 'wp' ).split( ' ' );

	return execFileSync( command, [ ...prefix, `--path=${ wpPath }`, ...args ], {
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} );
}

// Plugins may print notices before the output; the JSON is the last line.
const lastLine = ( out ) => out.trim().split( '\n' ).pop();

module.exports = {
	wp,
	fixtures: ( ...args ) => wp( 'eval-file', FIXTURES, ...args ),
	lastLine,
};
