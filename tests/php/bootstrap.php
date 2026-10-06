<?php
/**
 * Unit test bootstrap. No database and no WordPress bootstrap: WordPress
 * functions are stubbed per test with Brain Monkey (see TestCase), and the
 * HTML API classes the render filters use are loaded from WordPress core,
 * a dev dependency (johnpbloch/wordpress-core).
 */

$root = dirname( __DIR__, 2 );

require $root . '/vendor/autoload.php';

define( 'ABSPATH', $root . '/vendor/johnpbloch/wordpress-core/' );
define( 'WPINC', 'wp-includes' );
define( 'WP_DEBUG', false );
define( 'PHOTOPRESS_CORE_VERSION', 'test' );

/**
 * Stands in for the framework's pp_api, which needs the whole framework.
 * Declared before anything references pp_api, so the autoloader never loads
 * the real one. Tests set options in pp_api::$options.
 */
class pp_api {

	public static $options = [];

	public static function getOption( $package, $module, $key ) {

		return self::$options[ "$package/$module/$key" ] ?? null;
	}
}

require_once ABSPATH . WPINC . '/class-wp-token-map.php';

foreach ( [
	'class-wp-html-attribute-token.php',
	'class-wp-html-span.php',
	'class-wp-html-text-replacement.php',
	'class-wp-html-decoder.php',
	'class-wp-html-doctype-info.php',
	'html5-named-character-references.php',
	'class-wp-html-tag-processor.php',
] as $file ) {
	require_once ABSPATH . WPINC . '/html-api/' . $file;
}

require_once __DIR__ . '/TestCase.php';
