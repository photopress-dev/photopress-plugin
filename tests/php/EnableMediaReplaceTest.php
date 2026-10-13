<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PhotoPress\modules\media\EnableMediaReplace;

/**
 * Enable Media Replace's replacement of an image's file, announced as
 * PhotoPress's own: the file replaced, and the image, its files and the
 * posts that show it changed.
 */
final class EnableMediaReplaceTest extends TestCase {

	/** What was announced: [ hook, ...args ]. */
	private array $announced = [];

	protected function setUp(): void {

		global $wpdb;

		parent::setUp();

		$announced = &$this->announced;
		$this->announced = [];

		Functions\stubs( [
			'wp_attachment_is_image'  => static fn( $id ) => $id < 100,
			'wp_get_upload_dir'       => [ 'baseurl' => 'https://example.com/wp-content/uploads' ],
			'attachment_url_to_postid' => 7,
			'wp_basename'             => static fn( $path ) => basename( $path ),
			// Image 7 is attached to post 30.
			'get_post_field'          => static fn( $field, $id ) => 7 === $id ? 30 : 0,
		] );

		// Posts 21 and 22 show image 7.
		$wpdb = new class() {
			public $posts = 'wp_posts';
			public $postmeta = 'wp_postmeta';
			public function esc_like( $text ) { return $text; }
			// As WordPress's: the values one by one, or in one array.
			public function prepare( $query, ...$args ) { return [ $query, 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args ]; }
			public function get_col( $prepared ) { return '%wp-image-7%' === $prepared[1][0] ? [ '21', '22' ] : []; }
		};

		foreach ( [ 'photopress_attachment_file_replaced', 'photopress_post_changed' ] as $hook ) {
			Actions\expectDone( $hook )->zeroOrMoreTimes()->whenHappen( static function ( ...$args ) use ( $hook, &$announced ) {
				$announced[] = [ $hook, ...$args ];
			} );
		}
	}

	protected function tearDown(): void {

		global $wpdb;

		$wpdb = null;
		parent::tearDown();
	}

	public function test_a_replacement_is_announced_as_photopress_own(): void {

		EnableMediaReplace::replaced( 'https://example.com/wp-content/uploads/2026/10/ojai-dusk.jpg', 'https://example.com/wp-content/uploads/2026/10/ojai.jpg', 7 );

		$this->assertSame( [
			[ 'photopress_attachment_file_replaced', 7, [ '2026/10/ojai.jpg' => '2026/10/ojai-dusk.jpg' ], [], [ 'reprocess_metadata' => true ] ],
			[ 'photopress_post_changed', 7, [ 'files' => [ 'https://example.com/wp-content/uploads/2026/10/ojai.jpg', 'https://example.com/wp-content/uploads/2026/10/ojai-dusk.jpg' ] ] ],
			[ 'photopress_post_changed', 21, [] ],
			[ 'photopress_post_changed', 22, [] ],
			[ 'photopress_post_changed', 30, [ 'gallery' => true ] ],
		], $this->announced );
	}

	public function test_the_same_name_has_no_replacements_and_an_old_version_passes_urls_alone(): void {

		$url = 'https://example.com/wp-content/uploads/2026/10/ojai.jpg';
		EnableMediaReplace::replaced( $url, $url );

		$this->assertSame( [ 'photopress_attachment_file_replaced', 7, [], [], [ 'reprocess_metadata' => true ] ], $this->announced[0] );
		$this->assertSame( [ 'photopress_post_changed', 7, [ 'files' => [ $url ] ] ], $this->announced[1] );
	}

	public function test_not_an_image(): void {

		EnableMediaReplace::replaced( 'https://example.com/wp-content/uploads/2026/10/menu.pdf', '', 120 );

		$this->assertSame( [], $this->announced );
	}
}
