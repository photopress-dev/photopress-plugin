<?php

namespace PhotoPress\modules\search;

defined( 'ABSPATH' ) || exit;

use PhotoPress\modules\metadata\ImageTaxonomies;
use WP_Query;

/**
 * The Image Filters block: on an image search, a menu per image taxonomy of
 * the terms the images found have, each with how many of them have it, to
 * narrow the search by; and the filters applied, each to be taken off.
 *
 * A filter is the taxonomy's own query var, with term slugs, which WordPress
 * already turns into a taxonomy query on the search, with Relevanssi or
 * without: ?s=bridge&photos_people=ken-thompson+dennis-ritchie finds the
 * images of the bridge with both of them. Every filter narrows the search;
 * a menu lists only terms that leave some images and take some away.
 *
 * Search results are not cached, so each filter link is a search. The links
 * are nofollow, search results are noindex (WordPress's own wp_robots), the
 * query vars are disallowed in robots.txt, and past MOST filters the menus
 * are not offered.
 */
class ImageFilters {

	/** At most this many filters at once; past it, only those to take off. */
	const MOST = 4;

	/** A menu's terms before its More list. */
	const FIRST = 20;

	/** Its More list at most. */
	const MORE = 200;

	/**
	 * The ids of every image the main search found, as Relevanssi gave them
	 * before splitting them into pages; null until it has.
	 *
	 * @var int[]|null
	 */
	private static $hits = null;

	public static function addHooks() {

		register_block_type( __DIR__ . '/blocks/image-filters', [
			'render_callback' => [ self::class, 'render' ],
		] );

		// After ImageSearch::onlyImages(), added before it.
		Relevanssi::filteringResults( [ self::class, 'keepHits' ] );
		add_filter( 'robots_txt', [ self::class, 'robots' ], 20, 2 );
	}

	/**
	 * relevanssi_hits_filter: the main image search's hits, kept for the
	 * menus' counts, as they are.
	 */
	public static function keepHits( $hits, $query = null ) {

		if ( $query instanceof WP_Query && $query->is_main_query() && $query->get( ImageSearch::FLAG ) ) {
			self::$hits = array_map( static fn( $hit ) => (int) ( is_object( $hit ) ? $hit->ID : $hit ), (array) ( $hits[0] ?? [] ) );
		}

		return $hits;
	}

	/**
	 * The ids of every image the search found: Relevanssi's, or the search
	 * again without pages.
	 *
	 * @param WP_Query $query
	 * @return int[]
	 */
	public static function hits( $query ) {

		if ( null !== self::$hits ) {
			return self::$hits;
		}

		$all = new WP_Query();

		return array_map( 'intval', (array) $all->query( array_merge( $query->query_vars, [
			'fields'                 => 'ids',
			'nopaging'               => true,
			'posts_per_page'         => -1,
			'paged'                  => 0,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		] ) ) );
	}

	/**
	 * The image taxonomies the block offers: those visitors see, with a
	 * query var to filter by, but those it hides.
	 *
	 * @param string[] $hidden
	 * @return string[]
	 */
	public static function taxonomies( array $hidden = [] ) {

		return array_values( array_filter( ImageTaxonomies::shownImageTaxonomies(), static fn( $name ) => ! in_array( $name, $hidden, true ) && '' !== self::queryVar( $name ) ) );
	}

	/** A taxonomy's query var, or '' when it has none. */
	public static function queryVar( $taxonomy ) {

		$object = get_taxonomy( $taxonomy );

		return $object && ! empty( $object->query_var ) ? (string) $object->query_var : '';
	}

	/**
	 * The filters applied: term slugs by taxonomy, as the query vars have
	 * them ("a+b", which WordPress reads from "a b" too, or "a,b").
	 *
	 * @param string[] $taxonomies
	 * @return array<string, string[]>
	 */
	public static function applied( array $taxonomies ) {

		$applied = [];

		foreach ( $taxonomies as $taxonomy ) {

			$value = get_query_var( self::queryVar( $taxonomy ) );
			$value = is_array( $value ) ? implode( ',', $value ) : (string) $value;
			$slugs = array_values( array_unique( array_filter( preg_split( '/[+,\s]+/', $value ), 'strlen' ) ) );

			if ( $slugs ) {
				$applied[ $taxonomy ] = $slugs;
			}
		}

		return $applied;
	}

	/**
	 * A search's address with these filters: the words, then each
	 * taxonomy's terms joined by +, in the order of $taxonomies, so that one
	 * set of filters has one address.
	 *
	 * @param string                  $words
	 * @param array<string, string[]> $applied
	 * @param string[]                $taxonomies
	 */
	public static function url( $words, array $applied, array $taxonomies ) {

		$args = [ 's' => rawurlencode( $words ) ];

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! empty( $applied[ $taxonomy ] ) ) {
				$args[ self::queryVar( $taxonomy ) ] = implode( '+', $applied[ $taxonomy ] );
			}
		}

		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * Each taxonomy's terms among the images found, with how many have each,
	 * most first, then by name: those applied left out, and those every
	 * image found has, which would not narrow it.
	 *
	 * @param object[]                $terms   wp_get_object_terms() with all_with_object_id.
	 * @param int                     $found   How many images were found.
	 * @param array<string, string[]> $applied
	 * @return array<string, array{term: object, count: int}[]>
	 */
	public static function tally( array $terms, $found, array $applied ) {

		$tally = [];

		foreach ( $terms as $term ) {

			if ( in_array( $term->slug, $applied[ $term->taxonomy ] ?? [], true ) ) {
				continue;
			}

			if ( ! isset( $tally[ $term->taxonomy ][ $term->term_id ] ) ) {
				$tally[ $term->taxonomy ][ $term->term_id ] = [ 'term' => $term, 'count' => 0 ];
			}

			$tally[ $term->taxonomy ][ $term->term_id ]['count']++;
		}

		foreach ( $tally as $taxonomy => $rows ) {

			$rows = array_values( array_filter( $rows, static fn( $row ) => $row['count'] < $found ) );

			usort( $rows, static fn( $a, $b ) => $b['count'] <=> $a['count'] ?: strcasecmp( $a['term']->name, $b['term']->name ) );

			if ( $rows ) {
				$tally[ $taxonomy ] = $rows;
			} else {
				unset( $tally[ $taxonomy ] );
			}
		}

		return $tally;
	}

	/**
	 * render_callback of photopress/image-filters: on an image search with
	 * words, the filters applied, then the menus.
	 */
	public static function render( $attributes ) {

		global $wp_query;

		if ( ! is_search() || ! $wp_query instanceof WP_Query || ! $wp_query->get( ImageSearch::FLAG ) || ImageSearch::blank( $wp_query ) ) {
			return '';
		}

		$taxonomies = self::taxonomies( array_map( 'strval', (array) ( $attributes['hiddenTaxonomies'] ?? [] ) ) );

		if ( ! $taxonomies ) {
			return '';
		}

		$words   = get_search_query( false );
		$applied = self::applied( $taxonomies );
		$counts  = ! isset( $attributes['showCounts'] ) || $attributes['showCounts'];
		$active  = self::renderApplied( $words, $applied, $taxonomies );
		$menus   = '';

		if ( array_sum( array_map( 'count', $applied ) ) < self::MOST ) {

			$ids   = self::hits( $wp_query );
			$terms = $ids ? wp_get_object_terms( $ids, $taxonomies, [ 'fields' => 'all_with_object_id', 'update_term_meta_cache' => false ] ) : [];
			$tally = is_wp_error( $terms ) ? [] : self::tally( $terms, count( $ids ), $applied );

			foreach ( $taxonomies as $taxonomy ) {
				if ( ! empty( $tally[ $taxonomy ] ) ) {
					$menus .= self::renderMenu( $taxonomy, $tally[ $taxonomy ], $words, $applied, $taxonomies, $counts );
				}
			}
		}

		if ( '' === $active && '' === $menus ) {
			return '';
		}

		return sprintf(
			'<nav %s aria-label="%s">%s%s</nav>',
			get_block_wrapper_attributes(),
			esc_attr__( 'Filter the images found', 'photopress' ),
			$active,
			'' !== $menus ? '<div class="photopress-image-filters__menus">' . $menus . '</div>' : ''
		);
	}

	/** The filters applied, each a link without it, and one without any. */
	private static function renderApplied( $words, array $applied, array $taxonomies ) {

		if ( ! $applied ) {
			return '';
		}

		$items = '';

		foreach ( $applied as $taxonomy => $slugs ) {
			foreach ( $slugs as $slug ) {

				$term = get_term_by( 'slug', $slug, $taxonomy );
				$name = $term ? $term->name : $slug;
				$less = $applied;
				$less[ $taxonomy ] = array_values( array_diff( $slugs, [ $slug ] ) );

				$items .= sprintf(
					'<li><a href="%s" rel="nofollow">%s<span class="photopress-image-filters__remove" aria-hidden="true">×</span><span class="screen-reader-text">%s</span></a></li>',
					esc_url( self::url( $words, $less, $taxonomies ) ),
					esc_html( $name ),
					/* translators: %s: a term's name. */
					esc_html( sprintf( __( 'Remove the filter %s', 'photopress' ), $name ) )
				);
			}
		}

		$items .= sprintf(
			'<li class="photopress-image-filters__clear"><a href="%s" rel="nofollow">%s</a></li>',
			esc_url( self::url( $words, [], $taxonomies ) ),
			esc_html__( 'Clear all', 'photopress' )
		);

		return '<ul class="photopress-image-filters__applied">' . $items . '</ul>';
	}

	/**
	 * A taxonomy's menu: its first terms, and the rest in a More list. The
	 * menus share a name, so one open closes the others.
	 */
	private static function renderMenu( $taxonomy, array $rows, $words, array $applied, array $taxonomies, $counts ) {

		$object = get_taxonomy( $taxonomy );
		$items  = [];

		foreach ( array_slice( $rows, 0, self::FIRST + self::MORE ) as $row ) {

			$more = $applied;
			$more[ $taxonomy ][] = $row['term']->slug;

			$items[] = sprintf(
				'<li><a href="%s" rel="nofollow"><span class="photopress-image-filters__term">%s</span>%s</a></li>',
				esc_url( self::url( $words, $more, $taxonomies ) ),
				esc_html( $row['term']->name ),
				$counts ? '<span class="photopress-image-filters__count">' . esc_html( number_format_i18n( $row['count'] ) ) . '</span>' : ''
			);
		}

		$rest = array_slice( $items, self::FIRST );

		return sprintf(
			'<details class="photopress-image-filters__menu photopress-image-filters__menu--%s" name="photopress-image-filters"><summary>%s</summary><div class="photopress-image-filters__panel"><ul>%s</ul>%s</div></details>',
			esc_attr( $taxonomy ),
			esc_html( $object ? $object->labels->name : $taxonomy ),
			implode( '', array_slice( $items, 0, self::FIRST ) ),
			$rest ? sprintf( '<details class="photopress-image-filters__more"><summary>%s</summary><ul>%s</ul></details>', esc_html__( 'More', 'photopress' ), implode( '', $rest ) ) : ''
		);
	}

	/**
	 * robots_txt: with images in search results, no crawling of addresses
	 * with an image taxonomy's query var, which are the filter links.
	 *
	 * @param string $output
	 * @param string $public The site's Search engine visibility: "0" asks them not to index it.
	 */
	public static function robots( $output, $public ) {

		if ( ! $public || ! ImageSearch::imagesOn() ) {
			return $output;
		}

		$lines = [];

		foreach ( self::taxonomies() as $taxonomy ) {
			$lines[] = 'Disallow: /*?*' . self::queryVar( $taxonomy ) . '=';
		}

		if ( ! $lines ) {
			return $output;
		}

		return rtrim( (string) $output ) . "\n\n# PhotoPress: the search results' image filters\nUser-agent: *\n" . implode( "\n", $lines ) . "\n";
	}
}
