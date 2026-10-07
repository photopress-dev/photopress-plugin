<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Functions;
use PhotoPress\modules\metadata\metadata;
use PhotoPress\modules\metadata\XmpReader;
use PHPUnit\Framework\Attributes\DataProvider;

final class MetadataTest extends TestCase {

	private static function lookup( array $values ): \Closure {

		return static fn( $tag ) => $values[ $tag ] ?? '';
	}

	#[DataProvider( 'templates' )]
	public function test_alt_template( string $template, array $values, string $expected ): void {

		$this->assertSame( $expected, metadata::fillAltTemplate( $template, self::lookup( $values ) ) );
	}

	public static function templates(): array {

		$default = '[photoshop:Headline]. [photopress:stringOfKeywords].';

		return [
			'every tag present'            => [ $default, [ 'photoshop:Headline' => 'Fields Medalist', 'photopress:stringOfKeywords' => 'portrait' ], 'Fields Medalist. portrait.' ],
			'first tag empty'              => [ $default, [ 'photopress:stringOfKeywords' => 'portrait, Bob' ], 'portrait, Bob.' ],
			'last tag empty'               => [ $default, [ 'photoshop:Headline' => 'Fields Medalist' ], 'Fields Medalist.' ],
			'every tag empty: no ". ."'    => [ $default, [], '' ],
			'middle tag empty'             => [ '[dc:title] – [photoshop:City], [photoshop:State]', [ 'dc:title' => 'Bob', 'photoshop:State' => 'California' ], 'Bob – California' ],
			'a list is joined, not Array'  => [ 'Photo of [dc:subject]', [ 'dc:subject' => [ 'a', 'b' ] ], 'Photo of a, b' ],
			'markup is removed'            => [ '[dc:title]', [ 'dc:title' => 'Tao <b>bold</b>' ], 'Tao bold' ],
			'literal text without tags'    => [ 'No tags here', [], '' ],
		];
	}

	private function metadataWithTemplate( string $template ): metadata {

		\pp_api::$options['core/metadata/alt_text_template'] = $template;
		\pp_api::$options['core/metadata/alt_text_enable'] = true;

		return ( new \ReflectionClass( metadata::class ) )->newInstanceWithoutConstructor();
	}

	public function test_alt_text_falls_back_to_description_then_title(): void {

		$md = new XmpReader();
		$md->loadFromArray( [ 'xmp' => [ 'dc:title' => 'Bob' ] ] );

		$this->assertSame( 'Bob', $this->metadataWithTemplate( '[photoshop:Headline].' )->generateAltText( $md ) );

		$md->loadFromArray( [ 'xmp' => [ 'dc:title' => 'Bob', 'dc:description' => 'At UCLA' ] ] );
		$this->assertSame( 'At UCLA', $this->metadataWithTemplate( '[photoshop:Headline].' )->generateAltText( $md ) );
	}

	/**
	 * addAttachment() with the file reading and taxonomy assignment stubbed.
	 */
	private function upload( string $template, array $xmp ): void {

		$this->metadataWithTemplate( $template );

		$file = $this->tempFile( 'not an image' );
		Functions\when( 'get_attached_file' )->justReturn( $file );

		$m = $this->getMockBuilder( metadata::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'setTaxonomyTerms', 'generateAltText' ] )
			->getMock();

		$md = new XmpReader();
		$md->loadFromArray( [ 'xmp' => $xmp ] );
		$m->method( 'generateAltText' )->willReturnCallback( fn() => ( new \ReflectionClass( metadata::class ) )->newInstanceWithoutConstructor()->generateAltText( $md ) );

		$m->addAttachment( 42 );
	}

	public function test_upload_sets_one_alt_row(): void {

		Functions\expect( 'update_post_meta' )->once()->with( 42, '_wp_attachment_image_alt', 'portrait, Bob.' );
		Functions\expect( 'add_post_meta' )->never();

		$this->upload( '[photoshop:Headline]. [photopress:stringOfKeywords].', [ 'dc:subject' => [ 'genre:portrait', 'person:Bob' ] ] );
	}

	public function test_upload_with_nothing_to_say_leaves_alt_text_alone(): void {

		Functions\expect( 'update_post_meta' )->never();
		Functions\expect( 'add_post_meta' )->never();

		$this->upload( '[photoshop:Headline].', [] );
	}

	public function test_media_library_attributes_do_not_override_the_blocks_own(): void {

		$m = $this->getMockBuilder( metadata::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'addAttributesToImages', 'renderLicensingSchema' ] )
			->getMock();
		$m->method( 'addAttributesToImages' )->willReturn( [
			'data-caption'   => 'Media library caption',
			'data-orig-file' => 'https://example.test/full.jpg?a=1&amp;b=2',
		] );
		$m->method( 'renderLicensingSchema' )->willReturn( '' );
		Functions\when( 'get_posts' )->justReturn( [ (object) [ 'ID' => 7 ] ] );

		$html = '<figure><img class="wp-image-7" data-caption="Block caption" src="a.jpg"><img data-id="7" src="b.jpg"></figure>';
		$out = $m->addAttributesToImagesInContent( $html, [ 'blockName' => 'core/image' ] );

		$p = new \WP_HTML_Tag_Processor( $out );
		$p->next_tag( 'img' );
		$this->assertSame( 'Block caption', $p->get_attribute( 'data-caption' ) );
		$this->assertSame( 'https://example.test/full.jpg?a=1&b=2', $p->get_attribute( 'data-orig-file' ), 'escaped once, not twice' );
		$p->next_tag( 'img' );
		$this->assertSame( 'Media library caption', $p->get_attribute( 'data-caption' ), 'every copy of the image gets the attributes' );
		$this->assertSame( 1, substr_count( $out, 'data-caption="Block caption"' ) );
		$this->assertSame( 2, substr_count( $out, 'data-caption=' ) );
	}

	public function test_other_blocks_are_left_alone(): void {

		$m = ( new \ReflectionClass( metadata::class ) )->newInstanceWithoutConstructor();
		$html = '<img class="wp-image-7" src="a.jpg">';

		$this->assertSame( $html, $m->addAttributesToImagesInContent( $html, [ 'blockName' => 'core/paragraph' ] ) );
	}

	public function test_terms_for_hierarchical_and_plain_keywords(): void {

		\pp_api::$options['core/metadata/custom_taxonomies_tag_delimiter'] = ':';
		$m = ( new \ReflectionClass( metadata::class ) )->newInstanceWithoutConstructor();
		$family = [ 'parents' => [ 'photos_keywords' ], 'children' => [ 'pp_person' ] ];

		$this->assertSame( [ 'pp_person' => [ 'Bob' ] ], $m->matchTermToTaxonomy( 'person:Bob', $family ) );
		$this->assertSame( [ 'photos_keywords' => [ 'portrait' ] ], $m->matchTermToTaxonomy( 'portrait', $family ) );
		// A child taxonomy the family does not have: the keyword goes to the parent as is.
		$this->assertSame( [ 'photos_keywords' => [ 'genre:portrait' ] ], $m->matchTermToTaxonomy( 'genre:portrait', $family ) );
	}

	public function test_xmp_gives_the_title_and_caption_when_iptc_does_not(): void {

		$m = ( new \ReflectionClass( metadata::class ) )->newInstanceWithoutConstructor();
		$file = $this->tempFile( file_get_contents( __DIR__ . '/fixtures/xmp/capture-one.xml' ), '.xmp' );

		$meta = $m->storeMoreMetaData( [ 'title' => '', 'caption' => '' ], $file, 2, [], [] );
		$this->assertSame( 'Bob', $meta['title'] );

		$meta = $m->storeMoreMetaData( [ 'title' => 'From IPTC', 'caption' => 'IPTC caption' ], $file, 2, [], [] );
		$this->assertSame( [ 'From IPTC', 'IPTC caption' ], [ $meta['title'], $meta['caption'] ], 'IPTC wins' );
	}

	public function test_description_template(): void {

		$m = ( new \ReflectionClass( metadata::class ) )->newInstanceWithoutConstructor();
		$md = new XmpReader();
		$md->loadFromArray( [ 'xmp' => [ 'photoshop:Headline' => 'Bob at the lake' ] ] );

		\pp_api::$options['core/metadata/description_template'] = '';
		$this->assertNull( $m->generateDescription( $md ), 'no template: leave the description alone' );

		\pp_api::$options['core/metadata/description_template'] = '[photoshop:Headline]';
		$this->assertSame( 'Bob at the lake', $m->generateDescription( $md ) );

		\pp_api::$options['core/metadata/description_template'] = '[photoshop:City]';
		$this->assertSame( '', $m->generateDescription( $md ), 'nothing in the file: cleared' );
	}

	public function test_a_taxonomy_the_file_has_nothing_for_is_emptied(): void {

		\pp_api::$options['core/metadata/custom_taxonomies'] = [
			[ 'id' => 'photos_keywords', 'tag' => 'dc:subject', 'parseTagValue' => false ],
			[ 'id' => 'photos_people', 'tag' => 'dc:subject', 'parseTagValue' => true ],
			[ 'id' => 'photos_city', 'tag' => 'photoshop:City', 'parseTagValue' => false ],
		];
		\pp_api::$options['core/metadata/custom_taxonomies_tag_delimiter'] = ':';

		$m = $this->getMockBuilder( metadata::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'matchTermToTaxonomy' ] )
			->getMock();
		$m->method( 'matchTermToTaxonomy' )->willReturn( [ 'photos_keywords' => [ 'lake' ] ] );

		$set = [];
		Functions\when( 'taxonomy_exists' )->justReturn( true );
		Functions\when( 'wp_defer_term_counting' )->justReturn( true );
		Functions\when( 'wp_set_object_terms' )->alias( static function ( $id, $terms, $taxonomy ) use ( &$set ) {
			$set[ $taxonomy ] = $terms;
		} );

		$md = new XmpReader();
		$md->loadFromArray( [ 'xmp' => [ 'dc:subject' => [ 'lake' ] ] ] );
		$m->setTaxonomyTerms( 42, $md );

		$this->assertSame( [ 'photos_keywords' => [ 'lake' ], 'photos_people' => [], 'photos_city' => [] ], $set );
	}
}

