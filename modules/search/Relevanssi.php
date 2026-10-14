<?php

namespace PhotoPress\modules\search;

defined( 'ABSPATH' ) || exit;

use WP_Query;

/**
 * Relevanssi, as PhotoPress uses it: the one place that knows its function,
 * filter and setting names.
 *
 * Its documented functions (relevanssi_do_query(), and relevanssi_index_doc()
 * with relevanssi_get_custom_fields() as its documentation shows) and filters
 * (relevanssi_index_custom_fields, relevanssi_hits_filter). Which post types
 * and taxonomies it indexes, and whether image files, it has no filter for:
 * they are its settings, read and saved here as its Indexing tab saves them
 * (indexing(), setIndexing()), so its settings page shows what PhotoPress
 * changed and the site can change it back there.
 */
class Relevanssi {

	/** Its settings for what it indexes, as its Indexing tab saves them. */
	const POST_TYPES = 'relevanssi_index_post_types';

	const TAXONOMIES = 'relevanssi_index_taxonomies_list';

	/** "on" or "off": image attachments in its Build the index. */
	const IMAGE_FILES = 'relevanssi_index_image_files';

	/** Whether Relevanssi is active. */
	public static function active() {

		return function_exists( 'relevanssi_do_query' );
	}

	/** Whether Relevanssi can index a post. */
	public static function indexes() {

		return function_exists( 'relevanssi_index_doc' );
	}

	/** A post or image indexed again. */
	public static function index( $id ) {

		relevanssi_index_doc( (int) $id, true, relevanssi_get_custom_fields(), true );
	}

	/** The query run by Relevanssi, its posts found. */
	public static function search( WP_Query $query ) {

		relevanssi_do_query( $query );
	}

	/**
	 * What Relevanssi indexes, as its settings have it.
	 *
	 * @return array post_types and taxonomies (names), image_files (bool).
	 */
	public static function indexing() {

		return [
			'post_types'  => array_values( array_map( 'strval', (array) get_option( self::POST_TYPES, [] ) ) ),
			'taxonomies'  => array_values( array_map( 'strval', (array) get_option( self::TAXONOMIES, [] ) ) ),
			// As Relevanssi reads it: images are left out only when "off".
			'image_files' => 'off' !== get_option( self::IMAGE_FILES, 'off' ),
		];
	}

	/**
	 * Saves what Relevanssi indexes, as its Indexing tab does: each setting
	 * that changed, with the autoloading Relevanssi gives it.
	 *
	 * @param array $indexing As indexing() gives it.
	 */
	public static function setIndexing( array $indexing ) {

		$now = self::indexing();

		if ( $now['post_types'] !== $indexing['post_types'] ) {
			update_option( self::POST_TYPES, array_values( $indexing['post_types'] ) );
		}

		if ( $now['taxonomies'] !== $indexing['taxonomies'] ) {
			update_option( self::TAXONOMIES, array_values( $indexing['taxonomies'] ), false );
		}

		if ( $now['image_files'] !== $indexing['image_files'] ) {
			update_option( self::IMAGE_FILES, $indexing['image_files'] ? 'on' : 'off', true );
		}
	}

	/**
	 * Relevanssi indexing more custom fields of a post: the callback is given
	 * the fields and the post ID, and returns the fields.
	 *
	 * @param callable $fields
	 */
	public static function indexingFields( callable $fields ) {

		add_filter( 'relevanssi_index_custom_fields', $fields, 10, 2 );
	}

	/**
	 * Relevanssi's results filtered: the callback is given [ posts found,
	 * search terms ] and the WP_Query, and returns them.
	 *
	 * @param callable $hits
	 */
	public static function filteringResults( callable $hits ) {

		add_filter( 'relevanssi_hits_filter', $hits, 10, 2 );
	}
}
