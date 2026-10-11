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

	public function test_alt_text_written_in_the_file_is_used_in_place_of_the_template(): void {

		$m  = $this->metadataWithTemplate( '[photoshop:Headline]' );
		$md = new XmpReader();
		$md->loadFromArray( [ 'xmp' => [ 'photoshop:Headline' => 'At the lake', 'Iptc4xmpCore:AltTextAccessibility' => 'A man rowing a red boat on a lake' ] ] );

		\pp_api::$options['core/metadata/alt_text_from_file'] = true;
		$this->assertSame( 'A man rowing a red boat on a lake', $m->generateAltText( $md ) );

		\pp_api::$options['core/metadata/alt_text_from_file'] = false;
		$this->assertSame( 'At the lake', $m->generateAltText( $md ), 'off: the template' );

		\pp_api::$options['core/metadata/alt_text_from_file'] = true;
		$md->loadFromArray( [ 'xmp' => [ 'photoshop:Headline' => 'At the lake' ] ] );
		$this->assertSame( 'At the lake', $m->generateAltText( $md ), 'none in the file: the template' );
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
		Functions\when( 'wp_attachment_is_image' )->justReturn( true );

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

	public function test_the_license_is_written_into_a_jpeg_without_re_encoding_it(): void {

		Functions\stubs( [ 'is_wp_error' => static fn( $thing ) => $thing instanceof \WP_Error ] );
		\pp_api::$options = [
			'core/metadata/embed_licensor_enable'   => true,
			'core/metadata/web_statement_of_rights' => 'https://example.test/license',
			'core/metadata/licensor_name'           => 'Alice Photography',
			'core/metadata/licensor_url'            => 'https://alice.example',
		];

		$im = imagecreatetruecolor( 40, 30 );
		ob_start();
		imagejpeg( $im, null, 90 );
		$jpeg = ob_get_clean();

		// Capture One's packet, after the JFIF segment.
		$segment = "http://ns.adobe.com/xap/1.0/\0" . file_get_contents( __DIR__ . '/fixtures/xmp/capture-one.xml' );
		$jfif = 4 + unpack( 'n', substr( $jpeg, 4, 2 ) )[1];
		$file = $this->tempFile( substr( $jpeg, 0, $jfif ) . "\xFF\xE1" . pack( 'n', strlen( $segment ) + 2 ) . $segment . substr( $jpeg, $jfif ) );

		$m = ( new \ReflectionClass( metadata::class ) )->newInstanceWithoutConstructor();
		$this->assertNull( $m->embedLicense( null, [ 'tmp_name' => $file ], $file, 'image/jpeg' ) );

		$after = file_get_contents( $file );
		$length = 2 + unpack( 'n', substr( $after, $jfif + 2, 2 ) )[1];
		$this->assertSame( $jpeg, substr( $after, 0, $jfif ) . substr( $after, $jfif + $length ), 'only the XMP segment changed' );

		$md = new XmpReader();
		$md->loadFromFile( $file );
		$this->assertSame( 'https://example.test/license', $md->getXmp( 'xmpRights:WebStatement' ) );
		$this->assertSame( [ 'plus:LicensorName' => 'Alice Photography', 'plus:LicensorURL' => 'https://alice.example' ], $md->getXmp( 'plus:Licensor' ) );
		$this->assertSame( 'Bob', $md->getXmp( 'dc:title' ), 'the packet it had is kept' );
		$this->assertSame( 'IQ4 150MP', $md->getXmp( 'tiff:Model' ) );
	}

	private static function mergeLicense( string $existing, string $statement, string $name = '', string $url = '' ): string {

		$merge = new \ReflectionMethod( metadata::class, 'mergeLicenseIntoXmp' );
		$merge->setAccessible( true );

		return $merge->invoke( null, $existing, $statement, $name, $url );
	}

	public function test_a_web_statement_written_as_an_attribute_is_replaced_not_duplicated(): void {

		$existing = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
			. '<rdf:Description rdf:about="" xmlns:xmpRights="http://ns.adobe.com/xap/1.0/rights/" xmpRights:Marked="True" xmpRights:WebStatement="https://old.example"/>'
			. '</rdf:RDF></x:xmpmeta>';

		$packet = self::mergeLicense( $existing, 'https://example.test/license' );

		$this->assertSame( 1, substr_count( $packet, 'WebStatement' ) - substr_count( $packet, '</xmpRights:WebStatement' ) );
		$this->assertStringNotContainsString( 'old.example', $packet );
		$this->assertSame( 'https://example.test/license', ( new XmpReader() )->parsePacket( $packet )['xmpRights:WebStatement'] );
		$this->assertSame( 'True', ( new XmpReader() )->parsePacket( $packet )['xmpRights:Marked'] );
	}

	public function test_a_property_with_no_setting_keeps_the_files_value(): void {

		$existing = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
			. '<rdf:Description rdf:about="" xmlns:xmpRights="http://ns.adobe.com/xap/1.0/rights/" xmpRights:WebStatement="https://photographer.example"/>'
			. '</rdf:RDF></x:xmpmeta>';

		$md = ( new XmpReader() )->parsePacket( self::mergeLicense( $existing, '', 'Alice Photography', 'https://alice.example' ) );

		$this->assertSame( 'https://photographer.example', $md['xmpRights:WebStatement'] );
		$this->assertSame( [ [ 'plus:LicensorName' => 'Alice Photography', 'plus:LicensorURL' => 'https://alice.example' ] ], $md['plus:Licensor'] );
	}

	public function test_the_license_is_embedded_in_raster_images_only(): void {

		\pp_api::$options = [
			'core/metadata/embed_licensor_enable'   => true,
			'core/metadata/web_statement_of_rights' => 'https://example.test/license',
			'core/metadata/licensor_name'           => 'Alice Photography',
			'core/metadata/licensor_url'            => 'https://alice.example',
		];
		$m = ( new \ReflectionClass( metadata::class ) )->newInstanceWithoutConstructor();

		foreach ( [ 'application/pdf' => '%PDF-1.4 text', 'image/svg+xml' => '<svg xmlns="http://www.w3.org/2000/svg"/>', 'video/mp4' => 'not a video' ] as $type => $contents ) {
			$file = $this->tempFile( $contents, '.bin' );
			$this->assertNull( $m->embedLicense( null, [ 'tmp_name' => $file ], $file, $type ) );
			$this->assertSame( $contents, file_get_contents( $file ), "$type: left as it is" );
		}

		$this->assertTrue( metadata::isRasterImage( 'image/webp' ) );
		$this->assertFalse( metadata::isRasterImage( 'image/svg+xml' ) );
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

	/**
	 * WordPress adds srcset to content images together with sizes, but not
	 * to one that has a srcset already: given one here, the image had no
	 * sizes, and browsers took it for the width of the window.
	 */
	public function test_media_library_attributes_leave_srcset_to_wordpress(): void {

		Functions\stubs( [
			'wp_attachment_is_image'     => true,
			'wp_get_attachment_image_src' => [ 'https://example.test/full.jpg', 1200, 800, false ],
			'wptexturize'                => static fn( $text ) => $text,
			'wpautop'                    => static fn( $text ) => $text,
			'get_attachment_link'        => 'https://example.test/attachment/',
			'wp_get_attachment_metadata' => [ 'width' => 1200, 'height' => 800 ],
		] );
		Functions\expect( 'wp_get_attachment_image_srcset' )->never();

		$m = ( new \ReflectionClass( metadata::class ) )->newInstanceWithoutConstructor();
		$attr = $m->addAttributesToImages( [], (object) [ 'ID' => 7, 'post_title' => 'Bob', 'post_excerpt' => '', 'post_content' => '' ] );

		$this->assertArrayNotHasKey( 'srcset', $attr );
		$this->assertSame( 'https://example.test/full.jpg', $attr['data-orig-file'] );
	}

	public function test_other_blocks_are_left_alone(): void {

		$m = ( new \ReflectionClass( metadata::class ) )->newInstanceWithoutConstructor();
		$html = '<img class="wp-image-7" src="a.jpg">';

		$this->assertSame( $html, $m->addAttributesToImagesInContent( $html, [ 'blockName' => 'core/paragraph' ] ) );
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

		\pp_api::$options['core/metadata/description_enable'] = true;
		\pp_api::$options['core/metadata/description_template'] = '';
		$this->assertNull( $m->generateDescription( $md ), 'no template: leave the description alone' );

		\pp_api::$options['core/metadata/description_template'] = '[photoshop:Headline]';
		$this->assertSame( 'Bob at the lake', $m->generateDescription( $md ) );

		\pp_api::$options['core/metadata/description_template'] = '[photoshop:City]';
		$this->assertSame( '', $m->generateDescription( $md ), 'nothing in the file: cleared' );

		\pp_api::$options['core/metadata/description_enable'] = false;
		$this->assertNull( $m->generateDescription( $md ), 'off: leave the description alone' );
	}

	public function test_sites_saved_before_the_description_switch_get_it_on_where_a_template_is_set(): void {

		$stored = [
			'photopress_core_metadata'  => [ 'description_template' => '[dc:description]' ],
		];
		Functions\when( 'get_option' )->alias( static function ( $key ) use ( &$stored ) { return $stored[ $key ] ?? false; } );
		Functions\when( 'update_option' )->alias( static function ( $key, $value ) use ( &$stored ) { $stored[ $key ] = $value; } );

		metadata::addDescriptionSwitch();
		$this->assertTrue( $stored['photopress_core_metadata']['description_enable'] );

		$stored['photopress_core_metadata'] = [ 'description_template' => ' ' ];
		metadata::addDescriptionSwitch();
		$this->assertFalse( $stored['photopress_core_metadata']['description_enable'] );

		$stored['photopress_core_metadata'] = [ 'description_template' => '[dc:description]', 'description_enable' => false ];
		metadata::addDescriptionSwitch();
		$this->assertFalse( $stored['photopress_core_metadata']['description_enable'], 'a saved switch stays' );
	}

	public function test_the_license_json_ld_is_given_for_the_images_a_page_shows_when_licensing_is_on(): void {

		global $wp_query;

		$m = ( new \ReflectionClass( metadata::class ) )->newInstanceWithoutConstructor();
		$image = (object) [ 'ID' => 7, 'post_type' => 'attachment' ];
		$pdf   = (object) [ 'ID' => 8, 'post_type' => 'attachment' ];
		$post  = (object) [ 'ID' => 9, 'post_type' => 'post' ];
		$wp_query = (object) [ 'posts' => [ $image, $pdf, $post ] ];

		Functions\when( 'is_attachment' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( true );
		Functions\when( 'is_archive' )->justReturn( false );
		Functions\when( 'wp_attachment_is_image' )->alias( static fn( $p ) => 7 === $p->ID );

		\pp_api::$options['core/metadata/embed_licensor_enable'] = true;
		\pp_api::$options['core/metadata/licensor_name'] = 'Alice Photography';
		\pp_api::$options['core/metadata/licensor_url'] = 'https://alice.example/buy';
		\pp_api::$options['core/metadata/web_statement_of_rights'] = '';
		$this->assertSame( [], $m->licensableImagesOfPage(), 'not all three settings filled in' );

		\pp_api::$options['core/metadata/web_statement_of_rights'] = 'https://alice.example/terms';
		$this->assertSame( [ 7 ], $m->licensableImagesOfPage() );

		\pp_api::$options['core/metadata/embed_licensor_enable'] = false;
		$this->assertSame( [], $m->licensableImagesOfPage(), 'licensing off' );
		$this->assertSame( '', $m->renderLicensingSchema( [] ) );

		$wp_query = null;
	}

	public function test_the_license_is_written_into_each_file_of_an_image_without_re_encoding(): void {

		$dir  = sys_get_temp_dir() . '/pp-license-' . uniqid();
		mkdir( $dir );
		$jpeg = dirname( __DIR__ ) . '/fixtures/images/02-landscape-3x2.jpg';
		foreach ( [ 'a-scaled.jpg', 'a.jpg', 'a-300x200.jpg' ] as $name ) {
			copy( $jpeg, "$dir/$name" );
		}

		$updated = 0;
		Functions\when( 'get_attached_file' )->justReturn( "$dir/a-scaled.jpg" );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [ 'original_image' => 'a.jpg', 'sizes' => [ 'medium' => [ 'file' => 'a-300x200.jpg' ], 'gone' => [ 'file' => 'a-1x1.jpg' ] ] ] );
		Functions\when( 'wp_update_attachment_metadata' )->alias( static function () use ( &$updated ) { $updated++; } );
		Functions\when( 'wp_basename' )->alias( 'basename' );

		\pp_api::$options['core/metadata/embed_licensor_enable'] = true;
		\pp_api::$options['core/metadata/web_statement_of_rights'] = 'https://example.test/license';
		\pp_api::$options['core/metadata/licensor_name'] = 'Test Licensor';
		\pp_api::$options['core/metadata/licensor_url'] = '';

		$m = ( new \ReflectionClass( metadata::class ) )->newInstanceWithoutConstructor();
		$this->assertInstanceOf( \WP_Error::class, $m->embedLicenseInImage( 5 ), 'not all three settings filled in' );
		$this->assertSame( 0, $updated );
		\pp_api::$options['core/metadata/licensor_url'] = 'https://licensor.example';

		try {
			$this->assertSame( [ "$dir/a-scaled.jpg", "$dir/a.jpg", "$dir/a-300x200.jpg" ], metadata::imageFiles( 5 ), 'files that are missing are left out' );
			$this->assertTrue( $m->embedLicenseInImage( 5 ) );
			$this->assertSame( 1, $updated, 'its metadata is updated, for offloading plugins' );

			foreach ( [ 'a-scaled.jpg', 'a.jpg', 'a-300x200.jpg' ] as $name ) {
				$md = new XmpReader();
				$md->loadFromFile( "$dir/$name" );
				$this->assertSame( 'https://example.test/license', $md->getXmp( 'xmpRights:WebStatement' ), $name );
				$this->assertSame( [ 'plus:LicensorName' => 'Test Licensor', 'plus:LicensorURL' => 'https://licensor.example' ], $md->getXmp( 'plus:Licensor' ), $name );
				$this->assertSame( ( new \Imagick( $jpeg ) )->getImageSignature(), ( new \Imagick( "$dir/$name" ) )->getImageSignature(), "$name: same pixels" );
			}

			\pp_api::$options['core/metadata/embed_licensor_enable'] = false;
			$this->assertInstanceOf( \WP_Error::class, $m->embedLicenseInImage( 5 ), 'licensing off' );
		} finally {
			array_map( 'unlink', glob( "$dir/*" ) );
			rmdir( $dir );
		}
	}

	public function test_a_keyword_taxonomy_is_emptied_when_the_file_has_other_keywords(): void {

		\pp_api::$options['core/metadata/custom_taxonomies'] = [
			[ 'id' => 'photos_keywords', 'tag' => 'dc:subject', 'parseTagValue' => false ],
			[ 'id' => 'photos_people', 'tag' => 'dc:subject', 'parseTagValue' => true ],
			[ 'id' => 'photos_city', 'tag' => 'photoshop:City', 'parseTagValue' => false ],
		];
		\pp_api::$options['core/metadata/custom_taxonomies_tag_delimiter'] = ':';

		$m = ( new \ReflectionClass( metadata::class ) )->newInstanceWithoutConstructor();

		$set = [];
		Functions\when( 'taxonomy_exists' )->justReturn( true );
		Functions\when( 'wp_defer_term_counting' )->justReturn( true );
		Functions\when( 'wp_set_object_terms' )->alias( static function ( $id, $terms, $taxonomy ) use ( &$set ) {
			$set[ $taxonomy ] = $terms;
		} );

		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'term_exists' )->justReturn( null );
		Functions\when( 'wp_insert_term' )->justReturn( [ 'term_id' => 13 ] );

		$md = new XmpReader();
		$md->loadFromArray( [ 'xmp' => [ 'dc:subject' => [ 'lake' ] ] ] );
		$m->setTaxonomyTerms( 42, $md );

		// People is emptied (the file has keywords, none of them people); the
		// city is kept, as the file has no location at all.
		$this->assertSame( [ 'photos_keywords' => [ 13 ], 'photos_people' => [] ], $set );
	}
}

