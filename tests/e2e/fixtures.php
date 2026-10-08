<?php
/**
 * Test content for the end-to-end tests, run with WP-CLI:
 *
 *   wp eval-file tests/e2e/fixtures.php create
 *   wp eval-file tests/e2e/fixtures.php delete <json printed by create>
 *   wp eval-file tests/e2e/fixtures.php sweep
 *
 * create uploads the images in tests/fixtures/images, as an editor would
 * (so PhotoPress reads their metadata), builds draft pages from them, and
 * prints what it made. delete removes all of that, including the files and
 * any taxonomy terms the uploads created. sweep removes fixtures left behind
 * by a run that was killed before it could clean up.
 *
 * Everything created carries the _pp_test_fixture meta, and delete and sweep
 * only remove posts that carry it.
 */

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

const PP_FIXTURE_META = '_pp_test_fixture';

$pp_action = $args[0] ?? '';

function pp_fixture_terms() {

	$ids = get_terms( [
		'taxonomy'   => get_object_taxonomies( 'attachment' ),
		'hide_empty' => false,
		'fields'     => 'ids',
	] );

	return is_wp_error( $ids ) ? [] : array_map( 'intval', $ids );
}

function pp_fixture_delete_posts( array $ids ) {

	foreach ( $ids as $id ) {

		$post = get_post( (int) $id );

		$upload = $post && 'attachment' === $post->post_type && 0 === strpos( wp_basename( (string) get_post_meta( $post->ID, '_wp_attached_file', true ) ), 'pp-fixture-upload-' );

		if ( ! $post || ! ( $upload || get_post_meta( $post->ID, PP_FIXTURE_META, true ) ) ) {
			continue;
		}

		if ( 'attachment' === $post->post_type ) {
			wp_delete_attachment( $post->ID, true );
		} else {
			wp_delete_post( $post->ID, true );
		}
	}
}

function pp_fixture_delete_terms( array $term_ids ) {

	foreach ( $term_ids as $term_id ) {

		$term = get_term( (int) $term_id );

		// Only terms nothing else has come to use.
		if ( $term && ! is_wp_error( $term ) && 0 === (int) $term->count ) {
			wp_delete_term( $term->term_id, $term->taxonomy );
		}
	}
}

/**
 * Files left by the fixtures that no attachment owns any more: the old files
 * a replacement keeps (see MediaRest). Every fixture file name starts with
 * pp-fixture-.
 */
/**
 * Images the specs upload themselves (as a publishing tool would) cannot be
 * given the fixture meta, so they are named pp-fixture-upload-*.
 */
function pp_fixture_uploads( $older_than = 0 ) {

	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
		"SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
		WHERE p.post_type = 'attachment' AND m.meta_value LIKE %s AND p.post_date_gmt < %s",
		'%/pp-fixture-upload-%',
		gmdate( 'Y-m-d H:i:s', time() - $older_than )
	) ) );
}

function pp_fixture_delete_files( $older_than = 0 ) {

	$basedir = wp_get_upload_dir()['basedir'];

	foreach ( glob( $basedir . '/*/*/pp-fixture-*' ) ?: [] as $file ) {

		if ( ! $older_than || filemtime( $file ) < time() - $older_than ) {
			wp_delete_file( $file );
		}
	}
}

if ( 'delete' === $pp_action ) {

	$made = json_decode( $args[1] ?? '', true ) ?: [];
	pp_fixture_delete_posts( array_merge( array_values( $made['pages'] ?? [] ), $made['images'] ?? [], array_values( $made['replace'] ?? [] ), pp_fixture_uploads() ) );
	pp_fixture_delete_terms( $made['terms'] ?? [] );
	pp_fixture_delete_files();

	$pending = array_values( array_diff( get_option( '_pp_test_fixture_terms', [] ), $made['terms'] ?? [] ) );
	$pending ? update_option( '_pp_test_fixture_terms', $pending, false ) : delete_option( '_pp_test_fixture_terms' );
	exit;
}

if ( 'sweep' === $pp_action ) {

	$stale = get_posts( [
		'post_type'      => 'any',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_key'       => PP_FIXTURE_META,
		'date_query'     => [ [ 'before' => '1 hour ago' ] ],
	] );

	$stale = array_merge( $stale, pp_fixture_uploads( HOUR_IN_SECONDS ) );
	$terms = get_option( '_pp_test_fixture_terms', [] );
	pp_fixture_delete_posts( $stale );
	pp_fixture_delete_files( HOUR_IN_SECONDS );
	if ( $stale ) {
		pp_fixture_delete_terms( $terms );
		delete_option( '_pp_test_fixture_terms' );
	}
	echo count( $stale );
	exit;
}

if ( 'create' !== $pp_action ) {
	fwrite( STDERR, "Usage: wp eval-file fixtures.php create|delete <json>|sweep\n" );
	exit( 1 );
}

// Uploads run as an editor would, so the metadata module acts on them.
wp_set_current_user( (int) ( get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] )[0] ?? 1 ) );

$terms_before = pp_fixture_terms();
$images = [];

foreach ( glob( dirname( __DIR__ ) . '/fixtures/images/*.jpg' ) as $file ) {

	// sideload moves the file it is given.
	$tmp = wp_tempnam( basename( $file ) );
	copy( $file, $tmp );

	$id = media_handle_sideload( [ 'name' => 'pp-fixture-' . basename( $file ), 'tmp_name' => $tmp ], 0 );

	if ( is_wp_error( $id ) ) {
		@unlink( $tmp );
		// Nothing would delete the images uploaded so far.
		pp_fixture_delete_posts( $images );
		fwrite( STDERR, $file . ': ' . $id->get_error_message() . "\n" );
		exit( 1 );
	}

	update_post_meta( $id, PP_FIXTURE_META, 1 );
	// WordPress takes captions from IPTC, which the images do not have.
	wp_update_post( [ 'ID' => $id, 'post_excerpt' => 'Caption of ' . basename( $file, '.jpg' ) ] );
	$images[ basename( $file, '.jpg' ) ] = $id;
}

$new_terms = array_values( array_diff( pp_fixture_terms(), $terms_before ) );
// For sweep, should this run be killed before it deletes them.
update_option( '_pp_test_fixture_terms', array_values( array_unique( array_merge( get_option( '_pp_test_fixture_terms', [] ), $new_terms ) ) ), false );

// An image of its own for the replacement tests, which change its file.
$tmp = wp_tempnam( 'replace' );
copy( dirname( __DIR__ ) . '/fixtures/images/02-landscape-3x2.jpg', $tmp );
$replace_id = media_handle_sideload( [ 'name' => 'pp-fixture-replace-me.jpg', 'tmp_name' => $tmp ], 0 );

if ( is_wp_error( $replace_id ) ) {
	pp_fixture_delete_posts( $images );
	fwrite( STDERR, 'replace-me: ' . $replace_id->get_error_message() . "\n" );
	exit( 1 );
}

update_post_meta( $replace_id, PP_FIXTURE_META, 1 );

$image_blocks = '';

foreach ( $images as $id ) {
	$image_blocks .= sprintf(
		'<!-- wp:image {"id":%1$d,"sizeSlug":"large","linkDestination":"none"} --><figure class="wp-block-image size-large"><img src="%2$s" alt="" class="wp-image-%1$d"/></figure><!-- /wp:image -->',
		$id,
		esc_url( wp_get_attachment_image_url( $id, 'large' ) )
	);
}

// The same images with the landscape one first.
$landscape_first = $images;
$landscape_first = [ '02-landscape-3x2' => $landscape_first['02-landscape-3x2'] ] + $landscape_first;
$landscape_blocks = '';

foreach ( $landscape_first as $id ) {
	$landscape_blocks .= sprintf(
		'<!-- wp:image {"id":%1$d,"sizeSlug":"large","linkDestination":"none"} --><figure class="wp-block-image size-large"><img src="%2$s" alt="" class="wp-image-%1$d"/></figure><!-- /wp:image -->',
		$id,
		esc_url( wp_get_attachment_image_url( $id, 'large' ) )
	);
}

$gallery = static function ( array $attrs, $anchor = '', $blocks = null ) use ( $image_blocks ) {
	return sprintf(
		'<!-- wp:gallery %s --><figure class="wp-block-gallery has-nested-images columns-default is-cropped"%s>%s</figure><!-- /wp:gallery -->',
		wp_json_encode( $attrs + [ 'linkTo' => 'none' ] ),
		$anchor ? ' id="' . esc_attr( $anchor ) . '"' : '',
		$blocks ?? $image_blocks
	);
};

$page = static function ( $title, $content ) {
	$id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => $title, 'post_content' => $content ], true );
	if ( is_wp_error( $id ) ) {
		fwrite( STDERR, $id->get_error_message() . "\n" );
		exit( 1 );
	}
	update_post_meta( $id, PP_FIXTURE_META, 1 );
	return $id;
};

$spacer = str_repeat( '<!-- wp:paragraph --><p>Spacer paragraph between the slideshow and its gallery.</p><!-- /wp:paragraph -->', 4 );

$medium = wp_get_attachment_image_src( $replace_id, 'medium' );

echo wp_json_encode( [
	'images' => array_values( $images ),
	'replace' => [
		'image' => $replace_id,
		// An image block showing the medium size, with its own alt text and
		// caption, and a link to the full size.
		'post'  => $page( 'E2E: replaced image', sprintf(
			'<!-- wp:image {"id":%1$d,"sizeSlug":"medium"} --><figure class="wp-block-image size-medium"><img src="%2$s" alt="Alt written in the post" class="wp-image-%1$d"/><figcaption class="wp-element-caption">Caption written in the post</figcaption></figure><!-- /wp:image -->'
			. '<!-- wp:paragraph --><p><a href="%3$s">Full size</a></p><!-- /wp:paragraph -->',
			$replace_id,
			esc_url( $medium[0] ),
			esc_url( wp_get_attachment_url( $replace_id ) )
		) ),
	],
	'names'  => $images,
	'terms'  => $new_terms,
	'pages'  => [
		'slideshow' => $page( 'E2E: gallery slideshow', '<!-- wp:photopress/gallery-slideshow {"galleryAnchor":"main-gallery","maxHeightOffset":150} /-->' . $spacer . $gallery( [], 'main-gallery' ) ),
		'lightbox'  => $page( 'E2E: lightbox', $gallery( [ 'photopressSlideshow' => true ] ) ),
		'slideshowLandscapeFirst' => $page( 'E2E: gallery slideshow, landscape first', '<!-- wp:photopress/gallery-slideshow {"galleryAnchor":"landscape-gallery","maxHeightOffset":150} /-->' . $spacer . $gallery( [], 'landscape-gallery', $landscape_blocks ) ),
		'hidden'    => $page( 'E2E: hidden gallery', $gallery( [], 'hidden-gallery' ) . '<!-- wp:photopress/gallery-slideshow {"galleryAnchor":"hidden-gallery","hideGallery":true} /-->' ),
		// Galleries for the new image to take the format of: images linked
		// to their files, with captions, the last resized and cropped; and
		// images that open in the lightbox.
		'addStatic'  => $page( 'E2E: add to a static gallery', sprintf(
			'<!-- wp:gallery {"linkTo":"media","anchor":"linked"} --><figure class="wp-block-gallery has-nested-images columns-default is-cropped" id="linked">'
			. '<!-- wp:image {"id":%1$d,"sizeSlug":"large","linkDestination":"media"} --><figure class="wp-block-image size-large"><a href="%2$s"><img src="%3$s" alt="" class="wp-image-%1$d"/></a><figcaption class="wp-element-caption">Caption written in the post</figcaption></figure><!-- /wp:image -->'
			. '<!-- wp:image {"id":%4$d,"width":"300px","aspectRatio":"1","scale":"cover","sizeSlug":"large","linkDestination":"media"} --><figure class="wp-block-image size-large is-resized"><a href="%5$s"><img src="%6$s" alt="" class="wp-image-%4$d" style="aspect-ratio:1;object-fit:cover;width:300px;height:auto"/></a><figcaption class="wp-element-caption">Another</figcaption></figure><!-- /wp:image -->'
			. '</figure><!-- /wp:gallery -->'
			. '<!-- wp:gallery {"linkTo":"none","anchor":"expand"} --><figure class="wp-block-gallery has-nested-images columns-default is-cropped" id="expand">'
			. '<!-- wp:image {"lightbox":{"enabled":true},"id":%1$d,"sizeSlug":"large","linkDestination":"none"} --><figure class="wp-block-image size-large"><img src="%3$s" alt="" class="wp-image-%1$d"/></figure><!-- /wp:image -->'
			. '</figure><!-- /wp:gallery -->',
			$images['01-portrait-2x3'],
			esc_url( wp_get_attachment_url( $images['01-portrait-2x3'] ) ),
			esc_url( wp_get_attachment_image_url( $images['01-portrait-2x3'], 'large' ) ),
			$images['02-landscape-3x2'],
			esc_url( wp_get_attachment_url( $images['02-landscape-3x2'] ) ),
			esc_url( wp_get_attachment_image_url( $images['02-landscape-3x2'], 'large' ) )
		) ),
		'addDynamic' => $page( 'E2E: add to a dynamic gallery', '<!-- wp:photopress/gallery-slideshow {"galleryAnchor":"dynamic"} /--><!-- wp:gallery {"anchor":"dynamic","dynamicContent":{"source":"core/attached-media"}} /-->' ),
		'captions'  => $page( 'E2E: slideshow captions', '<!-- wp:photopress/gallery-slideshow {"galleryAnchor":"left-gallery","captionPosition":"left","captionPadding":10,"galleryNavigation":false} /-->'
			. '<!-- wp:photopress/gallery-slideshow {"galleryAnchor":"left-gallery","captionPosition":"right","galleryNavigation":false} /-->'
			. $gallery( [], 'left-gallery' ) ),
	],
] );
