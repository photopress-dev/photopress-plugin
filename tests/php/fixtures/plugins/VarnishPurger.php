<?php
/**
 * Proxy Cache Purge's VarnishPurger, as PageCache calls it: the URLs it was
 * asked to purge.
 */
class VarnishPurger {

	public static array $purged = [];

	public static function purge_url( $url ) {

		self::$purged[] = $url;
	}
}
