<?php

namespace PhotoPress\modules\metadata;

/**
 * The terms an image gets in each of its taxonomies (TaxonomyModel), from its
 * metadata.
 *
 * Keywords are read as paths. A keyword hierarchy, as Lightroom and Capture
 * One write it (lr:hierarchicalSubject, "People|Family|Jane"), gives one path
 * per keyword; a plain keyword is a path of one, or of several when it starts
 * with a parent keyword and a separator ("people: Jane"). The plain keyword
 * list repeats the hierarchy's keywords, often with their parents ("Family"),
 * so a plain keyword that is a level of a hierarchy path is not read again.
 * The plain list says which keywords the image has: software that edits only
 * it leaves the hierarchy out of date, so a hierarchy path whose keyword is
 * not in the list is not read. Without a plain list, the hierarchy is all.
 *
 * A path under a parent keyword goes to the parent's taxonomy: all of its
 * levels below the parent as nested terms, when the parent is nested, else
 * its last level, the levels between going to Keywords. Every level of any
 * other path goes to Keywords.
 */
final class TermRouter {

	/** Keyword hierarchies by the field they are written in, first found wins. */
	const HIERARCHIES = [
		'lr:hierarchicalSubject'        => '|',
		'digiKam:TagsList'              => '/',
		'MicrosoftPhoto:LastKeywordXMP' => '/',
	];

	/**
	 * Taxonomy id => its terms: names, or for a nested taxonomy paths (lists
	 * of names, parent first). Every taxonomy of the model is present, so one
	 * the image has nothing for is emptied.
	 */
	public static function route( XmpReader $md, TaxonomyModel $model ): array {

		$terms = array_fill_keys( $model->taxonomyIds(), [] );

		// The taxonomies that take every value of a field, and the parent
		// keywords read from it.
		$takers  = [];
		$parents = [];

		foreach ( $model->standard as $standard ) {
			$takers[ $standard['tag'] ][] = $standard['id'];
		}

		foreach ( $model->custom as $custom ) {
			$takers[ $custom['tag'] ][] = $custom['id'];
		}

		foreach ( $model->parents as $parent ) {
			$parents[ $parent['tag'] ][] = $parent;
		}

		foreach ( array_unique( array_merge( array_keys( $takers ), array_keys( $parents ) ) ) as $tag ) {

			$tag_parents = $parents[ $tag ] ?? [];
			$tag_takers  = $takers[ $tag ] ?? [];

			foreach ( self::paths( $md, $tag, $tag_parents, $model->separators ) as $path ) {

				$match = self::parentOf( $path, $tag_parents );

				if ( ! $match ) {
					foreach ( $path as $level ) {
						self::add( $terms, $tag_takers, $level );
					}
					continue;
				}

				[ $parent, $depth ] = $match;
				$under = array_slice( $path, $depth );

				if ( $parent['nested'] ) {
					$terms[ $parent['id'] ][] = $under;
					continue;
				}

				$terms[ $parent['id'] ][] = array_pop( $under );

				foreach ( $under as $level ) {
					self::add( $terms, $tag_takers, $level );
				}
			}
		}

		foreach ( $terms as $id => $list ) {
			$terms[ $id ] = self::unique( $list );
		}

		return $terms;
	}

	/**
	 * A field's values as paths. Keywords (dc:subject) come with the image's
	 * keyword hierarchy.
	 */
	public static function paths( XmpReader $md, string $tag, array $parents, array $separators ): array {

		$values    = self::values( $md, $tag );
		$hierarchy = 'dc:subject' === $tag ? self::hierarchy( $md ) : [];
		$levels    = [];
		$paths     = [];

		if ( $values && $hierarchy ) {

			$listed    = array_flip( array_map( [ TaxonomyModel::class, 'lower' ], $values ) );
			$hierarchy = array_filter( $hierarchy, static function ( $path ) use ( $listed ) {
				return isset( $listed[ TaxonomyModel::lower( end( $path ) ) ] );
			} );
		}

		foreach ( $hierarchy as $path ) {

			foreach ( $path as $level ) {
				$levels[ TaxonomyModel::lower( $level ) ] = true;
			}

			$paths[] = 1 === count( $path ) ? self::split( $path[0], $parents, $separators ) : $path;
		}

		foreach ( $values as $value ) {
			if ( ! isset( $levels[ TaxonomyModel::lower( $value ) ] ) ) {
				$paths[] = self::split( $value, $parents, $separators );
			}
		}

		return $paths;
	}

	/** The image's keyword hierarchy: a path per keyword. */
	public static function hierarchy( XmpReader $md ): array {

		foreach ( self::HIERARCHIES as $tag => $separator ) {

			$paths = [];

			foreach ( self::strings( $md->getXmp( $tag ) ) as $value ) {

				$path = self::trimmed( explode( $separator, $value ) );

				if ( $path ) {
					$paths[] = $path;
				}
			}

			if ( $paths ) {
				return $paths;
			}
		}

		return [];
	}

	/**
	 * "people: family: Jane" as [ 'people', 'family', 'Jane' ] when people is
	 * a parent keyword; any other keyword whole, so a separator in a value
	 * ("Star Wars: A New Hope") does not split it.
	 */
	public static function split( string $keyword, array $parents, array $separators ): array {

		if ( $parents ) {

			foreach ( $separators as $separator ) {

				if ( strpos( $keyword, $separator ) > 0 ) {

					$parts = self::trimmed( explode( $separator, $keyword ) );

					if ( self::parentOf( $parts, $parents ) ) {
						return $parts;
					}
				}
			}
		}

		return [ $keyword ];
	}

	/**
	 * The parent keyword a path is under, and how many of its levels name it:
	 * the parent naming the most, so Clients|Acme wins over Clients. A path
	 * that is only the parent (the keyword "People") is under none.
	 */
	public static function parentOf( array $path, array $parents ): ?array {

		$lower = array_map( [ TaxonomyModel::class, 'lower' ], $path );
		$found = null;

		foreach ( $parents as $parent ) {
			foreach ( $parent['names'] as $name ) {

				$depth = count( $name );

				if ( $depth < count( $lower ) && ( ! $found || $depth > $found[1] ) && array_slice( $lower, 0, $depth ) === $name ) {
					$found = [ $parent, $depth ];
				}
			}
		}

		return $found;
	}

	/** A field's values. Camera and lens are read from all their places. */
	private static function values( XmpReader $md, string $tag ): array {

		switch ( $tag ) {

			case 'photopress:camera':
				return self::strings( StandardMetadata::camera( $md ) );

			case 'aux:Lens':
				return self::strings( StandardMetadata::lens( $md ) );

			default:
				return self::strings( $md->getXmp( $tag ) );
		}
	}

	private static function strings( $value ): array {

		$out = [];

		foreach ( (array) $value as $item ) {
			if ( is_scalar( $item ) && '' !== trim( (string) $item ) ) {
				$out[] = trim( (string) $item );
			}
		}

		return $out;
	}

	private static function trimmed( array $parts ): array {

		return array_values( array_filter( array_map( 'trim', $parts ), 'strlen' ) );
	}

	private static function add( array &$terms, array $ids, string $term ): void {

		foreach ( $ids as $id ) {
			$terms[ $id ][] = $term;
		}
	}

	/** Each term once; names compared without case, as WordPress does. */
	private static function unique( array $list ): array {

		$seen = [];
		$out  = [];

		foreach ( $list as $term ) {

			$key = TaxonomyModel::lower( is_array( $term ) ? implode( '|', $term ) : $term );

			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				$out[]        = $term;
			}
		}

		return $out;
	}
}
