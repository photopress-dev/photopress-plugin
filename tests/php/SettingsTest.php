<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Functions;

final class SettingsTest extends TestCase {

	/**
	 * A settings page for the slideshow module with the fields that matter,
	 * built without the framework.
	 */
	private function page(): \photopress_settingsPage {

		$page = ( new \ReflectionClass( \photopress_settingsPage::class ) )->newInstanceWithoutConstructor();
		$page->package = 'core';
		$page->module = 'slideshow';

		$field = static fn( $type, $default, $extra = [] ) => (object) [ 'properties' => [ 'type' => $type, 'default_value' => $default ] + $extra ];

		$page->fields = [
			'enable'             => $field( 'boolean', true ),
			'thumbnailHeight'    => $field( 'integer', 120 ),
			'detail_position'    => $field( 'select', 'bottom', [ 'options' => [ 'bottom', 'right' ] ] ),
			'attachmentLinkText' => $field( 'text', 'Read More...' ),
			'licensor_url'       => $field( 'url', '' ),
			'custom_taxonomies'  => $field( 'none', [] ),
		];

		Functions\stubs( [
			// As WordPress's: tags (scripts with their contents) removed, whitespace collapsed.
			'sanitize_text_field' => static fn( $v ) => trim( preg_replace( '/\s+/', ' ', TestCase::stripAllTags( $v ) ) ),
			'esc_url_raw'         => static fn( $v ) => preg_match( '#^https?://#', (string) $v ) ? (string) $v : '',
			'sanitize_key'        => static fn( $v ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ),
		] );

		return $page;
	}

	public function test_every_field_has_a_definite_schema_type(): void {

		$schema = $this->page()->getSchema();

		$this->assertSame( [ 'type' => 'boolean' ], $schema['enable'] );
		$this->assertSame( 'integer', $schema['thumbnailHeight']['type'] );
		$this->assertSame( [ 'type' => 'string', 'enum' => [ 'bottom', 'right' ] ], $schema['detail_position'] );
		$this->assertSame( [ 'type' => 'string' ], $schema['licensor_url'] );
		$this->assertSame( 'array', $schema['custom_taxonomies']['type'] );
		$this->assertFalse( $schema['custom_taxonomies']['items']['additionalProperties'] );

		foreach ( $schema as $name => $definition ) {
			$this->assertNotSame( '', $definition['type'], "$name has no type" );
		}
	}

	public function test_sanitize_cleans_each_value_by_type(): void {

		Functions\when( 'get_option' )->justReturn( [ 'detail_position' => 'right' ] );

		$clean = $this->page()->sanitizeOptions( [
			'enable'             => '1',
			'thumbnailHeight'    => '-40',
			'detail_position'    => '"><script>alert(1)</script>',
			'attachmentLinkText' => 'More <script>alert(1)</script>',
			'licensor_url'       => 'javascript:alert(1)',
			'custom_taxonomies'  => [ [ 'id' => 'Photos Person!', 'pluralLabel' => '<b>People</b>', 'parseTagValue' => 'yes', 'extra' => 'dropped' ] ],
			'not_a_field'        => 'dropped',
		] );

		$this->assertTrue( $clean['enable'] );
		$this->assertSame( 0, $clean['thumbnailHeight'] );
		$this->assertSame( 'right', $clean['detail_position'], 'an unknown option keeps the stored value' );
		$this->assertSame( 'More', $clean['attachmentLinkText'] );
		$this->assertSame( '', $clean['licensor_url'] );
		$this->assertSame(
			[ [ 'id' => 'photosperson', 'pluralLabel' => 'People', 'singularLabel' => '', 'tag' => '', 'parseTagValue' => true ] ],
			$clean['custom_taxonomies']
		);
		$this->assertArrayNotHasKey( 'not_a_field', $clean );
	}

	public function test_each_taxonomy_id_once(): void {

		Functions\when( 'get_option' )->justReturn( [] );

		$long  = str_repeat( 'x', 32 );
		$clean = $this->page()->sanitizeOptions( [
			'custom_taxonomies' => [
				[ 'id' => 'pp_genre' ],
				[ 'id' => 'pp_genre' ],
				[ 'id' => 'PP_Genre' ],
				[ 'id' => $long ],
				[ 'id' => $long ],
			],
		] );

		$ids = array_column( $clean['custom_taxonomies'], 'id' );

		$this->assertSame( [ 'pp_genre', 'pp_genre_2', 'pp_genre_3', $long, str_repeat( 'x', 30 ) . '_2' ], $ids );
		$this->assertLessThanOrEqual( 32, strlen( $ids[4] ) );
	}

		public function test_taxonomy_names_nesting_and_turning_off_are_kept_where_set(): void {

		Functions\when( 'get_option' )->justReturn( [] );

		$clean = $this->page()->sanitizeOptions( [
			'custom_taxonomies' => [
				[ 'id' => 'pp_people', 'tag' => 'dc:subject', 'parseTagValue' => true, 'names' => [ 'People', ' <b>person</b> ', '' ], 'nested' => 1 ],
				[ 'id' => 'photos_lens', 'tag' => 'aux:Lens', 'disabled' => true ],
				[ 'id' => 'photos_city', 'tag' => 'photoshop:City', 'names' => [], 'nested' => false, 'disabled' => false ],
			],
		] );

		$this->assertSame( [ 'People', 'person' ], $clean['custom_taxonomies'][0]['names'] );
		$this->assertTrue( $clean['custom_taxonomies'][0]['nested'] );
		$this->assertTrue( $clean['custom_taxonomies'][1]['disabled'] );
		$this->assertSame( [ 'id', 'pluralLabel', 'singularLabel', 'tag', 'parseTagValue' ], array_keys( $clean['custom_taxonomies'][2] ) );
	}

	public function test_fields_missing_from_the_input_keep_their_stored_values(): void {

		Functions\when( 'get_option' )->justReturn( [ 'thumbnailHeight' => 150 ] );

		$clean = $this->page()->sanitizeOptions( [ 'enable' => false ] );

		$this->assertFalse( $clean['enable'] );
		$this->assertSame( 150, $clean['thumbnailHeight'] );
		$this->assertSame( 'bottom', $clean['detail_position'], 'falls back to the default' );
	}

	public function test_a_non_array_value_is_replaced_by_the_stored_option(): void {

		Functions\when( 'get_option' )->justReturn( [ 'enable' => true ] );

		$this->assertSame( [ 'enable' => true ], $this->page()->sanitizeOptions( 'garbage' ) );
	}
}
