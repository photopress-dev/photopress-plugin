<?php

namespace PhotoPress\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Base for PhotoPress unit tests: Brain Monkey set up and torn down, the
 * WordPress functions most code paths touch stubbed with simple equivalents,
 * and pp_api options reset.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {

		parent::setUp();
		Monkey\setUp();

		\pp_api::$options = [];

		Functions\stubs( [
			// Used by the HTML API.
			'__'                     => static fn( $text ) => $text,
			'_doing_it_wrong'        => null,
			'esc_url'                => static fn( $url ) => $url,
			'wp_kses_uri_attributes' => static fn() => [ 'href', 'src', 'action' ],
			'wp_has_noncharacters'   => static fn() => false,

			'esc_attr'               => static fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false ),
			'esc_html'               => static fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false ),
			'esc_html__'             => static fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false ),
			'wp_json_encode'         => static fn( $data, $flags = 0 ) => json_encode( $data, $flags ),
			'wp_strip_all_tags'      => [ self::class, 'stripAllTags' ],
			'wp_slash'               => static fn( $value ) => $value,
		] );
	}

	protected function tearDown(): void {

		foreach ( $this->tempFiles as $file ) {
			@unlink( $file );
		}

		// Expectations set with Functions\expect() are Mockery's; count them,
		// or PHPUnit reports a test that only has those as risky.
		$this->addToAssertionCount( \Mockery::getContainer()->mockery_getExpectationCount() );

		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * What WordPress's wp_strip_all_tags() does: script and style elements
	 * are removed with their contents, then the remaining tags.
	 */
	public static function stripAllTags( $text ): string {

		$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );

		return trim( strip_tags( $text ) );
	}

	/**
	 * Writes $contents to a temporary file that is removed after the test.
	 */
	protected function tempFile( string $contents, string $suffix = '.jpg' ): string {

		$file = tempnam( sys_get_temp_dir(), 'pp-test-' );
		rename( $file, $file .= $suffix );
		file_put_contents( $file, $contents );
		$this->tempFiles[] = $file;

		return $file;
	}

	/**
	 * Runs $run and returns what it returns, asserting it took no more than
	 * $megabytes of memory beyond what was in use before, and no more than
	 * $seconds.
	 */
	protected function assertCheap( callable $run, int $megabytes = 32, float $seconds = 3.0 ) {

		$before = memory_get_usage();

		if ( function_exists( 'memory_reset_peak_usage' ) ) {
			memory_reset_peak_usage();
		}

		$started = microtime( true );
		$result = $run();
		$took = microtime( true ) - $started;

		if ( function_exists( 'memory_reset_peak_usage' ) ) {
			$this->assertLessThan( $megabytes * 1048576, memory_get_peak_usage() - $before, 'memory used' );
		}

		$this->assertLessThan( $seconds, $took, 'seconds taken' );

		return $result;
	}

	private array $tempFiles = [];
}
