<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Filters;
use PHPUnit\Framework\Attributes\DataProvider;
use PhotoPress\modules\metadata\XmpReader;

final class XmpReaderTest extends TestCase {

	private const STANDARD  = "http://ns.adobe.com/xap/1.0/\0";
	private const EXTENSION = "http://ns.adobe.com/xmp/extension/\0";

	private static function fixture( string $name ): string {

		return file_get_contents( __DIR__ . '/fixtures/xmp/' . $name );
	}

	private static function segment( int $marker, string $payload ): string {

		return "\xFF" . chr( $marker ) . pack( 'n', strlen( $payload ) + 2 ) . $payload;
	}

	/**
	 * A JPEG's structure as far as the reader looks: SOI, an unrelated APP0,
	 * the given APP1 segments, then the start of the image data.
	 */
	private static function jpeg( array $app1 ): string {

		$out = "\xFF\xD8" . self::segment( 0xE0, "JFIF\0\x01\x01\0\0\x01\0\x01\0\0" );

		foreach ( $app1 as $payload ) {
			$out .= self::segment( 0xE1, $payload );
		}

		// Start of scan, then something that must never be parsed.
		return $out . "\xFF\xDA\x00\x08" . str_repeat( 'x', 64 ) . '<x:xmpmeta>not metadata</x:xmpmeta>' . "\xFF\xD9";
	}

	private function read( string $contents, string $suffix = '.jpg' ): XmpReader {

		$reader = new XmpReader();
		$reader->loadFromFile( $this->tempFile( $contents, $suffix ) );

		return $reader;
	}

	private function readPacket( string $packet ): XmpReader {

		return $this->read( self::jpeg( [ self::STANDARD . $packet ] ) );
	}

	public function test_reads_attributes_and_elements_of_a_capture_one_packet(): void {

		$md = $this->readPacket( self::fixture( 'capture-one.xml' ) );

		$this->assertSame( 'Los Angeles', $md->getXmp( 'photoshop:City' ) );
		$this->assertSame( 'IQ4 150MP', $md->getXmp( 'tiff:Model' ) );
		$this->assertSame( 'Schneider Kreuznach LS 80mm f/2.8', $md->getXmp( 'aux:Lens' ) );
		$this->assertSame( 'Bob', $md->getXmp( 'dc:title' ) );
		$this->assertSame( [ 'genre:portrait', 'person:Bob' ], $md->getXmp( 'dc:subject' ) );
		// A list of one is returned as its value.
		$this->assertSame( 'Alice', $md->getXmp( 'dc:creator' ) );
		$this->assertSame( 'genre|portrait', $md->getXmp( 'lr:hierarchicalSubject' ) );
	}

	public function test_a_structure_written_as_attributes_is_an_array_of_fields(): void {

		$md = $this->readPacket( self::fixture( 'capture-one.xml' ) );

		$this->assertSame(
			[ 'Iptc4xmpCore:CiUrlWork' => 'http://www.alice.example' ],
			$md->getXmp( 'Iptc4xmpCore:CreatorContactInfo' )
		);
		$this->assertSame( 'http://www.alice.example', $md->getContactUrl() );
	}

	public function test_properties_split_across_several_descriptions_are_merged(): void {

		$md = $this->readPacket( self::fixture( 'multi-description.xml' ) );

		$this->assertSame( 'Los Angeles', $md->getXmp( 'photoshop:City' ) );
		$this->assertSame( 'Fields Medalist', $md->getXmp( 'photoshop:Headline' ) );
		$this->assertSame( 'IQ4 150MP', $md->getXmp( 'tiff:Model' ) );
	}

	public function test_properties_are_named_by_namespace_not_by_the_prefix_in_the_file(): void {

		// Written as ps2:State, in the photoshop namespace.
		$md = $this->readPacket( self::fixture( 'multi-description.xml' ) );

		$this->assertSame( 'California', $md->getXmp( 'photoshop:State' ) );
		$this->assertArrayNotHasKey( 'ps2:State', $md->getAllXmp() );
	}

	public function test_language_alternatives_give_the_default_and_keep_the_rest(): void {

		$md = $this->readPacket( self::fixture( 'multi-description.xml' ) );

		$this->assertSame( 'Bob', $md->getXmp( 'dc:title' ) );
		$this->assertSame( [ 'x-default' => 'Bob', 'fr' => 'Bob (fr)' ], $md->getAlternatives( 'dc:title' ) );
	}

	public function test_lists_and_lists_of_structures(): void {

		$md = $this->readPacket( self::fixture( 'multi-description.xml' ) );

		$this->assertSame( [ 'Bob', 'Alice' ], $md->getXmp( 'Iptc4xmpExt:PersonInImage' ) );
		$this->assertSame(
			[ 'Iptc4xmpExt:City' => 'Los Angeles', 'Iptc4xmpExt:Sublocation' => 'UCLA' ],
			$md->getXmp( 'Iptc4xmpExt:LocationShown' )
		);
		$this->assertSame( [ 'plus:LicensorName' => 'Alice Photography' ], $md->getXmp( 'plus:Licensor' ) );
		$this->assertSame( 'Plain text & entity', $md->getXmp( 'dc:description' ) );
		$this->assertArrayNotHasKey( 'rdf:li', $md->getAllXmp() );
	}

	public function test_legacy_wrapper_prefixes_and_rdf_forms(): void {

		$md = $this->readPacket( self::fixture( 'legacy-forms.xml' ) );

		$this->assertSame( 'Adobe Photoshop 7.0', $md->getXmp( 'xmp:CreatorTool' ) );
		$this->assertSame( 'https://example.test/rights', $md->getXmp( 'xmpRights:WebStatement' ) );
		$this->assertSame(
			[ [ 'stEvt:action' => 'saved', 'stEvt:when' => '2026-06-12' ], [ 'stEvt:action' => 'derived' ] ],
			$md->getXmp( 'xmpMM:History' )
		);
		$this->assertSame( [ 'exif:Fired' => 'False', 'exif:Mode' => '2' ], $md->getXmp( 'exif:Flash' ) );
		$this->assertSame( 'Copyright Alice', $md->getXmp( 'dc:rights' ) );
		$this->assertSame( '', $md->getXmp( 'dc:creator' ), 'whitespace alone is no value' );
	}

	public function test_legacy_prefixes_are_accepted_when_asking_for_a_tag(): void {

		$md = $this->readPacket( self::fixture( 'legacy-forms.xml' ) );

		$this->assertSame( 'Adobe Photoshop 7.0', $md->getXmp( 'xap:CreatorTool' ) );
		$this->assertSame( 'place|Los Angeles', $md->getXmp( 'lightroom:hierarchicalSubject' ) );
		$this->assertSame( 'place|Los Angeles', $md->getXmp( 'lr:hierarchicalSubject' ) );
	}

	public function test_extended_xmp_is_reassembled_from_its_segments(): void {

		$caption = str_repeat( 'Long caption text. ', 6000 );
		$extended = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
			. '<rdf:Description rdf:about="" xmlns:photoshop="http://ns.adobe.com/photoshop/1.0/"><photoshop:Instructions>' . $caption
			. '</photoshop:Instructions></rdf:Description></rdf:RDF></x:xmpmeta>';
		$guid = strtoupper( md5( $extended ) );
		$main = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
			. '<rdf:Description rdf:about="" xmlns:xmpNote="http://ns.adobe.com/xmp/note/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmpNote:HasExtendedXMP="' . $guid . '">'
			. '<dc:title><rdf:Alt><rdf:li xml:lang="x-default">Main</rdf:li></rdf:Alt></dc:title></rdf:Description></rdf:RDF></x:xmpmeta>';

		$segments = [ self::STANDARD . $main ];

		// Out of order, as nothing requires them in order.
		foreach ( array_reverse( str_split( $extended, 65000 ), true ) as $i => $part ) {
			$segments[] = self::EXTENSION . $guid . pack( 'N', strlen( $extended ) ) . pack( 'N', $i * 65000 ) . $part;
		}

		$md = $this->read( self::jpeg( $segments ) );

		$this->assertSame( 'Main', $md->getXmp( 'dc:title' ) );
		$this->assertSame( $caption, $md->getXmp( 'photoshop:Instructions' ) );
	}

	public function test_extended_xmp_for_another_guid_or_incomplete_is_ignored(): void {

		$extended = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description rdf:about="" xmlns:photoshop="http://ns.adobe.com/photoshop/1.0/" photoshop:Credit="extended"/></rdf:RDF></x:xmpmeta>';
		$main = static fn( $guid ) => '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description rdf:about="" xmlns:xmpNote="http://ns.adobe.com/xmp/note/" xmpNote:HasExtendedXMP="' . $guid . '"/></rdf:RDF></x:xmpmeta>';
		$guid = strtoupper( md5( $extended ) );
		$chunk = static fn( $g, $total ) => self::EXTENSION . $g . pack( 'N', $total ) . pack( 'N', 0 ) . $extended;

		$other = $this->read( self::jpeg( [ self::STANDARD . $main( str_repeat( 'A', 32 ) ), $chunk( $guid, strlen( $extended ) ) ] ) );
		$this->assertNull( $other->getXmp( 'photoshop:Credit' ) ?: null, 'a different GUID' );

		$incomplete = $this->read( self::jpeg( [ self::STANDARD . $main( $guid ), $chunk( $guid, strlen( $extended ) + 10 ) ] ) );
		$this->assertNull( $incomplete->getXmp( 'photoshop:Credit' ) ?: null, 'a packet shorter than its declared length' );
	}

	public function test_a_jpeg_without_xmp_has_none_and_image_data_is_not_searched(): void {

		$md = $this->read( self::jpeg( [] ) );

		$this->assertSame( [], $md->getAllXmp() );
	}

	public function test_a_malformed_jpeg_falls_back_to_searching_the_file(): void {

		$md = $this->read( "\xFF\xD8garbage, not a segment" . self::fixture( 'capture-one.xml' ) );

		$this->assertSame( 'Los Angeles', $md->getXmp( 'photoshop:City' ) );
	}

	#[DataProvider( 'wrappers' )]
	public function test_other_formats_are_searched_for_any_packet_wrapper( string $packet ): void {

		// Past the first 64KB read, so the packet straddles a chunk boundary.
		$md = $this->read( "II*\0" . str_repeat( "\0", 65530 ) . $packet . str_repeat( "\0", 100 ), '.tif' );

		$this->assertSame( 'Phase One', $md->getXmp( 'tiff:Make' ) );
	}

	public static function wrappers(): array {

		$description = '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description rdf:about="" xmlns:tiff="http://ns.adobe.com/tiff/1.0/" tiff:Make="Phase One"/></rdf:RDF>';

		return [
			'x:xmpmeta' => [ '<x:xmpmeta xmlns:x="adobe:ns:meta/">' . $description . '</x:xmpmeta>' ],
			'x:xapmeta' => [ '<x:xapmeta xmlns:x="adobe:ns:meta/">' . $description . '</x:xapmeta>' ],
			'bare rdf:RDF' => [ '<?xpacket begin=""?>' . $description . '<?xpacket end="r"?>' ],
		];
	}

	public function test_unreadable_or_invalid_input_gives_no_properties(): void {

		$missing = new XmpReader();
		$missing->loadFromFile( sys_get_temp_dir() . '/photopress-does-not-exist.jpg' );
		$this->assertSame( [], $missing->getAllXmp() );

		$broken = $this->readPacket( '<x:xmpmeta><rdf:RDF><unclosed>' );
		$this->assertSame( [], $broken->getAllXmp() );
	}

	public function test_external_entities_are_not_expanded(): void {

		$secret = $this->tempFile( 'TOP-SECRET', '.txt' );
		$packet = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY xxe SYSTEM "file://' . $secret . '">]>'
			. '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
			. '<rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:description>&xxe;</dc:description></rdf:Description></rdf:RDF></x:xmpmeta>';

		$values = json_encode( $this->readPacket( $packet )->getAllXmp() );

		$this->assertStringNotContainsString( 'TOP-SECRET', $values );
	}

	public function test_string_of_keywords_uses_the_value_of_hierarchical_keywords(): void {

		$md = $this->readPacket( self::fixture( 'capture-one.xml' ) );

		$this->assertSame( 'portrait, Bob', $md->getXmp( 'photopress:stringOfKeywords' ) );
	}

	public function test_string_of_keywords_with_one_keyword_and_a_custom_delimiter(): void {

		\pp_api::$options['core/metadata/custom_taxonomies_tag_delimiter'] = '|';

		$md = new XmpReader();
		$md->loadFromArray( [ 'xmp' => [ 'dc:subject' => [ 'place|Ojai' ] ] ] );
		$this->assertSame( 'Ojai', $md->getXmp( 'photopress:stringOfKeywords' ) );

		$md->loadFromArray( [ 'xmp' => [ 'dc:subject' => 'solo' ] ] );
		$this->assertSame( 'solo', $md->getXmp( 'photopress:stringOfKeywords' ) );
	}

	public function test_camera_falls_back_to_xmp_when_exif_has_none(): void {

		$md = new XmpReader();
		$md->loadFromArray( [ 'xmp' => [ 'tiff:Model' => 'IQ4 150MP' ], 'exif' => [ 'Make' => '', 'Model' => '' ] ] );
		$this->assertSame( 'IQ4 150MP', $md->getCamera() );

		$md->loadFromArray( [ 'xmp' => [ 'tiff:Model' => 'IQ4 150MP' ], 'exif' => [ 'Make' => 'Phase One', 'Model' => 'IQ4' ] ] );
		$this->assertSame( 'Phase One IQ4', $md->getCamera() );
	}

	public function test_get_xmp_with_several_names_returns_each(): void {

		$md = new XmpReader();
		$md->loadFromArray( [ 'xmp' => [ 'photoshop:City' => 'Ojai', 'photoshop:State' => 'CA' ] ] );

		$this->assertSame(
			[ 'photoshop:City' => 'Ojai', 'photoshop:State' => 'CA' ],
			$md->getXmp( [ 'photoshop:City', 'photoshop:State', 'missing:Key' ] )
		);
	}

	public function test_rights_statement_is_the_whole_text(): void {

		$md = new XmpReader();
		$md->loadFromArray( [ 'xmp' => [ 'xmpRights:UsageTerms' => [ 'Licensed under CC BY-NC-SA.' ] ] ] );

		$this->assertSame( 'Licensed under CC BY-NC-SA.', $md->getRightsStatement() );
	}

	public function test_readers_do_not_register_global_filters(): void {

		Filters\expectAdded( 'photopress_metadata_tag_value' )->never();

		$md = new XmpReader();
		$md->loadFromArray( [ 'xmp' => [ 'dc:subject' => [ 'a' ] ] ] );
		$md->getXmp( 'photopress:stringOfKeywords' );

		$this->addToAssertionCount( 1 );
	}

	public function test_serialized_input_never_creates_objects(): void {

		$md = new XmpReader();
		$md->loadFromSerializedString( serialize( [ 'dc:title' => new \ArrayObject( [ 1 ] ) ] ) );

		$this->assertInstanceOf( \__PHP_Incomplete_Class::class, $md->getAllXmp()['dc:title'] );
	}

	public function test_getty_images_fields_capture_one_writes_are_read(): void {

		$packet = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
			. '<rdf:Description rdf:about="" xmlns:GettyImagesGIFT="http://xmp.gettyimages.com/gift/1.0/">'
			. '<GettyImagesGIFT:Personality><rdf:Bag><rdf:li>Jane Smith</rdf:li><rdf:li>John Doe</rdf:li></rdf:Bag></GettyImagesGIFT:Personality>'
			. '</rdf:Description></rdf:RDF></x:xmpmeta>';

		$md = ( new XmpReader() )->parsePacket( $packet );

		$this->assertSame( [ 'Jane Smith', 'John Doe' ], $md['GettyImagesGIFT:Personality'] );
	}

	/**
	 * Every field Photoshop's File Info writes, filled in: each one the
	 * Custom Metadata list offers is read as text, or a list of text.
	 */
	public function test_every_listed_field_photoshop_writes_is_read(): void {

		$md = ( new XmpReader() )->parsePacket( file_get_contents( dirname( __DIR__ ) . '/fixtures/xmp/photoshop-file-info.xmp' ) );

		$listed = [
			'photoshop:Headline', 'dc:title', 'Iptc4xmpCore:IntellectualGenre', 'Iptc4xmpCore:Scene', 'Iptc4xmpCore:SubjectCode',
			'Iptc4xmpCore:Location', 'Iptc4xmpCore:CountryCode', 'dc:creator', 'photoshop:AuthorsPosition', 'photoshop:CaptionWriter',
			'photoshop:Credit', 'photoshop:Source', 'dc:rights', 'xmpRights:UsageTerms', 'photoshop:Instructions',
			'photoshop:TransmissionReference', 'Iptc4xmpExt:Event', 'Iptc4xmpExt:PersonInImage', 'Iptc4xmpExt:OrganisationInImageName',
			'Iptc4xmpExt:OrganisationInImageCode', 'Iptc4xmpExt:ModelAge', 'Iptc4xmpExt:AddlModelInfo', 'xmp:Rating', 'xmp:CreatorTool',
		];

		foreach ( $listed as $tag ) {
			$value = $md[ $tag ] ?? null;
			$this->assertNotEmpty( $value, $tag );
			foreach ( (array) $value as $item ) {
				$this->assertIsString( $item, $tag );
			}
		}

		$this->assertSame( [ '18', '24' ], $md['Iptc4xmpExt:ModelAge'] );
		$this->assertSame( 'foo', $md['Iptc4xmpExt:Event'], 'one language of a language alternative' );
		$this->assertSame( 'bar', $md['Iptc4xmpExt:LocationShown'][0]['Iptc4xmpExt:City'], 'structures keep their parts' );
	}
}
