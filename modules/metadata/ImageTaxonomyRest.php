<?php

namespace PhotoPress\modules\metadata;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The image taxonomies are in the REST API (show_in_rest) so that the block
 * editor and core blocks can use them -- the Image Taxonomies block, Post
 * Terms, Query Loop filters -- but only for users who can edit posts.
 *
 * Without these gates show_in_rest would publish, to anyone: every term,
 * including terms used only on unpublished or private images (people's names
 * among them), through the term endpoints and the term search, which do not
 * hide empty terms; the taxonomies themselves; and each image's term ids and
 * term filters in /wp/v2/media. None of that is public today.
 */
class ImageTaxonomyRest {

	/**
	 * Whether the current request may read the image taxonomies over REST.
	 */
	public static function canRead() {

		return current_user_can( 'edit_posts' );
	}

	/**
	 * @return string[] Names of the attachment taxonomies registered by PhotoPress.
	 */
	public static function taxonomies() {

		$names = [];

		foreach ( get_object_taxonomies( 'attachment', 'objects' ) as $taxonomy ) {

			if ( isset( $taxonomy->rest_controller_class ) && TermsController::class === $taxonomy->rest_controller_class ) {
				$names[] = $taxonomy->name;
			}
		}

		return $names;
	}

	/**
	 * register_taxonomy() arguments that put a taxonomy in REST behind the gate.
	 */
	public static function taxonomyArgs() {

		return [
			'show_in_rest'          => true,
			'rest_controller_class' => TermsController::class,
		];
	}

	public static function addHooks() {

		add_filter( 'rest_endpoints', [ self::class, 'removeRoutes' ] );
		add_filter( 'rest_attachment_item_schema', [ self::class, 'removeFromMediaSchema' ] );
		add_filter( 'rest_post_dispatch', [ self::class, 'hideTaxonomies' ], 10, 3 );
		add_filter( 'rest_term_search_query', [ self::class, 'limitTermSearch' ] );
		add_filter( 'rest_prepare_attachment', [ self::class, 'stripMediaTerms' ] );
		add_filter( 'rest_attachment_query', [ self::class, 'ignoreMediaTermFilters' ] );
		add_filter( 'rest_queried_resource_route', [ self::class, 'noAlternateLink' ] );
	}

	/**
	 * For anyone else the term routes do not exist, as before: a request gets
	 * 404 rather than 401, and the REST index does not list them. The media
	 * route loses its term filter arguments too. Applied after authentication,
	 * as WordPress filters the routes on every dispatch. TermsController's
	 * permission check stays as a second layer.
	 */
	public static function removeRoutes( $endpoints ) {

		if ( self::canRead() ) {
			return $endpoints;
		}

		foreach ( self::taxonomies() as $name ) {

			$taxonomy = get_taxonomy( $name );
			$base = $taxonomy->rest_base ?: $name;
			$namespace = $taxonomy->rest_namespace ?: 'wp/v2';

			unset( $endpoints[ "/$namespace/$base" ], $endpoints[ "/$namespace/$base/(?P<id>[\\d]+)" ] );

			foreach ( [ '/wp/v2/media', '/wp/v2/media/(?P<id>[\\d]+)' ] as $route ) {

				if ( empty( $endpoints[ $route ] ) || ! is_array( $endpoints[ $route ] ) ) {
					continue;
				}

				foreach ( $endpoints[ $route ] as $i => $handler ) {

					if ( is_array( $handler ) && isset( $handler['args'] ) ) {
						unset( $endpoints[ $route ][ $i ]['args'][ $base ], $endpoints[ $route ][ $i ]['args'][ $base . '_exclude' ] );
					}
				}
			}
		}

		// The term search accepts any show_in_rest taxonomy as a subtype; a
		// name it rejects (400) looks the same as one that does not exist.
		foreach ( (array) ( $endpoints['/wp/v2/search'] ?? [] ) as $i => $handler ) {

			if ( is_array( $handler ) && isset( $handler['args']['subtype']['items']['enum'] ) ) {
				$endpoints['/wp/v2/search'][ $i ]['args']['subtype']['items']['enum'] = array_values(
					array_diff( $handler['args']['subtype']['items']['enum'], self::taxonomies() )
				);
			}
		}

		return $endpoints;
	}

	/**
	 * The media schema (OPTIONS /wp/v2/media, and the describedby link) lists
	 * each taxonomy as a property.
	 */
	public static function removeFromMediaSchema( $schema ) {

		if ( self::canRead() ) {
			return $schema;
		}

		$bases = [];

		foreach ( self::taxonomies() as $name ) {
			$bases[] = get_taxonomy( $name )->rest_base ?: $name;
		}

		foreach ( $bases as $base ) {
			unset( $schema['properties'][ $base ] );
		}

		// The "assign terms" and "create terms" action links, one pair per taxonomy.
		if ( ! empty( $schema['links'] ) ) {

			$schema['links'] = array_values( array_filter(
				$schema['links'],
				static function ( $link ) use ( $bases ) {
					foreach ( $bases as $base ) {
						if ( isset( $link['rel'] ) && preg_match( '#/action-(assign|create)-' . preg_quote( $base, '#' ) . '$#', $link['rel'] ) ) {
							return false;
						}
					}
					return true;
				}
			) );
		}

		return $schema;
	}

	/**
	 * /wp/v2/taxonomies lists every show_in_rest taxonomy to anyone, and
	 * /wp/v2/taxonomies/<name> describes it.
	 */
	public static function hideTaxonomies( $response, $server, WP_REST_Request $request ) {

		if ( self::canRead() || ! $response instanceof WP_REST_Response || $response->is_error() ) {
			return $response;
		}

		$route = $request->get_route();

		if ( '/wp/v2/taxonomies' === $route ) {

			$data = $response->get_data();

			foreach ( self::taxonomies() as $name ) {
				unset( $data[ $name ] );
			}

			$response->set_data( $data );

		} elseif ( preg_match( '#^/wp/v2/taxonomies/([^/]+)$#', $route, $m ) && in_array( $m[1], self::taxonomies(), true ) ) {

			$error = new WP_Error( 'rest_taxonomy_invalid', __( 'Invalid taxonomy.' ), [ 'status' => 404 ] );

			return rest_convert_error_to_response( $error );
		}

		return $response;
	}

	/**
	 * /wp/v2/search?type=term searches the terms of every show_in_rest taxonomy,
	 * empty ones included.
	 */
	public static function limitTermSearch( $query_args ) {

		if ( self::canRead() ) {
			return $query_args;
		}

		$remaining = array_values( array_diff( (array) $query_args['taxonomy'], self::taxonomies() ) );

		$query_args['taxonomy'] = $remaining;

		// An empty taxonomy list would search every taxonomy.
		if ( ! $remaining ) {
			$query_args['include'] = [ 0 ];
		}

		return $query_args;
	}

	/**
	 * Each image in /wp/v2/media carries its term ids and links to the terms.
	 */
	public static function stripMediaTerms( $response ) {

		if ( self::canRead() || ! $response instanceof WP_REST_Response ) {
			return $response;
		}

		$data = $response->get_data();

		foreach ( self::taxonomies() as $name ) {

			$base = get_taxonomy( $name )->rest_base ?: $name;
			unset( $data[ $base ] );
		}

		$response->set_data( $data );

		$links = $response->get_links();

		if ( isset( $links['https://api.w.org/term'] ) ) {

			foreach ( $links['https://api.w.org/term'] as $link ) {

				if ( isset( $link['attributes']['taxonomy'] ) && in_array( $link['attributes']['taxonomy'], self::taxonomies(), true ) ) {
					$response->remove_link( 'https://api.w.org/term', $link['href'] );
				}
			}
		}

		return $response;
	}

	/**
	 * /wp/v2/media?photos_city=34 filters images by term.
	 */
	public static function ignoreMediaTermFilters( $args ) {

		if ( self::canRead() || empty( $args['tax_query'] ) ) {
			return $args;
		}

		$ours = self::taxonomies();

		$args['tax_query'] = array_values( array_filter(
			$args['tax_query'],
			static function ( $clause ) use ( $ours ) {
				return ! is_array( $clause ) || ! isset( $clause['taxonomy'] ) || ! in_array( $clause['taxonomy'], $ours, true );
			}
		) );

		return $args;
	}

	/**
	 * Term archive pages advertise their REST resource in a <link> tag and a
	 * Link header, for anyone.
	 */
	public static function noAlternateLink( $route ) {

		$term = get_queried_object();

		if ( $term instanceof \WP_Term && in_array( $term->taxonomy, self::taxonomies(), true ) ) {
			return '';
		}

		return $route;
	}
}
