<?php
/**
 * A job type for jobs.spec.js, loaded into its WP-CLI commands with
 * --require: three items, one a batch, each done at once.
 */
WP_CLI::add_wp_hook( 'init', static function () {
	\PhotoPress\jobs\Jobs::register( 'e2e.items', [
		'label'       => 'E2E items',
		'count'       => static fn() => 3,
		'items'       => static fn( $after, $limit ) => array_slice( array_values( array_filter( [ 1, 2, 3 ], static fn( $i ) => $i > (int) $after ) ), 0, $limit ),
		'process'     => static fn() => true,
		'batch_items' => 1,
	] );
}, 20 );
