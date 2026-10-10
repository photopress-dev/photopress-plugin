<?php

namespace PhotoPress\modules\metadata;

use pp_api;

/**
 * The image taxonomies, in the three kinds PhotoPress fills them by:
 *
 * - Standard Metadata: camera, lens, city, state, country and keywords, each
 *   read from wherever photo software stores it (StandardMetadata).
 * - Hierarchical Keyword Metadata: a taxonomy per parent keyword. A keyword
 *   under the parent (People|Jane in a keyword hierarchy, or a prefixed
 *   "people: Jane") goes to the parent's taxonomy instead of Keywords.
 * - Custom Metadata: any other field, every value a term as written.
 *
 * Built from the custom_taxonomies setting. A definition with parseTagValue
 * is a parent keyword: its names are its "names" setting, or else its id
 * without the pp_ or photos_ prefix (how prefixes were matched before).
 */
final class TaxonomyModel {

	/** The field each standard taxonomy was defined with => its kind. */
	const STANDARD_TAGS = [
		'photopress:camera' => 'camera',
		'aux:Lens'          => 'lens',
		'photoshop:City'    => 'city',
		'photoshop:State'   => 'state',
		'photoshop:Country' => 'country',
		'dc:subject'        => 'keywords',
	];

	/** kind => [ 'id' => taxonomy, 'tag' => field ] */
	public array $standard = [];

	/**
	 * [ 'id', 'tag', 'names' ]: names are paths, each a list of
	 * lower-cased levels ("Clients|Acme" is [ 'clients', 'acme' ]).
	 */
	public array $parents = [];

	/** [ 'id', 'tag' ] */
	public array $custom = [];

	/** What separates a prefix from the rest of a keyword ("people: Jane"). */
	public array $separators = [];

	/**
	 * The Keywords taxonomy's id, also when it is turned off: its terms stay,
	 * and are where keywords no parent keyword took were filed.
	 */
	public ?string $keywordsId = null;

	public static function fromSettings(): self {

		return self::build(
			(array) pp_api::getOption( 'core', 'metadata', 'custom_taxonomies' ),
			(string) pp_api::getOption( 'core', 'metadata', 'custom_taxonomies_tag_delimiter' )
		);
	}

	/**
	 * @param array  $definitions The custom_taxonomies setting.
	 * @param string $delimiter   One or more separators, divided by spaces
	 *                            (": >"). Empty: prefixes are not read.
	 */
	public static function build( array $definitions, string $delimiter ): self {

		$model = new self();
		$model->separators = self::separators( $delimiter );

		foreach ( $definitions as $def ) {

			$def = (array) $def;
			$id  = (string) ( $def['id'] ?? '' );
			$tag = (string) ( $def['tag'] ?? '' );

			if ( 'dc:subject' === $tag && empty( $def['parseTagValue'] ) && null === $model->keywordsId ) {
				$model->keywordsId = $id;
			}

			if ( '' === $id || '' === $tag || ! empty( $def['disabled'] ) ) {
				continue;
			}

			if ( ! empty( $def['parseTagValue'] ) ) {

				$names = array_filter( (array) ( $def['names'] ?? [] ), 'strlen' );

				if ( ! $names ) {
					$names = [ preg_replace( '/^(pp|photos)_/', '', $id ) ];
				}

				$model->parents[] = [
					'id'     => $id,
					'tag'    => $tag,
					'names'  => array_values( array_filter( array_map( [ self::class, 'levels' ], $names ) ) ),
				];
				continue;
			}

			$kind = self::STANDARD_TAGS[ $tag ] ?? null;

			if ( $kind && ! isset( $model->standard[ $kind ] ) ) {
				$model->standard[ $kind ] = [ 'id' => $id, 'tag' => $tag ];
			} else {
				$model->custom[] = [ 'id' => $id, 'tag' => $tag ];
			}
		}

		return $model;
	}

	public static function separators( string $delimiter ): array {

		return array_values( array_filter( preg_split( '/\s+/', trim( $delimiter ) ), 'strlen' ) );
	}

	/** A parent keyword's name as lower-cased levels: "Clients|Acme". */
	public static function levels( $name ): array {

		$levels = array_map( 'trim', explode( '|', (string) $name ) );

		return array_values( array_filter( array_map( [ self::class, 'lower' ], $levels ), 'strlen' ) );
	}

	public static function lower( string $text ): string {

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );
	}

	/** Every taxonomy the model fills. */
	public function taxonomyIds(): array {

		return array_values( array_unique( array_merge(
			array_column( $this->standard, 'id' ),
			array_column( $this->parents, 'id' ),
			array_column( $this->custom, 'id' )
		) ) );
	}

	/** The field a taxonomy is filled from. */
	public function tagOf( string $id ): ?string {

		foreach ( array_merge( array_values( $this->standard ), $this->parents, $this->custom ) as $tax ) {
			if ( $tax['id'] === $id ) {
				return $tax['tag'];
			}
		}

		return null;
	}

	/**
	 * Whether the taxonomy's terms are nested, as keyword hierarchies are:
	 * Keywords and the parent keywords'.
	 */
	public function isNested( string $id ): bool {

		return in_array( $id, array_column( $this->parents, 'id' ), true ) || $id === ( $this->standard['keywords']['id'] ?? null );
	}
}
