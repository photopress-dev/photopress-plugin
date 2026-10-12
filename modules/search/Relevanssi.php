<?php

namespace PhotoPress\modules\search;

use WP_Query;

/**
 * Relevanssi, as PhotoPress uses it: the one place that knows its function,
 * filter and setting names.
 *
 * Its documented functions (relevanssi_do_query(), and relevanssi_index_doc()
 * with relevanssi_get_custom_fields() as its documentation shows) and filters
 * (relevanssi_index_custom_fields, relevanssi_hits_filter). Which post types
 * and taxonomies it indexes it has no filter for: they are its settings,
 * filtered where WordPress reads them (indexing()). Should Relevanssi add a
 * filter for them, only indexing() changes.
 */
class Relevanssi {

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
	 * Relevanssi indexing more post types and taxonomies than its settings
	 * list: each callback is given the list (an array, or what was saved)
	 * and returns it.
	 *
	 * @param callable $post_types
	 * @param callable $taxonomies
	 */
	public static function indexing( callable $post_types, callable $taxonomies ) {

		foreach ( [ 'option_', 'default_option_' ] as $read ) {
			add_filter( $read . 'relevanssi_index_post_types', $post_types );
			add_filter( $read . 'relevanssi_index_taxonomies_list', $taxonomies );
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
