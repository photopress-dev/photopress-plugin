<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Functions;
use PhotoPress\modules\base\base;

final class BaseTest extends TestCase {

	public function test_the_base_script_and_jquery_come_only_with_the_slideshow(): void {

		Functions\stubs( [ 'plugins_url' => static fn( $path = '' ) => 'https://example.test/' . $path ] );
		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_register_script' )->once()->with( 'photopress', \Mockery::type( 'string' ), [ 'jquery' ], \Mockery::any(), true );

		( new \ReflectionClass( base::class ) )->newInstanceWithoutConstructor()->definePublicHooks();
	}
}
