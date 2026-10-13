<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Functions;
use PhotoPress\modules\gallery\ImageTemplates;
use PhotoPress\modules\gallery\LegacyChildPages;
use PhotoPress\modules\gallery\TaxonomyGalleryPages;

/**
 * The legacy Child Pages block turned into a Child Pages Gallery, and the
 * Taxonomy Galleries a post records for the page cache.
 */
final class GalleryUpgradeTest extends TestCase {

	protected function setUp(): void {

		parent::setUp();
		Functions\when( 'sanitize_html_class' )->returnArg();
	}

	private static function blocks( string $content ): array {

		return ( new \WP_Block_Parser() )->parse( $content );
	}

	public function test_a_legacy_block_with_its_defaults_becomes_a_child_pages_gallery_as_it_looked(): void {

		$gallery = LegacyChildPages::convert( self::blocks( '<!-- wp:photopress/childpages /-->' )[0] );

		$this->assertSame( 'core/gallery', $gallery['blockName'] );
		$this->assertSame( [ 'photopressSource' => 'children', 'columns' => 2, 'sizeSlug' => 'medium', 'style' => [ 'spacing' => [ 'blockGap' => '10px' ] ] ], $gallery['attrs'] );
		$this->assertSame( '<figure class="wp-block-gallery has-nested-images columns-2 is-cropped"></figure>', $gallery['innerHTML'] );
		$this->assertSame( [ $gallery['innerHTML'] ], $gallery['innerContent'] );
	}

	public function test_a_legacy_blocks_settings_are_carried_over(): void {

		$gallery = LegacyChildPages::convert( self::blocks( '<!-- wp:photopress/childpages {"columns":3,"imageSize":"large","imageCrop":false,"align":"wide","padding":4,"className":"mine"} /-->' )[0] );

		$this->assertSame( [ 'photopressSource' => 'children', 'columns' => 3, 'sizeSlug' => 'large', 'style' => [ 'spacing' => [ 'blockGap' => '4px' ] ], 'align' => 'wide', 'imageCrop' => false, 'className' => 'mine' ], $gallery['attrs'] );
		$this->assertSame( '<figure class="wp-block-gallery alignwide has-nested-images columns-3 mine"></figure>', $gallery['innerHTML'] );
	}

	public function test_legacy_blocks_are_converted_at_any_depth_and_others_left(): void {

		$converted = 0;
		$blocks = LegacyChildPages::convertBlocks( self::blocks( '<!-- wp:group --><div class="wp-block-group"><!-- wp:photopress/childpages /--></div><!-- /wp:group --><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ), $converted );

		$this->assertSame( 1, $converted );
		$this->assertSame( 'core/gallery', $blocks[0]['innerBlocks'][0]['blockName'] );
		$this->assertSame( 'core/paragraph', $blocks[1]['blockName'] );
	}

	public function test_a_posts_taxonomy_galleries_give_a_key_each(): void {

		$keys = TaxonomyGalleryPages::keysOf( self::blocks(
			'<!-- wp:gallery {"photopressSource":"query","photopressTaxonomy":"photos_keywords","photopressTerm":12} /-->'
			. '<!-- wp:group --><div class="wp-block-group"><!-- wp:gallery {"photopressSource":"query"} /--><!-- wp:gallery {"photopressSource":"query","photopressTaxonomy":"photos_keywords"} /--></div><!-- /wp:group -->'
			. '<!-- wp:gallery {"photopressSource":"children"} /--><!-- wp:gallery {"photopressSource":"archive"} /-->'
		) );

		// Without a term, the latest, as ImageTemplates::queryArgs() reads it.
		$this->assertSame( [ 'latest', 'photos_keywords:12' ], $keys );
	}

	public function test_a_heading_left_unset_says_what_the_gallery_shows(): void {

		$this->assertSame( 'Pages', ImageTemplates::headingOf( [ 'photopressSource' => 'children' ] ) );
		$this->assertSame( 'Images', ImageTemplates::headingOf( [ 'photopressSource' => 'query' ] ) );
		$this->assertSame( 'Ojai', ImageTemplates::headingOf( [ 'photopressSource' => 'children', 'photopressHeading' => ' Ojai ' ] ) );
		$this->assertSame( '', ImageTemplates::headingOf( [ 'photopressSource' => 'query', 'photopressHeading' => '' ] ) );
	}
}
