<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Functions;
use PhotoPress\modules\metadata\StandardMetadata;
use PhotoPress\modules\metadata\TaxonomyModel;
use PhotoPress\modules\metadata\TermRouter;
use PhotoPress\modules\metadata\XmpReader;
use PhotoPress\modules\metadata\metadata;

/**
 * How an image's metadata becomes terms: Standard Metadata, parent keywords
 * (Hierarchical Keyword Metadata) and Custom Metadata.
 */
final class TaxonomyProcessingTest extends TestCase {

	/** The definitions of a site like peteradamsphoto.com. */
	private const DEFINITIONS = [
		[ 'id' => 'photos_camera', 'tag' => 'photopress:camera', 'parseTagValue' => false ],
		[ 'id' => 'photos_lens', 'tag' => 'aux:Lens', 'parseTagValue' => false ],
		[ 'id' => 'photos_city', 'tag' => 'photoshop:City', 'parseTagValue' => false ],
		[ 'id' => 'photos_keywords', 'tag' => 'dc:subject', 'parseTagValue' => false ],
		[ 'id' => 'photos_people', 'tag' => 'dc:subject', 'parseTagValue' => true ],
		[ 'id' => 'pp_genre', 'tag' => 'dc:subject', 'parseTagValue' => true ],
	];

	private static function reader( array $xmp, array $exif = [] ): XmpReader {

		$md = new XmpReader();
		$md->loadFromArray( [ 'xmp' => $xmp, 'exif' => $exif ] );

		return $md;
	}

	private static function route( array $xmp, array $definitions = self::DEFINITIONS, string $delimiter = ':', array $exif = [] ): array {

		return TermRouter::route( self::reader( $xmp, $exif ), TaxonomyModel::build( $definitions, $delimiter ) );
	}

	public function test_the_settings_become_standard_parent_and_custom_taxonomies(): void {

		$model = TaxonomyModel::build( array_merge( self::DEFINITIONS, [
			[ 'id' => 'pp_event', 'tag' => 'Iptc4xmpExt:Event', 'parseTagValue' => false ],
			[ 'id' => 'pp_clients', 'tag' => 'dc:subject', 'parseTagValue' => true, 'names' => [ 'Clients|Acme' ], 'nested' => true ],
		] ), ': >' );

		$this->assertSame( [ 'camera', 'lens', 'city', 'keywords' ], array_keys( $model->standard ) );
		$this->assertSame( [ [ 'id' => 'pp_event', 'tag' => 'Iptc4xmpExt:Event' ] ], $model->custom );
		$this->assertSame( [ [ 'people' ], [ 'genre' ], [ 'clients', 'acme' ] ], array_merge( ...array_column( $model->parents, 'names' ) ) );
		$this->assertTrue( $model->isNested( 'pp_clients' ) );
		$this->assertFalse( $model->isNested( 'pp_genre' ) );
		$this->assertSame( [ ':', '>' ], $model->separators );
	}

	public function test_a_taxonomy_turned_off_is_neither_read_nor_filled(): void {

		$definitions = self::DEFINITIONS;
		$definitions[1]['disabled'] = true;
		$definitions[4]['disabled'] = true;

		$terms = self::route( [ 'aux:Lens' => '35.0 mm f/2.0', 'dc:subject' => [ 'people: Jane' ] ], $definitions );

		$this->assertArrayNotHasKey( 'photos_lens', $terms );
		$this->assertArrayNotHasKey( 'photos_people', $terms );
		$this->assertSame( [ 'people: Jane' ], $terms['photos_keywords'], 'with People off, its prefix is a keyword' );
	}

	public function test_taxonomies_are_registered_with_clean_urls_and_nesting(): void {

		if ( ! defined( 'EP_PERMALINK' ) ) {
			define( 'EP_PERMALINK', 1 );
		}

		\pp_api::$options['core/metadata/custom_taxonomies'] = [
			[ 'id' => 'pp_acme_job', 'pluralLabel' => 'Acme jobs', 'singularLabel' => 'Acme job', 'tag' => 'dc:subject', 'parseTagValue' => true, 'nested' => true ],
			[ 'id' => 'photos_lens', 'pluralLabel' => 'lenses', 'singularLabel' => 'lens', 'tag' => 'aux:Lens', 'parseTagValue' => false, 'disabled' => true ],
		];

		$registered = [];
		Functions\when( 'sanitize_title' )->alias( static fn( $text ) => strtolower( preg_replace( '/[^A-Za-z0-9]+/', '-', trim( $text ) ) ) );
		Functions\when( 'register_taxonomy' )->alias( static function ( $id, $type, $args ) use ( &$registered ) {
			$registered[ $id ] = $args;
		} );

		( new \ReflectionClass( metadata::class ) )->newInstanceWithoutConstructor()->registerTaxonomies();

		$this->assertSame( [ 'pp_acme_job' ], array_keys( $registered ), 'a taxonomy turned off is not registered' );
		$this->assertSame( 'acme-job', $registered['pp_acme_job']['rewrite']['slug'] );
		$this->assertTrue( $registered['pp_acme_job']['rewrite']['hierarchical'] );
		$this->assertTrue( $registered['pp_acme_job']['hierarchical'] );
	}

		public function test_prefixes_in_keywords_no_parent_keyword_takes(): void {

		global $wpdb;

		$wpdb = new class {
			public $terms = 'wp_terms';
			public $term_taxonomy = 'wp_term_taxonomy';
			public $args;
			public function esc_like( $text ) { return addcslashes( $text, '_%\\' ); }
			public function prepare( $query, $args ) { $this->args = $args; return $query; }
			public function get_results() {
				return [
					(object) [ 'name' => 'organization: Automattic', 'count' => 3 ],
					(object) [ 'name' => 'Organization: Baidu', 'count' => 2 ],
					(object) [ 'name' => 'genre: reportage', 'count' => 9 ],
					(object) [ 'name' => 'publication: IEEE Spectrum', 'count' => 1 ],
					(object) [ 'name' => 'penre: science &amp; technology', 'count' => 1 ],
					(object) [ 'name' => 'people:', 'count' => 4 ],
				];
			}
		};

		$found = metadata::unclaimedPrefixes( TaxonomyModel::build( self::DEFINITIONS, ':' ) );

		$this->assertSame( 'photos_keywords', $wpdb->args[0] );
		$this->assertSame( [
			[ 'prefix' => 'organization', 'photos' => 5, 'examples' => [ 'Automattic', 'Baidu' ] ],
			[ 'prefix' => 'publication', 'photos' => 1, 'examples' => [ 'IEEE Spectrum' ] ],
			[ 'prefix' => 'penre', 'photos' => 1, 'examples' => [ 'science & technology' ] ],
		], $found );

		$wpdb = null;
	}

		public function test_prefixed_keywords_go_to_their_parent_keyword_and_the_rest_to_keywords(): void {

		$terms = self::route( [ 'dc:subject' => [ 'Silicon Valley', 'people: Parisa Tabriz', 'Genre:reportage', 'organization: Automattic' ] ] );

		$this->assertSame( [ 'Parisa Tabriz' ], $terms['photos_people'] );
		$this->assertSame( [ 'reportage' ], $terms['pp_genre'], 'the prefix matches without case' );
		$this->assertSame( [ 'Silicon Valley', 'organization: Automattic' ], $terms['photos_keywords'], 'a prefix no parent keyword has stays as written' );
	}

	public function test_a_separator_in_a_value_does_not_split_it(): void {

		$terms = self::route( [ 'dc:subject' => [ 'Star Wars: A New Hope', 'people:' ] ] );

		$this->assertSame( [ 'Star Wars: A New Hope', 'people:' ], $terms['photos_keywords'] );
		$this->assertSame( [], $terms['photos_people'] );
	}

	public function test_a_keyword_hierarchy_goes_to_the_parent_keyword_and_its_levels_are_not_read_twice(): void {

		// As Lightroom writes it with its parent keywords exported too.
		$terms = self::route( [
			'lr:hierarchicalSubject' => [ 'People|Family|Jane', 'Places|USA|California', 'lake' ],
			'dc:subject'             => [ 'People', 'Family', 'Jane', 'Places', 'USA', 'California', 'lake' ],
		] );

		$this->assertSame( [ 'Jane' ], $terms['photos_people'] );
		$this->assertSame( [ 'Family', 'Places', 'USA', 'California', 'lake' ], $terms['photos_keywords'] );
	}

	public function test_a_nested_parent_keyword_keeps_every_level_below_it(): void {

		$definitions = self::DEFINITIONS;
		$definitions[4] += [ 'nested' => true, 'names' => [ 'people', 'person' ] ];

		$terms = self::route( [
			'lr:hierarchicalSubject' => [ 'People|Family|Jane', 'People|Parisa Tabriz' ],
			'dc:subject'             => [ 'Family', 'Jane', 'Parisa Tabriz', 'person: Andrew Ng', 'person: friends: Ann' ],
		], $definitions );

		$this->assertSame( [ [ 'Family', 'Jane' ], [ 'Parisa Tabriz' ], [ 'Andrew Ng' ], [ 'friends', 'Ann' ] ], $terms['photos_people'] );
		$this->assertSame( [], $terms['photos_keywords'] );
	}

	public function test_the_parent_keyword_naming_the_most_levels_wins(): void {

		$definitions = array_merge( self::DEFINITIONS, [
			[ 'id' => 'pp_clients', 'tag' => 'dc:subject', 'parseTagValue' => true, 'names' => [ 'Clients' ] ],
			[ 'id' => 'pp_acme', 'tag' => 'dc:subject', 'parseTagValue' => true, 'names' => [ 'Clients|Acme' ] ],
		] );

		$terms = self::route( [ 'lr:hierarchicalSubject' => [ 'Clients|Acme|Launch', 'Clients|Globex', 'Clients' ] ], $definitions );

		$this->assertSame( [ 'Launch' ], $terms['pp_acme'] );
		$this->assertSame( [ 'Globex' ], $terms['pp_clients'] );
		$this->assertSame( [ 'Clients' ], $terms['photos_keywords'], 'the parent keyword alone is a keyword' );
	}

	public function test_an_image_gets_the_keywords_of_both_lists_when_they_disagree(): void {

		// Jim Gettys (peteradamsphoto.com): keywords edited in Photoshop's
		// File Info, which leaves the hierarchy Capture One shows as it was.
		$terms = self::route( [
			'lr:hierarchicalSubject' => [ 'faces of open source', 'high key', 'portrait', 'People|Jim Gettys' ],
			'dc:subject'             => [ 'faces of open source', 'unix', 'portrait' ],
		] );

		$this->assertSame( [ 'faces of open source', 'high key', 'portrait', 'unix' ], $terms['photos_keywords'] );
		$this->assertSame( [ 'Jim Gettys' ], $terms['photos_people'] );
	}

	public function test_other_keyword_hierarchies_when_there_is_no_lightroom_one(): void {

		$terms = self::route( [ 'digiKam:TagsList' => [ 'People/Jane' ], 'dc:subject' => [ 'Jane' ] ] );

		$this->assertSame( [ 'Jane' ], $terms['photos_people'] );
		$this->assertSame( [], $terms['photos_keywords'] );
	}

	public function test_more_than_one_separator(): void {

		$terms = self::route( [ 'dc:subject' => [ 'people > Jane', 'genre: still life' ] ], self::DEFINITIONS, ': >' );

		$this->assertSame( [ 'Jane' ], $terms['photos_people'] );
		$this->assertSame( [ 'still life' ], $terms['pp_genre'] );
	}

	public function test_no_separator_reads_no_prefixes(): void {

		$terms = self::route( [ 'dc:subject' => [ 'people: Jane' ] ], self::DEFINITIONS, '' );

		$this->assertSame( [ 'people: Jane' ], $terms['photos_keywords'] );
	}

	public function test_custom_metadata_takes_every_value_as_written(): void {

		$definitions = array_merge( self::DEFINITIONS, [ [ 'id' => 'pp_event', 'tag' => 'Iptc4xmpExt:Event', 'parseTagValue' => false ] ] );

		$this->assertSame( [ 'Maker Faire: Bay Area' ], self::route( [ 'Iptc4xmpExt:Event' => 'Maker Faire: Bay Area' ], $definitions )['pp_event'] );
	}

	public function test_every_taxonomy_is_listed_so_one_with_nothing_is_emptied(): void {

		$terms = self::route( [ 'photoshop:City' => 'Ojai' ] );

		$this->assertSame( [ 'photos_camera', 'photos_lens', 'photos_city', 'photos_keywords', 'photos_people', 'pp_genre' ], array_keys( $terms ) );
		$this->assertSame( [ 'Ojai' ], $terms['photos_city'] );
		$this->assertSame( [], $terms['photos_keywords'] );
	}

	public function test_each_term_once(): void {

		$terms = self::route( [ 'dc:subject' => [ 'lake', 'Lake', 'genre:reportage', 'genre: reportage' ] ] );

		$this->assertSame( [ 'lake' ], $terms['photos_keywords'] );
		$this->assertSame( [ 'reportage' ], $terms['pp_genre'] );
	}

	/**
	 * peteradamsphoto.com's cameras.
	 */
	public static function cameras(): array {

		return [
			'brand repeated'      => [ 'Canon', 'Canon PowerShot G9', 'Canon PowerShot G9' ],
			'corporate make'      => [ 'NIKON CORPORATION', 'NIKON D800', 'Nikon D800' ],
			'capitals'            => [ 'SONY', 'DSC-RX1', 'Sony DSC-RX1' ],
			'serial and body'     => [ 'Leaf', 'Leaf Aptus-II 5(LI300047 )/Phase One 645DF/645AF', 'Leaf Aptus-II 5' ],
			'model without brand' => [ 'Phase One', 'IQ140', 'Phase One IQ140' ],
			'model alone'         => [ '', 'NIKON D800E', 'Nikon D800E' ],
			'unknown model alone' => [ '', 'IQ4 150MP', 'IQ4 150MP' ],
			'software'            => [ 'Adobe Systems Inc.', 'Tiff File', '' ],
			'unknown make'        => [ 'ACME IMAGING CO., LTD.', 'ACME X1', 'Acme X1' ],
			'scanner'             => [ 'Nikon', 'Nikon SUPER COOLSCAN 9000 ED', 'Nikon SUPER COOLSCAN 9000 ED' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'cameras' )]
	public function test_cameras_are_cleaned_up( string $make, string $model, string $camera ): void {

		$this->assertSame( $camera, StandardMetadata::cleanCamera( $make, $model ) );
	}

	public function test_the_camera_is_read_from_exif_then_xmp(): void {

		$this->assertSame( 'Sony ILCE-9M2', StandardMetadata::camera( self::reader( [ 'tiff:Make' => 'Phase One', 'tiff:Model' => 'IQ4' ], [ 'Make' => 'SONY', 'Model' => 'ILCE-9M2' ] ) ) );
		$this->assertSame( 'Phase One IQ4', StandardMetadata::camera( self::reader( [ 'tiff:Make' => 'Phase One', 'tiff:Model' => 'IQ4' ], [ 'Make' => '', 'Model' => '' ] ) ) );
		$this->assertSame( 'IQ4', StandardMetadata::camera( self::reader( [ 'tiff:Model' => 'IQ4' ] ) ) );
		$this->assertSame( '', StandardMetadata::camera( self::reader( [] ) ) );
	}

	public function test_the_camera_comes_from_exif_when_the_file_has_no_xmp(): void {

		$terms = self::route( [], self::DEFINITIONS, ':', [ 'Make' => 'SONY', 'Model' => 'DSC-RX1' ] );

		$this->assertSame( [ 'Sony DSC-RX1' ], $terms['photos_camera'] );
	}

	public function test_the_lens_is_its_model_from_wherever_it_is_recorded(): void {

		$model = 'Sony FE 70-200mm F2.8 GM OSS (SEL70200GM)';

		$this->assertSame( $model, StandardMetadata::lens( self::reader( [ 'exifEX:LensModel' => $model, 'aux:Lens' => '70.0-200.0 mm f/2.8' ] ) ) );
		$this->assertSame( $model, StandardMetadata::lens( self::reader( [ 'aux:Lens' => '70.0-200.0 mm f/2.8' ], [ 'UndefinedTag:0xA434' => $model ] ) ) );
		$this->assertSame( '35.0 mm f/2.0', StandardMetadata::lens( self::reader( [ 'aux:Lens' => '35.0 mm f/2.0' ] ) ), 'a fixed lens has no model' );
		$this->assertSame( '', StandardMetadata::lens( self::reader( [ 'aux:Lens' => "'-- mm f/--" ] ) ) );
		$this->assertSame( '125 mm', StandardMetadata::lens( self::reader( [ 'aux:Lens' => '125 mm f/--' ] ) ), 'an unknown aperture is left out' );
		$this->assertSame( '', StandardMetadata::lens( self::reader( [ 'exifEX:LensModel' => '----' ] ) ) );
	}

	public function test_what_the_file_has_for_each_taxonomy(): void {

		$model = TaxonomyModel::build( self::DEFINITIONS, ':' );

		// Death Valley (peteradamsphoto.com): stripped of keywords and
		// location, its lens left.
		$present = TermRouter::present( self::reader( [ 'aux:Lens' => 'Schneider LS 80mm f/2.8' ] ), $model );

		$this->assertSame( [ 'photos_camera' => false, 'photos_lens' => true, 'photos_city' => false, 'photos_keywords' => false, 'photos_people' => false, 'pp_genre' => false ], $present );

		// A state is a location; a keyword hierarchy alone is keywords.
		$present = TermRouter::present( self::reader( [ 'photoshop:State' => 'California', 'lr:hierarchicalSubject' => [ 'People|Jane' ] ] ), $model );

		$this->assertTrue( $present['photos_city'] );
		$this->assertTrue( $present['photos_keywords'] );
		$this->assertTrue( $present['photos_people'] );
	}

	public function test_terms_a_file_has_nothing_for_are_kept_unless_forced(): void {

		\pp_api::$options['core/metadata/custom_taxonomies'] = self::DEFINITIONS;
		\pp_api::$options['core/metadata/custom_taxonomies_tag_delimiter'] = ':';

		$set = [];
		Functions\when( 'taxonomy_exists' )->justReturn( true );
		Functions\when( 'wp_defer_term_counting' )->justReturn( true );
		Functions\when( 'wp_set_object_terms' )->alias( static function ( $id, $terms, $taxonomy ) use ( &$set ) {
			$set[ $taxonomy ] = $terms;
		} );

		$m  = ( new \ReflectionClass( metadata::class ) )->newInstanceWithoutConstructor();
		$md = self::reader( [ 'aux:Lens' => 'Schneider LS 80mm f/2.8', 'dc:subject' => [] ] );

		$m->setTaxonomyTerms( 42, $md );
		$this->assertSame( [ 'photos_lens' => [ 'Schneider LS 80mm f/2.8' ] ], $set, 'only what the file has' );

		$set = [];
		$m->setTaxonomyTerms( 42, $md, true );
		$this->assertSame( [], $set['photos_keywords'], 'forced: emptied' );
		$this->assertSame( [], $set['photos_city'] );
		$this->assertCount( 6, $set );
	}

	public function test_the_reread_job_passes_force_on(): void {

		$file = $this->tempFile( 'not an image' );
		Functions\when( 'get_attached_file' )->justReturn( $file );

		$m = $this->getMockBuilder( metadata::class )->disableOriginalConstructor()->onlyMethods( [ 'addAttachment' ] )->getMock();
		$m->expects( $this->exactly( 2 ) )->method( 'addAttachment' )->willReturnCallback( function ( $id, $force ) use ( &$calls ) {
			$calls[] = [ $id, $force ];
		} );

		$m->reprocessImage( 7, [] );
		$m->reprocessImage( 7, [ 'force' => true ] );

		$this->assertSame( [ [ 7, false ], [ 7, true ] ], $calls );
	}

		public function test_nested_terms_are_made_level_by_level_and_the_image_gets_every_level(): void {

		$terms = [ 'Family' => [ 'term_id' => 11, 'parent' => 0 ] ];
		$made  = [];

		Functions\when( 'term_exists' )->alias( static function ( $name, $taxonomy, $parent ) use ( &$terms ) {
			return isset( $terms[ $name ] ) && $terms[ $name ]['parent'] === $parent ? [ 'term_id' => $terms[ $name ]['term_id'] ] : null;
		} );
		Functions\when( 'wp_insert_term' )->alias( static function ( $name, $taxonomy, $args ) use ( &$terms, &$made ) {
			$made[] = [ $name, $args['parent'] ];
			$terms[ $name ] = [ 'term_id' => 12, 'parent' => $args['parent'] ];
			return [ 'term_id' => 12 ];
		} );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$this->assertSame( [ 11, 12 ], metadata::termPath( 'photos_people', [ 'Family', 'Jane' ] ) );
		$this->assertSame( [ [ 'Jane', 11 ] ], $made );
	}

	public function test_an_image_gets_its_terms_and_nested_ones_by_id(): void {

		$definitions = self::DEFINITIONS;
		$definitions[4] += [ 'nested' => true ];
		\pp_api::$options['core/metadata/custom_taxonomies'] = $definitions;
		\pp_api::$options['core/metadata/custom_taxonomies_tag_delimiter'] = ':';

		$set = [];
		Functions\when( 'taxonomy_exists' )->justReturn( true );
		Functions\when( 'wp_defer_term_counting' )->justReturn( true );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'term_exists' )->justReturn( null );
		Functions\when( 'wp_insert_term' )->alias( static function ( $name ) {
			return [ 'term_id' => [ 'Family' => 11, 'Jane' => 12 ][ $name ] ];
		} );
		Functions\when( 'wp_set_object_terms' )->alias( static function ( $id, $terms, $taxonomy ) use ( &$set ) {
			$set[ $taxonomy ] = $terms;
		} );

		$m = ( new \ReflectionClass( metadata::class ) )->newInstanceWithoutConstructor();
		$m->setTaxonomyTerms( 42, self::reader( [ 'lr:hierarchicalSubject' => [ 'People|Family|Jane' ], 'dc:subject' => [ 'Jane', 'lake' ] ] ) );

		$this->assertSame( [ 11, 12 ], $set['photos_people'] );
		$this->assertSame( [ 'lake' ], $set['photos_keywords'] );
		$this->assertSame( [], $set['pp_genre'] );
	}
}
