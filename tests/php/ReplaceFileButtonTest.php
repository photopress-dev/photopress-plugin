<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Functions;
use PhotoPress\modules\media\ReplaceFileButton;

/**
 * The Replace file button in an image's details: for images that whoever is
 * looking may replace.
 */
final class ReplaceFileButtonTest extends TestCase {

	private array $caps = [];

	protected function setUp(): void {

		parent::setUp();

		$this->caps = [ 'upload_files' => true, 'edit_post' => true ];
		Functions\stubs( [
			'wp_attachment_is_image' => static fn( $id ) => $id < 100,
			'current_user_can'       => fn( $cap ) => $this->caps[ $cap ] ?? false,
			'esc_html__'             => static fn( $text ) => $text,
			'wp_script_is'           => true,
		] );
	}

	public function test_an_image_gets_the_button(): void {

		$fields = ReplaceFileButton::field( [ 'other' => [] ], (object) [ 'ID' => 12 ] );

		$this->assertArrayHasKey( 'other', $fields );
		$this->assertSame( 'html', $fields['photopress_replace_file']['input'] );
		$this->assertStringContainsString( 'data-id="12"', $fields['photopress_replace_file']['html'] );
		$this->assertStringContainsString( '>Replace file</button>', $fields['photopress_replace_file']['html'] );
	}

	public function test_no_button_for_other_files_or_without_the_rights_to_replace(): void {

		$this->assertSame( [], ReplaceFileButton::field( [], (object) [ 'ID' => 120 ] ), 'not an image' );

		$this->caps['edit_post'] = false;
		$this->assertSame( [], ReplaceFileButton::field( [], (object) [ 'ID' => 12 ] ), 'may not edit it' );

		$this->caps = [ 'edit_post' => true ];
		$this->assertSame( [], ReplaceFileButton::field( [], (object) [ 'ID' => 12 ] ), 'may not upload' );
	}
}
