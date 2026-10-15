<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Functions;
use PhotoPress\modules\search\Archives;
use PhotoPress\modules\search\search;

/**
 * Unneeded archives: off by default; once turned on, author, date and post
 * format archives are all off until their own switches are turned off. An
 * archive that is off redirects to the home page and leaves the sitemaps.
 */
final class ArchivesTest extends TestCase {

	/** Options, by name. */
	private array $options = [];

	/** What the request is: author, date or format. */
	private string $request = '';

	protected function setUp(): void {

		parent::setUp();

		$this->options = [];
		$this->request = '';
		$options = &$this->options;
		$request = &$this->request;

		Functions\when( 'get_option' )->alias( static function ( $name, $default = false ) use ( &$options ) {
			return $options[ $name ] ?? $default;
		} );
		Functions\when( 'is_author' )->alias( static function () use ( &$request ) {
			return 'author' === $request;
		} );
		Functions\when( 'is_date' )->alias( static function () use ( &$request ) {
			return 'date' === $request;
		} );
		Functions\when( 'is_tax' )->alias( static function ( $taxonomy = '' ) use ( &$request ) {
			return 'format' === $request && 'post_format' === $taxonomy;
		} );
	}

	public function test_off_by_default_and_every_archive_off_once_turned_on(): void {

		foreach ( [ 'author', 'date', 'format' ] as $archive ) {
			$this->assertFalse( Archives::off( $archive ), "$archive, never saved" );
		}

		$this->options[ search::OPTION ] = [ 'archives_off' => true ];

		foreach ( [ 'author', 'date', 'format' ] as $archive ) {
			$this->assertTrue( Archives::off( $archive ), "$archive, its switch never saved" );
		}

		$this->assertFalse( Archives::off( 'category' ), 'not one of them' );
	}

	public function test_each_archive_has_its_own_switch(): void {

		$this->options[ search::OPTION ] = [ 'archives_off' => true, 'date_archives_off' => false ];

		$this->assertTrue( Archives::off( 'author' ) );
		$this->assertFalse( Archives::off( 'date' ) );
		$this->assertTrue( Archives::off( 'format' ) );

		// The main switch off turns them all on again, whatever their own.
		$this->options[ search::OPTION ] = [ 'archives_off' => false, 'author_archives_off' => true ];
		$this->assertFalse( Archives::off( 'author' ) );
	}

	public function test_a_request_for_an_archive_that_is_off_redirects_to_the_home_page(): void {

		$this->options[ search::OPTION ] = [ 'archives_off' => true, 'format_archives_off' => false ];

		$this->request = 'author';
		$this->assertTrue( Archives::requested() );

		$this->request = 'date';
		$this->assertTrue( Archives::requested() );

		$this->request = 'format';
		$this->assertFalse( Archives::requested(), 'its switch is off' );

		$this->request = '';
		$this->assertFalse( Archives::requested(), 'not an archive' );

		Functions\expect( 'wp_safe_redirect' )->never();
		Archives::redirect();
	}

	public function test_before_wordpress_sends_author_1_to_the_authors_address(): void {

		Archives::addHooks();

		// redirect_canonical() runs at 10.
		$this->assertSame( 9, has_action( 'template_redirect', [ Archives::class, 'redirect' ] ) );
	}

	public function test_wordpresss_sitemap_leaves_out_users_and_post_formats_while_their_archives_are_off(): void {

		$users      = new \stdClass();
		$taxonomies = [ 'category' => 'c', 'post_format' => 'f' ];

		$this->assertSame( $users, Archives::sitemapProvider( $users, 'users' ) );
		$this->assertSame( $taxonomies, Archives::sitemapTaxonomies( $taxonomies ) );

		$this->options[ search::OPTION ] = [ 'archives_off' => true ];

		$this->assertFalse( Archives::sitemapProvider( $users, 'users' ) );
		$this->assertSame( $users, Archives::sitemapProvider( $users, 'posts' ), 'other sitemaps stay' );
		$this->assertSame( [ 'category' => 'c' ], Archives::sitemapTaxonomies( $taxonomies ) );
	}

	public function test_yoast_seos_sitemap_too(): void {

		Archives::addHooks();
		$this->assertFalse( has_filter( 'wpseo_sitemap_exclude_author', '__return_empty_array' ), 'not hooked while author archives are on' );
		$this->assertFalse( Archives::yoastExcludeTaxonomy( false, 'post_format' ) );

		$this->options[ search::OPTION ] = [ 'archives_off' => true ];

		Archives::addHooks();
		$this->assertNotFalse( has_filter( 'wpseo_sitemap_exclude_author', '__return_empty_array' ) );
		$this->assertTrue( Archives::yoastExcludeTaxonomy( false, 'post_format' ) );
		$this->assertFalse( Archives::yoastExcludeTaxonomy( false, 'category' ) );
		$this->assertTrue( Archives::yoastExcludeTaxonomy( true, 'category' ), 'what another filter excluded stays out' );
	}
}
