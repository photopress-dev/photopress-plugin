<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Functions;
use PhotoPress\modules\media\OffloadMedia;

/**
 * WP Offload Media, through the one class that knows it: its settings by
 * their documented keys, and its upload held while sizes are made.
 */
final class OffloadMediaTest extends TestCase {

	protected function tearDown(): void {

		OffloadMedia::init( null );
		parent::tearDown();
	}

	private static function offloadMedia( array $settings ): object {

		return new class( $settings ) {
			public function __construct( private array $settings ) {}
			public function get_setting( $key ) { return $this->settings[ $key ] ?? null; }
		};
	}

	public function test_its_settings_and_delivery_domain_while_it_is_active(): void {

		$this->assertFalse( OffloadMedia::active() );
		$this->assertNull( OffloadMedia::setting( 'bucket' ) );
		$this->assertSame( '', OffloadMedia::deliveryDomain() );

		OffloadMedia::init( self::offloadMedia( [ 'bucket' => 'images.example.com', 'serve-from-s3' => true, 'enable-delivery-domain' => true, 'delivery-domain' => 'cdn.example.com' ] ) );
		$this->assertTrue( OffloadMedia::active() );
		$this->assertSame( 'images.example.com', OffloadMedia::setting( 'bucket' ) );
		$this->assertSame( 'cdn.example.com', OffloadMedia::deliveryDomain() );

		OffloadMedia::init( self::offloadMedia( [ 'serve-from-s3' => true, 'enable-delivery-domain' => false, 'delivery-domain' => 'cdn.example.com' ] ) );
		$this->assertSame( '', OffloadMedia::deliveryDomain(), 'the domain turned off' );

		OffloadMedia::init( new \stdClass() );
		$this->assertFalse( OffloadMedia::active(), 'not its object' );
	}

	public function test_its_upload_is_held_while_sizes_are_made_even_when_making_them_fails(): void {

		$held = OffloadMedia::withUploadHeld( static fn() => has_filter( 'as3cf_pre_update_attachment_metadata', '__return_true' ) );
		$this->assertNotFalse( $held );
		$this->assertFalse( has_filter( 'as3cf_pre_update_attachment_metadata', '__return_true' ) );

		try {
			OffloadMedia::withUploadHeld( static function () { throw new \RuntimeException( 'no sizes' ); } );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'no sizes', $e->getMessage() );
		}
		$this->assertFalse( has_filter( 'as3cf_pre_update_attachment_metadata', '__return_true' ) );
	}

	public function test_clearing_everything_is_for_a_network_admin_and_once_in_ten_minutes(): void {

		$routes = [];
		Functions\when( 'register_rest_route' )->alias( static function ( $ns, $route, $args ) use ( &$routes ) { $routes[ $route ] = $args; } );
		( new \ReflectionClass( \PhotoPress\modules\media\media::class ) )->newInstanceWithoutConstructor()->registerRoutes();
		$clear = $routes['/cdn/invalidate']['callback'];

		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'manage_network_options' !== $cap );
		$this->assertSame( 'photopress_cdn_network', $clear( [ 'scope' => 'all' ] )->get_error_code(), 'a site admin on a network' );

		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( time() - 60 );
		$recent = $clear( [ 'scope' => 'all' ] );
		$this->assertSame( 'photopress_cdn_recent', $recent->get_error_code() );
		$this->assertSame( 429, $recent->get_error_data()['status'] );
	}
}

