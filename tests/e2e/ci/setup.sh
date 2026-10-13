#!/usr/bin/env bash
#
# Builds the WordPress site the end-to-end tests run against in CI
# (.github/workflows/tests.yml): this checkout as the plugin, the plugins
# the test site has at their versions there, Twenty Twenty-Four, the test
# site's permalinks and PhotoPress settings (options.json). Needs WP-CLI and
# a MySQL server at 127.0.0.1 with root/root.
#
#   PP_E2E_WP_PATH  Where to put WordPress. Required.
#   PP_E2E_URL      The site's address. Default http://localhost.
set -euo pipefail

plugin=$( cd "$( dirname "$0" )/../../.." && pwd )
wp_path=${PP_E2E_WP_PATH:?Set PP_E2E_WP_PATH}
url=${PP_E2E_URL:-http://localhost}
wp() { command wp --path="$wp_path" "$@"; }

wp core download --quiet
wp config create --dbname=wordpress --dbuser=root --dbpass=root --dbhost=127.0.0.1 --skip-check --quiet
# Notices to a log, kept with the results, not into the pages.
wp config set WP_DEBUG true --raw
wp config set WP_DEBUG_DISPLAY false --raw
wp config set WP_DEBUG_LOG true --raw
# Jetpack without a WordPress.com connection.
wp config set JETPACK_DEV_DEBUG true --raw
# WP Offload Media, for the test bucket and distribution, as on the test
# site, but with the AWS credentials the workflow is given (an IAM role, by
# GitHub's OpenID Connect): Offload Media finds them itself (use-server-roles);
# PhotoPress's CloudFront invalidations take them from its own constants.
wp config set AS3CF_SETTINGS "serialize( [ 'provider' => 'aws', 'use-server-roles' => true, 'bucket' => 'photopress-e2e-test-109862024820', 'region' => 'us-east-1', 'copy-to-s3' => true, 'serve-from-s3' => true, 'remove-local-file' => false, 'enable-object-prefix' => true, 'object-prefix' => 'wp-content/uploads/', 'use-yearmonth-folders' => true, 'object-versioning' => true, 'delivery-provider' => 'aws', 'enable-delivery-domain' => true, 'delivery-domain' => 'd3gw179dkybu04.cloudfront.net', 'force-https' => true, 'block-public-access' => true, 'object-ownership-enforced' => true, 'use-bucket-acls' => false, 'enable-signed-urls' => false ] )" --raw
wp eval-file --skip-wordpress - "$wp_path/wp-config.php" <<'PHP'
<?php
$config = file_get_contents( $args[0] );
$aws = <<<'CONFIG'
if ( getenv( 'AWS_ACCESS_KEY_ID' ) ) {
	define( 'PHOTOPRESS_AWS_ACCESS_KEY_ID', getenv( 'AWS_ACCESS_KEY_ID' ) );
	define( 'PHOTOPRESS_AWS_SECRET_ACCESS_KEY', getenv( 'AWS_SECRET_ACCESS_KEY' ) );
	define( 'PHOTOPRESS_AWS_SESSION_TOKEN', getenv( 'AWS_SESSION_TOKEN' ) );
}

CONFIG;
file_put_contents( $args[0], str_replace( "/* That's all, stop editing!", $aws . "/* That's all, stop editing!", $config ) );
PHP
wp core install --url="$url" --title='PhotoPress E2E' --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email

ln -s "$plugin" "$wp_path/wp-content/plugins/photopress-plugin"
wp theme install twentytwentyfour --activate
wp rewrite structure '/%year%/%monthnum%/%day%/%postname%/'

# As on the test site. Yoast SEO is installed, not active: its default
# redirects images' pages to their files; the sitemaps spec makes it active
# in its own requests. WP Offload Media is made active by the offload spec
# while it runs.
for p in relevanssi@4.28.4 enable-media-replace@4.2.2 varnish-http-purge@5.12.3 jetpack@16.3 wordpress-seo@28.6 amazon-s3-and-cloudfront@3.4.3; do
	wp plugin install "${p%@*}" --version="${p#*@}" --quiet
done
wp plugin activate photopress-plugin relevanssi enable-media-replace varnish-http-purge jetpack
wp option update vhp_varnish_ip 127.0.0.1

wp eval-file - "$plugin/tests/e2e/ci/options.json" <<'PHP'
<?php
foreach ( json_decode( file_get_contents( $args[0] ), true ) as $name => $value ) {
	update_option( $name, $value );
}
PHP
wp rewrite flush
