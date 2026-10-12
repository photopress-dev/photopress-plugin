<?php

namespace PhotoPress\jobs;

/**
 * The media library's images, for a job over them: every image attachment,
 * or those in $args['ids'], by ID.
 */
class ImageQuery {

	/**
	 * The images, for a WHERE clause, with the posts table's alias if it
	 * has one. Integers only; not for $wpdb->prepare(), which would take
	 * its % for a placeholder.
	 */
	public static function where( $alias = '', array $args = [] ) {

		$column = $alias ? "{$alias}." : '';
		$ids    = array_filter( array_map( 'intval', (array) ( $args['ids'] ?? [] ) ) );

		return "{$column}post_type = 'attachment' AND {$column}post_mime_type LIKE 'image/%'"
			. ( $ids ? " AND {$column}ID IN (" . implode( ',', $ids ) . ')' : '' );
	}

	/** How many there are. */
	public static function count( $args = [] ) {

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- integers.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE " . self::where( '', (array) $args ) );
	}

	/** The next after $after, by ID. @return int[] */
	public static function next( $after, $limit, $args = [] ) {

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- integers.
		return array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE " . self::where( '', (array) $args ) . $wpdb->prepare( ' AND ID > %d ORDER BY ID LIMIT %d', (int) $after, (int) $limit ) ) );
	}
}
