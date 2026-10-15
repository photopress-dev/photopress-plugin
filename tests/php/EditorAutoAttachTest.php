<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Filters;
use PhotoPress\modules\gallery\EditorAutoAttach;

/**
 * The block editor's attaching of images to a post on save: off, unless
 * photopress_editor_auto_attach turns it on.
 */
final class EditorAutoAttachTest extends TestCase {

	public function test_the_setting_is_hooked() {

		EditorAutoAttach::addHooks();

		$this->assertNotFalse( has_filter( 'block_editor_settings_all', [ EditorAutoAttach::class, 'setting' ] ) );
	}

	public function test_attaching_is_off_and_other_settings_are_kept() {

		$settings = EditorAutoAttach::setting( [ 'autoAttachMediaEnabled' => true, 'richEditingEnabled' => true ] );

		$this->assertSame( [ 'autoAttachMediaEnabled' => false, 'richEditingEnabled' => true ], $settings );
	}

	public function test_the_filter_turns_attaching_on() {

		Filters\expectApplied( 'photopress_editor_auto_attach' )->once()->with( false )->andReturn( true );

		$this->assertTrue( EditorAutoAttach::setting( [] )['autoAttachMediaEnabled'] );
	}

	public function test_settings_that_are_not_an_array_pass_through() {

		$this->assertNull( EditorAutoAttach::setting( null ) );
	}
}
