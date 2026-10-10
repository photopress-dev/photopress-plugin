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
 * The two lists can disagree (software that edits only one of them, such as
 * Photoshop's File Info, leaves the other as it was); the image gets the
 * keywords of both, since neither can be told to be the newer.
 *
 * A path under a parent keyword goes to the parent's taxonomy, its levels
 * below the parent as nested terms (People|Family|Jane: Jane under Family).
 * Any other path goes to Keywords, nested the same way (Places|USA|California:
 * California under USA under Places); a plain keyword is a path of one.
 */
final class TermRouter {

	/** Keyword hierarchies by the field they are written in, first found wins. */
	const HIERARCHIES = [
		'lr:hierarchicalSubject'        => '|',
		'digiKam:TagsList'              => '/',
		'MicrosoftPhoto:LastKeywordXMP' => '/',
	];

	/**
	 * Taxonomy id => its terms: names, or for a nested taxonomy (Keywords
	 * and the parent keywords') paths, lists of names, parent first. Every taxonomy of the model is present, so one
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
					foreach ( $tag_takers as $id ) {
						if ( $model->isNested( $id ) ) {
							$terms[ $id ][] = $path;
						} else {
							array_push( $terms[ $id ], ...$path );
						}
					}
					continue;
				}

				[ $parent, $depth ] = $match;
				$terms[ $parent['id'] ][] = array_slice( $path, $depth );
			}
		}

		foreach ( $terms as $id => $list ) {
			$terms[ $id ] = self::unique( $list );
		}

		return $terms;
	}

	/** Fields read together: a file with any of them has a location. */
	const SOURCES = [
		'photoshop:City'    => 'location',
		'photoshop:State'   => 'location',
		'photoshop:Country' => 'location',
	];

	/**
	 * Whether the file has anything for each taxonomy's source: its keywords
	 * (both lists), its location (city, state or country), camera, lens, or
	 * custom field. A file with nothing for a source may have had its
	 * metadata stripped, so its terms there are better left as they are.
	 */
	public static function present( XmpReader $md, TaxonomyModel $model ): array {

		$has     = [];
		$present = [];

		foreach ( $model->taxonomyIds() as $id ) {

			$tag    = (string) $model->tagOf( $id );
			$source = self::SOURCES[ $tag ] ?? $tag;

			if ( ! isset( $has[ $source ] ) ) {

				$tags = 'location' === $source ? array_keys( self::SOURCES ) : [ $tag ];
				$has[ $source ] = false;

				foreach ( $tags as $one ) {
					if ( self::values( $md, $one ) || ( 'dc:subject' === $one && self::hierarchy( $md ) ) ) {
						$has[ $source ] = true;
						break;
					}
				}
			}

			$present[ $id ] = $has[ $source ];
		}

		return $present;
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
