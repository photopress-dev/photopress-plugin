/**
 * Logs in and creates the test content before the run; the function it
 * returns deletes the content afterwards, whether the tests passed or not.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { wp, fixtures, lastLine } = require( './wp' );

const AUTH = path.join( __dirname, '.auth.json' );

module.exports = async ( config ) => {
	const url = new URL( config.projects[ 0 ].use.baseURL );

	// Left by a run that was killed before its teardown.
	fixtures( 'sweep' );

	// Admin login cookies, made by WordPress itself.
	const cookies = JSON.parse( lastLine( wp( 'eval', `
		$user = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];
		$expires = time() + 2 * HOUR_IN_SECONDS;
		echo wp_json_encode( [
			[ 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $user->ID, $expires, 'logged_in' ), 'path' => '/' ],
			[ 'name' => SECURE_AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $user->ID, $expires, 'secure_auth' ), 'path' => '/wp-admin' ],
			[ 'name' => AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $user->ID, $expires, 'auth' ), 'path' => '/wp-admin' ],
		] );
	` ) ) ).map( ( c ) => ( { ...c, domain: url.hostname, expires: -1, httpOnly: true, secure: url.protocol === 'https:', sameSite: 'Lax' } ) );

	fs.writeFileSync( AUTH, JSON.stringify( { cookies, origins: [] } ), { mode: 0o600 } );

	const made = lastLine( fixtures( 'create' ) );
	process.env.PP_E2E_FIXTURES = made;

	return () => {
		try {
			fixtures( 'delete', made );
		} finally {
			fs.rmSync( AUTH, { force: true } );
		}
	};
};
