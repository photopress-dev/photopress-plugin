<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Functions;
use PhotoPress\modules\search\search;

/**
 * The Pages & Search switches. Attachment pages is WordPress's own setting:
 * until the switch is saved, WordPress's value stands and the switch shows
 * it (off on sites installed since 6.4); once saved, the switch decides it.
 */
final class SearchTest extends TestCase {

	/** Options, by name. */
	private array $options = [];

	protected function setUp(): void {

		parent::setUp();

		$this->options = [];
		$options = &$this->options;
		Functions\when( 'get_option' )->alias( static function ( $name, $default = false ) use ( &$options ) {
			return $options[ $name ] ?? $default;
		} );
	}

	public function test_until_saved_attachment_pages_follow_wordpress_and_other_switches_their_default(): void {

		// Installed since WordPress 6.4: off.
		$this->options['wp_attachment_pages_enabled'] = '0';
		$this->assertFalse( search::enabled( 'attachment_pages' ) );
		$this->assertFalse( search::attachmentPagesOption( false ), 'WordPress reads its own' );

		// Older: on. Never set: off, as WordPress reads it.
		$this->options['wp_attachment_pages_enabled'] = '1';
		$this->assertTrue( search::enabled( 'attachment_pages' ) );
		unset( $this->options['wp_attachment_pages_enabled'] );
		$this->assertFalse( search::enabled( 'attachment_pages' ) );

		$this->assertTrue( search::enabled( 'taxonomy_pages' ) );
		$this->assertFalse( search::enabled( 'images_in_search', false ) );
	}

	public function test_once_saved_the_switch_decides_wordpresss_setting(): void {

		$this->options['wp_attachment_pages_enabled'] = '0';
		$this->options[ search::OPTION ] = [ 'attachment_pages' => true ];
		$this->assertTrue( search::enabled( 'attachment_pages' ) );
		$this->assertSame( '1', search::attachmentPagesOption( false ) );

		$this->options[ search::OPTION ] = [ 'attachment_pages' => false ];
		$this->assertSame( '0', search::attachmentPagesOption( false ) );

		// Another switch saved, this one not: WordPress's still.
		$this->options[ search::OPTION ] = [ 'images_in_search' => true ];
		$this->assertFalse( search::attachmentPagesOption( false ) );
	}
}
