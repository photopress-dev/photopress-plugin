<?php

namespace PhotoPress\modules\search;

use PhotoPress\jobs\Jobs;
use PhotoPress\modules\metadata\ImageTaxonomies;
use PhotoPress\modules\metadata\metadata;
use WP_Query;

/**
 * Images in a site's search results, with the Pages & Search tab's Images in
 * search results switch (off by default).
 *
 * The front end's main search query lists public images: with the theme's
 * own search template, alongside posts and pages unless their switch is off;
 * in a block theme, PhotoPress's Image Search template shows the images and
 * lists posts and pages apart (the Search Posts block), so the main query is
 * of images alone. A search for a post type of its own (?post_type=post) is
 * left as it is.
 *
 * An image is found by its title, caption, description and alt text, and by
 * the names of its terms in the image taxonomies visitors may see. Without
 * Relevanssi, by extending core's search SQL; with it, Relevanssi's index
 * includes images and those taxonomies, through its options (filtered here,
 * so its settings screen shows the same) and its hooks.
 */
class ImageSearch {

	/** A query var: the query is the image search this class set up. */
	const FLAG = 'photopress_image_search';

	/** A search template for block themes, ahead of the theme's search. */
	const TEMPLATE = 'search-images';

	/** The job that adds every image to Relevanssi's index. */
	const JOB = 'search.index';

	/** Images whose terms changed in this request, to index again. */
	private static $changed = [];

	public static function addHooks() {

		add_action( 'pre_get_posts', [ self::class, 'searchImages' ] );
		add_filter( 'posts_search', [ self::class, 'searchSql' ], 10, 2 );
		add_filter( 'search_template_hierarchy', [ self::class, 'addTemplate' ] );

		register_block_type( __DIR__ . '/blocks/search-posts', [
			'render_callback' => [ self::class, 'renderPosts' ],
		] );

		if ( self::imagesOn() && function_exists( 'register_block_template' ) ) {
			$file = __DIR__ . '/templates/' . self::TEMPLATE . '.html';
			register_block_template( 'photopress//' . self::TEMPLATE, [
				'title'       => __( 'Image Search', 'photopress' ),
				'description' => __( 'Search results with images: the images found in a Taxonomy Gallery, and the posts and pages found apart. From PhotoPress.', 'photopress' ),
				'content'     => is_readable( $file ) ? (string) file_get_contents( $file ) : '',
			] );
		}

		// Relevanssi's settings, as PhotoPress has them, wherever they are read.
		foreach ( [ 'option_relevanssi_index_taxonomies_list', 'default_option_relevanssi_index_taxonomies_list' ] as $hook ) {
			add_filter( $hook, [ self::class, 'relevanssiTaxonomies' ] );
		}
		foreach ( [ 'option_relevanssi_index_post_types', 'default_option_relevanssi_index_post_types' ] as $hook ) {
			add_filter( $hook, [ self::class, 'relevanssiPostTypes' ] );
		}
		add_filter( 'relevanssi_index_custom_fields', [ self::class, 'relevanssiAltText' ], 10, 2 );
		add_filter( 'relevanssi_hits_filter', [ self::class, 'relevanssiOnlyImages' ], 10, 2 );
		add_action( 'set_object_terms', [ self::class, 'termsChanged' ], 10, 4 );

		if ( self::relevanssi() ) {

			Jobs::register( self::JOB, [
				'label'       => __( 'Add images to the search index', 'photopress' ),
				'description' => __( 'Indexes every image in Relevanssi, with its terms and alt text.', 'photopress' ),
				'count'       => [ self::class, 'countImages' ],
				'items'       => [ self::class, 'nextImages' ],
				'process'     => [ self::class, 'indexImage' ],
				// Indexing writes to the database: a few at a time, resting
				// between batches, and not while the server is busy.
				'batch_seconds' => 10,
				'batch_items'   => 25,
				'pace'          => true,
			] );

			// Images turned on: into the index, without a trip to Relevanssi.
			add_action( 'add_option_' . search::OPTION, static fn( $option, $value ) => self::maybeIndex( [], $value ), 10, 2 );
			add_action( 'update_option_' . search::OPTION, [ self::class, 'maybeIndex' ], 10, 2 );
		}
	}

	/**
	 * When images are turned on in search results, the job that adds them to
	 * Relevanssi's index: once, while its index has fewer images than the
	 * site and none is running. After that Relevanssi indexes new and edited
	 * images itself, and termsChanged() those whose terms change.
	 */
	public static function maybeIndex( $before, $after ) {

		$was = is_array( $before ) && ! empty( $before['images_in_search'] );
		$is  = is_array( $after ) && ! empty( $after['images_in_search'] );

		if ( $is && ! $was && self::indexedImages() < self::countImages() ) {

			foreach ( Jobs::recent( self::JOB ) as $job ) {
				if ( in_array( $job['status'] ?? '', [ 'queued', 'running' ], true ) ) {
					return;
				}
			}

			Jobs::start( self::JOB );
		}
	}

	/** The images Relevanssi's index has. */
	public static function indexedImages() {

		global $wpdb;

		$table = $wpdb->prefix . 'relevanssi';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name.
		return (int) $wpdb->get_var( "SELECT COUNT( DISTINCT doc ) FROM {$table} WHERE type = 'attachment'" );
	}

	/** The job's count: every image. */
	public static function countImages() {

		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'" );
	}

	/** The job's items: the next images by ID. @return int[] */
	public static function nextImages( $after, $limit ) {

		global $wpdb;

		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%%' AND ID > %d ORDER BY ID ASC LIMIT %d",
			(int) $after,
			(int) $limit
		) ) );
	}

	/** The job's work: one image, indexed again. */
	public static function indexImage( $id ) {

		if ( ! function_exists( 'relevanssi_index_doc' ) ) {
			return new \WP_Error( 'photopress_no_relevanssi', __( 'Relevanssi is not active.', 'photopress' ) );
		}

		relevanssi_index_doc( (int) $id, true, relevanssi_get_custom_fields(), true );

		return true;
	}

	public static function imagesOn() {

		return search::enabled( 'images_in_search', false );
	}

	public static function postsOn() {

		return search::enabled( 'posts_in_search' );
	}

	/** Whether Relevanssi is the site's search. */
	public static function relevanssi() {

		return function_exists( 'relevanssi_do_query' );
	}

	/**
	 * The taxonomies images are found by: those for images alone that
	 * visitors may see.
	 *
	 * @return string[]
	 */
	public static function taxonomies() {

		return array_values( array_filter(
			get_object_taxonomies( 'attachment', 'names' ),
			static fn( $name ) => metadata::areImageTaxonomies( [ $name ] ) && ImageTaxonomies::isShown( $name )
		) );
	}

	/**
	 * The post types listed with images: those core searches, but images.
	 *
	 * @return string[]
	 */
	public static function postTypes() {

		return array_values( array_diff( get_post_types( [ 'exclude_from_search' => false ] ), [ 'attachment' ] ) );
	}

	/**
	 * pre_get_posts: the front end's main search, with images.
	 *
	 * @param WP_Query $query
	 */
	public static function searchImages( $query ) {

		if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() || ! self::imagesOn() ) {
			return;
		}

		// A search of a post type of its own, such as "all matching posts".
		$asked = $query->get( 'post_type' );

		if ( ! empty( $asked ) && 'any' !== $asked ) {
			return;
		}

		// In a block theme, Image Search lists posts and pages apart.
		$types = self::postsOn() && ! wp_is_block_theme() ? array_merge( [ 'attachment' ], self::postTypes() ) : [ 'attachment' ];

		$query->set( 'post_type', $types );
		$query->set( 'post_status', count( $types ) > 1 ? [ 'publish', 'inherit' ] : 'inherit' );
		$query->set( metadata::PUBLIC_IMAGES, true );
		$query->set( self::FLAG, true );
	}

	/**
	 * posts_search, for the image search: core's search, word by word, with
	 * an image also found by its alt text and its terms; only images among
	 * attachments. Not with Relevanssi, which searches its index instead.
	 *
	 * @param string   $search The search SQL core made.
	 * @param WP_Query $query
	 */
	public static function searchSql( $search, $query ) {

		global $wpdb;

		if ( '' === $search || ! $query instanceof WP_Query || ! $query->get( self::FLAG ) || self::relevanssi() ) {
			return $search;
		}

		$words = (array) $query->get( 'search_terms' );

		if ( ! $words ) {
			return $search;
		}

		$taxonomies = self::taxonomies();
		$in         = $taxonomies ? implode( ', ', array_fill( 0, count( $taxonomies ), '%s' ) ) : '';
		$exclusion  = apply_filters( 'wp_query_search_exclusion_prefix', '-' );
		$clauses    = [];

		foreach ( $words as $word ) {

			$exclude = $exclusion && 0 === strpos( $word, $exclusion ) && strlen( $word ) > strlen( $exclusion );

			if ( $exclude ) {
				$word = substr( $word, strlen( $exclusion ) );
			}

			$like  = '%' . $wpdb->esc_like( $word ) . '%';
			$parts = [
				$wpdb->prepare( "{$wpdb->posts}.post_title LIKE %s", $like ),
				$wpdb->prepare( "{$wpdb->posts}.post_excerpt LIKE %s", $like ),
				$wpdb->prepare( "{$wpdb->posts}.post_content LIKE %s", $like ),
				$wpdb->prepare( "EXISTS ( SELECT 1 FROM {$wpdb->postmeta} AS photopress_alt WHERE photopress_alt.post_id = {$wpdb->posts}.ID AND photopress_alt.meta_key = '_wp_attachment_image_alt' AND photopress_alt.meta_value LIKE %s )", $like ),
			];

			if ( $taxonomies ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is placeholders.
				$parts[] = $wpdb->prepare( "EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} AS photopress_tr INNER JOIN {$wpdb->term_taxonomy} AS photopress_tt ON photopress_tt.term_taxonomy_id = photopress_tr.term_taxonomy_id INNER JOIN {$wpdb->terms} AS photopress_t ON photopress_t.term_id = photopress_tt.term_id WHERE photopress_tr.object_id = {$wpdb->posts}.ID AND photopress_tt.taxonomy IN ( $in ) AND photopress_t.name LIKE %s )", ...array_merge( $taxonomies, [ $like ] ) );
			}

			$clauses[] = ( $exclude ? 'NOT ' : '' ) . '(' . implode( ' OR ', $parts ) . ')';
		}

		$sql = ' AND (' . implode( ' AND ', $clauses ) . ')';
		$sql .= " AND ( {$wpdb->posts}.post_type <> 'attachment' OR {$wpdb->posts}.post_mime_type LIKE 'image/%' )";

		if ( ! is_user_logged_in() ) {
			$sql .= " AND ( {$wpdb->posts}.post_password = '' )";
		}

		return $sql . ' ';
	}

	/**
	 * search_template_hierarchy: Image Search before the theme's search, for
	 * the image search in a block theme.
	 *
	 * @param string[] $templates
	 * @return string[]
	 */
	public static function addTemplate( $templates ) {

		global $wp_query;

		if ( ! wp_is_block_theme() || ! $wp_query instanceof WP_Query || ! $wp_query->get( self::FLAG ) ) {
			return $templates;
		}

		$at = array_search( 'search.php', (array) $templates, true );
		array_splice( $templates, false === $at ? 0 : $at, 0, [ self::TEMPLATE . '.php' ] );

		return $templates;
	}

	/**
	 * render_callback of photopress/search-posts: on an image search, the
	 * posts and pages found, a few, with a link to all of them; and when
	 * neither they nor images were found, a note saying so.
	 */
	public static function renderPosts( $attributes ) {

		global $wp_query;

		if ( ! is_search() || ! $wp_query instanceof WP_Query || ! $wp_query->get( self::FLAG ) ) {
			return '';
		}

		$terms  = get_search_query( false );
		$found  = null;
		$images = (int) $wp_query->found_posts;

		if ( self::postsOn() ) {

			$found = new WP_Query();
			$found->parse_query( [
				's'              => $terms,
				'post_type'      => self::postTypes(),
				'post_status'    => 'publish',
				'posts_per_page' => min( 20, max( 1, (int) ( $attributes['count'] ?? 5 ) ) ),
			] );

			if ( self::relevanssi() ) {
				relevanssi_do_query( $found );
			} else {
				$found->get_posts();
			}
		}

		$posts = $found ? (array) $found->posts : [];

		if ( ! $posts ) {
			return $images ? '' : sprintf(
				'<div %s><p>%s</p></div>',
				get_block_wrapper_attributes( [ 'class' => 'is-empty' ] ),
				/* translators: %s: the search terms. */
				esc_html( sprintf( __( 'Nothing was found for “%s”.', 'photopress' ), $terms ) )
			);
		}

		$items = '';

		foreach ( $posts as $post ) {
			$items .= sprintf(
				'<li><a href="%s">%s</a><p>%s</p></li>',
				esc_url( get_permalink( $post ) ),
				esc_html( get_the_title( $post ) ),
				esc_html( wp_trim_words( get_the_excerpt( $post ), 30 ) )
			);
		}

		$all = '';

		if ( (int) $found->found_posts > count( $posts ) ) {
			$all = sprintf(
				'<p class="photopress-search-posts__all"><a href="%s">%s</a></p>',
				esc_url( add_query_arg( [ 's' => rawurlencode( $terms ), 'post_type' => self::postTypes() ], home_url( '/' ) ) ),
				/* translators: %s: a number of posts and pages. */
				esc_html( sprintf( _n( 'The %s matching post or page', 'All %s matching posts and pages', (int) $found->found_posts, 'photopress' ), number_format_i18n( (int) $found->found_posts ) ) )
			);
		}

		return sprintf(
			'<section %s><h2>%s</h2><ul>%s</ul>%s</section>',
			get_block_wrapper_attributes(),
			esc_html( (string) ( $attributes['heading'] ?? __( 'Posts and pages', 'photopress' ) ) ),
			$items,
			$all
		);
	}

	/**
	 * option_relevanssi_index_taxonomies_list: the image taxonomies visitors
	 * may see are always indexed, with whatever else the site has chosen.
	 */
	public static function relevanssiTaxonomies( $taxonomies ) {

		return array_values( array_unique( array_merge( is_array( $taxonomies ) ? $taxonomies : [], self::taxonomies() ) ) );
	}

	/**
	 * option_relevanssi_index_post_types: images, while they are in search
	 * results.
	 */
	public static function relevanssiPostTypes( $types ) {

		$types = is_array( $types ) ? $types : [];

		return self::imagesOn() ? array_values( array_unique( array_merge( $types, [ 'attachment' ] ) ) ) : $types;
	}

	/**
	 * relevanssi_index_custom_fields: an image's alt text.
	 */
	public static function relevanssiAltText( $fields, $post_id ) {

		if ( 'attachment' !== get_post_type( $post_id ) ) {
			return $fields;
		}

		return array_values( array_unique( array_merge( is_array( $fields ) ? $fields : [], [ '_wp_attachment_image_alt' ] ) ) );
	}

	/**
	 * relevanssi_hits_filter, for the image search: only images among the
	 * attachments found, as without Relevanssi.
	 *
	 * @param array    $hits  [ 0 => posts found, 1 => the search terms ].
	 * @param WP_Query $query
	 */
	public static function relevanssiOnlyImages( $hits, $query = null ) {

		if ( ! $query instanceof WP_Query || ! $query->get( self::FLAG ) || empty( $hits[0] ) ) {
			return $hits;
		}

		$hits[0] = array_values( array_filter( $hits[0], static function ( $hit ) {
			$post = is_object( $hit ) ? $hit : get_post( $hit );
			return ! $post || 'attachment' !== $post->post_type || wp_attachment_is_image( $post );
		} ) );

		return $hits;
	}

	/**
	 * set_object_terms: Relevanssi indexes an image again when it is saved,
	 * not when only its terms change, as when PhotoPress reprocesses it. Those
	 * of the image taxonomies are indexed again at the end of the request.
	 */
	public static function termsChanged( $object_id, $terms, $tt_ids, $taxonomy ) {

		if ( ! self::relevanssi() || ! function_exists( 'relevanssi_index_doc' ) || ! in_array( $taxonomy, self::taxonomies(), true ) || 'attachment' !== get_post_type( $object_id ) ) {
			return;
		}

		if ( ! self::$changed ) {
			add_action( 'shutdown', [ self::class, 'indexChanged' ] );
		}

		self::$changed[ (int) $object_id ] = true;
	}

	/** shutdown: the images whose terms changed, indexed again. */
	public static function indexChanged() {

		$ids = array_keys( self::$changed );
		self::$changed = [];

		foreach ( $ids as $id ) {
			relevanssi_index_doc( $id, true, relevanssi_get_custom_fields(), true );
		}
	}
}
