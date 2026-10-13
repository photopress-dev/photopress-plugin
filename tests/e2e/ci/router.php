<?php
/**
 * PHP's built-in server, for the end-to-end tests in CI: a file or
 * directory that exists is served as it is; any other address goes to
 * WordPress, as Apache's rewrite rules send it.
 */
$path = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );

if ( file_exists( $_SERVER['DOCUMENT_ROOT'] . $path ) ) {
	return false;
}

$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
